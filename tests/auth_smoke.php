<?php
declare(strict_types=1);

/**
 * Humo de autenticación: requiere PostgreSQL vivo y el esquema aplicado.
 * Cubre la sincronización desde ipro, el derivado, el login por usuario y el
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

$login = 'T' . bin2hex(random_bytes(4));
$clave = 'clave-de-prueba-larga';

$estado = sincronizarUsuario([
    'externo_id' => $login,
    'correo' => 'prueba+' . bin2hex(random_bytes(3)) . '@servicios.care',
    'nombre' => 'Prueba',
    'token_hash' => derivadoIpro($login, $clave),
    'activo' => true,
]);
ok($estado === 'alta', 'alta desde ipro');

$fila = usuarioPorLogin('  ' . $login . '  ');
ok($fila !== null, 'login normalizado (solo trim)');
ok(
    $fila !== null && hash_equals((string) $fila['token_hash'], derivadoIpro($login, $clave)),
    'token_hash = derivado de ipro'
);

// El sync no debe tocar el nivel local.
marcarNivel($login, 'admin');
sincronizarUsuario([
    'externo_id' => $login,
    'correo' => (string) $fila['correo'],
    'nombre' => 'Prueba',
    'token_hash' => derivadoIpro($login, $clave),
    'activo' => true,
]);
$fila = usuarioPorLogin($login);
ok($fila !== null && $fila['nivel'] === 'admin', 'la sincronización respeta el nivel local');

$_SERVER['REMOTE_ADDR'] = '10.9.9.9';
limpiarFalloLogin($login, '10.9.9.9');
registrarFalloLogin($login, '10.9.9.9');
ok(bloqueoLogin($login, '10.9.9.9') === 0, 'un fallo no bloquea');

for ($i = 0; $i < 9; $i++) {
    registrarFalloLogin($login, '10.9.9.9');
}
ok(bloqueoLogin($login, '10.9.9.9') > 0, 'diez fallos bloquean');

limpiarFalloLogin($login, '10.9.9.9');
ok(bloqueoLogin($login, '10.9.9.9') === 0, 'limpiar fallos desbloquea');

ok(loginLocal($login, $clave) !== null, 'login correcto');
ok(loginLocal($login, 'otra-clave-cualquiera') === null, 'clave incorrecta niega');
ok(loginLocal('no-existe-jamas', $clave) === null, 'usuario inexistente niega');

bd()->prepare('update usuario set activo = false where externo_id = :e')->execute(['e' => $login]);
ok(loginLocal($login, $clave) === null, 'usuario inactivo niega');

// Login con el prefijo de ipro (`JOSE_13010`): el espejo solo trae el número,
// así que la fila se localiza por los últimos 5 y el hash confirma el prefijo.
$id = '9' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
$prefijado = 'JOSE_' . $id;
sincronizarUsuario([
    'externo_id' => $id,
    'correo' => 'prefijo+' . bin2hex(random_bytes(3)) . '@servicios.care',
    'nombre' => 'Prefijo',
    'token_hash' => derivadoIpro($prefijado, $clave),
    'activo' => true,
]);
ok(loginLocal($prefijado, $clave) !== null, 'login con prefijo (JOSE_12345) entra');
ok(loginLocal('OTRO_' . $id, $clave) === null, 'prefijo distinto no entra');

exit($fallos === 0 ? 0 : 1);
