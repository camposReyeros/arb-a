# AGENTS.md — arb-a

Portal de entrega de archivos CSV. Dominio: **arb-a.servicios.care**.
Reemplaza el envío de CSV como adjunto en correo: se sube por API, se comprime y se
entrega por link directo con token.

## Idioma

Español de México. Identificadores técnicos en inglés; dominio en español
(`archivo`, `destinatario`, `usuario`, `envio`).

## Arquitectura

| Archivo | Responsabilidad |
|---|---|
| `public/` | **Docroot**. Único servido por HTTP: `index.php`, `login.php`, `d.php`, `api/upload.php`, `admin.php`, `assets/`. |
| `src/bootstrap.php` | Config, sesión (`arb_a`), CSRF, escape, cabeceras de seguridad. |
| `src/bd.php` | PDO único. |
| `src/usuarios.php` | Usuarios: login de ipro, derivado, sincronización y bitácora. |
| `src/auth.php` | Login usuario + clave con el derivado de ipro, niveles y throttle. |
| `src/archivos.php` | Subida, compresión gzip en streaming, token, consultas por correo, purga. |
| `src/correo.php` | SMTP y registro en `envio` (salud del panel). |
| `src/csv.php` | Validación del CSV en una pasada (`fgetcsv`), nombre en MAYÚSCULAS y cabecera `X-Correos`. |
| `src/errores.php` | Catálogo `codigo → [status, mensaje]` de la API. Es el contrato con el cliente. |
| `src/limite.php` | Límite de cargas por IP: ventana móvil de una hora sobre `limite_carga`. |
| `src/cuota.php` | Cuota global de disco (2 GB por defecto) sobre la suma de `.gz` vivos. |
| `public/api/index.php` | Documentación viva en `/api`; la tabla de errores sale de `src/errores.php`. |
| `public/api/openapi.yaml` | Contrato OpenAPI 3.1, servido tal cual. |
| `docs/api/upload.md` | Documentación de referencia (estilo abuseipdb) para quien integra. |
| `vistas/layout.php` | Helpers de presentación: `htmlInicio`/`htmlFin`, fechas, tamaños. |
| `public/index.php` | Raíz: manda a `/archivos.php` o `/login.php` según la sesión. |
| `public/login.php` | Login del portal (usuario + clave, CSRF, throttle, un solo mensaje de error). |
| `public/salir.php` | Cierre de sesión: solo POST con CSRF. |
| `public/archivos.php` | "Mis archivos": tabla con buscador y filtro de vigencia. |
| `public/archivo.php` | Descarga desde el portal; valida sesión y pertenencia (`via = 'ui'`). |
| `public/admin.php` | Panel de administración: salud, estadísticas de uso y purga manual. Solo `admin`. |
| `src/admin.php` | Consultas del panel: salud, uso por archivo/destinatario y purga. |
| `public/assets/portal.css` | Tokens de Corporate Precision. Sin fuentes ni CDN externos. |
| `public/assets/portal.js` | Filtro de la tabla. Sin dependencias. |
| `db/schema.sql` | Esquema PostgreSQL idempotente. |
| `db/migraciones/` | Cambios posteriores, numerados, aplicados a mano. |
| `bin/limpiar.php` | Purga diaria por cron. |
| `bin/sincronizar_usuarios.php` | Copia las credenciales de ipro (`externo_id`, correo, `token_hash`). Nunca toca `nivel`. |
| `bin/marcar_admin.php` | Marca el nivel local de un usuario ya sincronizado. |
| `tests/auth_smoke.php` | Humo de auth y throttle contra Postgres. |
| `tests/admin_smoke.php` | Humo del panel contra Postgres: salud, estadísticas y purga. |

## Reglas del dominio

- **El login es el de ipro:** `usuario` (`externo_id`) + la misma contraseña. Las credenciales
  se sincronizan con `bin/sincronizar_usuarios.php`; `usuario.token_hash` guarda el derivado
  `md5(últimos 5 + usuario + clave)`. El correo **no** sirve para iniciar sesión.
- **La regla de acceso es el correo:** un usuario ve un archivo si su correo está en
  `archivo_destinatario`. Se compara en minúsculas.
- **El link directo es el token.** `/d/<token>` descarga sin login, con token aleatorio
  (32 bytes hex) y **vigencia de 30 días** (`archivo.link_expira_en`). En BD se guarda **solo su
  sha256** (`archivo.token_hash`): un volcado no entrega links vivos.
- **El archivo dura 90 días** (`archivo.expira_en`); pasados los 30 del link solo se llega por el
  portal con el usuario. `expira_en` y `link_expira_en` son independientes.
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
- **El panel es solo para `admin`** (`requerirAdmin()`): salud (archivos activos, disco vs cuota,
  correos de 7 días, accesos de 30 días), uso por archivo y por destinatario, y purga manual.
  La purga borra el `.gz` (confinado a `ARB_A_DIR`), marca `borrado_en` y deja rastro en
  `bitacora`; si el fichero ya no existe, también lo marca para que el cron no lo reintente.
- **El link directo es anónimo:** sus accesos se cuentan por archivo, nunca por persona. Los
  accesos de la UI se atribuyen al correo de la sesión.

## Convenciones de código

- `declare(strict_types=1)` en todo archivo PHP.
- Archivos de menos de 300 líneas.
- **Nunca SQL interpolado**: `PDO::prepare` con parámetros enlazados.
- Endpoints JSON terminan en `exit`.
- `hash_equals` para tokens y para el derivado de ipro; **no** hay `password_hash` local.
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
- Login con throttle: 10 fallos en 15 min bloquean 15 min por usuario+IP.
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

`tests/auth_smoke.php` cubre la sincronización desde ipro, el derivado, el nivel local, el
throttle y el login. Es el gate que detecta errores de binding que `php -l` no ve.

`tests/admin_smoke.php` cubre el panel contra la misma base (`ARB_A_DIR` debe apuntar al
depósito): salud, estadísticas, filtro "nunca abierto", purga con fichero y sin él, y bitácora.

## Despliegue

- **Dominio:** `arb-a.servicios.care`, TLS en el contenedor `caddy` del proyecto `/opt/insumos`.
- **Raíz:** `/opt/arb-a` — `compose.yml`, `.env` (600), `app/` (copia del repo),
  `archivos/` (uid 82, modo 700), `docker/app/Dockerfile`.
- **Contenedores:** `app-arb-a` (php:8.5-fpm-alpine + pdo_pgsql) y `db-arb-a`
  (postgres:16-alpine), redes `arb-a` + `redweb`.
- **Caddy:** bloque `arb-a.servicios.care` en `/opt/insumos/Caddyfile` (ver
  `docs/despliegue/Caddyfile`). El código se monta además en el contenedor caddy
  (`/opt/arb-a/app:/var/www/arb-a:ro`); sin ese montaje, `php_fastcgi` no resuelve el script.
  La Caddyfile es bind de un solo archivo: editarla **en el mismo inodo** (`>>` o `cat >`),
  nunca con `sed -i`.
- **`ARB_A_PROXY_IPS`** = IP de caddy en `redweb`. Si caddy se recrea puede cambiar:
  revisar, actualizar `.env` y recrear `app-arb-a`.
- **SMTP:** `mail.servicios.care:465` (SSL implícito), cuenta `noreply-arb-a@servicios.care`.
  El default del código (`smtp.servicios.care:587`) es solo eso, un default.
- **Cron:** `/etc/cron.d/arb-a` corre a las 03:23
  `docker exec app-arb-a php /var/www/arb-a/bin/limpiar.php >> /var/log/arb-a-cron.log 2>&1`.

## Deuda conocida

- El panel limita a 200 archivos y 300 destinatarios sin paginación: "seleccionar todos" cubre
  solo lo visible. El cron sigue purgando los vencidos, así que no se acumulan.
- La purga manual no es transaccional (select → `unlink` → update): un doble POST concurrente
  puede duplicar la entrada de bitácora, pero nunca borra de más.

## Glosario

| Término | Significado |
|---|---|
| `archivo` | CSV subido, ya comprimido en gzip |
| `destinatario` | correo al que se le envió el link; define qué ve en la UI |
| `usuario` | `externo_id` de ipro; es el login y la contraseña es la misma de allá |
| `token` | secreto del link directo `/d/<token>`; vence a los 30 días |
| `via` | cómo se accedió: `ui` (con sesión) o `directo` (link) |
