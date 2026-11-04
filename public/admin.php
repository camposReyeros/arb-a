<?php
declare(strict_types=1);

/**
 * Panel de administración: salud, estadísticas de uso y purga manual.
 * Solo lectura en GET; la purga es POST con CSRF.
 */

require_once __DIR__ . '/../src/admin.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../vistas/layout.php';

cabecerasDeSeguridad();

$usuario = requerirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
        aviso('La sesión expiró. Vuelve a intentarlo.', 'error');
        redirigir('/admin.php');
    }

    if ((string) ($_POST['accion'] ?? '') === 'purgar') {
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $resultado = purgarArchivos($ids, (int) $usuario['id']);
        $mensaje = 'Archivos purgados: ' . $resultado['borrados'] . '.';
        if ($resultado['sin_fichero'] > 0) {
            $mensaje .= ' ' . $resultado['sin_fichero'] . ' ya no tenían fichero en disco.';
        }
        aviso($mensaje, $resultado['borrados'] > 0 ? 'ok' : 'error');
    }

    redirigir('/admin.php');
}

$filtros = [
    'nombre' => (string) ($_GET['nombre'] ?? ''),
    'correo' => (string) ($_GET['correo'] ?? ''),
    'nunca' => isset($_GET['nunca']) && $_GET['nunca'] === '1',
    'estado' => (string) ($_GET['estado'] ?? 'todos'),
];

$salud = resumenSalud();
$porArchivo = estadisticasArchivos($filtros);
$porDestinatario = estadisticasDestinatarios($filtros);
$purgables = archivosPurgables($filtros);

$uso = $salud['bytes'];
$cuota = $salud['cuota'];
$porcentaje = $cuota > 0 ? min(100, (int) round($uso * 100 / $cuota)) : 0;

htmlInicio('Administración', $usuario);
?>
<h1>Administración</h1>
<p class="sub">Salud del sistema, uso de los enlaces y purga manual de archivos.</p>

<section class="seccion">
  <h2>Salud del sistema</h2>
  <div class="rejilla-datos">
    <div class="tarjeta dato">
      <span class="dato-etiqueta">Archivos activos</span>
      <strong class="dato-valor"><?= (int) $salud['activos'] ?></strong>
      <span class="dato-pie"><?= (int) $salud['vencidos'] ?> vencidos pendientes de purga</span>
    </div>
    <div class="tarjeta dato">
      <span class="dato-etiqueta">Disco</span>
      <strong class="dato-valor"><?= e(tamanoLegible($uso)) ?></strong>
      <progress class="uso" value="<?= $uso ?>" max="<?= max(1, $cuota) ?>" aria-label="Uso de disco"></progress>
      <span class="dato-pie">de <?= e(tamanoLegible($cuota)) ?> (<?= $porcentaje ?>%)</span>
    </div>
    <div class="tarjeta dato">
      <span class="dato-etiqueta">Correos · 7 días</span>
      <strong class="dato-valor"><?= (int) $salud['envios_ok'] ?> ok</strong>
      <span class="dato-pie">
        <?php if ($salud['envios_error'] > 0): ?>
        <span class="badge badge-alerta"><?= (int) $salud['envios_error'] ?> con error</span>
        <?php else: ?>
        Sin errores de SMTP
        <?php endif; ?>
      </span>
    </div>
    <div class="tarjeta dato">
      <span class="dato-etiqueta">Accesos · 30 días</span>
      <strong class="dato-valor"><?= (int) $salud['accesos_ui'] ?> UI</strong>
      <span class="dato-pie"><?= (int) $salud['accesos_directo'] ?> por link directo</span>
    </div>
  </div>
</section>

<section class="seccion">
  <h2>Filtros</h2>
  <form method="get" action="/admin.php" class="filtros">
    <input type="search" name="nombre" value="<?= e($filtros['nombre']) ?>" placeholder="Nombre del archivo…" aria-label="Nombre del archivo">
    <input type="search" name="correo" value="<?= e($filtros['correo']) ?>" placeholder="Correo destinatario…" aria-label="Correo destinatario">
    <select name="estado" aria-label="Vigencia en la purga">
      <option value="todos"<?= $filtros['estado'] === 'todos' ? ' selected' : '' ?>>Todos</option>
      <option value="expirado"<?= $filtros['estado'] === 'expirado' ? ' selected' : '' ?>>Vencidos</option>
      <option value="vigente"<?= $filtros['estado'] === 'vigente' ? ' selected' : '' ?>>Vigentes</option>
    </select>
    <label class="check-filtro">
      <input type="checkbox" name="nunca" value="1"<?= $filtros['nunca'] ? ' checked' : '' ?>>
      Solo nunca abiertos
    </label>
    <button type="submit" class="boton boton-suave">Filtrar</button>
  </form>
</section>

<section class="seccion">
  <h2>Uso por archivo</h2>
  <p class="sub">El link directo es anónimo: se cuenta por archivo, no por persona.</p>
  <?php if ($porArchivo === []): ?>
  <div class="tarjeta vacio"><p>Ningún archivo coincide con los filtros.</p></div>
  <?php else: ?>
  <div class="tabla-caja">
    <table>
      <thead>
        <tr>
          <th>Archivo</th>
          <th>Destinatarios</th>
          <th>Accesos UI</th>
          <th>Link directo</th>
          <th>Último acceso</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($porArchivo as $fila):
            $total = (int) $fila['ui'] + (int) $fila['directo']; ?>
        <tr>
          <td class="nombre"><?= e((string) $fila['nombre']) ?></td>
          <td><?= (int) $fila['destinatarios'] ?></td>
          <td><?= (int) $fila['ui'] ?></td>
          <td><?= (int) $fila['directo'] ?></td>
          <td><?= e(fechaHoraVisible($fila['ultimo'] !== null ? (string) $fila['ultimo'] : null)) ?></td>
          <td><?php if ($total === 0): ?><span class="badge badge-pronto">Nunca abierto</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="seccion">
  <h2>Accesos por destinatario</h2>
  <p class="sub">Solo los accesos con sesión se pueden atribuir a un correo.</p>
  <?php if ($porDestinatario === []): ?>
  <div class="tarjeta vacio"><p>Ningún destinatario coincide con los filtros.</p></div>
  <?php else: ?>
  <div class="tabla-caja">
    <table>
      <thead>
        <tr>
          <th>Archivo</th>
          <th>Correo</th>
          <th>Accesos UI</th>
          <th>Primer acceso</th>
          <th>Último acceso</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($porDestinatario as $fila): ?>
        <tr>
          <td class="nombre"><?= e((string) $fila['nombre']) ?></td>
          <td><?= e((string) $fila['correo']) ?></td>
          <td><?= (int) $fila['accesos'] ?></td>
          <td><?= e(fechaHoraVisible($fila['primero'] !== null ? (string) $fila['primero'] : null)) ?></td>
          <td><?= e(fechaHoraVisible($fila['ultimo'] !== null ? (string) $fila['ultimo'] : null)) ?></td>
          <td><?php if ((int) $fila['accesos'] === 0): ?><span class="badge badge-pronto">Nunca abierto</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="seccion">
  <h2>Purga de archivos</h2>
  <p class="sub">Borra el archivo comprimido y lo marca como purgado. La estadística se conserva.</p>
  <?php if ($purgables === []): ?>
  <div class="tarjeta vacio"><p>No hay archivos que coincidan con los filtros.</p></div>
  <?php else: ?>
  <form method="post" action="/admin.php" data-confirmar="¿Borrar los archivos seleccionados? Esta acción no se puede deshacer.">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="purgar">
    <div class="tabla-caja">
      <table>
        <thead>
          <tr>
            <th class="col-check"><input type="checkbox" id="seleccionar-todos" aria-label="Seleccionar todos"></th>
            <th>Archivo</th>
            <th>Subido</th>
            <th>Vence</th>
            <th>Tamaño</th>
            <th>Destinatarios</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($purgables as $fila):
              $vencido = yaVencio((string) $fila['expira_en']); ?>
          <tr>
            <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $fila['id'] ?>" aria-label="Seleccionar <?= e((string) $fila['nombre']) ?>"></td>
            <td class="nombre"><?= e((string) $fila['nombre']) ?></td>
            <td><?= e(fechaVisible((string) $fila['subido_en'])) ?></td>
            <td><?= e(fechaVisible((string) $fila['expira_en'])) ?></td>
            <td><?= e(tamanoLegible((int) $fila['bytes_gz'])) ?></td>
            <td><?= (int) $fila['destinatarios'] ?></td>
            <td><span class="badge <?= $vencido ? 'badge-alerta' : 'badge-ok' ?>"><?= $vencido ? 'Vencido' : 'Vigente' ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($purgables) >= 200): ?>
    <p class="sub">Se muestran los primeros 200 archivos del filtro. Afina la búsqueda para ver el resto.</p>
    <?php endif; ?>
    <div class="acciones-panel">
      <button type="submit" class="boton boton-peligro">Purgar seleccionados</button>
    </div>
  </form>
  <?php endif; ?>
</section>

<script src="/assets/portal.js" defer></script>
<?php
htmlFin();
