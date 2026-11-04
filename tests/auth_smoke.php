<?php
declare(strict_types=1);

/**
 * Humo de autenticación: requiere PostgreSQL vivo y el esquema aplicado.
 * Cubre el alta, la verificación de clave, la normalización del correo y el
 * throttle (la consulta con placeholders repetidos no la ve `php -l`).
 */

require_once __DIR__ . '/../src/auth.php';

$fallos = 0;
function ok(bool $condicion, string $mensaje): void
{
    global $fallos;
    if (!$condicion) {
        $fallos++;
        fwrite(STDERR, 'FALLO: ' . $mensaje . "\n");
        return;
    }
    fwrite(STDOUT, 'ok: ' . $mensaje . "\n");
}

$correo = 'prueba+' . bin2hex(random_bytes(4)) . '@servicios.care';
$id = crearUsuario($correo, 'clave-de-prueba-larga', 'Prueba', 'lector');
ok($id > 0, 'alta de usuario');

$fila = usuarioPorCorreo($correo);
ok($fila !== null && password_verify('clave-de-prueba-larga', (string) $fila['clave_hash']), 'verificación de clave');
ok(usuarioPorCorreo('  ' . strtoupper($correo) . '  ') !== null, 'correo normalizado');

$_SERVER['REMOTE_ADDR'] = '10.9.9.9';
limpiarFalloLogin($correo, '10.9.9.9');
registrarFalloLogin($correo, '10.9.9.9');
ok(bloqueoLogin($correo, '10.9.9.9') === 0, 'un fallo no bloquea');

for ($i = 0; $i < 9; $i++) {
    registrarFalloLogin($correo, '10.9.9.9');
}
ok(bloqueoLogin($correo, '10.9.9.9') > 0, 'diez fallos bloquean');

limpiarFalloLogin($correo, '10.9.9.9');
ok(bloqueoLogin($correo, '10.9.9.9') === 0, 'limpiar fallos desbloquea');

ok(loginLocal($correo, 'clave-de-prueba-larga') !== null, 'login correcto');
ok(loginLocal($correo, 'otra-clave-cualquiera') === null, 'clave incorrecta niega');

exit($fallos === 0 ? 0 : 1);
