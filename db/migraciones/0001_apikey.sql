-- 0001 · API-Key única rotable.
-- Para bases creadas antes de este paso (las nuevas ya la traen en db/schema.sql):
--   psql -U arb_a -d arb_a -f db/migraciones/0001_apikey.sql

begin;

create table if not exists apikey (
    id          smallint primary key default 1 check (id = 1),
    hash        char(64) not null check (hash ~ '^[a-f0-9]{64}$'),
    creada_en   timestamptz not null default now(),
    creada_por  text
);

commit;
