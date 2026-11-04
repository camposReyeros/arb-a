<?php
declare(strict_types=1);

/**
 * Marca el nivel local de un usuario ya sincronizado desde ipro.
 * Uso: docker exec app-arb-a php /var/www/arb-a/bin/marcar_admin.php 13010 [lector|admin]
 */

require_once __DIR__ . '/../src/usuarios.php';

$login = trim($argv[1] ?? '');
$nivel = $argv[2] ?? 'admin';

if ($login === '') {
    fwrite(STDERR, "uso: marcar_admin.php usuario [lector|admin]\n");
    exit(1);
}

try {
    if (!marcarNivel($login, $nivel)) {
        fwrite(STDERR, "error: no existe el usuario {$login} (¿corriste la sincronización?)\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

bitacora(null, 'nivel_usuario', ['externo_id' => $login, 'nivel' => $nivel]);
fwrite(STDOUT, "usuario {$login}: nivel {$nivel}\n");
