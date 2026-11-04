<?php
declare(strict_types=1);

/**
 * Piezas de presentación compartidas por las pantallas del portal.
 * HTML mínimo, sin dependencias ni estilos en línea (la CSP solo permite archivos propios).
 */

function fechaVisible(string $marca): string
{
    try {
        return (new DateTimeImmutable($marca))->format('d/m/Y');
    } catch (Throwable) {
        return '';
    }
}

/** Fecha y hora, para los accesos del panel. */
function fechaHoraVisible(?string $marca): string
{
    if ($marca === null || $marca === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($marca))->format('d/m/Y H:i');
    } catch (Throwable) {
        return '—';
    }
}

/** Días completos que le quedan a un archivo (0 si ya venció). */
function diasRestantes(string $marca): int
{
    try {
        $restan = (new DateTimeImmutable($marca))->getTimestamp() - time();
    } catch (Throwable) {
        return 0;
    }

    return max(0, (int) ceil($restan / 86400));
}

/** ¿La marca ya pasó? Una fecha corrupta no tumba la pantalla. */
function yaVencio(?string $marca): bool
{
    if ($marca === null || $marca === '') {
        return false;
    }

    try {
        return new DateTimeImmutable($marca) <= new DateTimeImmutable();
    } catch (Throwable) {
        return false;
    }
}

function tamanoLegible(int $bytes): string
{
    if ($bytes >= 1024 ** 2) {
        return number_format($bytes / 1024 ** 2, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024) . ' KB';
    }

    return $bytes . ' B';
}

/** Cabecera y apertura del `<main>`. */
function htmlInicio(string $titulo, ?array $usuario = null, bool $fuentePortal = true): void
{
    $nombre = (string) ($usuario['nombre'] ?? $usuario['correo'] ?? '');
    ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · ARB-A</title>
<link rel="stylesheet" href="/assets/portal.css">
</head>
<body<?= $fuentePortal ? ' class="portal"' : '' ?>>
<header class="barra">
  <a class="marca" href="/archivos.php">ARB-A</a>
  <?php if ($usuario !== null): ?>
  <nav class="nav">
    <a href="/archivos.php" class="nav-enlace">Mis archivos</a>
    <?php if (esAdmin($usuario)): ?>
    <a href="/admin.php" class="nav-enlace">Administración</a>
    <?php endif; ?>
    <span class="nav-usuario" title="<?= e((string) ($usuario['correo'] ?? '')) ?>"><?= e($nombre) ?></span>
    <form method="post" action="/salir.php" class="nav-salir">
      <?= campoCsrf() ?>
      <button type="submit" class="enlace">Salir</button>
    </form>
  </nav>
  <?php endif; ?>
</header>
<main class="contenido">
    <?php
    $aviso = tomarAviso();
    if ($aviso !== null): ?>
  <div class="aviso aviso-<?= e((string) ($aviso['tipo'] ?? 'ok')) ?>"><?= e((string) ($aviso['mensaje'] ?? '')) ?></div>
    <?php endif;
}

function htmlFin(): void
{
    ?>
</main>
<footer class="pie">ARB-A · los archivos se conservan 90 días</footer>
</body>
</html>
    <?php
}
