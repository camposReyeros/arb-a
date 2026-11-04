<?php
declare(strict_types=1);

/**
 * Sección "API-Key" del panel: estado de la clave y botón de rotación.
 * La clave nueva llega ya generada y se muestra una sola vez.
 */

/** @param array{origen:string, creada_en:?string, creada_por:?string} $apikey */
function htmlApikey(?string $apikeyNueva, array $apikey): void
{
    $rotulo = match ($apikey['origen']) {
        'bd' => 'Base de datos',
        'entorno' => 'Variable de entorno',
        'error' => 'Base de datos no disponible',
        default => 'Sin configurar',
    };
    ?>
<section class="seccion">
  <h2>API-Key de carga</h2>
  <p class="sub">Una sola clave para <strong>subir</strong> archivos (<code>POST /api/upload</code>). No afecta a los links de descarga, que usan su propio token. Se guarda como hash: no se puede releer, solo regenerar.</p>
  <?php if ($apikeyNueva !== null): ?>
  <div class="tarjeta clave-nueva">
    <p><strong>API-Key nueva</strong> — se muestra solo esta vez:</p>
    <code class="clave"><?= e($apikeyNueva) ?></code>
    <p class="sub">Actualiza la integración antes de cerrar esta página. Si la pierdes, regenera otra.</p>
  </div>
  <?php endif; ?>
  <div class="tarjeta dato">
    <span class="dato-etiqueta">Origen</span>
    <strong class="dato-valor"><?= e($rotulo) ?></strong>
    <span class="dato-pie">
      <?php if ($apikey['origen'] === 'bd'): ?>
      Regenerada <?= e(fechaHoraVisible($apikey['creada_en'])) ?><?= $apikey['creada_por'] !== null ? ' por ' . e($apikey['creada_por']) : '' ?>
      <?php elseif ($apikey['origen'] === 'entorno'): ?>
      Se usa <code>ARB_A_APIKEY</code>. Al regenerar, manda la de la base.
      <?php elseif ($apikey['origen'] === 'error'): ?>
      No se pudo leer la clave. La API responde 503 mientras dure el fallo.
      <?php else: ?>
      La API responde 503 hasta que generes una.
      <?php endif; ?>
    </span>
  </div>
  <form method="post" action="/admin.php" data-confirmar="La clave actual dejará de funcionar de inmediato. ¿Regenerar la API-Key?">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="regenerar_apikey">
    <div class="acciones-panel">
      <button type="submit" class="boton boton-peligro">Regenerar clave de carga</button>
    </div>
  </form>
</section>
    <?php
}
