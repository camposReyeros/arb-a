<?php
declare(strict_types=1);

/** Pruebas puras de los helpers de presentación. No renderizan HTML. */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../vistas/layout.php';

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

ok(fechaVisible('2027-02-02 14:07:57.783687+00') === '02/02/2027', 'fecha en formato visible');
ok(diasRestantes((new DateTimeImmutable('+30 days'))->format('c')) === 30, 'días restantes');
ok(diasRestantes('2020-01-01 00:00:00+00') === 0, 'vencido nunca da negativo');
ok(tamanoLegible(2048) === '2 KB', 'tamaño en KB');
ok(tamanoLegible(5 * 1024 * 1024) === '5.0 MB', 'tamaño en MB');

if ($fallos > 0) {
    fwrite(STDERR, $fallos . " fallo(s)\n");
    exit(1);
}

fwrite(STDOUT, "vistas: todo ok\n");
