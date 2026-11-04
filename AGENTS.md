# AGENTS.md — arb-a

Portal de entrega de archivos CSV. Dominio: **arb-a.servicios.care**.
Reemplaza el envío de CSV como adjunto en correo: se sube por API, se comprime y se
entrega por link directo con token.

## Idioma

Español de México. Identificadores técnicos en inglés; dominio en español
(`archivo`, `destinatario`, `plaza`, `envio`).

## Arquitectura

| Archivo | Responsabilidad |
|---|---|
| `public/` | **Docroot**. Único servido por HTTP: `index.php`, `login.php`, `d.php`, `api/upload.php`, `admin.php`, `assets/`. |
| `src/bootstrap.php` | Config, sesión (`arb_a`), CSRF, escape, cabeceras de seguridad. |
| `src/bd.php` | PDO único. |
| `src/usuarios.php` | Usuarios, normalización de correo, bitácora. |
| `src/auth.php` | Login correo + clave (`password_hash`), niveles, throttle. |
| `src/archivos.php` | Subida, compresión gzip en streaming, token, consultas por correo, purga. |
| `src/correo.php` | SMTP y registro en `envio` (salud del panel). |
| `src/csv.php` | Validación del CSV en una pasada (`fgetcsv`), nombre en MAYÚSCULAS, cabeceras `X-Correos`/`X-Plazas`. |
| `src/errores.php` | Catálogo `codigo → [status, mensaje]` de la API. Es el contrato con el cliente. |
| `src/limite.php` | Límite de cargas por IP: ventana móvil de una hora sobre `limite_carga`. |
| `src/cuota.php` | Cuota global de disco (2 GB por defecto) sobre la suma de `.gz` vivos. |
| `public/api/index.php` | Documentación viva en `/api`; la tabla de errores sale de `src/errores.php`. |
| `public/api/openapi.yaml` | Contrato OpenAPI 3.1, servido tal cual. |
| `docs/api/upload.md` | Documentación de referencia (estilo abuseipdb) para quien integra. |
| `vistas/layout.php` | Helpers de presentación: `htmlInicio`/`htmlFin`, plazas, fechas, tamaños. |
| `public/index.php` | Raíz: manda a `/archivos.php` o `/login.php` según la sesión. |
| `public/login.php` | Login del portal (correo + clave, CSRF, throttle, un solo mensaje de error). |
| `public/salir.php` | Cierre de sesión: solo POST con CSRF. |
| `public/archivos.php` | "Mis archivos": tabla con buscador y filtro de vigencia. |
| `public/archivo.php` | Descarga desde el portal; valida sesión y pertenencia (`via = 'ui'`). |
| `public/assets/portal.css` | Tokens de Corporate Precision. Sin fuentes ni CDN externos. |
| `public/assets/portal.js` | Filtro de la tabla. Sin dependencias. |
| `db/schema.sql` | Esquema PostgreSQL idempotente. |
| `db/migraciones/` | Cambios posteriores, numerados, aplicados a mano. |
| `bin/limpiar.php` | Purga diaria por cron. |
| `bin/crear_usuario.php` | Alta de usuario por terminal (la clave se pide por stdin). |
| `tests/auth_smoke.php` | Humo de auth y throttle contra Postgres. |

## Reglas del dominio

- **La plaza es informativa.** `archivo.plazas` se guarda y se muestra; **nunca** restringe
  acceso. Hay usuarios que apoyan otras plazas.
- **La regla de acceso es el correo:** un usuario ve un archivo si su correo está en
  `archivo_destinatario`. Se compara en minúsculas.
- **El link directo es el token.** `/d/<token>` descarga sin login, con token aleatorio
  (32 bytes hex) y vigencia igual a la del archivo. En BD se guarda **solo su sha256**
  (`archivo.token_hash`): un volcado no entrega links vivos.
- **Todo vive 3 meses.** `expira_en = now() + 90 days`; el cron borra el .gz y marca
  `borrado_en`. Archivo vencido no se descarga ni por link directo.
- **Nombre visible en MAYÚSCULAS**, conservando el nombre original como base.
- **La subida exige API-Key** (`X-Api-Key`); sin ella no se escribe nada.
- **El CSV se valida antes de guardar:** no gzip, no binario, mismo número de columnas en
  todas las líneas (RFC 4180), hasta 500 columnas. Se hace en una sola lectura con `fgetcsv`.
  Cada fila se lee con tope de 1 MiB (`ARB_A_MAX_LINEA`) para acotar la memoria ante una línea
  gigante o una comilla sin cerrar. El muestreo de binario revisa los primeros y los últimos
  64 KB (riesgo aceptado: un polyglot con relleno de texto en ambos extremos se guarda igual,
  pero se entrega como `application/gzip` + `attachment` + `nosniff`).
- **Solo se avisa a dominios autorizados** (`correo.dominios`): `camposreyeros.com`,
  `transportesvata.com`, `comex.care`, `comexpintacolor.com`. Comparación exacta, sin
  subdominios. Lista vacía = nadie. La API no es un relay abierto.
- **El disco tiene cuota global** (`archivos.cuota_bytes`, 2 GB): suma de `bytes_gz` de los
  archivos vivos, ni vencidos ni borrados. Se revisa antes de comprimir y se confirma dentro de
  la transacción con un lock global. Se libera sola al vencer los archivos.
- **Cada IP tiene 100 cargas aceptadas por hora** (ventana móvil, `limite_carga`). Se revisa
  antes de trabajar (barato) y se vuelve a revisar **dentro de la transacción del guardado**,
  con un lock por IP: dos peticiones simultáneas no pueden colarse contando a la vez. La cuota
  se reserva en esa misma transacción, así que un archivo inválido no la consume.
  Sin IP identificable no hay cuota (no se puede castigar a todos en la misma cubeta).
- **Los errores de la API tienen `codigo` estable** (`src/errores.php`). El cliente compara
  `codigo`; el mensaje puede cambiar. La documentación de `/api` se genera del mismo catálogo.
- **Todo acceso se registra** en `evento_acceso` con `via`: `ui` o `directo`. Es la
  materia prima de las estadísticas del panel.
- **El portal no reutiliza el token del correo** (en BD solo hay `token_hash`). La descarga
  de la UI es `/archivo.php?id=N`, exige sesión y comprueba que el correo del usuario esté en
  `archivo_destinatario`; si no, responde 404. Registra el acceso con `via = 'ui'`.
- **Toda acción con efecto va por POST con CSRF**; la consulta es GET. `salir.php` no acepta GET.

## Convenciones de código

- `declare(strict_types=1)` en todo archivo PHP.
- Archivos de menos de 300 líneas.
- **Nunca SQL interpolado**: `PDO::prepare` con parámetros enlazados.
- Endpoints JSON terminan en `exit`.
- `hash_equals` para tokens; `password_hash`/`password_verify` para claves.
- **PHP 8.5** (última línea con parches de seguridad). No bajar de versión.
- CSS propio self-hosted, **sin CDN**: el diseño viene de Stitch (`Corporate Precision`) y se
  porta a `public/assets/estilo.css`. Nada de Tailwind en producción, nada de Google Fonts.
- Iconos: SVG en línea, nunca una fuente de iconos externa (la CSP no lo permite).

## Base de datos

- PostgreSQL 16, DSN `pgsql:`.
- Tablas: `usuario`, `archivo`, `archivo_destinatario`, `evento_acceso`, `envio`,
  `intento_login`, `limite_carga`, `bitacora` (append-only por trigger).
- El esquema se aplica solo al crear el volumen (`docker-entrypoint-initdb.d`).
- Cambios posteriores: `db/migraciones/000X_*.sql` a mano.

## Seguridad

- Nada de archivos dentro del docroot: `archivo.ruta` vive en `/var/lib/arb-a/archivos`,
  modo `0600`, servido solo por PHP tras validar token.
- API-Key comparada con `hash_equals`, tomada de `ARB_A_APIKEY` (env, nunca en git).
- Login con throttle: 10 fallos en 15 min bloquean 15 min por correo+IP.
- Sesión PHP con `HttpOnly`, `SameSite=Lax`, `secure` cuando hay HTTPS. Vigencia:
  **30 min de inactividad y 8 h de tope absoluto**.
- **CSRF obligatorio en todo POST** (`campoCsrf()` en el formulario, `csrfValido()` en el
  controlador). `csrfValido()`/`csrfToken()` arrancan la sesión por su cuenta: una pantalla que
  los use no puede quedarse sin sesión por descuido.
- La descarga del portal (`/archivo.php?id=N`) no confía en el id: junta `archivo` con
  `archivo_destinatario` por correo de la sesión. Mismo 404 para "no es tuyo" y "no existe".
- Detrás de Caddy, la IP real y el HTTPS se toman de `X-Forwarded-For`/`X-Forwarded-Proto`
  **solo** si `REMOTE_ADDR` está en `ARB_A_PROXY_IPS`. Sin esa lista no se cree el header.
  De `X-Forwarded-For` se usa el **último** salto (el que añadió el proxy): el primero lo fija
  el cliente y no sirve para el rate limit.
- La purga solo borra rutas que caen dentro de `ARB_A_DIR` (validación con `realpath`). La
  descarga hace la misma comprobación antes de servir.
- La descarga responde **un solo 404** para token inválido, vencido y fichero ausente: sin oráculo.
- Los correos no van al `error_log` en claro: se registra el `archivo_id` y un hash del destino.
- CSP `default-src 'self'`, sin `unsafe-inline`. Los estilos y scripts son archivos propios.

## Gates antes de commit

```bash
docker run --rm -v "$PWD":/app -w /app php:8.5-cli sh -c \
  'find public src vistas bin tests tools -name "*.php" | while read f; do php -l "$f" >/dev/null || exit 1; done'
```

Pruebas puras (sin BD), corren en el mismo contenedor:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.5-cli php tests/csv_test.php
docker run --rm -v "$PWD":/app -w /app php:8.5-cli php tests/vistas_test.php
```

El humo con BD (levantar `db-arb-a`, aplicar `db/schema.sql`, subir un CSV y descargarlo)
se corre antes de desplegar. No se commitea con pruebas rojas.

```bash
# humo de auth (necesita Postgres)
docker run --rm --network <red> -v "$PWD":/app -w /app \
  -e ARB_A_DSN="pgsql:host=<db>;port=5432;dbname=arb_a" \
  -e ARB_A_DB_USER=arb_a -e ARB_A_DB_PASS=<pass> \
  arb-a-app:1 php tests/auth_smoke.php
```

`tests/auth_smoke.php` cubre alta, verificación de clave, normalización de correo,
throttle y login. Es el gate que detecta errores de binding que `php -l` no ve.

## Despliegue

- **Dominio:** `arb-a.servicios.care` (Caddy del host hace TLS y `php_fastcgi app-arb-a:9000`).
- **Raíz:** `/opt/arb-a` — `compose.yml`, `.env` (600), `app/` (copia), `db/`, `archivos/`,
  `docker/app/Dockerfile`.
- **Contenedores:** `app-arb-a` (php:8.5-fpm-alpine + pdo_pgsql) y `db-arb-a`
  (postgres:16-alpine), red `arb-a` + `redweb`.
- **Cron:** `/etc/cron.d/arb-a` corre `bin/limpiar.php` a las 03:23.

## Glosario

| Término | Significado |
|---|---|
| `archivo` | CSV subido, ya comprimido en gzip |
| `destinatario` | correo al que se le envió el link; define qué ve en la UI |
| `plaza` | agrupador informativo (PLAZA1, PLAZA2...); no restringe |
| `token` | secreto del link directo `/d/<token>` |
| `via` | cómo se accedió: `ui` (con sesión) o `directo` (link) |
