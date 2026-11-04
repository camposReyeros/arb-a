<?php
declare(strict_types=1);

/**
 * Pruebas de la validación de .xlsx (ZIP OOXML) y del nombre visible.
 * Sin BD ni HTTP. Requiere ext-zip en la imagen.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/errores.php';
require_once __DIR__ . '/../src/csv.php';
require_once __DIR__ . '/../src/xlsx.php';
require_once __DIR__ . '/../src/entrega.php';
require_once __DIR__ . '/../src/archivos.php';

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

/** Crea un ZIP con las partes indicadas; devuelve su ruta. */
function zipTemporal(array $partes): string
{
    $ruta = (string) tempnam(sys_get_temp_dir(), 'arba');
    @unlink($ruta);

    $zip = new ZipArchive();
    if ($zip->open($ruta, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('no se pudo crear el fixture');
    }
    foreach ($partes as $nombre => $contenido) {
        $zip->addFromString($nombre, $contenido);
    }
    $zip->close();

    return $ruta;
}

const TIPOS = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '</Types>';

const LIBRO = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheets><sheet name="Enero" sheetId="1" r:id="rId1"/><sheet name="Febrero" sheetId="2" r:id="rId2"/></sheets>'
    . '</workbook>';

$temporales = [];

try {
    // 1) Libro válido de dos hojas.
    $bueno = zipTemporal(['[Content_Types].xml' => TIPOS, 'xl/workbook.xml' => LIBRO, 'xl/worksheets/sheet1.xml' => '<worksheet/>']);
    $temporales[] = $bueno;
    ok(esXlsx($bueno), 'detecta un xlsx por su firma ZIP');
    ok(!esXlsBinario($bueno), 'un xlsx no se confunde con un .xls binario');
    $resumen = analizarXlsx($bueno);
    ok($resumen['hojas'] === 2, 'cuenta las hojas del libro (2)');
    ok($resumen['entradas'] === 3, 'cuenta las partes del ZIP (3)');

    // 2) ZIP sin workbook: no es un libro.
    $sinLibro = zipTemporal(['[Content_Types].xml' => TIPOS, 'otra.xml' => '<x/>']);
    $temporales[] = $sinLibro;
    try {
        analizarXlsx($sinLibro);
        ok(false, 'un ZIP sin workbook debe rechazarse');
    } catch (ErrorDeCarga $e) {
        ok($e->codigo === 'xlsx_invalido', 'un ZIP sin workbook da xlsx_invalido');
    }

    // 3) Con macros declaradas en los tipos.
    $conMacros = zipTemporal([
        '[Content_Types].xml' => str_replace('main+xml', 'macroEnabled.main+xml', TIPOS),
        'xl/workbook.xml' => LIBRO,
    ]);
    $temporales[] = $conMacros;
    try {
        analizarXlsx($conMacros);
        ok(false, 'un libro con macros debe rechazarse');
    } catch (ErrorDeCarga $e) {
        ok($e->codigo === 'xlsx_con_macros', 'macroEnabled da xlsx_con_macros');
    }

    // 4) Con una parte vbaProject.bin.
    $vba = zipTemporal([
        '[Content_Types].xml' => TIPOS,
        'xl/workbook.xml' => LIBRO,
        'xl/vbaProject.bin' => 'binario',
    ]);
    $temporales[] = $vba;
    try {
        analizarXlsx($vba);
        ok(false, 'un xlsx con vbaProject.bin debe rechazarse');
    } catch (ErrorDeCarga $e) {
        ok($e->codigo === 'xlsx_con_macros', 'vbaProject.bin da xlsx_con_macros');
    }

    // 4b) Macros declaradas en un Content_Types en UTF-16 (evasión por encoding).
    $utf16 = zipTemporal([
        '[Content_Types].xml' => "\xFF\xFE" . mb_convert_encoding(
            str_replace('main+xml', 'macroEnabled.main+xml', TIPOS),
            'UTF-16LE',
            'UTF-8'
        ),
        'xl/workbook.xml' => LIBRO,
    ]);
    $temporales[] = $utf16;
    try {
        analizarXlsx($utf16);
        ok(false, 'un libro con macros en UTF-16 debe rechazarse');
    } catch (ErrorDeCarga $e) {
        ok($e->codigo === 'xlsx_con_macros', 'macroEnabled en UTF-16 da xlsx_con_macros');
    }

    // 4c) Un .xls binario (OLE2) se detecta y no se confunde con xlsx.
    $ole = (string) tempnam(sys_get_temp_dir(), 'arba');
    $temporales[] = $ole;
    file_put_contents($ole, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 512));
    ok(esXlsBinario($ole), 'detecta un .xls binario por su firma OLE2');
    ok(!esXlsx($ole), 'un .xls binario no se detecta como xlsx');

    // 5) Un archivo de texto no es un xlsx.
    $texto = (string) tempnam(sys_get_temp_dir(), 'arba');
    $temporales[] = $texto;
    file_put_contents($texto, "a,b,c\n1,2,3\n");
    ok(!esXlsx($texto), 'un CSV no se detecta como xlsx');

    // 6) Nombres visibles por tipo.
    ok(nombreVisible('reporte.xlsx', 'xlsx') === 'REPORTE.XLSX', 'nombre xlsx conserva la extensión correcta');
    ok(nombreVisible('reporte.csv') === 'REPORTE.CSV', 'nombre csv sigue igual');
    ok(nombreVisible('', 'xlsx') === 'ARCHIVO.XLSX', 'nombre vacío cae al genérico');
    ok(nombreVisible('../../etc/passwd', 'xlsx') === 'PASSWD.XLSX', 'sin rutas en el nombre');

    // 7) Descarga: MIME y nombre según tipo.
    $csv = ['nombre' => 'A.CSV', 'tipo' => 'csv'];
    $xlsx = ['nombre' => 'B.XLSX', 'tipo' => 'xlsx'];
    ok(descargaDe($csv)['mime'] === 'application/gzip' && descargaDe($csv)['nombre'] === 'A.CSV.gz', 'csv se descarga como .gz');
    ok(descargaDe($xlsx)['mime'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx se descarga con su MIME');
    ok(descargaDe($xlsx)['nombre'] === 'B.XLSX', 'xlsx se descarga sin .gz');
} finally {
    foreach ($temporales as $ruta) {
        @unlink($ruta);
    }
}

if ($fallos > 0) {
    fwrite(STDERR, "\n{$fallos} fallo(s)\n");
    exit(1);
}

fwrite(STDOUT, "\ntodo ok\n");
