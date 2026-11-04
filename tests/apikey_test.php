<?php
declare(strict_types=1);

/**
 * Humo de la API-Key: requiere PostgreSQL con el esquema aplicado.
 *
 * Restaura la fila previa en `finally`, para poder correrlo contra un entorno real sin dejar
 * la API cerrada con una clave que nadie conoce, aunque el test falle a la mitad.
 */

require_once __DIR__ . '/../src/apikey.php';

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

$bd = bd();
$antes = $bd->query('select hash, creada_en, creada_por from apikey where id = 1')->fetch();
$antes = is_array($antes) ? $antes : null;

try {
    $nueva = regenerarApikey(null, 'prueba');
    ok((bool) preg_match('/^[a-f0-9]{64}$/', $nueva), 'la clave nueva son 64 hex');
    ok(apikeyValida($nueva), 'la clave nueva vale');
    ok(!apikeyValida('otra'), 'una clave ajena no vale');
    ok(!apikeyValida(''), 'la cadena vacía no vale');

    $fila = $bd->query('select hash, creada_por from apikey where id = 1')->fetch();
    ok(
        is_array($fila) && hash_equals((string) $fila['hash'], hash('sha256', $nueva)),
        'en la base queda el sha256'
    );
    ok(
        is_array($fila) && !hash_equals((string) $fila['hash'], $nueva),
        'la clave en claro no se guarda'
    );
    ok(is_array($fila) && ($fila['creada_por'] ?? null) === 'prueba', 'queda registrado quién la generó');

    $info = apikeyInfo();
    ok($info['origen'] === 'bd' && $info['creada_por'] === 'prueba', 'el origen pasa a ser la base');

    $segunda = regenerarApikey(null, 'prueba-2');
    ok(apikeyValida($segunda), 'la segunda clave vale');
    ok(!apikeyValida($nueva), 'la anterior deja de valer');

    $bitacoras = (int) $bd->query("select count(*) from bitacora where accion = 'apikey_regenerada'")->fetchColumn();
    ok($bitacoras >= 2, 'cada regeneración queda en bitácora');
} finally {
    if ($antes !== null) {
        $bd->prepare('update apikey set hash = :h, creada_en = :c, creada_por = :p where id = 1')
            ->execute(['h' => $antes['hash'], 'c' => $antes['creada_en'], 'p' => $antes['creada_por']]);
    } else {
        $bd->exec('delete from apikey where id = 1');
    }
}

$restaurada = $bd->query('select hash from apikey where id = 1')->fetchColumn();
ok(
    $antes !== null
        ? is_string($restaurada) && hash_equals((string) $antes['hash'], $restaurada)
        : $restaurada === false,
    'se restauró el estado previo'
);

exit($fallos === 0 ? 0 : 1);
