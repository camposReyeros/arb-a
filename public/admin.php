<?php
declare(strict_types=1);

/**
 * Panel de administración: salud, estadísticas de uso y purga manual.
 * Solo lectura en GET; la purga es POST con CSRF.
 *
 * Las tres secciones son pestañas por navegación (`?tab=`): cada carga ejecuta solo
 * la consulta de la pestaña activa. Dentro de cada pestaña se ven los 5 archivos más
 * recientes y el buscador filtra al vuelo por nombre o correo.
 */

require_once __DIR__ . '/../src/admin.php';
require_once __DIR__ . '/../src/apikey.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../vistas/admin_apikey.php';
require_once __DIR__ . '/../vistas/admin_tablas.php';

cabecerasDeSeguridad();
header('Cache-Control: no-store');

$usuario = requerirAdmin();

$tabs = [
    'archivos' => 'Uso por archivo',
    'destinatarios' => 'Accesos por destinatario',
    'purga' => 'Purga de archivos',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // La pestaña viaja en el formulario para volver donde estaba el usuario.
    $tabPost = (string) ($_POST['tab'] ?? 'archivos');
    if (!isset($tabs[$tabPost])) {
        $tabPost = 'archivos';
    }

    if (!csrfValido(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
        aviso('La sesión expiró. Vuelve a intentarlo.', 'error');
        redirigir('/admin.php?tab=' . $tabPost);
    }

    if ((string) ($_POST['accion'] ?? '') === 'purgar') {
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $resultado = purgarArchivos($ids, (int) $usuario['id']);
        $mensaje = 'Archivos purgados: ' . $resultado['borrados'] . '.';
        if ($resultado['sin_fichero'] > 0) {
            $mensaje .= ' ' . $resultado['sin_fichero'] . ' ya no tenían fichero en disco.';
        }
        aviso($mensaje, $resultado['borrados'] > 0 ? 'ok' : 'error');
    } elseif ((string) ($_POST['accion'] ?? '') === 'regenerar_apikey') {
        if (isset($_SESSION['apikey_nueva'])) {
            // Evita perder una clave que aún no se mostró (doble envío o dos pestañas).
            aviso('Ya hay una clave sin copiar. Recarga la página para verla antes de regenerar otra.', 'error');
        } else {
            $_SESSION['apikey_nueva'] = regenerarApikey((int) $usuario['id'], (string) $usuario['externo_id']);
            aviso('API-Key regenerada. Cópiala antes de salir: no se vuelve a mostrar.', 'ok');
        }
    }

    redirigir('/admin.php?tab=' . $tabPost);
}

$apikeyNueva = isset($_SESSION['apikey_nueva']) ? (string) $_SESSION['apikey_nueva'] : null;
unset($_SESSION['apikey_nueva']);
$apikey = apikeyInfo();

$tab = (string) ($_GET['tab'] ?? 'archivos');
if (!isset($tabs[$tab])) {
    $tab = 'archivos';
}

$filtros = [
    'nombre' => (string) ($_GET['nombre'] ?? ''),
    'correo' => (string) ($_GET['correo'] ?? ''),
    'nunca' => isset($_GET['nunca']) && $_GET['nunca'] === '1',
    'estado' => (string) ($_GET['estado'] ?? 'todos'),
];

$salud = resumenSalud();

// Carga perezosa: solo se consulta la pestaña activa.
$porArchivo = $tab === 'archivos' ? estadisticasArchivos($filtros) : [];
$porDestinatario = $tab === 'destinatarios' ? estadisticasDestinatarios($filtros) : [];
$purgables = $tab === 'purga' ? archivosPurgables($filtros) : [];

$recientesArchivo = idsRecientes($porArchivo);
$recientesDestinatario = idsRecientes($porDestinatario, 'archivo_id');

$archivosTotales = count($porArchivo);
$archivosDestinatarios = count(array_unique(array_column($porDestinatario, 'archivo_id')));
$topeArchivos = $archivosTotales >= 200;
$topeDestinatarios = count($porDestinatario) >= 300;

// Los filtros activos viajan al cambiar de pestaña: solo los que esa pestaña usa.
$enlaceTab = static function (string $destino) use ($filtros): string {
    $parametros = ['tab' => $destino];
    if ($filtros['nombre'] !== '') {
        $parametros['nombre'] = $filtros['nombre'];
    }
    if ($filtros['correo'] !== '') {
        $parametros['correo'] = $filtros['correo'];
    }
    if ($destino === 'purga') {
        if ($filtros['estado'] !== 'todos') {
            $parametros['estado'] = $filtros['estado'];
        }
    } elseif ($filtros['nunca']) {
        $parametros['nunca'] = '1';
    }

    return '/admin.php?' . http_build_query($parametros);
};

$uso = $salud['bytes'];
$cuota = $salud['cuota'];
$porcentaje = $cuota > 0 ? min(100, (int) round($uso * 100 / $cuota)) : 0;

htmlInicio('Administración', $usuario, false);
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

<nav class="tabs" aria-label="Secciones del panel">
  <?php foreach ($tabs as $clave => $etiqueta): ?>
  <a class="tab" href="<?= e($enlaceTab($clave)) ?>"<?= $clave === $tab ? ' aria-current="page"' : '' ?>><?= e($etiqueta) ?></a>
  <?php endforeach; ?>
</nav>

<section class="seccion">
  <h2>Filtros</h2>
  <form method="get" action="/admin.php" class="filtros">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input type="search" name="nombre" value="<?= e($filtros['nombre']) ?>" placeholder="Nombre del archivo…" aria-label="Nombre del archivo">
    <input type="search" name="correo" value="<?= e($filtros['correo']) ?>" placeholder="Correo destinatario…" aria-label="Correo destinatario">
    <?php if ($tab === 'purga'): ?>
    <select name="estado" aria-label="Vigencia en la purga">
      <option value="todos"<?= $filtros['estado'] === 'todos' ? ' selected' : '' ?>>Todos</option>
      <option value="expirado"<?= $filtros['estado'] === 'expirado' ? ' selected' : '' ?>>Vencidos</option>
      <option value="vigente"<?= $filtros['estado'] === 'vigente' ? ' selected' : '' ?>>Vigentes</option>
    </select>
    <?php else: ?>
    <label class="check-filtro">
      <input type="checkbox" name="nunca" value="1"<?= $filtros['nunca'] ? ' checked' : '' ?>>
      Solo nunca abiertos
    </label>
    <?php endif; ?>
    <button type="submit" class="boton boton-suave">Filtrar</button>
  </form>
</section>

<?php if ($tab === 'archivos'): ?>
<section class="seccion">
  <h2>Uso por archivo</h2>
  <p class="sub" id="ayuda-archivos">
    El link directo es anónimo: se cuenta por archivo, no por persona.
    Se muestran los 5 más recientes<?= $archivosTotales > 5 ? ' de los ' . $archivosTotales . ' cargados' : '' ?>;
    usa la búsqueda rápida para ver el resto.
    <?php if ($topeArchivos): ?>Solo se cargan los primeros 200: afina los filtros para ver más.<?php endif; ?>
  </p>
  <?php if ($porArchivo === []): ?>
  <div class="tarjeta vacio"><p>Ningún archivo coincide con los filtros.</p></div>
  <?php else: ?>
  <?php tablaUsoPorArchivo($porArchivo, $recientesArchivo, 'ayuda-archivos'); ?>
  <?php endif; ?>
</section>
<?php elseif ($tab === 'destinatarios'): ?>
<section class="seccion">
  <h2>Accesos por destinatario</h2>
  <p class="sub" id="ayuda-destinatarios">
    Solo los accesos con sesión se pueden atribuir a un correo.
    Se muestran los destinatarios de los 5 archivos más recientes<?= $archivosDestinatarios > 5 ? ' de los ' . $archivosDestinatarios . ' cargados' : '' ?>;
    usa la búsqueda rápida para filtrar por nombre o correo.
    <?php if ($topeDestinatarios): ?>Solo se cargan los primeros 300: afina los filtros para ver más.<?php endif; ?>
  </p>
  <?php if ($porDestinatario === []): ?>
  <div class="tarjeta vacio"><p>Ningún destinatario coincide con los filtros.</p></div>
  <?php else: ?>
  <?php tablaAccesosPorDestinatario($porDestinatario, $recientesDestinatario, 'ayuda-destinatarios'); ?>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="seccion">
  <h2>Purga de archivos</h2>
  <p class="sub">Borra el archivo comprimido y lo marca como purgado. La estadística se conserva.</p>
  <?php if ($purgables === []): ?>
  <div class="tarjeta vacio"><p>No hay archivos que coincidan con los filtros.</p></div>
  <?php else: ?>
  <form method="post" action="/admin.php" data-confirmar="¿Borrar los archivos seleccionados? Esta acción no se puede deshacer.">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="purgar">
    <input type="hidden" name="tab" value="purga">
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
<?php endif; ?>

<?php htmlApikey($apikeyNueva, $apikey, $tab); ?>

<script src="/assets/portal.js" defer></script>
<?php
htmlFin();
