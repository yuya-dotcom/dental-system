-- =====================================================================
--  EssenciaSmile — Supabase API-role hardening        DRAFT v2 (2026-11-09)
--  Written against the REAL audit result of 2026-11-09 15:47 UTC
--  (Postgres 17.6; 33 public tables, all with RLS enabled).
--
--  DO NOT RUN ON PRODUCTION until the owner explicitly approves it.
--
--  CONFIRMED PROBLEM IT FIXES
--    medical_questionnaire has three PERMISSIVE policies for {public}
--    (= anon + authenticated) with `true`: anyone holding the publishable
--    key (it is in the portal login page) can SELECT every row, INSERT rows
--    and UPDATE any row. Nothing in the app uses them: the browser never
--    queries tables, and PHP uses the secret key (service_role, BYPASSRLS).
--
--  DEFENSE IN DEPTH (not exploitable today, but one mistake away)
--    • anon/authenticated hold ALL privileges (incl. TRUNCATE, which RLS does
--      not restrict) on all 33 tables and all 33 sequences.
--    • get_dashboard_summary() (SECURITY INVOKER) is executable by anon /
--      authenticated. Today it returns "Account not found" for them because
--      RLS hides the rows; it becomes a full patient-data leak the moment any
--      table it reads gets a permissive policy.
--    • Default privileges grant every FUTURE table/function/sequence created
--      by postgres to anon/authenticated.
--
--  WHY IT IS SAFE FOR THE APP (verified 2026-11-09)
--    • PHP: secret key = service_role (BYPASSRLS) and keeps all privileges (step E).
--    • Browser: Supabase Auth only (signInWithOAuth / exchangeCodeForSession /
--      getSession). No table/RPC/Storage/Realtime use. Auth runs in the auth
--      schema, which this script does not touch.
--    • audit: auth_users_triggers = [] and http_webhook_triggers = [], so no
--      trigger fired by Google sign-up writes into public tables.
--    • Storage buckets = [], Realtime tables = [], pg_graphql not installed.
--    • Trigger functions (fn_*_code, set_updated_at, …) are NOT changed.
--  The whole script is one transaction: it applies completely or not at all.
-- =====================================================================
begin;

-- A. RLS on every public table (all 33 already have it; kept as a safety net
--    for any table added before this runs).
do $$
declare r record;
begin
  for r in
    select c.relname
    from pg_class c join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'public' and c.relkind in ('r', 'p') and not c.relrowsecurity
  loop
    execute format('alter table public.%I enable row level security', r.relname);
    raise notice 'RLS enabled: public.%', r.relname;
  end loop;
end $$;

-- B. Remove the three open medical_questionnaire policies (the confirmed exposure).
--    Names exactly as reported by the audit. RLS stays enabled on the table, so
--    after this anon/authenticated see 0 rows; service_role (PHP) is unaffected.
drop policy if exists mq_select_all on public.medical_questionnaire;
drop policy if exists mq_insert_all on public.medical_questionnaire;
drop policy if exists mq_update_all on public.medical_questionnaire;

-- C. No direct table / sequence access for the public API roles
revoke all on all tables    in schema public from anon, authenticated;
revoke all on all sequences in schema public from anon, authenticated;

-- D. Portal RPC: server only (PHP calls it with the secret key = service_role)
do $$
declare r record;
begin
  for r in
    select p.oid::regprocedure as fn
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
    where n.nspname = 'public' and p.proname = 'get_dashboard_summary'
  loop
    execute format('revoke execute on function %s from public, anon, authenticated', r.fn);
    execute format('grant execute on function %s to service_role', r.fn);
    raise notice 'RPC locked to service_role: %', r.fn;
  end loop;
end $$;

-- E. Future objects created by postgres (SQL Editor) are not exposed automatically.
--    (Defaults owned by supabase_admin cannot be changed by postgres; RLS-on +
--    no policies still protects such tables, and step C can simply be re-run.)
alter default privileges for role postgres in schema public revoke all     on tables    from anon, authenticated;
alter default privileges for role postgres in schema public revoke all     on sequences from anon, authenticated;
alter default privileges for role postgres in schema public revoke execute on functions from public, anon, authenticated;

-- F. The PHP app (service_role) keeps full access
grant usage on schema public to service_role;
grant all     on all tables    in schema public to service_role;
grant all     on all sequences in schema public to service_role;
grant execute on all functions in schema public to service_role;

commit;

-- AFTER APPLYING: re-run QUERY 0 of 2026-11-09_security_audit_READONLY.sql.
-- Expected: policies = [] ; every table anon_privs = "" and authenticated_privs = "" ;
-- get_dashboard_summary anon_execute = false, authenticated_execute = false ;
-- sequences_granted_to_api_roles = [] ; service_role_full = true on every table.
--
-- ROLLBACK (restores today's grants; the three open policies are intentionally NOT recreated):
--   grant all on all tables    in schema public to anon, authenticated;
--   grant all on all sequences in schema public to anon, authenticated;
--   grant execute on function public.get_dashboard_summary(integer) to anon, authenticated;
