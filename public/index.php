<?php
declare(strict_types=1);

/** Raíz del portal: manda a "Mis archivos" o al login. */

require_once __DIR__ . '/../src/auth.php';

cabecerasDeSeguridad();
redirigir(usuarioActual() !== null ? '/archivos.php' : '/login.php');
