-- arb-a — portal de entrega de CSV
-- PostgreSQL. Aplicar con: psql -f db/schema.sql (idempotente)

begin;

-- Usuarios del portal. Datos mínimos: correo, clave y nivel.
-- El acceso a un archivo NO depende de esta tabla: depende de que el correo
-- del usuario esté en `archivo_destinatario`.
create table if not exists usuario (
    id          bigserial primary key,
    correo      text not null,
    nombre      text,
    clave_hash  text not null,
    nivel       text not null default 'lector' check (nivel in ('lector', 'admin')),
    activo      boolean not null default true,
    creado_en   timestamptz not null default now()
);

-- El correo es único sin importar mayúsculas; se guarda en minúsculas.
create unique index if not exists usuario_correo_uidx on usuario (lower(correo));

-- Archivo CSV comprimido. `ruta` está fuera del docroot: jamás se sirve directo.
create table if not exists archivo (
    id          bigserial primary key,
    nombre      text not null,                    -- nombre visible, en MAYÚSCULAS
    ruta        text not null,
    bytes       bigint not null check (bytes > 0),      -- tamaño original
    bytes_gz    bigint not null check (bytes_gz > 0),   -- tamaño comprimido
    sha256      char(64) not null,                -- del CSV original
    token_hash  char(64) not null unique,         -- sha256 del token del link /d/<token>
    plazas      text[] not null default '{}',     -- informativo, nunca restringe
    ip_origen   inet,
    subido_en   timestamptz not null default now(),
    expira_en   timestamptz not null,
    borrado_en  timestamptz                       -- purgado: la fila se conserva
);

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
