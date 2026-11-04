<?php
declare(strict_types=1);

/**
 * Router SOLO para desarrollo con el servidor embebido de PHP:
 *
 *   php -S 0.0.0.0:8080 -t public tools/router.php
 *
 * En producción las rutas las resuelve Caddy (ver docs/despliegue/Caddyfile); este archivo
 * vive fuera del docroot, así que nunca se sirve por HTTP.
 */

$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($ruta === '/api/upload') {
    require __DIR__ . '/../public/api/upload.php';

    return true;
}

if (preg_match('~^/d/[a-f0-9]{64}/?$~', $ruta) === 1) {
    require __DIR__ . '/../public/d.php';

    return true;
}

if ($ruta === '/api' || $ruta === '/') {
    $destino = $ruta === '/' ? '/../public/index.php' : '/../public/api/index.php';
    if (is_file(__DIR__ . $destino)) {
        require __DIR__ . $destino;

        return true;
    }

    return false;
}

return false; // estático: lo sirve el propio servidor embebido
