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

// Fuentes auto-hospedadas: la CSP es `default-src 'self'`, no se permite Google Fonts.
$css = (string) file_get_contents(__DIR__ . '/../public/assets/portal.css');
ok(str_contains($css, '@font-face'), 'el CSS declara las fuentes locales');
ok(str_contains($css, '/assets/fonts/aguafina-script.woff2'), 'el CSS usa Aguafina Script local');
ok(str_contains($css, '/assets/fonts/edu-tas-beginner.woff2'), 'el CSS usa Edu TAS Beginner local');
ok(!str_contains($css, 'fonts.googleapis.com') && !str_contains($css, 'fonts.gstatic.com'), 'sin fuentes de terceros');
ok(is_file(__DIR__ . '/../public/assets/fonts/aguafina-script.woff2'), 'existe el woff2 de Aguafina Script');
ok(is_file(__DIR__ . '/../public/assets/fonts/edu-tas-beginner.woff2'), 'existe el woff2 de Edu TAS Beginner');

if ($fallos > 0) {
    fwrite(STDERR, $fallos . " fallo(s)\n");
    exit(1);
}

fwrite(STDOUT, "vistas: todo ok\n");
