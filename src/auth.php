<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/usuarios.php';

/**
 * Autenticación local con las credenciales de ipro: usuario + clave, con el
 * mismo derivado (`md5(últimos 5 + usuario + clave)`) contra `usuario.token_hash`.
 * Las credenciales las sincroniza `bin/sincronizar_usuarios.php`; el correo del
 * usuario solo decide a qué archivos entra, no cómo inicia sesión.
 */

const ARB_A_IDLE_MIN = 30;      // inactividad máxima
const ARB_A_SESION_HORAS = 8;   // tope absoluto de la sesión
const ARB_A_LOGIN_MAX_FALLOS = 10;
const ARB_A_LOGIN_VENTANA_MIN = 15;
const ARB_A_LOGIN_BLOQUEO_MIN = 15;

function usuarioActual(): ?array
{
    iniciarSesion();
    $sesion = $_SESSION['usuario'] ?? null;

    if (!is_array($sesion) || (int) ($sesion['id'] ?? 0) <= 0) {
        return null;
    }

    // Vigencia de la sesión: 30 min sin actividad y 8 h de tope absoluto.
    $ahora = time();
    $inicio = (int) ($sesion['iniciado_en'] ?? 0);
    $ultimo = (int) ($sesion['ultimo_en'] ?? 0);
    if ($inicio <= 0
        || $ahora - $inicio > ARB_A_SESION_HORAS * 3600
        || $ahora - $ultimo > ARB_A_IDLE_MIN * 60) {
        cerrarSesion();

        return null;
    }
    $_SESSION['usuario']['ultimo_en'] = $ahora;

    try {
        $usuario = usuarioPorSesion((int) $sesion['id']);
    } catch (Throwable) {
        return null; // BD caída = negar, nunca "lector por default".
    }

    if ($usuario === null || !(bool) $usuario['activo']) {
        return null;
    }

    return $usuario;
}

function guardarSesion(array $usuario): void
{
    iniciarSesion();
    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id' => (int) ($usuario['id'] ?? 0),
        'externo_id' => (string) ($usuario['externo_id'] ?? ''),
        'correo' => (string) ($usuario['correo'] ?? ''),
        'nombre' => (string) ($usuario['nombre'] ?? ''),
        'iniciado_en' => time(),
        'ultimo_en' => time(),
    ];
}

function cerrarSesion(): void
{
    iniciarSesion();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

function esAdmin(?array $usuario): bool
{
    return $usuario !== null && ($usuario['nivel'] ?? '') === 'admin';
}

function requerirSesion(): array
{
    $usuario = usuarioActual();
    if ($usuario === null) {
        redirigir('/login.php');
    }

    return $usuario;
}

function requerirAdmin(): array
{
    $usuario = requerirSesion();
    if (!esAdmin($usuario)) {
        redirigir('/');
    }

    return $usuario;
}

function claveThrottle(string $login, string $ip): string
{
    // Trim y minúsculas SOLO para el bucket: el derivado usa el usuario crudo, igual que ipro.
    return mb_substr(mb_strtolower(trim($login)), 0, 80) . '|' . $ip;
}

/** Segundos restantes de bloqueo, o 0 si puede intentar. */
function bloqueoLogin(string $login, string $ip): int
{
    $stmt = bd()->prepare('select bloqueado_hasta from intento_login where clave = :c');
    $stmt->execute(['c' => claveThrottle($login, $ip)]);
    $hasta = $stmt->fetchColumn();

    if ($hasta === false || $hasta === null) {
        return 0;
    }

    $restan = (new DateTimeImmutable((string) $hasta))->getTimestamp() - time();

    return $restan > 0 ? $restan : 0;
}

function registrarFalloLogin(string $login, string $ip): void
{
    $pdo = bd();
    $clave = claveThrottle($login, $ip);

    $pdo->beginTransaction();
    try {
        // Candado por clave: dos fallos concurrentes no se pisan el contador.
        $pdo->prepare('select pg_advisory_xact_lock(hashtext(:c))')->execute(['c' => $clave]);

        $st = $pdo->prepare('select intentos, ultimo from intento_login where clave = :c');
        $st->execute(['c' => $clave]);
        $fila = $st->fetch();

        $base = new DateTimeImmutable('-' . ARB_A_LOGIN_VENTANA_MIN . ' minutes');
        $intentos = ($fila !== false && new DateTimeImmutable((string) $fila['ultimo']) > $base)
            ? (int) $fila['intentos'] + 1
            : 1;

        $bloqueo = $intentos >= ARB_A_LOGIN_MAX_FALLOS
            ? ARB_A_LOGIN_BLOQUEO_MIN . ' minutes'
            : null;

        // El driver nativo no admite el mismo placeholder dos veces: van distintos.
        $pdo->prepare(
            "insert into intento_login (clave, intentos, ultimo, bloqueado_hasta)
             values (:c1, :n1, now(), case when :b1::text is null then null
                                           else now() + :b1::interval end)
             on conflict (clave) do update set
                intentos = :n2, ultimo = now(),
                bloqueado_hasta = case when :b2::text is null then null
                                       else now() + :b2::interval end"
        )->execute([
            'c1' => $clave,
            'n1' => $intentos,
            'b1' => $bloqueo,
            'n2' => $intentos,
            'b2' => $bloqueo,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function limpiarFalloLogin(string $login, string $ip): void
{
    bd()->prepare('delete from intento_login where clave = :c')
        ->execute(['c' => claveThrottle($login, $ip)]);
}

/**
 * Valida usuario + clave contra el derivado de ipro. Devuelve la identidad o null.
 * Un solo camino de negación: el controlador muestra siempre el mismo mensaje.
 */
function loginLocal(string $login, string $clave): ?array
{
    $login = normalizarLogin($login);
    if ($login === '' || $clave === '') {
        return null;
    }

    $ip = ipCliente() ?? '';
    $fila = usuarioPorLogin($login);

    // El bucket va por el usuario real (no por la cadena tecleada): variar el
    // prefijo no debe abrir un contador nuevo en cada intento.
    $claveIntento = $fila !== null ? (string) $fila['externo_id'] : $login;
    if (bloqueoLogin($claveIntento, $ip) > 0) {
        return null;
    }

    $derivado = derivadoIpro($login, $clave);

    // Siempre se compara: sin fila se usa un hash imposible, sin oráculo por tiempo.
    $esperado = $fila !== null && is_string($fila['token_hash']) ? (string) $fila['token_hash'] : str_repeat('0', 32);
    $ok = hash_equals($esperado, $derivado) && $fila !== null && (bool) $fila['activo'];

    if (!$ok) {
        registrarFalloLogin($claveIntento, $ip);

        return null;
    }

    limpiarFalloLogin($claveIntento, $ip);
    guardarSesion($fila);

    return $fila;
}
