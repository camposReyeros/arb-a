<?php
declare(strict_types=1);

/** "Mis archivos": lo que le corresponde al correo de la sesión. */

require_once __DIR__ . '/../src/archivos.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../vistas/layout.php';

cabecerasDeSeguridad();

$usuario = requerirSesion();
$archivos = archivosDeUsuario((string) $usuario['correo']);

htmlInicio('Mis archivos', $usuario);
?>
<h1>Mis archivos</h1>
<p class="sub">Los archivos que te compartieron. Se conservan <?= (int) config()['archivos']['dias'] ?> días desde la carga; el enlace del correo dura <?= (int) config()['archivos']['dias_link'] ?> días.</p>

<?php if ($archivos === []): ?>
<div class="tarjeta vacio">
  <h2>Todavía no tienes archivos</h2>
  <p>Cuando alguien te envíe uno, aparecerá aquí y también llegarás por correo con el enlace.</p>
</div>
<?php else: ?>
<div class="filtros">
  <input type="search" id="buscar" placeholder="Buscar por nombre…" aria-label="Buscar por nombre">
  <select id="vigencia" aria-label="Filtrar por vigencia">
    <option value="todos">Todas las vigencias</option>
    <option value="pronto">Vencen en 7 días</option>
    <option value="vigente">Más de 7 días</option>
  </select>
</div>

<div class="tabla-caja">
  <table>
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Fecha</th>
        <th>Vigencia</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="filas">
      <?php foreach ($archivos as $archivo):
          $dias = diasRestantes((string) $archivo['expira_en']);
          $clase = $dias <= 7 ? 'badge-alerta' : 'badge-ok';
          ?>
      <tr data-nombre="<?= e(mb_strtolower((string) $archivo['nombre'])) ?>"
          data-dias="<?= (int) $dias ?>">
        <td class="nombre"><?= e((string) $archivo['nombre']) ?></td>
        <td><?= e(fechaVisible((string) $archivo['subido_en'])) ?></td>
        <td><span class="badge <?= $clase ?>"><?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></span></td>
        <td class="accion">
          <a class="boton boton-suave" href="/archivo.php?id=<?= (int) $archivo['id'] ?>">Descargar</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="sub" id="sin-resultados" hidden>Ningún archivo coincide con el filtro.</p>
<script src="/assets/portal.js" defer></script>
<?php endif; ?>
<?php
htmlFin();
