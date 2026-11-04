-- 0002 · Soporte de libros .xlsx además de CSV.
-- Para bases creadas antes de este paso (las nuevas ya la traen en db/schema.sql):
--   psql -U arb_a -d arb_a -f db/migraciones/0002_xlsx.sql

begin;

alter table archivo add column if not exists tipo text not null default 'csv';

do $$
begin
    alter table archivo add constraint archivo_tipo_check check (tipo in ('csv', 'xlsx'));
exception
    when duplicate_object then null;
end $$;

-- Lo cargado antes de este paso era CSV; el default ya lo deja así.

commit;
