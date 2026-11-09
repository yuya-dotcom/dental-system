-- =====================================================================
--  EssenciaSmile — trigger-function execute privileges          DRAFT
--  DEFENSE IN DEPTH ONLY. Not a fix for an exploitable hole (see below).
--  DO NOT RUN until the owner reviewed the result of
--  2026-11-10_functions_defaults_audit_READONLY.sql and approved this.
--
--  WHAT IT CHANGES
--    Removes EXECUTE from PUBLIC / anon / authenticated on every public
--    function that RETURNS trigger (fn_*_code, fn_inventory_status,
--    fn_set_updated_at, set_updated_at, generate_user_code, …), and grants it
--    to service_role. Nothing else.
--
--  WHY IT IS LOW RISK
--    • PostgreSQL checks EXECUTE on a trigger function only when a trigger is
--      CREATED, not when it fires — inserts/updates by the PHP app keep
--      firing every trigger (verified on an isolated copy, 2026-11-10).
--    • Trigger functions cannot be called directly ("trigger functions can only
--      be called as triggers") and PostgREST does not expose them as /rpc,
--      so nothing the app or browser does uses this EXECUTE right.
--    • get_dashboard_summary is NOT touched here (already server-only).
--  Only functions that RETURN trigger are affected — selected by catalog
--  query, so a regular function can never be caught by mistake.
-- =====================================================================
begin;

do $$
declare r record;
begin
  for r in
    select p.oid::regprocedure as fn
    from pg_proc p join pg_namespace n on n.oid = p.pronamespace
    where n.nspname = 'public'
      and p.prorettype = 'trigger'::regtype
  loop
    execute format('revoke execute on function %s from public, anon, authenticated', r.fn);
    execute format('grant execute on function %s to service_role', r.fn);
    raise notice 'trigger function locked: %', r.fn;
  end loop;
end $$;

commit;

-- VERIFY: re-run 2026-11-10_functions_defaults_audit_READONLY.sql —
-- every function returning "trigger" should show anon_execute = false and
-- authenticated_execute = false, and "used_by_triggers" unchanged.
-- Then do one write in the app that fires a trigger (e.g. add an inventory
-- item → item code + status are filled in; edit a patient → updated_at changes).
--
-- ROLLBACK:
--   do $$ declare r record; begin for r in select p.oid::regprocedure fn from pg_proc p
--     join pg_namespace n on n.oid = p.pronamespace where n.nspname = 'public' and p.prorettype = 'trigger'::regtype
--   loop execute format('grant execute on function %s to public', r.fn); end loop; end $$;
