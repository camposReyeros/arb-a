# arb-a

Portal de entrega de archivos CSV. Reemplaza el envío de CSV por correo: el archivo se sube
por API, se comprime y se entrega por **link directo** con token; la UI muestra a cada usuario
solo los archivos donde su correo aparece como destinatario.

Dominio: **arb-a.servicios.care**

## Qué hace

1. `PUT /api/upload` (o `POST`) con `X-Api-Key`, `X-Correos` (lista separada por comas) y el
   CSV **crudo** en el cuerpo; `X-Nombre` es opcional → guarda el CSV comprimido en gzip y
   responde con el link.
2. Se envía a cada destinatario un correo con el link directo `/d/<token>`, vigente 30 días.
3. La UI con login (usuario y contraseña de ipro) lista los archivos del usuario y registra
   cada acceso. El archivo se conserva 90 días; después de los 30 del link, solo se llega aquí.
4. Panel de administración: purga de archivos, salud de SMTP y estadísticas de uso
   (UI vs link directo).

El permiso de ver un archivo es por **coincidencia de correo** con los destinatarios. No hay
filtro por plaza ni por sucursal.

## Requisitos

- PHP 8.5 (última versión con parches de seguridad) + `pdo_pgsql`
- PostgreSQL 16
- Acceso al SMTP de `servicios.care`

## Estructura

| Ruta | Responsabilidad |
|---|---|
| `public/` | Docroot. Lo único servido por HTTP. |
| `src/` | Lógica: configuración, BD, sesión, auth, archivos, correo. |
| `vistas/` | Fragmentos HTML, no se sirven directo. |
| `db/schema.sql` | Esquema PostgreSQL (idempotente). |
| `docker/app/` | Imagen `php:8.5-fpm-alpine` + `pdo_pgsql`. |
| `compose.yml` | `app-arb-a` + `db-arb-a`. |
| `bin/limpiar.php` | Purga de archivos vencidos (cron). |
| `bin/sincronizar_usuarios.php` | Copia credenciales desde ipro (cron); nunca toca el nivel. |
| `bin/marcar_admin.php` | Marca `lector`/`admin` a un usuario ya sincronizado. |
| `docs/api/upload.md` | Referencia de la API de carga (también servida en `/api/`). |

## Configuración

Copiar `.env.example` a `.env` (modo 600) y `config.example.php` a `config.php`.
Las credenciales **solo** por variables de entorno; `config.php` no se commitea.

## API-Key

Es **una sola**, solo para subir archivos y se genera en `/admin.php` → *API-Key de carga*.
Se muestra una vez y se guarda como `sha256`; al regenerarla, la anterior deja de funcionar
de inmediato. Mientras no exista en la base, se usa `ARB_A_APIKEY` del entorno.

## Documentación interna

Ver `AGENTS.md` para arquitectura, base de datos, seguridad y despliegue.
