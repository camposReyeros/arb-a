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

foreach ($vencidos as $archivo) {
    $ruta = (string) $archivo['ruta'];
    $dir = realpath((string) config()['archivos']['dir']);
    $real = $ruta !== '' ? realpath($ruta) : false;

    // Solo se borra dentro del directorio de archivos: nunca una ruta de fuera.
    if ($dir === false || $real === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
        $fallos++;
        error_log('arb-a: ruta fuera del directorio de archivos, no se borra (archivo ' . $archivo['id'] . ')');
        continue;
    }

    if (is_file($real) && !@unlink($real)) {
        $fallos++;
        error_log('arb-a: no se pudo borrar ' . $real . ' (archivo ' . $archivo['id'] . ')');
        continue;
    }

    $pdo->prepare('update archivo set borrado_en = now() where id = :id')
        ->execute(['id' => (int) $archivo['id']]);
    $borrados++;
}

// El throttle y los eventos viejos no crecen sin límite.
$pdo->exec("delete from intento_login where ultimo < now() - interval '7 days'");
$pdo->prepare('delete from evento_acceso where creado_en < now() - make_interval(days => :d)')
    ->execute(['d' => $diasEventos]);

bitacora(null, 'purga_automatica', ['borrados' => $borrados, 'fallos' => $fallos]);

fwrite(STDOUT, sprintf("arb-a: %d archivos purgados, %d fallos\n", $borrados, $fallos));
