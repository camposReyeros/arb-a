<?php
declare(strict_types=1);

/**
 * GET /d/<token> — descarga directa, sin login.
 * Caddy reescribe /d/* a este script; también acepta /d.php?t=<token>.
 * Todo acceso queda en `evento_acceso` con via = 'directo'.
 */

require_once __DIR__ . '/../src/archivos.php';

cabecerasDeSeguridad();

/** Token inválido, vencido o archivo ausente: una sola respuesta, sin oráculo. */
function noDisponible(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Enlace no válido · ARB-A</title>'
        . '<link rel="stylesheet" href="/assets/aviso.css"></head>'
        . '<body><main>'
        . '<h1>Este enlace no está disponible</h1>'
        . '<p>Puede haber expirado o ser incorrecto. '
        . 'Solicite un nuevo enlace a quien le compartió el archivo.</p>'
        . '</main></body></html>';
    exit;
}

$token = (string) ($_GET['t'] ?? '');
if ($token === '') {
    // /d/<token>: Caddy puede entregar el token en el path.
    $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('~^/d/([a-f0-9]{64})/?$~', $ruta, $m) === 1) {
        $token = $m[1];
    }
}

$archivo = $token !== '' ? archivoPorToken($token) : null;
if ($archivo === null) {
    noDisponible();
}

$ruta = (string) $archivo['ruta'];

// Se sirve solo un fichero que viva dentro del directorio de archivos: nunca una ruta de fuera.
$dir = realpath((string) config()['archivos']['dir']);
$real = $ruta !== '' ? realpath($ruta) : false;
if ($dir === false || $real === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
    // Un link vivo sin fichero es un problema operativo: se registra, no se anuncia.
    error_log('arb-a: archivo ' . $archivo['id'] . ' sin fichero válido en disco');
    noDisponible();
}

$bytes = is_file($real) ? @filesize($real) : false;
if ($bytes === false || $bytes <= 0) {
    error_log('arb-a: archivo ' . $archivo['id'] . ' con tamaño ilegible');
    noDisponible();
}

// Solo se cuenta el acceso cuando la descarga sí puede ocurrir.
registrarAcceso(
    (int) $archivo['id'],
    null,
    'directo',
    null,
    ipCliente(),
    isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 300) : null
);

// El nombre viene saneado en origen; se limpia otra vez en el sumidero (cabecera HTTP).
$nombre = preg_replace('/[\r\n"\\\\\x00-\x1f\x7f]/', '', (string) $archivo['nombre']) ?? 'ARCHIVO.CSV';

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $nombre . '.gz"');
header('Content-Length: ' . (string) $bytes);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

readfile($real);
exit;
