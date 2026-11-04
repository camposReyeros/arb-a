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

function usuarioPorCorreo(string $correo): ?array
{
    $stmt = bd()->prepare('select * from usuario where lower(correo) = :c');
    $stmt->execute(['c' => normalizarCorreo($correo)]);
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
 * Alta de usuario. La clave se guarda con `password_hash` (argon2id si está
 * disponible): este proyecto no arrastra el derivado histórico de ipro.
 */
function crearUsuario(string $correo, string $clave, string $nombre = '', string $nivel = 'lector'): int
{
    $correo = normalizarCorreo($correo);

    if (!correoValido($correo)) {
        throw new InvalidArgumentException('Correo inválido: ' . $correo);
    }
    if (!in_array($nivel, ARB_A_NIVELES, true)) {
        throw new InvalidArgumentException('Nivel inválido: ' . $nivel);
    }
    if (mb_strlen($clave) < 12) {
        throw new InvalidArgumentException('La clave debe tener al menos 12 caracteres.');
    }

    $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

    $stmt = bd()->prepare(
        'insert into usuario (correo, nombre, clave_hash, nivel)
         values (:c, :n, :h, :nivel) returning id'
    );
    $stmt->execute([
        'c' => $correo,
        'n' => $nombre !== '' ? $nombre : null,
        'h' => password_hash($clave, $algoritmo),
        'nivel' => $nivel,
    ]);

    return (int) $stmt->fetchColumn();
}

function listarUsuarios(): array
{
    return bd()->query('select id, correo, nombre, nivel, activo, creado_en
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
