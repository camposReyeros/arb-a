<?php
declare(strict_types=1);

/**
 * Pruebas puras del CSV y sus cabeceras. Sin BD ni HTTP.
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/usuarios.php';
require_once __DIR__ . '/../src/errores.php';
require_once __DIR__ . '/../src/csv.php';

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

function csvTemporal(string $contenido): string
{
    $ruta = (string) tempnam(sys_get_temp_dir(), 'arba');
    file_put_contents($ruta, $contenido);

    return $ruta;
}

/** Devuelve el código del error, o null si el CSV se aceptó. */
function errorCsv(string $contenido): ?string
{
    $ruta = csvTemporal($contenido);
    try {
        analizarCsv($ruta);

        return null;
    } catch (ErrorDeCarga $e) {
        return $e->codigo;
    } finally {
        @unlink($ruta);
    }
}

ok(nombreVisible('ventas_marzo.csv') === 'VENTAS_MARZO.CSV', 'nombre en mayúsculas');
ok(nombreVisible('../../etc/passwd') === 'PASSWD.CSV', 'sin traversal');
ok(nombreVisible('nomina 2026.csv') === 'NOMINA_2026.CSV', 'espacios a guion bajo');
ok(nombreVisible('') === 'ARCHIVO.CSV', 'nombre vacío tiene default');
ok(nombreVisible(str_repeat('a', 300) . '.csv') === str_repeat('A', 120) . '.CSV', 'nombre acotado');

$d = parseDestinatarios('uno@x.com, DOS@x.com ; dos@x.com');
ok($d['ok'] && $d['correos'] === ['uno@x.com', 'dos@x.com'], 'destinatarios normalizados y sin duplicados');
ok(!parseDestinatarios('no-es-correo')['ok'], 'correo inválido se rechaza');
ok(!parseDestinatarios('')['ok'], 'lista vacía se rechaza');

$p = parsePlazas('plaza1, Plaza2, plaza1, <script>');
ok($p === ['PLAZA1', 'PLAZA2', 'SCRIPT'], 'plazas en mayúsculas, sin duplicados ni símbolos');
ok(parsePlazas('') === [], 'sin plazas es válido');

// --- CSV válido y variantes de formato ---
$ruta = csvTemporal("A,B\n1,2\n");
$r = analizarCsv($ruta);
ok($r === ['lineas' => 2, 'columnas' => 2, 'separador' => ','], 'CSV con coma: 2x2');
unlink($ruta);

$ruta = csvTemporal("A;B\n1;2\n3;4\n");
$r = analizarCsv($ruta);
ok($r === ['lineas' => 3, 'columnas' => 2, 'separador' => ';'], 'CSV con punto y coma');
unlink($ruta);

$ruta = csvTemporal("\xEF\xBB\xBFA,B\r\n1,2\r\n");
$r = analizarCsv($ruta);
ok($r['lineas'] === 2 && $r['columnas'] === 2, 'CSV con BOM y CRLF');
unlink($ruta);

$ruta = csvTemporal("nombre,detalle\n\"Perez, Juan\",\"dice \"\"hola\"\"\"\n");
$r = analizarCsv($ruta);
ok($r['columnas'] === 2 && $r['lineas'] === 2, 'comas y comillas escapadas dentro de campo');
unlink($ruta);

$ruta = csvTemporal("a,b\n\"linea1\nlinea2\",x\n");
$r = analizarCsv($ruta);
ok($r['columnas'] === 2 && $r['lineas'] === 2, 'salto de línea dentro de campo entrecomillado');
unlink($ruta);

$ruta = csvTemporal("A,B\n1,2\n\n3,4\n");
$r = analizarCsv($ruta);
ok($r['lineas'] === 3, 'línea en blanco intermedia no cuenta');
unlink($ruta);

$ruta = csvTemporal('"A","B","C"');
$r = analizarCsv($ruta);
ok($r['lineas'] === 1 && $r['columnas'] === 3, 'una sola línea sin salto final');
unlink($ruta);

$ruta = csvTemporal("solo_una_columna\nvalor\n");
$r = analizarCsv($ruta);
ok($r['columnas'] === 1, 'CSV de una columna se acepta');
unlink($ruta);

// --- Rechazos, con su código estable ---
ok(errorCsv("A,B\n1,2,3\n") === 'csv_columnas_inconsistentes', 'columnas dispares se rechazan');
ok(errorCsv("") === 'csv_vacio', 'archivo vacío se rechaza');
ok(errorCsv("\n\n") === 'csv_vacio', 'solo líneas en blanco se rechaza');
ok(errorCsv(gzencode("A,B\n1,2\n")) === 'csv_ya_comprimido', 'gz se rechaza');
ok(errorCsv("A\0B") === 'csv_binario', 'NUL se rechaza');
ok(errorCsv("a,b\n" . str_repeat("1,", 600) . "2\n") === 'csv_demasiadas_columnas', 'demasiadas columnas se rechaza');

// --- Costo de la validación sobre un archivo grande ---
$grande = csvTemporal("id,nombre,importe\n");
$fh = fopen($grande, 'ab');
for ($i = 0; $i < 200000; $i++) {
    fwrite($fh, $i . ",SUCURSAL_" . ($i % 40) . "," . ($i * 1.5) . "\n");
}
fclose($fh);
$inicio = microtime(true);
$r = analizarCsv($grande);
$ms = (int) round((microtime(true) - $inicio) * 1000);
ok($r['lineas'] === 200001 && $r['columnas'] === 3, 'CSV grande: 200,001 líneas contadas');
ok($ms < 3000, 'validar ~7 MB tarda menos de 3 s (' . $ms . ' ms)');
fwrite(STDOUT, 'info: ' . $ms . " ms para " . number_format((int) filesize($grande)) . " bytes\n");
unlink($grande);

exit($fallos === 0 ? 0 : 1);
