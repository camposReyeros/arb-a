<?php
declare(strict_types=1);

/**
 * GET /api — documentación viva de la API de carga.
 * La tabla de errores sale del mismo catálogo que usa `upload.php`: no puede desincronizarse.
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/errores.php';

cabecerasDeSeguridad();
header('Content-Type: text/html; charset=utf-8');

$curl = <<<'TXT'
curl -X PUT https://arb-a.servicios.care/api/upload \
  -H "X-Api-Key: $ARB_A_APIKEY" \
  -H "X-Correos: uno@comex.care;dos@transportesvata.com" \
  -H "X-Nombre: ventas_marzo.csv" \
  -H "Content-Type: text/csv" \
  --data-binary @ventas_marzo.csv
TXT;

$python = <<<'TXT'
import os
import requests

with open("ventas_marzo.csv", "rb") as fh:
    r = requests.put(
        "https://arb-a.servicios.care/api/upload",
        headers={
            "X-Api-Key": os.environ["ARB_A_APIKEY"],
            "X-Correos": "uno@comex.care;dos@transportesvata.com",
            "X-Nombre": "ventas_marzo.csv",
            "Content-Type": "text/csv",
        },
        data=fh,
        timeout=180,
    )

datos = r.json()
if r.status_code != 201:
    print("falló:", datos["codigo"], datos["error"])
else:
    print("link:", datos["link"])
TXT;

$respuesta = <<<'TXT'
{
  "ok": true,
  "id": 42,
  "nombre": "VENTAS_MARZO.CSV",
  "bytes": 31457299,
  "bytes_gz": 4399307,
  "sha256": "938b393a…5330",
  "expira_en": "2027-02-02T14:03:11+00:00",
  "link_expira_en": "2027-01-03T14:03:11+00:00",
  "link": "https://arb-a.servicios.care/d/4f1c…9ab2",
  "csv": { "lineas": 812345, "columnas": 12, "separador": "," },
  "envios": { "ok": 2, "error": 0 }
}
TXT;

$filas = '';
foreach (ARB_A_ERRORES as $codigo => [$estado, $mensaje]) {
    $filas .= '<tr><td><code>' . e($codigo) . '</code></td>'
        . '<td class="estado ' . ($estado >= 500 ? 'estado-5xx' : 'estado-4xx') . '">' . $estado . '</td>'
        . '<td>' . e($mensaje) . '</td></tr>';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API de carga · ARB-A</title>
<link rel="stylesheet" href="/assets/api.css">
</head>
<body>
<main>
  <h1>API de carga</h1>
  <p class="sub">Sube un CSV, se comprime y se avisa por correo con un link de 30 días (el archivo se guarda 90).</p>

  <div class="tarjeta">
    <p><strong>Endpoint</strong> <span class="snippet">PUT /api/upload</span>
      (<span class="snippet">POST</span> también se acepta)</p>
    <p><strong>Autenticación</strong> <span class="snippet">X-Api-Key</span> — sin clave no se escribe nada.
      La clave se genera en el <strong>panel de administración</strong> (sección API-Key) y se muestra una sola vez.</p>
    <p><strong>Cuerpo</strong> el CSV crudo en el cuerpo, <span class="snippet">Content-Type: text/csv</span>,
      sin multipart. Máximo 64 MB por defecto (configurable en el servidor).
      <span class="snippet">X-Nombre</span> es opcional y solo cambia el nombre visible.</p>
    <p><strong>Destinatarios</strong> <span class="snippet">X-Correos</span> separados por coma o punto y coma, máximo 50.
      Son la regla de acceso: cada uno ve el archivo en el portal.</p>
    <p><strong>Dominios autorizados</strong> solo <span class="snippet">camposreyeros.com</span>,
      <span class="snippet">transportesvata.com</span>, <span class="snippet">comex.care</span> y
      <span class="snippet">comexpintacolor.com</span>. Otro dominio se rechaza sin guardar nada.</p>
    <p><strong>Vigencia</strong> el enlace del correo dura 30 días; el archivo se conserva 90 y
      después solo se llega desde el portal.</p>
  </div>

  <h2>Ejemplo con curl</h2>
  <pre class="codigo"><?= e($curl) ?></pre>

  <h2>Ejemplo con Python</h2>
  <pre class="codigo"><?= e($python) ?></pre>

  <h2>Respuesta 201</h2>
  <pre class="codigo"><?= e($respuesta) ?></pre>
  <p>Un <strong>201</strong> significa que el archivo quedó guardado y el link sirve. Si
    <span class="snippet">envios.error</span> es mayor que cero, algún correo no salió y la
    respuesta trae <span class="snippet">aviso</span>: no se vuelve a subir (crearía otro archivo),
    se reporta el <span class="snippet">id</span>.</p>

  <h2>Errores</h2>
  <p>Compare siempre <span class="snippet">codigo</span>, nunca el mensaje. En <strong>429</strong> la
    respuesta trae <span class="snippet">Retry-After</span> y <span class="snippet">reintentar_en</span>.</p>
  <div class="tarjeta tabla-caja">
    <table>
      <thead><tr><th>Código</th><th>HTTP</th><th>Cuándo</th></tr></thead>
      <tbody><?= $filas ?></tbody>
    </table>
  </div>

  <h2>Validación del CSV</h2>
  <ul>
    <li>Se rechaza gzip: se sube el CSV tal cual.</li>
    <li>Se rechazan binarios (bytes NUL).</li>
    <li>Todas las líneas deben tener el mismo número de columnas, respetando comillas y
      saltos de línea entrecomillados (RFC 4180).</li>
    <li>Separador detectado: coma, punto y coma, tabulador o pipe. Se informa en
      <span class="snippet">csv.separador</span>.</li>
    <li>La codificación no se impone: latin1 se acepta.</li>
  </ul>

  <p class="pie">
    Contrato completo: <a href="/api/openapi.yaml">openapi.yaml</a> (OpenAPI 3.1).
    La subida <strong>no es idempotente</strong>: repetir una que ya devolvió 201 crea otro archivo y otros correos.
  </p>
</main>
</body>
</html>
