<?php
declare(strict_types=1);

/**
 * Arranque común: configuración, sesión, CSRF y utilidades.
 * Todas las pantallas y binarios empiezan aquí.
 */

const ARB_A_RAIZ = __DIR__ . '/..';
const ARB_A_NIVELES = ['lector', 'admin'];

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $propia = ARB_A_RAIZ . '/config.php';
        $cfg = require is_file($propia) ? $propia : ARB_A_RAIZ . '/config.example.php';
    }

    return $cfg;
}

/** Escape de salida. Todo lo que venga de la BD o del usuario pasa por aquí. */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // TLS lo termina Caddy: el header solo vale si viene de un proxy de confianza.
    $https = isset($_SERVER['HTTPS'])
        || (in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), config()['proxy_confiable'] ?? [], true)
            && (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $https,
        'path' => '/',
    ]);
    session_name('arb_a');
    session_start();
}

function cabecerasDeSeguridad(): void
{
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
}

function csrfToken(): string
{
    iniciarSesion();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrfValido(?string $token): bool
{
    iniciarSesion();

    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function campoCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function redirigir(string $destino): never
{
    // Solo rutas internas: evita un open redirect si algún día el destino es variable.
    if (!str_starts_with($destino, '/') || str_starts_with($destino, '//')) {
        $destino = '/';
    }

    header('Location: ' . $destino, true, 303);
    exit;
}

function aviso(string $mensaje, string $tipo = 'ok'): void
{
    iniciarSesion();
    $_SESSION['aviso'] = ['mensaje' => $mensaje, 'tipo' => $tipo];
}

function tomarAviso(): ?array
{
    iniciarSesion();
    $aviso = $_SESSION['aviso'] ?? null;
    unset($_SESSION['aviso']);

    return is_array($aviso) ? $aviso : null;
}

function ipCliente(): ?string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $proxies = config()['proxy_confiable'] ?? [];

    // X-Forwarded-For solo se cree a un proxy de confianza (Caddy), nunca a cualquiera.
    if ($ip !== '' && in_array($ip, $proxies, true)) {
        // Se toma el último salto: es el que añadió nuestro proxy. El primero lo puede
        // fijar el cliente, así que no sirve para el rate limit.
        $saltos = array_values(array_filter(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')))));
        $real = $saltos !== [] ? $saltos[count($saltos) - 1] : '';
        if (filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }
    }

    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

/** Convierte un valor de php.ini ("64M", "1G", "512K", "1.5M") a bytes; 0 si es ilegible. */
function bytesDeIni(string $valor): int
{
    $valor = trim($valor);
    if ($valor === '') {
        return 0;
    }

    if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])?b?$/i', $valor, $m) !== 1) {
        return 0;
    }

    // Se redondea hacia arriba: declarar menos bytes de los reales abriría un hueco en la validación.
    $numero = (float) $m[1];
    $factor = (int) match (strtolower($m[2] ?? '')) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };

    return (int) ceil($numero * $factor);
}
