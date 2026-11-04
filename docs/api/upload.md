# ARB-A · API de carga

Carga un CSV y avisa por correo a los destinatarios indicados. Cada destinatario recibe un
link directo con vigencia de **30 días**; el archivo se conserva 90 días y se consulta desde el
portal.

- **URL base:** `https://arb-a.servicios.care`
- **Cuerpo:** el CSV crudo (`Content-Type: text/csv`), sin multipart
- **Respuesta:** siempre JSON (`application/json`)
- **Autenticación:** API-Key por cabecera
- **Tamaño máximo:** 64 MB sin comprimir por defecto, configurable en el servidor (el caso normal son ~30 MB)

## Autenticación

Todas las peticiones llevan la cabecera `X-Api-Key` con la clave del servicio. Sin clave, o con
una clave equivocada, la respuesta es `401` y **no se escribe nada**.

```
X-Api-Key: <clave>
```

Hay **una sola clave** para todo el servicio. Se genera desde el panel de administración
(`/admin.php`, sección *API-Key*), que la muestra **una sola vez**; no se puede releer después,
solo regenerar. Al regenerarla, la anterior deja de funcionar de inmediato: actualiza la
integración antes de cerrar esa página.

No se pasa por query string ni por el cuerpo: quedaría en logs de proxy y en historiales de
shell.

---

## PUT|POST /api/upload

Sube un CSV y dispara los correos. `PUT` es la forma recomendada; `POST` se acepta igual.

### Cabeceras

| Cabecera | Obligatoria | Ejemplo | Descripción |
|---|---|---|---|
| `X-Api-Key` | sí | `k3y...` | Clave del servicio |
| `X-Correos` | sí | `uno@comex.care;dos@transportesvata.com` | Destinatarios del link. Separados por coma o punto y coma. Máximo 50; se normalizan a minúsculas y se deduplican. **Solo se aceptan dominios autorizados** (ver abajo) |
| `X-Nombre` | no | `ventas_marzo.csv` | Nombre que se verá en el portal. Se sanea y se guarda en mayúsculas; si falta, `ARCHIVO.CSV` |

### Dominios autorizados

Solo se avisa a direcciones de estos dominios (comparación exacta, sin subdominios, sin
distinción de mayúsculas):

`camposreyeros.com` · `transportesvata.com` · `comex.care` · `comexpintacolor.com`

Una dirección de otro dominio devuelve `422` con `codigo: "destinatario_no_permitido"` y **no se
guarda el archivo**. La lista se ajusta en el servidor (`ARB_A_CORREOS_PERMITIDOS`); una lista
vacía significa que no se acepta ningún destinatario.

### Cuerpo

El CSV **crudo** en el cuerpo de la petición, con `Content-Type: text/csv`. Nada de
`multipart/form-data`: no hay campos de formulario, solo el archivo.

El archivo debe ser el **CSV sin comprimir**. Si ya viene en gzip se rechaza con
`csv_ya_comprimido`; el servidor comprime.

### Ejemplo con curl

```bash
curl -X PUT https://arb-a.servicios.care/api/upload \
  -H "X-Api-Key: $ARB_A_APIKEY" \
  -H "X-Correos: uno@comex.care;dos@transportesvata.com" \
  -H "X-Nombre: ventas_marzo.csv" \
  -H "Content-Type: text/csv" \
  --data-binary @ventas_marzo.csv
```

### Ejemplo con Python

```python
import os
import requests

API = "https://arb-a.servicios.care/api/upload"

with open("ventas_marzo.csv", "rb") as fh:
    respuesta = requests.put(
        API,
        headers={
            "X-Api-Key": os.environ["ARB_A_APIKEY"],
            "X-Correos": "uno@comex.care;dos@transportesvata.com",
            "X-Nombre": "ventas_marzo.csv",
            "Content-Type": "text/csv",
        },
        data=fh,  # el archivo va tal cual en el cuerpo
        timeout=180,
    )

datos = respuesta.json()

if respuesta.status_code != 201:
    # Se compara el código estable, nunca el texto del mensaje.
    print("falló:", datos["codigo"], datos["error"])
else:
    print("link:", datos["link"])
    print("link vigente hasta:", datos["link_expira_en"])
    print("archivo disponible hasta:", datos["expira_en"])
    print("CSV:", datos["csv"]["lineas"], "líneas de", datos["csv"]["columnas"], "columnas")
```

`data=fh` manda el CSV crudo sin cargarlo en memoria. No uses `files=` ni `data=...csv...`
(eso sería multipart o formulario) y no fijes `Content-Length` a mano: lo calcula `requests`.


### Respuesta 201

```json
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
```

| Campo | Descripción |
|---|---|
| `id` | Identificador interno del archivo |
| `nombre` | Nombre visible, en MAYÚSCULAS y con extensión `.CSV` |
| `bytes` | Tamaño del CSV original |
| `bytes_gz` | Tamaño del `.gz` que se sirve en la descarga |
| `sha256` | SHA-256 del **CSV original** (el que se subió, no el `.gz`) |
| `expira_en` | Fecha en que el archivo deja de estar disponible (90 días) |
| `link_expira_en` | Fecha en que el link del correo deja de funcionar (30 días). Después se entra por el portal con el usuario |
| `link` | Link directo. Contiene el token: es un secreto, no se registra ni se comparte |
| `csv` | Resumen de la validación: líneas de datos, columnas y separador detectado |
| `envios` | Correos que salieron (`ok`) y que fallaron (`error`) |
| `aviso` | Solo aparece si `envios.ok` es 0: el archivo quedó guardado y ningún correo salió |

Un `201` significa **el archivo quedó guardado y el link es válido**. Si además
`envios.error > 0`, el link existe pero algún destinatario no se enteró; se reintenta avisando
a soporte con el `id`, no volviendo a subir (una segunda subida crea otro archivo y otros
correos).

---

## Errores

Todos los errores devuelven `ok: false`, un **`codigo` estable** y un `error` legible. El
cliente debe comparar `codigo`; el texto puede cambiar.

```json
{ "ok": false, "codigo": "csv_columnas_inconsistentes", "error": "todas las líneas deben tener el mismo número de columnas (línea 120: 5 columnas; la primera trae 4)" }
```

| `codigo` | HTTP | Cuándo |
|---|---|---|
| `metodo_no_permitido` | 405 | La ruta solo acepta `PUT` o `POST` |
| `servicio_no_disponible` | 503 | El servicio no tiene clave configurada |
| `apikey_invalida` | 401 | Falta `X-Api-Key` o no coincide |
| `destinatarios_invalidos` | 422 | `X-Correos` vacía, con un correo inválido o con más de 50 |
| `destinatario_no_permitido` | 422 | `X-Correos` trae una dirección de un dominio no autorizado |
| `cuota_excedida` | 507 | El total de archivos vivos ya ocupa la cuota de disco del servidor. Se libera al vencer los archivos |
| `falta_archivo` | 422 | No llegó el CSV en el cuerpo (o vino como `multipart/form-data`) |
| `archivo_vacio` | 422 | El archivo pesa 0 bytes |
| `archivo_muy_grande` | 413 | Excede el máximo configurado (64 MB por defecto) o el límite del servidor |
| `archivo_incompleto` | 422 | Se leyó menos de lo que declaraba el archivo; reintentar |
| `limite_cargas` | 429 | Más de 100 cargas aceptadas desde la misma IP en una hora. Trae `Retry-After` (segundos) |
| `csv_ilegible` | 422 | No se pudo abrir el archivo recibido (fallo del servidor al leerlo) |
| `csv_ya_comprimido` | 422 | El archivo empieza con la firma gzip (`1f 8b`) |
| `csv_binario` | 422 | El contenido no es texto: trae bytes NUL |
| `csv_vacio` | 422 | Sin datos, o solo líneas en blanco |
| `csv_columnas_inconsistentes` | 422 | Alguna línea no tiene el mismo número de columnas que la primera |
| `csv_demasiadas_columnas` | 422 | Más de 500 columnas |
| `error_interno` | 500 | Fallo al guardar (BD o disco). Reintentar más tarde |

En `429` la respuesta incluye además `reintentar_en` (segundos) y la cabecera `Retry-After`.

## Validación del CSV

La API revisa el contenido antes de guardarlo, en una sola lectura:

1. **No comprimido** — se rechaza gzip.
2. **Texto, no binario** — sin bytes NUL en los primeros 64 KB.
3. **Mismo número de columnas en todas las líneas** — respetando comillas (`"`), comas
   dentro de campo y saltos de línea entrecomillados (RFC 4180).
4. **Separador** — coma, punto y coma, tabulador o pipe; se detecta el más frecuente de la
   primera línea y se informa en `csv.separador`.
5. **Hasta 500 columnas**.

No se impone la codificación: los CSV en latin1 se aceptan tal cual. La primera línea se toma
como encabezado y cuenta para el total de `lineas`; las líneas en blanco no cuentan.

## Notas de operación

- **Vigencia:** el link del correo dura **30 días**; el archivo se conserva **90 días**. Pasados
  los 30, `/d/<token>` responde `404` y solo se llega por el portal con el usuario. A los 90 el
  `.gz` se borra del disco con la purga diaria.
- **Descarga:** el link (`/d/<token>`) descarga sin login, como `application/gzip` con
  `Content-Disposition: attachment`. El `.gz` descomprimido es byte por byte el CSV subido:
  compárelo con `sha256`.
- **Reintentos:** la operación **no es idempotente**. Reintentar una subida que ya devolvió
  `201` crea otro archivo y manda otros correos. Reintente solo si la respuesta fue de error.
- **Tiempo:** una carga de 30 MB tarda unos segundos (validación + compresión). Use un timeout
  de cliente de al menos 180 s.
