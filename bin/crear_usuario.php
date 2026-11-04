<?php
declare(strict_types=1);

/**
 * Alta de usuario desde la terminal.
 * Uso: docker exec -it app-arb-a php /var/www/arb-a/bin/crear_usuario.php correo clave [nombre] [nivel]
 */

require_once __DIR__ . '/../src/usuarios.php';

$correo = $argv[1] ?? '';
$clave = $argv[2] ?? '';
$nombre = $argv[3] ?? '';
$nivel = $argv[4] ?? 'lector';

if ($correo === '') {
    fwrite(STDERR, "uso: crear_usuario.php correo [clave] [nombre] [lector|admin]\n");
    exit(1);
}

// La clave por argumento queda visible en `ps` y en el historial: se prefiere stdin.
if ($clave === '') {
    fwrite(STDOUT, 'clave (no se muestra): ');
    if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY, 'linux') !== false) {
        @shell_exec('stty -echo');
        $clave = (string) fgets(STDIN);
        @shell_exec('stty echo');
        fwrite(STDOUT, "\n");
        $clave = rtrim($clave, "\r\n");
    } else {
        $clave = rtrim((string) fgets(STDIN), "\r\n");
    }
}

if ($clave === '') {
    fwrite(STDERR, "error: clave vacía\n");
    exit(1);
}

try {
    $id = crearUsuario($correo, $clave, $nombre, $nivel);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

bitacora(null, 'alta_usuario', ['usuario_id' => $id, 'correo' => normalizarCorreo($correo), 'nivel' => $nivel]);
fwrite(STDOUT, "usuario {$id} creado: " . normalizarCorreo($correo) . " ({$nivel})\n");
