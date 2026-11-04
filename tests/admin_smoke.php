<?php
declare(strict_types=1);

/**
 * Humo del panel: requiere PostgreSQL vivo y el esquema aplicado.
 * Cubre salud, estadísticas por archivo y por destinatario, y la purga manual
 * (incluye el caso de fichero ya ausente, que debe marcarse igual).
 */

require_once __DIR__ . '/../src/admin.php';

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

$sufijo = bin2hex(random_bytes(4));
$nombre = 'PRUEBAADMIN' . strtoupper($sufijo) . '.CSV';
$correo = 'destino+' . $sufijo . '@comex.care';
$sinAcceso = 'nadie+' . $sufijo . '@comex.care';

$adminId = crearUsuario('admin+' . $sufijo . '@comex.care', 'clave-de-prueba-larga', 'Admin de prueba', 'admin');
ok($adminId > 0, 'alta del administrador de prueba');

$dir = rtrim((string) config()['archivos']['dir'], '/');
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "FALLO: no se pudo crear el depósito {$dir}\n");
    exit(1);
}

$ruta = $dir . '/prueba-admin-' . $sufijo . '.csv.gz';
$gz = gzencode("a,b\n1,2\n");
file_put_contents($ruta, $gz);
chmod($ruta, 0600);

$pdo = bd();
$pdo->prepare(
    "insert into archivo (nombre, ruta, bytes, bytes_gz, sha256, token_hash, plazas, expira_en)
     values (:n, :r, :b, :g, :s, :t, array['PLAZA1', 'PLAZA2'], now() + interval '30 days')
     returning id"
)->execute([
    'n' => $nombre,
    'r' => $ruta,
    'b' => 8,
    'g' => strlen($gz),
    's' => hash('sha256', 'original-' . $sufijo),
    't' => hash('sha256', 'token-' . $sufijo),
]);
$archivoId = (int) $pdo->lastInsertId();
ok($archivoId > 0, 'archivo de prueba insertado');

$insertar = $pdo->prepare('insert into archivo_destinatario (archivo_id, correo) values (:a, :c)');
$insertar->execute(['a' => $archivoId, 'c' => $correo]);
$insertar->execute(['a' => $archivoId, 'c' => $sinAcceso]);

$envio = $pdo->prepare('insert into envio (archivo_id, correo, estado, error) values (:a, :c, :e, :x)');
$envio->execute(['a' => $archivoId, 'c' => $correo, 'e' => 'ok', 'x' => null]);
$envio->execute(['a' => $archivoId, 'c' => $sinAcceso, 'e' => 'error', 'x' => 'SMTP inalcanzable']);

$evento = $pdo->prepare(
    'insert into evento_acceso (archivo_id, via, correo, ip) values (:a, :v, :c, :ip)'
);
$evento->execute(['a' => $archivoId, 'v' => 'ui', 'c' => $correo, 'ip' => '10.0.0.1']);
$evento->execute(['a' => $archivoId, 'v' => 'directo', 'c' => null, 'ip' => '10.0.0.2']);

$salud = resumenSalud();
ok($salud['activos'] >= 1, 'salud: cuenta archivos activos');
ok($salud['envios_error'] >= 1, 'salud: cuenta errores SMTP de 7 días');
ok($salud['accesos_ui'] >= 1 && $salud['accesos_directo'] >= 1, 'salud: separa UI y directo');
ok($salud['cuota'] > 0, 'salud: reporta la cuota');

$porArchivo = estadisticasArchivos(['nombre' => $nombre]);
ok(count($porArchivo) === 1, 'estadística: un archivo con el filtro de nombre');
$fila = $porArchivo[0] ?? [];
ok((int) ($fila['ui'] ?? -1) === 1, 'estadística: un acceso UI');
ok((int) ($fila['directo'] ?? -1) === 1, 'estadística: un acceso directo');
ok((int) ($fila['destinatarios'] ?? -1) === 2, 'estadística: dos destinatarios');

$porDestinatario = estadisticasDestinatarios(['nombre' => $nombre]);
ok(count($porDestinatario) === 2, 'destinatarios: dos filas');
$nunca = estadisticasDestinatarios(['nombre' => $nombre, 'nunca' => true]);
ok(count($nunca) === 1 && (string) ($nunca[0]['correo'] ?? '') === $sinAcceso, 'destinatarios: filtro nunca abierto');

$purgables = archivosPurgables(['nombre' => $nombre, 'estado' => 'vigente']);
ok(count($purgables) === 1 && (int) $purgables[0]['id'] === $archivoId, 'purga: lista el archivo vigente');
ok(archivosPurgables(['nombre' => $nombre, 'estado' => 'expirado']) === [], 'purga: el filtro vencido no lo incluye');

$resultado = purgarArchivos([$archivoId], $adminId);
ok($resultado['borrados'] === 1 && $resultado['sin_fichero'] === 0, 'purga: borra un archivo');
ok(!is_file($ruta), 'purga: el .gz ya no está en disco');
ok(estadisticasArchivos(['nombre' => $nombre]) === [], 'purga: el archivo sale de las estadísticas');

$borrado = $pdo->prepare('select borrado_en from archivo where id = :i');
$borrado->execute(['i' => $archivoId]);
ok($borrado->fetchColumn() !== null, 'purga: marca borrado_en');

$otraVez = purgarArchivos([$archivoId], $adminId);
ok($otraVez['borrados'] === 0, 'purga: no vuelve a borrar lo ya purgado');

// Fichero ausente: se marca igual, para que el cron no lo reintente para siempre.
$rutaFantasma = $dir . '/fantasma-' . $sufijo . '.csv.gz';
$pdo->prepare(
    "insert into archivo (nombre, ruta, bytes, bytes_gz, sha256, token_hash, expira_en)
     values (:n, :r, 10, 5, :s, :t, now() + interval '10 days')"
)->execute([
    'n' => $nombre . '.FANTASMA',
    'r' => $rutaFantasma,
    's' => hash('sha256', 'original-fantasma-' . $sufijo),
    't' => hash('sha256', 'token-fantasma-' . $sufijo),
]);
$fantasmaId = (int) $pdo->lastInsertId();

$fantasma = purgarArchivos([$fantasmaId], $adminId);
ok($fantasma['borrados'] === 1 && $fantasma['sin_fichero'] === 1, 'purga: marca un archivo sin fichero');

$bitacora = $pdo->query("select detalle from bitacora where accion = 'purga_manual' order by id desc limit 1")->fetchColumn();
ok(is_string($bitacora) && str_contains($bitacora, (string) $fantasmaId), 'purga: deja rastro en bitácora');

// Limpieza de las filas de archivo (la bitácora y los usuarios quedan: es append-only).
$pdo->prepare('delete from archivo where id = :i')->execute(['i' => $archivoId]);
$pdo->prepare('delete from archivo where id = :i')->execute(['i' => $fantasmaId]);

exit($fallos === 0 ? 0 : 1);
