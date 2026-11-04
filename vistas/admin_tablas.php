<?php
declare(strict_types=1);

/**
 * Tablas del panel. Cada fila lleva en `data-*` lo que necesita el filtro del cliente:
 * nombre/correo en minúsculas, estado, «nunca abierto» y si pertenece a los 5 recientes.
 */

require_once __DIR__ . '/layout.php';

/** Uso por archivo y purga en la misma tabla (checkbox dentro del formulario POST). */
function tablaArchivos(array $filas, array $recientes): void
{
    ?>
  <div class="tabla-caja tabla-densa">
    <table>
      <thead>
        <tr>
          <th class="col-check"><input type="checkbox" id="seleccionar-todos" aria-label="Seleccionar los archivos visibles"></th>
          <th>Archivo</th>
          <th>Tamaño</th>
          <th>Destinatarios</th>
          <th>Sesión</th>
          <th>Directo</th>
          <th>Último acceso</th>
          <th>Vencimiento</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody id="filas-archivos">
        <?php foreach ($filas as $fila):
            $total = (int) $fila['ui'] + (int) $fila['directo'];
            $vencido = yaVencio((string) $fila['expira_en']); ?>
        <tr data-nombre="<?= e(mb_strtolower((string) $fila['nombre'])) ?>"
            data-estado="<?= $vencido ? 'expirado' : 'vigente' ?>"
            data-nunca="<?= $total === 0 ? '1' : '0' ?>"
            data-reciente="<?= isset($recientes[(int) $fila['id']]) ? '1' : '0' ?>">
          <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $fila['id'] ?>" aria-label="Seleccionar <?= e((string) $fila['nombre']) ?>"></td>
          <td class="nombre"><?= e((string) $fila['nombre']) ?></td>
          <td><?= e(tamanoLegible((int) $fila['bytes_gz'])) ?></td>
          <td><?= (int) $fila['destinatarios'] ?></td>
          <td><?= (int) $fila['ui'] ?></td>
          <td><?= (int) $fila['directo'] ?></td>
          <td><?= e(fechaHoraVisible($fila['ultimo'] !== null ? (string) $fila['ultimo'] : null)) ?></td>
          <td><?= e(fechaVisible((string) $fila['expira_en'])) ?></td>
          <td><span class="badge <?= $vencido ? 'badge-alerta' : 'badge-ok' ?>"><?= $vencido ? 'Vencido' : 'Vigente' ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="sub" id="conteo-archivos" aria-live="polite"></p>
  <p class="sub" id="sin-resultados-archivos" hidden>Ningún archivo coincide con la búsqueda.</p>
    <?php
}

/** Accesos por destinatario: una fila por archivo y correo. */
function tablaDestinatarios(array $filas, array $recientes): void
{
    ?>
  <div class="tabla-caja tabla-densa">
    <table>
      <thead>
        <tr>
          <th>Archivo</th>
          <th>Correo</th>
          <th>Accesos por sesión</th>
          <th>Primer acceso</th>
          <th>Último acceso</th>
          <th><span class="solo-lectores">Estado</span></th>
        </tr>
      </thead>
      <tbody id="filas-destinatarios">
        <?php foreach ($filas as $fila): ?>
        <tr data-archivo="<?= e(mb_strtolower((string) $fila['nombre'])) ?>"
            data-correo="<?= e(mb_strtolower((string) $fila['correo'])) ?>"
            data-nunca="<?= (int) $fila['accesos'] === 0 ? '1' : '0' ?>"
            data-reciente="<?= isset($recientes[(int) $fila['archivo_id']]) ? '1' : '0' ?>">
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
  <p class="sub" id="conteo-destinatarios" aria-live="polite"></p>
  <p class="sub" id="sin-resultados-destinatarios" hidden>Ningún destinatario coincide con la búsqueda.</p>
    <?php
}
