-- =====================================================================
--  EssenciaSmile — Supabase security audit            READ-ONLY
--  Run in: Supabase Dashboard > SQL Editor (as the default postgres user)
--
--  Every statement here is a SELECT on the system catalogs. Nothing is
--  created, changed, granted or revoked. No table data is returned —
--  only metadata (names, flags, privileges, policy text, row estimates).
--
--  The SQL Editor shows only the LAST result of a run, so either:
--    • run QUERY 0 alone (one JSON cell with everything) — easiest to send back, or
--    • select one numbered query at a time and press "Run selected".
-- =====================================================================


-- ---------------------------------------------------------------------
-- QUERY 0 — everything below in ONE JSON value (copy the single cell)
-- ---------------------------------------------------------------------
with
tbl as (
  select c.oid, c.relname,
         c.relrowsecurity      as rls_enabled,
         c.relforcerowsecurity as rls_forced,
         c.reltuples::bigint   as approx_rows
  from pg_class c join pg_namespace n on n.oid = c.relnamespace
  where n.nspname = 'public' and c.relkind in ('r', 'p')
),
tables as (
  select t.relname as table_name, t.rls_enabled, t.rls_forced, t.approx_rows,
         (select count(*) from pg_policies p where p.schemaname = 'public' and p.tablename = t.relname) as policies,
         concat_ws('', case when has_table_privilege('anon', t.oid, 'SELECT') then 'S' end,
                       case when has_table_privilege('anon', t.oid, 'INSERT') then 'I' end,
                       case when has_table_privilege('anon', t.oid, 'UPDATE') then 'U' end,
                       case when has_table_privilege('anon', t.oid, 'DELETE') then 'D' end,
                       case when has_table_privilege('anon', t.oid, 'TRUNCATE') then 'T' end) as anon_privs,
         concat_ws('', case when has_table_privilege('authenticated', t.oid, 'SELECT') then 'S' end,
                       case when has_table_privilege('authenticated', t.oid, 'INSERT') then 'I' end,
                       case when has_table_privilege('authenticated', t.oid, 'UPDATE') then 'U' end,
                       case when has_table_privilege('authenticated', t.oid, 'DELETE') then 'D' end,
                       case when has_table_privilege('authenticated', t.oid, 'TRUNCATE') then 'T' end) as authenticated_privs,
         has_table_privilege('service_role', t.oid, 'SELECT,INSERT,UPDATE,DELETE') as service_role_full,
         (select string_agg(a.attname, ',' order by a.attnum) from pg_attribute a
           where a.attrelid = t.oid and a.attnum > 0 and not a.attisdropped
             and a.attname ~* '(password|token|otp|secret|hash)') as secret_columns
  from tbl t
),
policies as (
  select schemaname, tablename, policyname, permissive, roles::text as roles, cmd, qual, with_check
  from pg_policies where schemaname in ('public', 'storage')
),
functions as (
  select p.proname as function_name, pg_get_function_identity_arguments(p.oid) as args,
         p.prosecdef as security_definer,
         has_function_privilege('anon', p.oid, 'EXECUTE')          as anon_execute,
         has_function_privilege('authenticated', p.oid, 'EXECUTE') as authenticated_execute,
         p.proconfig::text as config, l.lanname as language
  from pg_proc p join pg_namespace n on n.oid = p.pronamespace join pg_language l on l.oid = p.prolang
  where n.nspname = 'public'
),
views as (
  select c.relname as view_name, c.relkind as kind, c.reloptions::text as options,
         has_table_privilege('anon', c.oid, 'SELECT') as anon_select,
         has_table_privilege('authenticated', c.oid, 'SELECT') as authenticated_select
  from pg_class c join pg_namespace n on n.oid = c.relnamespace
  where n.nspname = 'public' and c.relkind in ('v', 'm', 'f')
),
sequences as (
  select c.relname as sequence_name,
         has_sequence_privilege('anon', c.oid, 'USAGE') as anon_usage,
         has_sequence_privilege('authenticated', c.oid, 'USAGE') as authenticated_usage
  from pg_class c join pg_namespace n on n.oid = c.relnamespace
  where n.nspname = 'public' and c.relkind = 'S'
)
select jsonb_pretty(jsonb_build_object(
  'generated_at', now(),
  'postgres_version', current_setting('server_version'),
  'tables',     (select jsonb_agg(to_jsonb(x) order by x.table_name) from tables x),
  'policies',   (select coalesce(jsonb_agg(to_jsonb(x) order by x.schemaname, x.tablename, x.policyname), '[]') from policies x),
  'functions',  (select coalesce(jsonb_agg(to_jsonb(x) order by x.function_name), '[]') from functions x),
  'views',      (select coalesce(jsonb_agg(to_jsonb(x) order by x.view_name), '[]') from views x),
  'sequences_granted_to_api_roles',
                (select coalesce(jsonb_agg(x.sequence_name order by x.sequence_name), '[]') from sequences x where x.anon_usage or x.authenticated_usage),
  'api_roles',  (select jsonb_agg(jsonb_build_object('role', rolname, 'bypassrls', rolbypassrls, 'config', rolconfig) order by rolname)
                   from pg_roles where rolname in ('anon', 'authenticated', 'service_role', 'authenticator', 'postgres')),
  'schema_usage', (select jsonb_agg(jsonb_build_object('schema', nspname,
                     'anon', has_schema_privilege('anon', oid, 'USAGE'),
                     'authenticated', has_schema_privilege('authenticated', oid, 'USAGE')) order by nspname)
                   from pg_namespace where nspname in ('public', 'storage', 'graphql_public', 'graphql')),
  'default_privileges', (select coalesce(jsonb_agg(jsonb_build_object('owner', pg_get_userbyid(d.defaclrole),
                     'schema', d.defaclnamespace::regnamespace::text, 'object', d.defaclobjtype, 'acl', d.defaclacl::text)), '[]')
                   from pg_default_acl d where d.defaclnamespace = 'public'::regnamespace),
  'extensions', (select jsonb_agg(extname order by extname) from pg_extension),
  'realtime_tables', (select coalesce(jsonb_agg(schemaname || '.' || tablename), '[]') from pg_publication_tables where pubname = 'supabase_realtime'),
  'storage_buckets', (select coalesce(jsonb_agg(jsonb_build_object('id', b.id, 'public', b.public)), '[]') from storage.buckets b),
  'auth_users_triggers', (select coalesce(jsonb_agg(pg_get_triggerdef(t.oid)), '[]') from pg_trigger t
                   join pg_class c on c.oid = t.tgrelid join pg_namespace n on n.oid = c.relnamespace
                   where n.nspname = 'auth' and c.relname = 'users' and not t.tgisinternal),
  'http_webhook_triggers', (select coalesce(jsonb_agg(jsonb_build_object('table', t.tgrelid::regclass::text, 'trigger', t.tgname)), '[]')
                   from pg_trigger t where not t.tgisinternal and pg_get_triggerdef(t.oid) ilike '%http_request%')
)) as security_report;


-- ---------------------------------------------------------------------
-- QUERY 1 — every public table: RLS, privileges of the API roles, verdict
--   anon_privs / authenticated_privs letters: S=select I=insert U=update D=delete T=truncate
-- ---------------------------------------------------------------------
select c.relname                                     as table_name,
       c.relrowsecurity                              as rls_enabled,
       c.relforcerowsecurity                         as rls_forced,
       (select count(*) from pg_policies p where p.schemaname = 'public' and p.tablename = c.relname) as policies,
       concat_ws('', case when has_table_privilege('anon', c.oid, 'SELECT') then 'S' end,
                     case when has_table_privilege('anon', c.oid, 'INSERT') then 'I' end,
                     case when has_table_privilege('anon', c.oid, 'UPDATE') then 'U' end,
                     case when has_table_privilege('anon', c.oid, 'DELETE') then 'D' end,
                     case when has_table_privilege('anon', c.oid, 'TRUNCATE') then 'T' end) as anon_privs,
       concat_ws('', case when has_table_privilege('authenticated', c.oid, 'SELECT') then 'S' end,
                     case when has_table_privilege('authenticated', c.oid, 'INSERT') then 'I' end,
                     case when has_table_privilege('authenticated', c.oid, 'UPDATE') then 'U' end,
                     case when has_table_privilege('authenticated', c.oid, 'DELETE') then 'D' end,
                     case when has_table_privilege('authenticated', c.oid, 'TRUNCATE') then 'T' end) as authenticated_privs,
       c.reltuples::bigint                           as approx_rows,
       case
         when not c.relrowsecurity and (has_table_privilege('anon', c.oid, 'SELECT') or has_table_privilege('authenticated', c.oid, 'SELECT'))
           then 'EXPOSED — no RLS, API roles can read (and likely write)'
         when not c.relrowsecurity and (has_table_privilege('anon', c.oid, 'INSERT,UPDATE,DELETE') or has_table_privilege('authenticated', c.oid, 'INSERT,UPDATE,DELETE'))
           then 'EXPOSED — no RLS, API roles can write'
         when c.relrowsecurity and not (has_table_privilege('anon', c.oid, 'SELECT,INSERT,UPDATE,DELETE')
                                       or has_table_privilege('authenticated', c.oid, 'SELECT,INSERT,UPDATE,DELETE'))
           then 'protected — RLS on and API roles have no privileges (any policies are inert)'
         when c.relrowsecurity and (select count(*) from pg_policies p where p.schemaname = 'public' and p.tablename = c.relname) = 0
           then 'protected — RLS on, no policies (deny-all for API roles)'
         when c.relrowsecurity then 'REVIEW policies (Query 2)'
         else 'protected — API roles have no privileges'
       end as verdict
from pg_class c join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and c.relkind in ('r', 'p')
order by (case when not c.relrowsecurity then 0 else 1 end), c.relname;


-- ---------------------------------------------------------------------
-- QUERY 2 — all RLS policies (public + storage) with a risk hint
-- ---------------------------------------------------------------------
select schemaname, tablename, policyname, permissive, roles, cmd, qual, with_check,
       case
         when roles && array['public', 'anon']::name[] and coalesce(qual, with_check, '') in ('true', '(true)') then 'HIGH — anyone with the public key, unrestricted'
         when roles && array['public', 'anon']::name[] then 'review — applies to anonymous visitors'
         when roles && array['authenticated']::name[] and coalesce(qual, with_check, '') in ('true', '(true)') then 'HIGH — any signed-in Supabase user, unrestricted'
         when roles && array['authenticated']::name[] then 'review — any signed-in Supabase user (incl. Google sign-in)'
         else 'ok'
       end as risk
from pg_policies
where schemaname in ('public', 'storage')
order by risk desc, schemaname, tablename, policyname;


-- ---------------------------------------------------------------------
-- QUERY 3 — functions in public that the API roles can call (RPC)
-- ---------------------------------------------------------------------
select p.proname                                      as function_name,
       pg_get_function_identity_arguments(p.oid)      as args,
       p.prosecdef                                    as security_definer,
       has_function_privilege('anon', p.oid, 'EXECUTE')          as anon_execute,
       has_function_privilege('authenticated', p.oid, 'EXECUTE') as authenticated_execute,
       p.proconfig                                    as config_search_path,
       case when p.prosecdef and (has_function_privilege('anon', p.oid, 'EXECUTE') or has_function_privilege('authenticated', p.oid, 'EXECUTE'))
            then 'HIGH — runs with owner rights (bypasses RLS) and is callable with the public key'
            when has_function_privilege('anon', p.oid, 'EXECUTE') then 'review — callable with the public key'
            else 'ok' end as risk
from pg_proc p join pg_namespace n on n.oid = p.pronamespace
where n.nspname = 'public'
order by risk desc, p.proname;


-- ---------------------------------------------------------------------
-- QUERY 4 — source of the portal RPC (to see what it returns for an account id)
-- ---------------------------------------------------------------------
select pg_get_functiondef(p.oid) as definition
from pg_proc p join pg_namespace n on n.oid = p.pronamespace
where n.nspname = 'public' and p.proname = 'get_dashboard_summary';


-- ---------------------------------------------------------------------
-- QUERY 5 — views / materialized views / foreign tables, buckets, Realtime
-- ---------------------------------------------------------------------
select 'view' as kind, c.relname as name, c.reloptions::text as detail,
       has_table_privilege('anon', c.oid, 'SELECT') as anon_can_read,
       has_table_privilege('authenticated', c.oid, 'SELECT') as authenticated_can_read
from pg_class c join pg_namespace n on n.oid = c.relnamespace
where n.nspname = 'public' and c.relkind in ('v', 'm', 'f')
union all
select 'realtime', schemaname || '.' || tablename, 'in supabase_realtime publication', null, null
from pg_publication_tables where pubname = 'supabase_realtime'
union all
select 'storage_bucket', b.id, case when b.public then 'PUBLIC bucket' else 'private bucket' end, null, null
from storage.buckets b
order by 1, 2;
