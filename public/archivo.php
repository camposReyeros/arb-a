<?php
declare(strict_types=1);

/**
 * GET /archivo.php?id=N — descarga desde el portal.
 * Exige sesión y que el correo del usuario esté entre los destinatarios del archivo.
 */

require_once __DIR__ . '/../src/archivos.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../vistas/layout.php';

cabecerasDeSeguridad();

$usuario = requerirSesion();
$id = (int) ($_GET['id'] ?? 0);
$archivo = $id > 0 ? archivoParaCorreo($id, (string) $usuario['correo']) : null;

if ($archivo === null) {
    http_response_code(404);
    htmlInicio('Archivo no disponible', $usuario);
    ?>
    <div class="tarjeta vacio">
      <h2>Este archivo no está disponible</h2>
      <p>Puede haber expirado o no estar compartido contigo.</p>
      <p><a class="boton boton-suave" href="/archivos.php">Volver a mis archivos</a></p>
    </div>
    <?php
    htmlFin();
    exit;
}

$dir = realpath((string) config()['archivos']['dir']);
$ruta = (string) $archivo['ruta'];
$real = $ruta !== '' ? realpath($ruta) : false;

if ($dir === false || $real === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
    error_log('arb-a: archivo ' . $id . ' sin fichero válido en disco (portal)');
    http_response_code(404);
    htmlInicio('Archivo no disponible', $usuario);
    ?>
    <div class="tarjeta vacio">
      <h2>Este archivo no está disponible</h2>
      <p>Hubo un problema al recuperarlo. Avisa a quien te lo compartió.</p>
      <p><a class="boton boton-suave" href="/archivos.php">Volver a mis archivos</a></p>
    </div>
    <?php
    htmlFin();
    exit;
}

$bytes = @filesize($real);
if ($bytes === false || $bytes <= 0) {
    error_log('arb-a: archivo ' . $id . ' con tamaño ilegible (portal)');
    http_response_code(404);
    htmlInicio('Archivo no disponible', $usuario);
    ?>
    <div class="tarjeta vacio">
      <h2>Este archivo no está disponible</h2>
      <p>Hubo un problema al recuperarlo. Avisa a quien te lo compartió.</p>
      <p><a class="boton boton-suave" href="/archivos.php">Volver a mis archivos</a></p>
    </div>
    <?php
    htmlFin();
    exit;
}

registrarAcceso(
    $id,
    (int) $usuario['id'],
    'ui',
    (string) $usuario['correo'],
    ipCliente(),
    isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 300) : null
);

// MIME y nombre según el tipo real (CSV comprimido a .gz; xlsx tal cual).
$descarga = descargaDe($archivo);

header('Content-Type: ' . $descarga['mime']);
header('Content-Disposition: attachment; filename="' . $descarga['nombre'] . '"');
header('Content-Length: ' . (string) $bytes);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

readfile($real);
exit;
