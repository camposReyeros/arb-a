<?php
declare(strict_types=1);

/**
 * Login del portal: usuario + clave, las mismas credenciales de ipro.
 * Un solo mensaje de error para todas las negativas; el throttle vive en src/auth.php.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../vistas/layout.php';

cabecerasDeSeguridad();

if (usuarioActual() !== null) {
    redirigir('/archivos.php');
}

$error = null;
$login = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrfValido(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
        $error = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        $login = trim((string) ($_POST['usuario'] ?? ''));
        $clave = (string) ($_POST['clave'] ?? '');

        $espera = bloqueoLogin($login, ipCliente() ?? '');
        if ($espera > 0) {
            $error = sprintf(
                'Demasiados intentos fallidos. Vuelve a intentarlo en %d minutos.',
                (int) ceil($espera / 60)
            );
        } elseif (loginLocal($login, $clave) !== null) {
            aviso('Sesión iniciada');
            redirigir('/archivos.php');
        } else {
            $error = 'Usuario o contraseña incorrectos.';
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
        <label for="usuario">Usuario</label>
        <input id="usuario" name="usuario" type="text" required autocomplete="username"
               autocapitalize="none" spellcheck="false" value="<?= e($login) ?>">
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
