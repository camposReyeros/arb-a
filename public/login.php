<?php
declare(strict_types=1);

/**
 * Login del portal: correo + clave contra la tabla `usuario`.
 * Un solo mensaje de error para todas las negativas; el throttle vive en src/auth.php.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../vistas/layout.php';

cabecerasDeSeguridad();

if (usuarioActual() !== null) {
    redirigir('/archivos.php');
}

$error = null;
$correo = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfValido(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
        $error = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        $correo = trim((string) ($_POST['correo'] ?? ''));
        $clave = (string) ($_POST['clave'] ?? '');

        $espera = bloqueoLogin($correo, ipCliente() ?? '');
        if ($espera > 0) {
            $error = sprintf(
                'Demasiados intentos fallidos. Vuelve a intentarlo en %d minutos.',
                (int) ceil($espera / 60)
            );
        } elseif (loginLocal($correo, $clave) !== null) {
            aviso('Sesión iniciada');
            redirigir('/archivos.php');
        } else {
            $error = 'Correo o contraseña incorrectos.';
        }
    }
}

htmlInicio('Iniciar sesión');
?>
<div class="login">
  <div class="tarjeta">
    <h1>Iniciar sesión</h1>
    <?php if (isset($_GET['salio'])): ?>
    <div class="aviso aviso-ok">Sesión cerrada</div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
    <div class="aviso aviso-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login.php" autocomplete="on">
      <?= campoCsrf() ?>
      <div class="campo">
        <label for="correo">Correo</label>
        <input id="correo" name="correo" type="email" required autocomplete="username"
               inputmode="email" value="<?= e($correo) ?>">
      </div>
      <div class="campo">
        <label for="clave">Contraseña</label>
        <input id="clave" name="clave" type="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="boton boton-ancho">Entrar</button>
    </form>
  </div>
</div>
<?php
htmlFin();
