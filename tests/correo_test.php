<?php
declare(strict_types=1);

/** Pruebas del correo de aviso: HTML de correo, sin dependencias externas. */

require_once __DIR__ . '/../src/correo.php';

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

$link = 'https://arb-a.servicios.care/d/' . str_repeat('a', 64);
$enlace = (new DateTimeImmutable('+30 days'))->format(DATE_ATOM);
$archivo = (new DateTimeImmutable('+90 days'))->format(DATE_ATOM);
$html = avisoHtml('NOMINA_MARZO.CSV', $link, $enlace, $archivo, 31457280);

ok(str_contains($html, 'NOMINA_MARZO.CSV'), 'el nombre aparece en el HTML');
ok(str_contains($html, $link), 'el enlace aparece en el HTML');
ok(str_contains($html, '30.0 MB'), 'el tamaño aparece en el HTML');
ok(str_contains($html, 'Vence en 30 días'), 'los días de vigencia aparecen');
ok(str_contains($html, date('d/m/Y', strtotime('+30 days'))), 'la fecha de vencimiento del enlace');
ok(str_contains($html, date('d/m/Y', strtotime('+90 days'))), 'la fecha del portal');
ok(str_contains($html, 'Descargar archivo'), 'el botón de descarga está');
ok(substr_count($html, 'style="') > 10, 'todo el estilo va en línea');
ok(!str_contains($html, '<script'), 'sin JavaScript');
ok(!str_contains($html, 'class="'), 'sin clases: los clientes de correo no cargan CSS');
ok(!str_contains($html, 'fonts.googleapis'), 'sin fuentes externas');
ok(!str_contains($html, 'cdn.'), 'sin CDN externo');
ok(!str_contains($html, '<img'), 'sin imágenes externas que bloquee el cliente');

$malicioso = avisoHtml('<b>X</b>.CSV', $link, $enlace, $archivo, 1024);
ok(!str_contains($malicioso, '<b>X</b>'), 'el nombre se escapa');
ok(str_contains($malicioso, '&lt;b&gt;X&lt;/b&gt;.CSV'), 'el nombre escapado se conserva');

[$texto] = cuerpoAviso('NOMINA_MARZO.CSV', $link, $enlace, $archivo, 31457280);
ok(str_contains($texto, $link), 'el texto plano trae el enlace');
ok(str_contains($texto, '30.0 MB'), 'el texto plano trae el tamaño');
ok(str_contains($texto, date('d/m/Y', strtotime('+30 days'))), 'el texto plano trae la vigencia');

if ($fallos > 0) {
    fwrite(STDERR, $fallos . " fallo(s)\n");
    exit(1);
}

fwrite(STDOUT, "correo: todo ok\n");
