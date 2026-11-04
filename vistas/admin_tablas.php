<?php
declare(strict_types=1);

/**
 * Tablas de uso del panel de administración.
 *
 * Cada fila lleva `data-buscar` (nombre y correos en minúsculas) y `data-reciente`;
 * el buscador de `portal.js` filtra al vuelo y arranca mostrando solo las recientes.
 */

/**
 * @param array<int, array<string, mixed>> $filas
 * @param array<int, true> $recientes
 */
function tablaUsoPorArchivo(array $filas, array $recientes, string $ayudaId = ''): void
{
    ?>
  <div class="filtros">
    <input type="search" id="buscar-archivos" placeholder="Búsqueda rápida: nombre o correo…" aria-label="Búsqueda rápida por nombre de archivo o correo destinatario"<?= $ayudaId !== '' ? ' aria-describedby="' . e($ayudaId) . '"' : '' ?>>
  </div>
  <div class="tabla-caja">
    <table>
      <thead>
        <tr>
          <th>Archivo</th>
          <th>Destinatarios</th>
          <th>Accesos UI</th>
          <th>Link directo</th>
          <th>Último acceso</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="filas-archivos">
        <?php foreach ($filas as $fila):
            $total = (int) $fila['ui'] + (int) $fila['directo'];
            $reciente = isset($recientes[(int) $fila['id']]);
            $texto = mb_strtolower((string) $fila['nombre'] . ' ' . (string) $fila['correos']); ?>
        <tr data-buscar="<?= e($texto) ?>" data-reciente="<?= $reciente ? '1' : '0' ?>">
          <td class="nombre"><?= e((string) $fila['nombre']) ?></td>
          <td><?= (int) $fila['destinatarios'] ?></td>
          <td><?= (int) $fila['ui'] ?></td>
          <td><?= (int) $fila['directo'] ?></td>
          <td><?= e(fechaHoraVisible($fila['ultimo'] !== null ? (string) $fila['ultimo'] : null)) ?></td>
          <td><?php if ($total === 0): ?><span class="badge badge-pronto">Nunca abierto</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="sub" id="sin-resultados-archivos" role="status" hidden>Ningún archivo coincide con la búsqueda.</p>
    <?php
}

/**
 * @param array<int, array<string, mixed>> $filas
 * @param array<int, true> $recientes
 */
function tablaAccesosPorDestinatario(array $filas, array $recientes, string $ayudaId = ''): void
{
    ?>
  <div class="filtros">
    <input type="search" id="buscar-destinatarios" placeholder="Búsqueda rápida: correo o archivo…" aria-label="Búsqueda rápida por correo destinatario o nombre de archivo"<?= $ayudaId !== '' ? ' aria-describedby="' . e($ayudaId) . '"' : '' ?>>
  </div>
  <div class="tabla-caja">
    <table>
      <thead>
        <tr>
          <th>Archivo</th>
          <th>Correo</th>
          <th>Accesos UI</th>
          <th>Primer acceso</th>
          <th>Último acceso</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="filas-destinatarios">
        <?php foreach ($filas as $fila):
            $reciente = isset($recientes[(int) $fila['archivo_id']]);
            $texto = mb_strtolower((string) $fila['nombre'] . ' ' . (string) $fila['correo']); ?>
        <tr data-buscar="<?= e($texto) ?>" data-reciente="<?= $reciente ? '1' : '0' ?>">
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
  <p class="sub" id="sin-resultados-destinatarios" role="status" hidden>Ningún destinatario coincide con la búsqueda.</p>
    <?php
}
