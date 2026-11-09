-- =====================================================================
--  EssenciaSmile — functions + default privileges audit       READ-ONLY
--  Run in: Supabase Dashboard > SQL Editor. Paste ALL of it, press Run,
--  copy the single "report" cell. Only catalog metadata and function
--  source code are returned — no table data, no keys.
-- =====================================================================
with fns as (
  select p.oid, p.proname, pg_get_function_identity_arguments(p.oid) as args,
         pg_get_function_result(p.oid) as returns,
         p.prosecdef as security_definer,
         p.proconfig::text as config,
         pg_get_userbyid(p.proowner) as owner,
         l.lanname as language,
         p.prosrc
  from pg_proc p
  join pg_namespace n on n.oid = p.pronamespace
  join pg_language l on l.oid = p.prolang
  where n.nspname = 'public'
)
select jsonb_pretty(jsonb_build_object(
  'generated_at', now(),
  'functions', (select jsonb_agg(jsonb_build_object(
      'name', f.proname,
      'args', f.args,
      'returns', f.returns,
      'security_definer', f.security_definer,
      'config_search_path', f.config,
      'owner', f.owner,
      'language', f.language,
      'execute_grantees', (select coalesce(jsonb_agg(distinct case when a.grantee = 0 then 'PUBLIC' else pg_get_userbyid(a.grantee) end), '[]')
                             from pg_proc p2, aclexplode(coalesce(p2.proacl, acldefault('f', p2.proowner))) a
                             where p2.oid = f.oid and a.privilege_type = 'EXECUTE'),
      'anon_execute', has_function_privilege('anon', f.oid, 'EXECUTE'),
      'authenticated_execute', has_function_privilege('authenticated', f.oid, 'EXECUTE'),
      'used_by_triggers', (select coalesce(jsonb_agg(t.tgrelid::regclass::text || ':' || t.tgname), '[]')
                             from pg_trigger t where t.tgfoid = f.oid and not t.tgisinternal),
      'tables_mentioned', (select coalesce(jsonb_agg(c.relname order by c.relname), '[]')
                             from pg_class c join pg_namespace n on n.oid = c.relnamespace
                             where n.nspname = 'public' and c.relkind in ('r', 'p')
                               and f.prosrc ~* ('\m' || c.relname || '\M')),
      'definition', pg_get_functiondef(f.oid)
    ) order by f.proname) from fns f),
  'object_owners_in_public', (select jsonb_agg(jsonb_build_object('owner', o, 'kind', k, 'count', n))
      from (select pg_get_userbyid(c.relowner) as o,
                   case c.relkind when 'r' then 'table' when 'p' then 'table' when 'S' then 'sequence'
                                  when 'v' then 'view' when 'm' then 'matview' else c.relkind::text end as k,
                   count(*) as n
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public' and c.relkind in ('r', 'p', 'S', 'v', 'm')
            group by 1, 2) x),
  'function_owners_in_public', (select jsonb_agg(jsonb_build_object('owner', owner, 'count', n))
      from (select pg_get_userbyid(p.proowner) as owner, count(*) as n
            from pg_proc p join pg_namespace ns on ns.oid = p.pronamespace
            where ns.nspname = 'public' group by 1) y),
  'default_privileges_all_roles', (select coalesce(jsonb_agg(jsonb_build_object(
        'for_role', pg_get_userbyid(d.defaclrole),
        'schema', coalesce(d.defaclnamespace::regnamespace::text, '(all schemas)'),
        'object', case d.defaclobjtype when 'r' then 'tables' when 'S' then 'sequences' when 'f' then 'functions'
                                       when 'T' then 'types' when 'n' then 'schemas' else d.defaclobjtype::text end,
        'acl', d.defaclacl::text)), '[]')
      from pg_default_acl d),
  'event_triggers', (select coalesce(jsonb_agg(jsonb_build_object('name', evtname, 'event', evtevent,
        'function', evtfoid::regproc::text, 'owner', pg_get_userbyid(evtowner), 'enabled', evtenabled)), '[]')
      from pg_event_trigger),
  'current_user_can_act_as', (select jsonb_agg(r.rolname) from pg_roles r
      where pg_has_role(current_user, r.oid, 'MEMBER') and r.rolname in ('postgres', 'supabase_admin', 'service_role', 'authenticated', 'anon'))
)) as report;
