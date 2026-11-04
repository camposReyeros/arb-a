<?php
declare(strict_types=1);

/**
 * Purga de archivos vencidos (cron diario).
 * Borra el .gz del disco y marca `borrado_en`; la fila se conserva para que las
 * estadísticas históricas sigan teniendo sentido.
 */

require_once __DIR__ . '/../src/usuarios.php';

$diasEventos = 400; // un año largo de historia para el panel

$pdo = bd();
$vencidos = $pdo->query(
    "select id, ruta, nombre from archivo
      where borrado_en is null and expira_en <= now()
      order by expira_en"
)->fetchAll();

$borrados = 0;
$fallos = 0;
$dirBase = realpath((string) config()['archivos']['dir']);
$marcar = $pdo->prepare('update archivo set borrado_en = now() where id = :id');

foreach ($vencidos as $archivo) {
    $id = (int) $archivo['id'];
    $ruta = (string) $archivo['ruta'];
    $real = $ruta !== '' ? realpath($ruta) : false;

    if ($dirBase === false) {
        $fallos++;
        error_log('arb-a: el directorio de archivos no existe, no se purga (archivo ' . $id . ')');
        continue;
    }

    if ($real === false) {
        // El .gz ya no está. Si la ruta declarada apunta dentro del directorio, la fila queda
        // marcada (si no, se reintentaría y se registraría el fallo todos los días).
        if ($ruta !== '' && str_starts_with($ruta, $dirBase . DIRECTORY_SEPARATOR)) {
            $marcar->execute(['id' => $id]);
            $borrados++;
            continue;
        }

        $fallos++;
        error_log('arb-a: ruta fuera del directorio de archivos, no se borra (archivo ' . $id . ')');
        continue;
    }

    // Solo se borra dentro del directorio de archivos: nunca una ruta de fuera.
    if (!str_starts_with($real, $dirBase . DIRECTORY_SEPARATOR)) {
        $fallos++;
        error_log('arb-a: ruta fuera del directorio de archivos, no se borra (archivo ' . $id . ')');
        continue;
    }

    if (is_file($real) && !@unlink($real)) {
        $fallos++;
        error_log('arb-a: no se pudo borrar ' . $real . ' (archivo ' . $id . ')');
        continue;
    }

    $marcar->execute(['id' => $id]);
    $borrados++;
}

// El throttle y los eventos viejos no crecen sin límite.
$pdo->exec("delete from intento_login where ultimo < now() - interval '7 days'");
$pdo->exec("delete from limite_carga where creado_en < now() - interval '1 hour'");
$pdo->prepare('delete from evento_acceso where creado_en < now() - make_interval(days => :d)')
    ->execute(['d' => $diasEventos]);

bitacora(null, 'purga_automatica', ['borrados' => $borrados, 'fallos' => $fallos]);

fwrite(STDOUT, sprintf("arb-a: %d archivos purgados, %d fallos\n", $borrados, $fallos));
