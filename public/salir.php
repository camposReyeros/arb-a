<?php
declare(strict_types=1);

/** Cierre de sesión: solo por POST con CSRF. */

require_once __DIR__ . '/../src/auth.php';

cabecerasDeSeguridad();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirigir(usuarioActual() !== null ? '/archivos.php' : '/login.php');
}

if (!csrfValido(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
    aviso('No se pudo cerrar la sesión; inténtalo de nuevo.', 'error');
    redirigir('/archivos.php');
}

cerrarSesion();
redirigir('/login.php?salio=1');
