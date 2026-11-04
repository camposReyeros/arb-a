<?php
declare(strict_types=1);

/**
 * Panel de administración: dos pestañas por navegación (`?tab=`).
 *
 * - `archivos`: uso por archivo y purga manual en una sola tabla densa; buscador por nombre.
 * - `destinatarios`: accesos por correo; buscador por correo y por nombre de archivo (uno o ambos).
 *
 * Cada carga ejecuta solo la consulta de la pestaña activa. Sin filtros se ven los 5 archivos
 * más recientes; el buscador filtra al instante lo cargado y «Filtrar» vuelve a consultar.
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
    'archivos' => 'Archivos',
    'destinatarios' => 'Accesos por destinatario',
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
if (!in_array($filtros['estado'], ['todos', 'vigente', 'expirado'], true)) {
    $filtros['estado'] = 'todos';
}

$salud = resumenSalud();

// Carga perezosa: solo se consulta la pestaña activa.
$porArchivo = $tab === 'archivos' ? estadisticasArchivos($filtros) : [];
$porDestinatario = $tab === 'destinatarios' ? estadisticasDestinatarios($filtros) : [];
$recientes = $tab === 'archivos'
    ? idsRecientes($porArchivo)
    : idsRecientes($porDestinatario, 'archivo_id');

$tope = $tab === 'archivos' ? count($porArchivo) >= 200 : count($porDestinatario) >= 300;
$hayFiltro = $filtros['nombre'] !== '' || $filtros['correo'] !== '' || $filtros['nunca']
    || $filtros['estado'] !== 'todos';

// Los filtros activos viajan al cambiar de pestaña, sin los que no aplican al destino.
$enlaceTab = static function (string $destino) use ($filtros): string {
    $parametros = ['tab' => $destino];
    if ($filtros['nombre'] !== '') {
        $parametros['nombre'] = $filtros['nombre'];
    }
    // «Archivos» no busca por correo: no se arrastra un filtro invisible.
    if ($destino === 'destinatarios' && $filtros['correo'] !== '') {
        $parametros['correo'] = $filtros['correo'];
    }
    if ($filtros['nunca']) {
        $parametros['nunca'] = '1';
    }
    if ($destino === 'archivos' && $filtros['estado'] !== 'todos') {
        $parametros['estado'] = $filtros['estado'];
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
      <strong class="dato-valor"><?= (int) $salud['accesos_ui'] ?> con sesión</strong>
      <span class="dato-pie"><?= (int) $salud['accesos_directo'] ?> por enlace directo</span>
    </div>
  </div>
</section>

<nav class="tabs" aria-label="Secciones del panel">
  <?php foreach ($tabs as $clave => $etiqueta): ?>
  <a class="tab" href="<?= e($enlaceTab($clave)) ?>"<?= $clave === $tab ? ' aria-current="page"' : '' ?>><?= e($etiqueta) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'archivos'): ?>
<section class="seccion">
  <h2>Archivos</h2>
  <p class="sub" id="ayuda-archivos">
    Uso por archivo y purga en una sola tabla; el enlace directo es anónimo y no se puede atribuir a un correo.
    <?php if ($hayFiltro): ?>
    Mostrando las coincidencias del filtro<?= $tope ? ' (primeras 200)' : '' ?>.
    <?php else: ?>
    Se muestran los 5 más recientes: escribe un nombre para filtrar lo cargado y pulsa «Filtrar» para buscar en todo.
    <?php endif; ?>
  </p>
  <form method="get" action="/admin.php" class="filtros">
    <input type="hidden" name="tab" value="archivos">
    <input type="search" id="filtro-nombre" name="nombre" value="<?= e($filtros['nombre']) ?>" placeholder="Nombre del archivo…" aria-label="Nombre del archivo" aria-describedby="ayuda-archivos">
    <select id="filtro-estado" name="estado" aria-label="Estado del archivo">
      <option value="todos"<?= $filtros['estado'] === 'todos' ? ' selected' : '' ?>>Todos</option>
      <option value="vigente"<?= $filtros['estado'] === 'vigente' ? ' selected' : '' ?>>Vigentes</option>
      <option value="expirado"<?= $filtros['estado'] === 'expirado' ? ' selected' : '' ?>>Vencidos</option>
    </select>
    <label class="check-filtro">
      <input type="checkbox" id="filtro-nunca" name="nunca" value="1"<?= $filtros['nunca'] ? ' checked' : '' ?>>
      Solo nunca abiertos
    </label>
    <button type="submit" class="boton boton-suave">Filtrar</button>
  </form>
  <?php if ($porArchivo === []): ?>
  <div class="tarjeta vacio"><p>Ningún archivo coincide con los filtros.</p></div>
  <?php else: ?>
  <form method="post" action="/admin.php" data-confirmar="¿Borrar los archivos seleccionados? Esta acción no se puede deshacer.">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="purgar">
    <input type="hidden" name="tab" value="archivos">
    <?php tablaArchivos($porArchivo, $recientes); ?>
    <div class="acciones-panel">
      <button type="submit" class="boton boton-peligro">Purgar seleccionados</button>
    </div>
  </form>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="seccion">
  <h2>Accesos por destinatario</h2>
  <p class="sub" id="ayuda-destinatarios">
    Solo los accesos con sesión se pueden atribuir a un correo.
    <?php if ($hayFiltro): ?>
    Mostrando las coincidencias del filtro<?= $tope ? ' (primeras 300 filas)' : '' ?>.
    <?php else: ?>
    Se muestran los destinatarios de los 5 archivos más recientes; filtra por correo, por archivo o por ambos.
    <?php endif; ?>
  </p>
  <form method="get" action="/admin.php" class="filtros">
    <input type="hidden" name="tab" value="destinatarios">
    <input type="search" id="filtro-correo" name="correo" value="<?= e($filtros['correo']) ?>" placeholder="Correo destinatario…" aria-label="Correo destinatario" aria-describedby="ayuda-destinatarios">
    <input type="search" id="filtro-archivo" name="nombre" value="<?= e($filtros['nombre']) ?>" placeholder="Nombre del archivo…" aria-label="Nombre del archivo" aria-describedby="ayuda-destinatarios">
    <label class="check-filtro">
      <input type="checkbox" id="filtro-nunca" name="nunca" value="1"<?= $filtros['nunca'] ? ' checked' : '' ?>>
      Solo nunca abiertos
    </label>
    <button type="submit" class="boton boton-suave">Filtrar</button>
  </form>
  <?php if ($porDestinatario === []): ?>
  <div class="tarjeta vacio"><p>Ningún destinatario coincide con los filtros.</p></div>
  <?php else: ?>
  <?php tablaDestinatarios($porDestinatario, $recientes); ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php htmlApikey($apikeyNueva, $apikey, $tab); ?>

<script src="/assets/portal.js" defer></script>
<?php
htmlFin();
