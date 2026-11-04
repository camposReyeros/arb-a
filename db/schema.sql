-- arb-a — portal de entrega de CSV
-- PostgreSQL. Aplicar con: psql -f db/schema.sql (idempotente)

begin;

-- Usuarios del portal. El login (`externo_id`) y el `token_hash` se sincronizan
-- desde ipro: la contraseña es la misma que ya usan allá, no se duplica.
-- El acceso a un archivo NO depende de esta tabla: depende de que el correo
-- del usuario esté en `archivo_destinatario`.
create table if not exists usuario (
    id              bigserial primary key,
    externo_id      text,                         -- login: el mismo de ipro (p. ej. 13010)
    correo          text not null,                -- regla de acceso a archivos
    nombre          text,
    token_hash      text,                         -- derivado de ipro (md5), lo escribe el sync
    nivel           text not null default 'lector' check (nivel in ('lector', 'admin')),
    activo          boolean not null default true,
    sincronizado_en timestamptz,
    creado_en       timestamptz not null default now()
);

-- Instalaciones previas al login de ipro: se migran sin perder correo ni nivel.
-- `clave_hash` (argon2 local) se retira: ya no hay contraseña propia que verificar.
alter table usuario add column if not exists externo_id text;
alter table usuario add column if not exists token_hash text;
alter table usuario add column if not exists sincronizado_en timestamptz;
alter table usuario drop column if exists clave_hash;

-- El correo se guarda en minúsculas. No es único: ipro tiene personas
-- repetidas con el mismo correo y el acceso se resuelve por correo, no por fila.
drop index if exists usuario_correo_uidx;
create index if not exists usuario_correo_idx on usuario (lower(correo));
-- El login es único; los usuarios aún no sincronizados quedan con NULL.
create unique index if not exists usuario_externo_id_uidx on usuario (externo_id);

-- Archivo CSV comprimido. `ruta` está fuera del docroot: jamás se sirve directo.
create table if not exists archivo (
    id          bigserial primary key,
    nombre      text not null,                    -- nombre visible, en MAYÚSCULAS
    ruta        text not null,
    bytes       bigint not null check (bytes > 0),      -- tamaño original
    bytes_gz    bigint not null check (bytes_gz > 0),   -- tamaño comprimido
    sha256      char(64) not null,                -- del CSV original
    token_hash  char(64) not null unique,         -- sha256 del token del link /d/<token>
    ip_origen   inet,
    subido_en   timestamptz not null default now(),
    expira_en   timestamptz not null,             -- vigencia del archivo (90 días)
    link_expira_en timestamptz not null,          -- vigencia del link del correo (30 días)
    borrado_en  timestamptz                       -- purgado: la fila se conserva
);

-- Instalaciones previas: el link nace con 30 días contados desde la subida.
alter table archivo add column if not exists link_expira_en timestamptz;
update archivo set link_expira_en = subido_en + interval '30 days' where link_expira_en is null;
alter table archivo alter column link_expira_en set not null;
-- La plaza era solo una etiqueta informativa; el acceso siempre fue por correo.
alter table archivo drop column if exists plazas;

create index if not exists archivo_expira_idx on archivo (expira_en) where borrado_en is null;
create index if not exists archivo_subido_idx on archivo (subido_en desc);

-- Destinatarios del correo con el link. Es la regla de acceso de la UI.
create table if not exists archivo_destinatario (
    archivo_id  bigint not null references archivo(id) on delete cascade,
    correo      text not null,
    primary key (archivo_id, correo)
);

create index if not exists archivo_destinatario_correo_idx
    on archivo_destinatario (lower(correo));

-- Un mismo correo no se repite por archivo, sin importar mayúsculas.
create unique index if not exists archivo_destinatario_correo_uidx
    on archivo_destinatario (archivo_id, lower(correo));

-- Bitácora de accesos: alimenta las estadísticas del panel (UI vs link directo).
create table if not exists evento_acceso (
    id          bigserial primary key,
    archivo_id  bigint not null references archivo(id) on delete cascade,
    usuario_id  bigint references usuario(id) on delete set null,
    via         text not null check (via in ('ui', 'directo')),
    correo      text,                             -- quién entró (o el destinatario)
    ip          inet,
    user_agent  text,
    creado_en   timestamptz not null default now()
);

create index if not exists evento_acceso_archivo_idx on evento_acceso (archivo_id, creado_en desc);
create index if not exists evento_acceso_fecha_idx on evento_acceso (creado_en desc);

-- Resultado del envío de cada correo: salud del SMTP en el panel.
create table if not exists envio (
    id          bigserial primary key,
    archivo_id  bigint not null references archivo(id) on delete cascade,
    correo      text not null,
    estado      text not null check (estado in ('ok', 'error')),
    error       text,
    creado_en   timestamptz not null default now()
);

create index if not exists envio_fecha_idx on envio (creado_en desc);

-- Throttle del login: frena la fuerza bruta.
create table if not exists intento_login (
    clave            text primary key,            -- correo|ip
    intentos         int not null default 0,
    ultimo           timestamptz not null default now(),
    bloqueado_hasta  timestamptz
);

-- Límite de cargas por IP: ventana móvil de una hora sobre esta tabla.
-- Se revisa antes de trabajar y se registra solo cuando la carga se guardó.
create table if not exists limite_carga (
    id          bigserial primary key,
    ip          inet,
    creado_en   timestamptz not null default now()
);

create index if not exists limite_carga_ip_fecha_idx on limite_carga (ip, creado_en desc);
create index if not exists limite_carga_fecha_idx on limite_carga (creado_en desc);

-- Bitácora administrativa append-only.
create table if not exists bitacora (
    id          bigserial primary key,
    usuario_id  bigint references usuario(id) on delete set null,
    accion      text not null,
    detalle     jsonb,
    creado_en   timestamptz not null default now()
);

create or replace function bitacora_solo_insert() returns trigger as $$
begin
    raise exception 'bitacora es append-only (no admite update ni delete)';
end;
$$ language plpgsql;

drop trigger if exists bitacora_no_update on bitacora;
create trigger bitacora_no_update
    before update or delete on bitacora
    for each row execute function bitacora_solo_insert();

commit;
