# arb-a

Portal de entrega de archivos CSV. Reemplaza el envío de CSV por correo: el archivo se sube
por API, se comprime y se entrega por **link directo** con token; la UI muestra a cada usuario
solo los archivos donde su correo aparece como destinatario.

Dominio: **arb-a.servicios.care**

## Qué hace

1. `POST /api/upload` con `X-Api-Key`, `X-Correos` (lista separada por comas) y opcional
   `X-Plazas` → guarda el CSV comprimido en gzip y responde con el link.
2. Se envía a cada destinatario un correo con el link directo `/d/<token>`.
3. La UI con login (correo + clave, sesión propia) lista los archivos del usuario y registra
   cada acceso.
4. Panel de administración: purga de archivos, salud de SMTP y estadísticas de uso
   (UI vs link directo).

La **plaza** es informativa: nunca restringe. El permiso de ver un archivo es por coincidencia
de correo, porque hay usuarios que apoyan otras plazas.

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

## Configuración

Copiar `.env.example` a `.env` (modo 600) y `config.example.php` a `config.php`.
Las credenciales **solo** por variables de entorno; `config.php` no se commitea.

## Documentación interna

Ver `AGENTS.md` para arquitectura, base de datos, seguridad y despliegue.
