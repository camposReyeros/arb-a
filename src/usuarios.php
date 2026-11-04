<?php
declare(strict_types=1);

require_once __DIR__ . '/bd.php';

/** El correo se guarda y se compara en minúsculas, sin espacios. */
function normalizarCorreo(string $correo): string
{
    return mb_strtolower(trim($correo));
}

function correoValido(string $correo): bool
{
    return filter_var($correo, FILTER_VALIDATE_EMAIL) !== false;
}

/** El login es el `externo_id` de ipro: se compara tal cual, solo sin espacios. */
function normalizarLogin(string $login): string
{
    return trim($login);
}

/**
 * Derivado del feeder, idéntico al de ipro: `md5(últimos 5 + usuario + clave)`.
 * Es rápido; el throttle por usuario+IP y la comparación con `hash_equals` lo compensan.
 */
function derivadoIpro(string $login, string $clave): string
{
    return md5(substr($login, -5) . $login . $clave);
}

/**
 * Busca la fila por el usuario tecleado.
 *
 * ipro no guarda el prefijo del login: el `per_token` se calculó con la cadena
 * completa (`JOSE_13010`) y en `usuario` solo quedó el número. Por eso, si no
 * hay coincidencia exacta, se prueba con los últimos 5 caracteres, que por
 * construcción son el `externo_id`. El hash se verifica después, así que la
 * cadena completa (prefijo incluido, con su caso) sigue siendo obligatoria.
 */
function usuarioPorLogin(string $login): ?array
{
    $login = normalizarLogin($login);
    $stmt = bd()->prepare('select * from usuario where externo_id = :e');
    $stmt->execute(['e' => $login]);
    $fila = $stmt->fetch();

    if ($fila !== false) {
        return $fila;
    }

    $sufijo = substr($login, -5);
    if (strlen($login) <= 5 || !ctype_digit($sufijo)) {
        return null;
    }

    $stmt->execute(['e' => $sufijo]);
    $fila = $stmt->fetch();

    return $fila !== false ? $fila : null;
}

function usuarioPorSesion(int $id): ?array
{
    $stmt = bd()->prepare('select * from usuario where id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();

    return $fila !== false ? $fila : null;
}

/**
 * Alta o actualización de un usuario venido de ipro. Nunca toca `nivel`:
 * el nivel es local (un admin de arb-a no tiene por qué serlo en ipro).
 * Devuelve 'alta', 'actualiza' u 'omitido'.
 */
function sincronizarUsuario(array $fila): string
{
    $login = normalizarLogin((string) ($fila['externo_id'] ?? ''));
    $correo = normalizarCorreo((string) ($fila['correo'] ?? ''));
    $nombre = trim((string) ($fila['nombre'] ?? ''));
    $token = mb_strtolower(trim((string) ($fila['token_hash'] ?? '')));
    $activo = (bool) ($fila['activo'] ?? true);

    if ($login === '' || !correoValido($correo) || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        return 'omitido';
    }

    $pdo = bd();

    // Instalaciones viejas: el correo ya existía con clave local y sin login.
    $adopta = $pdo->prepare(
        'update usuario set externo_id = :e, nombre = :n, token_hash = :t,
                activo = :a, sincronizado_en = now()
         where externo_id is null and lower(correo) = lower(:c)'
    );
    $adopta->execute([
        'e' => $login,
        'n' => $nombre !== '' ? $nombre : null,
        't' => $token,
        'a' => $activo ? 1 : 0,
        'c' => $correo,
    ]);
    if ($adopta->rowCount() > 0) {
        return 'actualiza';
    }

    $existe = $pdo->prepare('select 1 from usuario where externo_id = :e');
    $existe->execute(['e' => $login]);

    if ($existe->fetchColumn() !== false) {
        $pdo->prepare(
            'update usuario set correo = :c, nombre = :n, token_hash = :t,
                    activo = :a, sincronizado_en = now()
             where externo_id = :e'
        )->execute([
            'c' => $correo,
            'n' => $nombre !== '' ? $nombre : null,
            't' => $token,
            'a' => $activo ? 1 : 0,
            'e' => $login,
        ]);

        return 'actualiza';
    }

    $pdo->prepare(
        'insert into usuario (externo_id, correo, nombre, token_hash, activo, sincronizado_en)
         values (:e, :c, :n, :t, :a, now())'
    )->execute([
        'e' => $login,
        'c' => $correo,
        'n' => $nombre !== '' ? $nombre : null,
        't' => $token,
        'a' => $activo ? 1 : 0,
    ]);

    return 'alta';
}

/** Cambia el nivel local de un usuario ya sincronizado. */
function marcarNivel(string $login, string $nivel): bool
{
    if (!in_array($nivel, ARB_A_NIVELES, true)) {
        throw new InvalidArgumentException('Nivel inválido: ' . $nivel);
    }

    $stmt = bd()->prepare('update usuario set nivel = :n where externo_id = :e');
    $stmt->execute(['n' => $nivel, 'e' => normalizarLogin($login)]);

    return $stmt->rowCount() > 0;
}

function listarUsuarios(): array
{
    return bd()->query('select id, externo_id, correo, nombre, nivel, activo, sincronizado_en
                        from usuario order by creado_en desc')->fetchAll();
}

/** Bitácora administrativa (append-only en la BD). */
function bitacora(?int $usuarioId, string $accion, array $detalle = []): void
{
    bd()->prepare('insert into bitacora (usuario_id, accion, detalle) values (:u, :a, :d)')
        ->execute([
            'u' => $usuarioId,
            'a' => $accion,
            'd' => json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
}
