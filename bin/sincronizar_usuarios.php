<?php
declare(strict_types=1);

/**
 * Sincroniza las credenciales de ipro hacia arb-a.
 *
 * No crea ni cambia contraseñas: copia `externo_id`, correo, nombre,
 * `token_hash` (el derivado que ya valida ipro) y `activo`. El `nivel` es local
 * y este script nunca lo toca: un admin de arb-a se marca con bin/marcar_admin.php.
 *
 * Uso: docker exec app-arb-a php /var/www/arb-a/bin/sincronizar_usuarios.php
 */

require_once __DIR__ . '/../src/usuarios.php';

$cfg = config()['ipro'] ?? [];
$dsn = (string) ($cfg['dsn'] ?? '');
if ($dsn === '') {
    fwrite(STDERR, "error: falta ARB_A_IPRO_DSN (ver .env.example)\n");
    exit(1);
}

try {
    $ipro = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $filas = $ipro->query(
        'select externo_id, nombre, email as correo, token_hash, activo from usuario'
    )->fetchAll();
} catch (Throwable $e) {
    fwrite(STDERR, 'error: no se pudo leer ipro: ' . $e->getMessage() . "\n");
    exit(1);
}

$conteo = ['alta' => 0, 'actualiza' => 0, 'omitido' => 0];
$pdo = bd();
$pdo->beginTransaction();

try {
    foreach ($filas as $fila) {
        $conteo[sincronizarUsuario($fila)]++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'error: fallo al sincronizar: ' . $e->getMessage() . "\n");
    exit(1);
}

bitacora(null, 'sincronizar_usuarios', $conteo);
fwrite(STDOUT, sprintf(
    "sincronizados: %d altas, %d actualizados, %d omitidos\n",
    $conteo['alta'],
    $conteo['actualiza'],
    $conteo['omitido']
));
