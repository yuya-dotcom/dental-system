-- =====================================================================
--  EssenciaSmile — Settings › Database & Backup (Owner only)
--  Run in: Supabase Dashboard > SQL Editor, BEFORE using the buttons.
--  Safe to run more than once (CREATE OR REPLACE). Changes NO table and
--  NO data: it only adds two read-only functions.
--
--  WHY A DATABASE FUNCTION
--    The live site (InfinityFree) can only reach Supabase over HTTPS: no
--    pg_dump, no shell, no Postgres port 5432/6543. The REST API alone
--    cannot read table definitions, functions, triggers or policies.
--    So the dump is written INSIDE Postgres from its own catalog and the
--    PHP page (api/settings_backup.php) only downloads it.
--
--  ADDS
--    es_admin_backup_info()          small JSON summary for the Settings
--                                    page: server version, database size,
--                                    table / row counts, function version.
--    es_admin_export_sql(p_include_data boolean)
--                                    the whole public schema as one SQL
--                                    script (text):
--                                      false → structure only (no records)
--                                      true  → structure + every row
--
--  CONSISTENCY
--    Both are STABLE: every query inside runs on the SAME snapshot, so a
--    full backup is one consistent point in time (an invoice saved while
--    it runs is either fully in the backup or not at all).
--
--  WHAT THE SCRIPT CONTAINS (schema "public")
--    extensions, enum/domain/composite types, sequences (+ current values),
--    functions/procedures, tables (columns, types, defaults, NOT NULL,
--    identity, generated columns), comments, views / materialized views,
--    primary keys, unique / check / exclusion constraints, indexes,
--    foreign keys, triggers, row level security + policies, grants,
--    realtime publication membership — and, for a backup, every row.
--    Order: tables → data → keys/indexes → foreign keys → triggers, so
--    rows load in any order and triggers don't fire during a restore.
--
--  NOT CONTAINED (managed by Supabase / outside the database)
--    auth.* and storage.* schemas, storage buckets and uploaded files,
--    Supabase project settings, role passwords. (Staff and patient logins
--    are app tables in public, so they ARE included.)
--
--  FAILS LOUDLY
--    Objects it cannot reproduce faithfully (partitioned / inherited /
--    foreign tables) raise an error instead of producing a partial file.
--
--  SECURITY
--    SECURITY DEFINER (runs as the owner, reads every row), execute is
--    revoked from public / anon / authenticated and granted ONLY to
--    service_role — i.e. only the PHP server with the secret key, and the
--    PHP endpoint itself is Owner-only with password re-entry.
--
--  RESTORE (into an EMPTY public schema, e.g. a new Supabase project)
--    psql "<connection string>" -v ON_ERROR_STOP=1 -f backup.sql
--    The script is one transaction: any error rolls everything back.
-- =====================================================================

begin;

-- ---------------------------------------------------------------------
--  Summary for the Settings page (cheap: catalog estimates, no scans)
-- ---------------------------------------------------------------------
create or replace function public.es_admin_backup_info()
returns jsonb
language sql
stable
security definer
set search_path = ''
as $$
    select jsonb_build_object(
        'function_version', 1,
        'server_version',   pg_catalog.current_setting('server_version'),
        'database_size',    pg_catalog.pg_database_size(pg_catalog.current_database()),
        'tables',           (select count(*) from pg_catalog.pg_class c
                               join pg_catalog.pg_namespace n on n.oid = c.relnamespace
                              where n.nspname = 'public' and c.relkind = 'r'),
        'rows_estimate',    (select coalesce(sum(greatest(c.reltuples, 0)), 0)::bigint from pg_catalog.pg_class c
                               join pg_catalog.pg_namespace n on n.oid = c.relnamespace
                              where n.nspname = 'public' and c.relkind = 'r'),
        'checked_at',       pg_catalog.now()
    );
$$;

-- ---------------------------------------------------------------------
--  The export itself
-- ---------------------------------------------------------------------
create or replace function public.es_admin_export_sql(p_include_data boolean default false)
returns text
language plpgsql
stable                      -- one snapshot for the whole export
security definer
set search_path = ''        -- every name in the output is schema-qualified
as $fn$
declare
    v_ns          oid := 'public'::regnamespace;
    v_out         text[] := '{}';     -- the script, in parts (joined once at the end)
    v_manifest    text[] := '{}';
    v_tables      int := 0;
    v_rows        bigint := 0;
    v_n           bigint;
    v_tag         text;
    v_cols        text;
    v_sql         text;
    v_bad         text;
    r             record;
    c             record;
    d             record;
begin
    -- ── Refuse what this exporter can't reproduce faithfully ──────────
    select string_agg(format('%I (%s)', cl.relname,
             case cl.relkind when 'p' then 'partitioned table' when 'f' then 'foreign table' else 'inherited table' end), ', ')
      into v_bad
      from pg_catalog.pg_class cl
     where cl.relnamespace = v_ns
       and (cl.relkind in ('p', 'f')
            or (cl.relkind = 'r' and (cl.relispartition
                or exists (select 1 from pg_catalog.pg_inherits i where i.inhrelid = cl.oid or i.inhparent = cl.oid))));
    if v_bad is not null then
        raise exception 'Export stopped: unsupported objects in schema public: %', v_bad;
    end if;

    -- Row level security that applies even to the owner would hide rows
    if p_include_data and exists (
        select 1 from pg_catalog.pg_class cl
         where cl.relnamespace = v_ns and cl.relkind = 'r' and cl.relforcerowsecurity)
       and not exists (select 1 from pg_catalog.pg_roles ro where ro.rolname = current_user and (ro.rolsuper or ro.rolbypassrls)) then
        raise exception 'Export stopped: a table uses FORCE ROW LEVEL SECURITY, so % cannot read every row.', current_user;
    end if;

    v_out := v_out || array[
        'SET statement_timeout = 0;',
        'SET lock_timeout = 0;',
        'SET client_encoding = ''UTF8'';',
        'SET standard_conforming_strings = on;',
        'SET check_function_bodies = false;',
        'SET client_min_messages = warning;',
        'SET row_security = off;',
        'SELECT pg_catalog.set_config(''search_path'', '''', false);',
        '',
        'BEGIN;',
        ''];

    -- ── Roles named in grants / policies (exist already on Supabase) ──
    for r in
        select distinct ro.rolname
          from pg_catalog.pg_roles ro
         where ro.rolname not like 'pg\_%'
           and (ro.oid in (select (pg_catalog.aclexplode(cl.relacl)).grantee from pg_catalog.pg_class cl
                            where cl.relnamespace = v_ns and cl.relacl is not null)
             or ro.oid in (select (pg_catalog.aclexplode(p.proacl)).grantee from pg_catalog.pg_proc p
                            where p.pronamespace = v_ns and p.proacl is not null)
             or ro.oid in (select unnest(po.polroles) from pg_catalog.pg_policy po
                             join pg_catalog.pg_class cl on cl.oid = po.polrelid where cl.relnamespace = v_ns))
         order by 1
    loop
        if v_tables = 0 then   -- first role: section title (v_tables is still 0 here)
            v_out := v_out || array['-- Roles used by grants and policies (created only if missing; no login, no password)'];
            v_tables := -1;
        end if;
        v_out := v_out || format('DO $r$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = %L) THEN CREATE ROLE %I NOLOGIN; END IF; END $r$;',
                                 r.rolname, r.rolname);
    end loop;
    v_tables := 0;

    -- ── Extensions ────────────────────────────────────────────────────
    v_out := v_out || array['', '-- Extensions'];
    for r in
        select e.extname, en.nspname
          from pg_catalog.pg_extension e
          join pg_catalog.pg_namespace en on en.oid = e.extnamespace
         where e.extname <> 'plpgsql'
         order by e.extname
    loop
        if r.nspname in ('public', 'extensions') then
            if not (format('CREATE SCHEMA IF NOT EXISTS %I;', r.nspname) = any (v_out)) then
                v_out := v_out || format('CREATE SCHEMA IF NOT EXISTS %I;', r.nspname);
            end if;
            v_out := v_out || format('CREATE EXTENSION IF NOT EXISTS %I WITH SCHEMA %I;', r.extname, r.nspname);
        else
            v_out := v_out || format('-- extension %s (schema %s) is managed by the platform', r.extname, r.nspname);
        end if;
    end loop;

    -- ── Types: enums, domains, composite types ────────────────────────
    v_out := v_out || array['', '-- Types'];
    for r in
        select t.oid, t.typname, t.typtype, t.typrelid
          from pg_catalog.pg_type t
         where t.typnamespace = v_ns
           and (t.typtype in ('e', 'd')
                or (t.typtype = 'c' and (select cl.relkind from pg_catalog.pg_class cl where cl.oid = t.typrelid) = 'c'))
           and not exists (select 1 from pg_catalog.pg_depend dp
                            where dp.classid = 'pg_catalog.pg_type'::regclass and dp.objid = t.oid and dp.deptype = 'e')
         order by t.typtype desc, t.oid      -- enums, then domains, then composites
    loop
        if r.typtype = 'e' then
            v_out := v_out || format('CREATE TYPE public.%I AS ENUM (%s);', r.typname,
                (select string_agg(pg_catalog.quote_literal(e.enumlabel::text), ', ' order by e.enumsortorder)
                   from pg_catalog.pg_enum e where e.enumtypid = r.oid));
        elsif r.typtype = 'd' then
            v_out := v_out || (format('CREATE DOMAIN public.%I AS %s', r.typname,
                                  (select pg_catalog.format_type(t.typbasetype, t.typtypmod) from pg_catalog.pg_type t where t.oid = r.oid))
                || coalesce((select ' DEFAULT ' || t.typdefault from pg_catalog.pg_type t where t.oid = r.oid and t.typdefault is not null), '')
                || (select case when t.typnotnull then ' NOT NULL' else '' end from pg_catalog.pg_type t where t.oid = r.oid)
                || coalesce((select string_agg(' CONSTRAINT ' || pg_catalog.quote_ident(co.conname) || ' ' || pg_catalog.pg_get_constraintdef(co.oid), '' order by co.conname)
                               from pg_catalog.pg_constraint co where co.contypid = r.oid and co.contype = 'c'), '')
                || ';');
        else
            v_out := v_out || format('CREATE TYPE public.%I AS (%s);', r.typname,
                (select string_agg(pg_catalog.quote_ident(a.attname) || ' ' || pg_catalog.format_type(a.atttypid, a.atttypmod), ', ' order by a.attnum)
                   from pg_catalog.pg_attribute a where a.attrelid = r.typrelid and a.attnum > 0 and not a.attisdropped));
        end if;
    end loop;

    -- ── Sequences (not the ones behind identity columns) ──────────────
    v_out := v_out || array['', '-- Sequences'];
    for r in
        select cl.oid, cl.relname, s.*
          from pg_catalog.pg_class cl
          join pg_catalog.pg_sequence s on s.seqrelid = cl.oid
         where cl.relnamespace = v_ns and cl.relkind = 'S'
           and not exists (select 1 from pg_catalog.pg_depend dp
                            where dp.classid = 'pg_catalog.pg_class'::regclass and dp.objid = cl.oid and dp.deptype = 'i')
         order by cl.relname
    loop
        v_out := v_out || format('CREATE SEQUENCE public.%I AS %s START WITH %s INCREMENT BY %s MINVALUE %s MAXVALUE %s CACHE %s%s;',
            r.relname, pg_catalog.format_type(r.seqtypid, null), r.seqstart, r.seqincrement, r.seqmin, r.seqmax, r.seqcache,
            case when r.seqcycle then ' CYCLE' else ' NO CYCLE' end);
    end loop;

    -- ── Functions / procedures (those whose signature uses a table row
    --    type are created after the tables, further down) ───────────────
    v_out := v_out || array['', '-- Functions'];
    for r in
        select p.oid, p.prokind
          from pg_catalog.pg_proc p
         where p.pronamespace = v_ns
           and not exists (select 1 from pg_catalog.pg_depend dp
                            where dp.classid = 'pg_catalog.pg_proc'::regclass and dp.objid = p.oid and dp.deptype = 'e')
           and not exists (select 1 from pg_catalog.pg_type t
                             join pg_catalog.pg_class cl on cl.oid = t.typrelid and cl.relkind in ('r', 'v', 'm')
                            where t.oid = p.prorettype or t.oid = any (coalesce(p.proallargtypes, p.proargtypes::oid[])))
         order by p.proname, p.oid
    loop
        if r.prokind = 'a' then
            raise exception 'Export stopped: aggregate %(…) in schema public is not supported.', r.oid::regproc;
        end if;
        v_out := v_out || (pg_catalog.pg_get_functiondef(r.oid) || ';');
    end loop;

    -- ── Tables ─────────────────────────────────────────────────────────
    v_out := v_out || array['', '-- Tables'];
    for r in
        select cl.oid, cl.relname, cl.relpersistence, cl.reloptions
          from pg_catalog.pg_class cl
         where cl.relnamespace = v_ns and cl.relkind = 'r'
         order by cl.relname
    loop
        v_sql := null;
        for c in
            select a.attname, a.attnotnull, a.attidentity, a.attgenerated,
                   pg_catalog.format_type(a.atttypid, a.atttypmod) as typ,
                   pg_catalog.pg_get_expr(ad.adbin, ad.adrelid) as def,
                   case when a.attcollation <> 0 and a.attcollation <> t.typcollation
                        then (select pg_catalog.quote_ident(cn.nspname) || '.' || pg_catalog.quote_ident(co.collname)
                                from pg_catalog.pg_collation co join pg_catalog.pg_namespace cn on cn.oid = co.collnamespace
                               where co.oid = a.attcollation) end as coll,
                   (select s from pg_catalog.pg_sequence s
                     where s.seqrelid = pg_catalog.pg_get_serial_sequence(format('public.%I', r.relname), a.attname)::regclass) as seq
              from pg_catalog.pg_attribute a
              join pg_catalog.pg_type t on t.oid = a.atttypid
              left join pg_catalog.pg_attrdef ad on ad.adrelid = a.attrelid and ad.adnum = a.attnum
             where a.attrelid = r.oid and a.attnum > 0 and not a.attisdropped
             order by a.attnum
        loop
            v_sql := coalesce(v_sql || ',' || chr(10), '') || '    ' || pg_catalog.quote_ident(c.attname) || ' ' || c.typ
                || coalesce(' COLLATE ' || c.coll, '')
                || case
                     when c.attgenerated = 's' then ' GENERATED ALWAYS AS (' || c.def || ') STORED'
                     when c.attidentity in ('a', 'd') then
                          ' GENERATED ' || case c.attidentity when 'a' then 'ALWAYS' else 'BY DEFAULT' end || ' AS IDENTITY'
                          || coalesce(format(' (START WITH %s INCREMENT BY %s MINVALUE %s MAXVALUE %s CACHE %s%s)',
                                 (c.seq).seqstart, (c.seq).seqincrement, (c.seq).seqmin, (c.seq).seqmax, (c.seq).seqcache,
                                 case when (c.seq).seqcycle then ' CYCLE' else '' end), '')
                     when c.def is not null then ' DEFAULT ' || c.def
                     else '' end
                || case when c.attnotnull then ' NOT NULL' else '' end;
        end loop;
        v_out := v_out || (format('CREATE %sTABLE public.%I (', case when r.relpersistence = 'u' then 'UNLOGGED ' else '' end, r.relname)
                           || chr(10) || coalesce(v_sql, '') || chr(10) || ')'
                           || coalesce(' WITH (' || array_to_string(r.reloptions, ', ') || ')', '') || ';');
        v_tables := v_tables + 1;
    end loop;

    -- Serial-style sequences belong to their column (dropped with it)
    for r in
        select sc.relname as seq, tc.relname as tbl, a.attname
          from pg_catalog.pg_depend dp
          join pg_catalog.pg_class sc on sc.oid = dp.objid and sc.relkind = 'S'
          join pg_catalog.pg_class tc on tc.oid = dp.refobjid
          join pg_catalog.pg_attribute a on a.attrelid = tc.oid and a.attnum = dp.refobjsubid
         where dp.classid = 'pg_catalog.pg_class'::regclass and dp.deptype = 'a'
           and sc.relnamespace = v_ns and tc.relnamespace = v_ns
         order by 1
    loop
        v_out := v_out || format('ALTER SEQUENCE public.%I OWNED BY public.%I.%I;', r.seq, r.tbl, r.attname);
    end loop;

    -- ── Functions that use a table / view row type ─────────────────────
    for r in
        select p.oid
          from pg_catalog.pg_proc p
         where p.pronamespace = v_ns and p.prokind in ('f', 'p', 'w')
           and not exists (select 1 from pg_catalog.pg_depend dp
                            where dp.classid = 'pg_catalog.pg_proc'::regclass and dp.objid = p.oid and dp.deptype = 'e')
           and exists (select 1 from pg_catalog.pg_type t
                         join pg_catalog.pg_class cl on cl.oid = t.typrelid and cl.relkind in ('r', 'v', 'm')
                        where t.oid = p.prorettype or t.oid = any (coalesce(p.proallargtypes, p.proargtypes::oid[])))
         order by p.proname, p.oid
    loop
        v_out := v_out || (pg_catalog.pg_get_functiondef(r.oid) || ';');
    end loop;

    -- ── Views / materialized views, dependencies first ─────────────────
    v_out := v_out || array['', '-- Views'];
    for r in
        with recursive vw as (
            select cl.oid from pg_catalog.pg_class cl where cl.relnamespace = v_ns and cl.relkind in ('v', 'm')
        ), dep as (
            select distinct rw.ev_class as v_oid, dp.refobjid as on_oid
              from pg_catalog.pg_rewrite rw
              join pg_catalog.pg_depend dp on dp.classid = 'pg_catalog.pg_rewrite'::regclass and dp.objid = rw.oid
             where rw.ev_class in (select oid from vw) and dp.refobjid in (select oid from vw) and dp.refobjid <> rw.ev_class
        ), lvl as (
            select vw.oid, 0 as depth from vw
            union all
            select dep.v_oid, lvl.depth + 1 from dep join lvl on dep.on_oid = lvl.oid where lvl.depth < 64
        )
        select cl.oid, cl.relname, cl.relkind, cl.reloptions, max(lvl.depth) as depth
          from lvl join pg_catalog.pg_class cl on cl.oid = lvl.oid
         group by cl.oid, cl.relname, cl.relkind, cl.reloptions
         order by depth, cl.relname
    loop
        v_out := v_out || (format('CREATE %s public.%I', case when r.relkind = 'm' then 'MATERIALIZED VIEW' else 'VIEW' end, r.relname)
                           || coalesce(' WITH (' || array_to_string(r.reloptions, ', ') || ')', '')
                           || ' AS' || chr(10) || rtrim(pg_catalog.pg_get_viewdef(r.oid, true), ';' || chr(10) || ' ')
                           || case when r.relkind = 'm' then chr(10) || 'WITH NO DATA' else '' end || ';');
    end loop;

    -- ── Comments ───────────────────────────────────────────────────────
    v_out := v_out || array['', '-- Comments'];
    for r in
        select cl.relname, cl.relkind, ds.objsubid, ds.description, a.attname
          from pg_catalog.pg_description ds
          join pg_catalog.pg_class cl on cl.oid = ds.objoid and ds.classoid = 'pg_catalog.pg_class'::regclass
          left join pg_catalog.pg_attribute a on a.attrelid = cl.oid and a.attnum = ds.objsubid
         where cl.relnamespace = v_ns and cl.relkind in ('r', 'v', 'm', 'S')
         order by cl.relname, ds.objsubid
    loop
        if r.objsubid = 0 then
            v_out := v_out || format('COMMENT ON %s public.%I IS %L;',
                case r.relkind when 'r' then 'TABLE' when 'v' then 'VIEW' when 'm' then 'MATERIALIZED VIEW' else 'SEQUENCE' end,
                r.relname, r.description);
        else
            v_out := v_out || format('COMMENT ON COLUMN public.%I.%I IS %L;', r.relname, r.attname, r.description);
        end if;
    end loop;
    for r in
        select p.oid, ds.description
          from pg_catalog.pg_description ds
          join pg_catalog.pg_proc p on p.oid = ds.objoid and ds.classoid = 'pg_catalog.pg_proc'::regclass
         where p.pronamespace = v_ns
         order by p.proname
    loop
        v_out := v_out || format('COMMENT ON %s %s IS %L;',
            case (select prokind from pg_catalog.pg_proc where oid = r.oid) when 'p' then 'PROCEDURE' else 'FUNCTION' end,
            r.oid::regprocedure, r.description);
    end loop;

    -- ── Data ───────────────────────────────────────────────────────────
    if p_include_data then
        v_out := v_out || array['', '-- Data (types are restored exactly through jsonb_populate_recordset)'];
        for r in
            select cl.oid, cl.relname
              from pg_catalog.pg_class cl
             where cl.relnamespace = v_ns and cl.relkind = 'r'
             order by cl.relname
        loop
            -- generated columns are computed again on insert, so they're left out
            select string_agg(pg_catalog.quote_ident(a.attname), ', ' order by a.attnum)
              into v_cols
              from pg_catalog.pg_attribute a
             where a.attrelid = r.oid and a.attnum > 0 and not a.attisdropped and a.attgenerated = '';

            execute format('select count(*) from public.%I', r.relname) into v_n;
            v_rows := v_rows + v_n;
            v_manifest := v_manifest || format('--   %-40s %10s rows', r.relname, v_n);
            if v_n = 0 or v_cols is null then continue; end if;

            -- one INSERT per 500 rows; a random dollar-quote tag that never occurs in the data
            for d in execute format(
                'select string_agg(j, '','' order by rn) as js from (
                     select row_number() over () as rn, pg_catalog.to_jsonb(t)::text as j from public.%I t) s
                  group by (rn - 1) / 500 order by min(rn)', r.relname)
            loop
                loop
                    v_tag := '$es' || substr(md5(pg_catalog.clock_timestamp()::text || random()::text), 1, 10) || '$';
                    exit when strpos(d.js, v_tag) = 0;
                end loop;
                v_out := v_out || (format('INSERT INTO public.%I (%s) OVERRIDING SYSTEM VALUE', r.relname, v_cols) || chr(10)
                    || format('SELECT %s FROM pg_catalog.jsonb_populate_recordset(NULL::public.%I, ', v_cols, r.relname)
                    || v_tag || '[' || d.js || ']' || v_tag || ');');
            end loop;
        end loop;

        -- sequences continue where the live database is
        v_out := v_out || array['', '-- Sequence positions'];
        for r in
            select sc.relname as seq, ps.last_value, (ps.last_value is not null) as called,
                   idt.relname as itbl, idt.attname as icol
              from pg_catalog.pg_class sc
              join pg_catalog.pg_sequences ps on ps.schemaname = 'public' and ps.sequencename = sc.relname
              left join lateral (
                    select tc.relname, a.attname
                      from pg_catalog.pg_depend dp
                      join pg_catalog.pg_class tc on tc.oid = dp.refobjid
                      join pg_catalog.pg_attribute a on a.attrelid = tc.oid and a.attnum = dp.refobjsubid
                     where dp.classid = 'pg_catalog.pg_class'::regclass and dp.objid = sc.oid and dp.deptype = 'i'
                     limit 1) idt on true
             where sc.relnamespace = v_ns and sc.relkind = 'S'
             order by sc.relname
        loop
            continue when not r.called;
            if r.itbl is not null then
                -- identity sequence: its name on the target comes from the column
                v_out := v_out || format('SELECT pg_catalog.setval(pg_catalog.pg_get_serial_sequence(%L, %L), %s, true);',
                                         'public.' || pg_catalog.quote_ident(r.itbl), r.icol, r.last_value);
            else
                v_out := v_out || format('SELECT pg_catalog.setval(%L, %s, true);', 'public.' || pg_catalog.quote_ident(r.seq), r.last_value);
            end if;
        end loop;

        for r in
            select cl.relname from pg_catalog.pg_class cl
             where cl.relnamespace = v_ns and cl.relkind = 'm' and cl.relispopulated order by cl.relname
        loop
            v_out := v_out || format('REFRESH MATERIALIZED VIEW public.%I;', r.relname);
        end loop;
    else
        select count(*) into v_tables from pg_catalog.pg_class cl where cl.relnamespace = v_ns and cl.relkind = 'r';
    end if;

    -- ── Primary keys, unique, check and exclusion constraints ──────────
    v_out := v_out || array['', '-- Keys and constraints'];
    for r in
        select cl.relname, co.conname, pg_catalog.pg_get_constraintdef(co.oid) as def
          from pg_catalog.pg_constraint co
          join pg_catalog.pg_class cl on cl.oid = co.conrelid
         where cl.relnamespace = v_ns and cl.relkind = 'r' and co.contype in ('p', 'u', 'x', 'c')
           and co.conislocal
         order by cl.relname, case co.contype when 'p' then 0 when 'u' then 1 when 'x' then 2 else 3 end, co.conname
    loop
        v_out := v_out || format('ALTER TABLE ONLY public.%I ADD CONSTRAINT %I %s;', r.relname, r.conname, r.def);
    end loop;

    -- ── Indexes (not the ones a constraint created) ────────────────────
    v_out := v_out || array['', '-- Indexes'];
    for r in
        select pg_catalog.pg_get_indexdef(i.indexrelid) as def
          from pg_catalog.pg_index i
          join pg_catalog.pg_class ic on ic.oid = i.indexrelid
          join pg_catalog.pg_class tc on tc.oid = i.indrelid
         where tc.relnamespace = v_ns and tc.relkind in ('r', 'm')
           and not exists (select 1 from pg_catalog.pg_constraint co where co.conindid = i.indexrelid and co.contype in ('p', 'u', 'x'))
         order by tc.relname, ic.relname
    loop
        v_out := v_out || (r.def || ';');
    end loop;

    -- ── Foreign keys ───────────────────────────────────────────────────
    v_out := v_out || array['', '-- Foreign keys'];
    for r in
        select cl.relname, co.conname, pg_catalog.pg_get_constraintdef(co.oid) as def
          from pg_catalog.pg_constraint co
          join pg_catalog.pg_class cl on cl.oid = co.conrelid
         where cl.relnamespace = v_ns and co.contype = 'f'
         order by cl.relname, co.conname
    loop
        v_out := v_out || format('ALTER TABLE ONLY public.%I ADD CONSTRAINT %I %s;', r.relname, r.conname, r.def);
    end loop;

    -- ── Triggers ───────────────────────────────────────────────────────
    v_out := v_out || array['', '-- Triggers'];
    for r in
        select pg_catalog.pg_get_triggerdef(tg.oid, false) as def, tg.tgenabled, tc.relname, tg.tgname
          from pg_catalog.pg_trigger tg
          join pg_catalog.pg_class tc on tc.oid = tg.tgrelid
         where tc.relnamespace = v_ns and not tg.tgisinternal
         order by tc.relname, tg.tgname
    loop
        v_out := v_out || (r.def || ';');
        if r.tgenabled <> 'O' then
            v_out := v_out || format('ALTER TABLE public.%I %s TRIGGER %I;', r.relname,
                case r.tgenabled when 'D' then 'DISABLE' when 'R' then 'ENABLE REPLICA' else 'ENABLE ALWAYS' end, r.tgname);
        end if;
    end loop;

    -- ── Row level security ─────────────────────────────────────────────
    v_out := v_out || array['', '-- Row level security'];
    for r in
        select cl.relname, cl.relrowsecurity, cl.relforcerowsecurity
          from pg_catalog.pg_class cl
         where cl.relnamespace = v_ns and cl.relkind = 'r' and (cl.relrowsecurity or cl.relforcerowsecurity)
         order by cl.relname
    loop
        if r.relrowsecurity then v_out := v_out || format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY;', r.relname); end if;
        if r.relforcerowsecurity then v_out := v_out || format('ALTER TABLE public.%I FORCE ROW LEVEL SECURITY;', r.relname); end if;
    end loop;
    for r in
        select cl.relname, po.polname, po.polcmd, po.polpermissive,
               case when po.polroles = '{0}'::oid[] then 'PUBLIC'
                    else (select string_agg(pg_catalog.quote_ident(ro.rolname), ', ' order by ro.rolname)
                            from pg_catalog.pg_roles ro where ro.oid = any (po.polroles)) end as roles,
               pg_catalog.pg_get_expr(po.polqual, po.polrelid) as qual,
               pg_catalog.pg_get_expr(po.polwithcheck, po.polrelid) as chk
          from pg_catalog.pg_policy po
          join pg_catalog.pg_class cl on cl.oid = po.polrelid
         where cl.relnamespace = v_ns
         order by cl.relname, po.polname
    loop
        v_out := v_out || (format('CREATE POLICY %I ON public.%I AS %s FOR %s TO %s', r.polname, r.relname,
                               case when r.polpermissive then 'PERMISSIVE' else 'RESTRICTIVE' end,
                               case r.polcmd when 'r' then 'SELECT' when 'a' then 'INSERT' when 'w' then 'UPDATE' when 'd' then 'DELETE' else 'ALL' end,
                               r.roles)
                           || coalesce(' USING (' || r.qual || ')', '')
                           || coalesce(' WITH CHECK (' || r.chk || ')', '') || ';');
    end loop;

    -- ── Privileges (exactly as on the source: default grants on the
    --    target are revoked first, so nothing becomes MORE open) ────────
    v_out := v_out || array['', '-- Privileges'];
    for r in
        select cl.oid, cl.relname, cl.relkind, cl.relacl, cl.relowner
          from pg_catalog.pg_class cl
         where cl.relnamespace = v_ns and cl.relkind in ('r', 'v', 'm', 'S')
         order by cl.relname
    loop
        v_out := v_out || format('REVOKE ALL ON %s public.%I FROM PUBLIC%s;',
            case when r.relkind = 'S' then 'SEQUENCE' else 'TABLE' end, r.relname,
            coalesce((select string_agg(', ' || pg_catalog.quote_ident(ro.rolname), '' order by ro.rolname)
                        from pg_catalog.pg_roles ro where ro.rolname in ('anon', 'authenticated', 'service_role')), ''));
        for c in
            select case when x.grantee = 0 then 'PUBLIC' else pg_catalog.quote_ident(ro.rolname) end as who,
                   string_agg(x.privilege_type, ', ' order by x.privilege_type) as privs, x.is_grantable
              from pg_catalog.aclexplode(r.relacl) x
              left join pg_catalog.pg_roles ro on ro.oid = x.grantee
             where x.grantee <> r.relowner
             group by 1, x.is_grantable
             order by 1
        loop
            v_out := v_out || format('GRANT %s ON %s public.%I TO %s%s;', c.privs,
                case when r.relkind = 'S' then 'SEQUENCE' else 'TABLE' end, r.relname, c.who,
                case when c.is_grantable then ' WITH GRANT OPTION' else '' end);
        end loop;
    end loop;
    for r in
        select p.oid, p.prokind, p.proacl, p.proowner
          from pg_catalog.pg_proc p
         where p.pronamespace = v_ns and p.proacl is not null
           and not exists (select 1 from pg_catalog.pg_depend dp
                            where dp.classid = 'pg_catalog.pg_proc'::regclass and dp.objid = p.oid and dp.deptype = 'e')
         order by p.proname, p.oid
    loop
        v_out := v_out || format('REVOKE ALL ON %s %s FROM PUBLIC%s;',
            case when r.prokind = 'p' then 'PROCEDURE' else 'FUNCTION' end, r.oid::regprocedure,
            coalesce((select string_agg(', ' || pg_catalog.quote_ident(ro.rolname), '' order by ro.rolname)
                        from pg_catalog.pg_roles ro where ro.rolname in ('anon', 'authenticated', 'service_role')), ''));
        for c in
            select case when x.grantee = 0 then 'PUBLIC' else pg_catalog.quote_ident(ro.rolname) end as who, x.is_grantable
              from pg_catalog.aclexplode(r.proacl) x
              left join pg_catalog.pg_roles ro on ro.oid = x.grantee
             where x.grantee <> r.proowner and x.privilege_type = 'EXECUTE'
             order by 1
        loop
            v_out := v_out || format('GRANT EXECUTE ON %s %s TO %s%s;',
                case when r.prokind = 'p' then 'PROCEDURE' else 'FUNCTION' end, r.oid::regprocedure, c.who,
                case when c.is_grantable then ' WITH GRANT OPTION' else '' end);
        end loop;
    end loop;

    -- ── Realtime / logical replication publications ────────────────────
    for r in
        select pu.pubname, cl.relname
          from pg_catalog.pg_publication_rel pr
          join pg_catalog.pg_publication pu on pu.oid = pr.prpubid
          join pg_catalog.pg_class cl on cl.oid = pr.prrelid
         where cl.relnamespace = v_ns
         order by 1, 2
    loop
        v_out := v_out || format('DO $p$ BEGIN IF EXISTS (SELECT 1 FROM pg_catalog.pg_publication WHERE pubname = %L) THEN ALTER PUBLICATION %I ADD TABLE public.%I; END IF; END $p$;',
                                 r.pubname, r.pubname, r.relname);
    end loop;

    v_out := v_out || array['', 'COMMIT;', '',
        format('-- EssenciaSmile export complete: %s tables%s.', v_tables,
               case when p_include_data then format(', %s rows', v_rows) else ', structure only' end), ''];

    -- ── Header (written last, so it can list the row counts) ───────────
    return array_to_string(array[
        '-- =====================================================================',
        case when p_include_data then '--  EssenciaSmile — FULL DATABASE BACKUP (structure + all records)'
             else '--  EssenciaSmile — DATABASE SCHEMA (structure only, no records)' end,
        '--  Generated : ' || pg_catalog.to_char(pg_catalog.now() at time zone 'Asia/Manila', 'YYYY-MM-DD HH24:MI:SS') || ' (Asia/Manila)',
        '--  Server    : PostgreSQL ' || pg_catalog.current_setting('server_version'),
        '--  Scope     : schema public — ' || v_tables || ' tables',
        '--  Snapshot  : one consistent point in time (single read-only query)',
        case when p_include_data
             then '--  CONTAINS PATIENT AND STAFF PERSONAL DATA. Store it encrypted; never email or share it.'
             else '--  Contains NO table records.' end,
        '--',
        '--  Restore into an EMPTY public schema (e.g. a new Supabase project):',
        '--    psql "<connection string>" -v ON_ERROR_STOP=1 -f <this file>',
        '--  Runs as one transaction: any error rolls the whole restore back.',
        '--  Not included: auth / storage schemas, uploaded files, project settings.',
        '-- ====================================================================='
    ] || case when p_include_data then array['--', '--  Row counts:'] || v_manifest else '{}'::text[] end
      || array['', ''], chr(10))
      || array_to_string(v_out, chr(10));
end;
$fn$;

-- ---------------------------------------------------------------------
--  Server only: the PHP server calls these with the secret key
-- ---------------------------------------------------------------------
revoke all on function public.es_admin_backup_info()            from public, anon, authenticated;
revoke all on function public.es_admin_export_sql(boolean)      from public, anon, authenticated;
grant execute on function public.es_admin_backup_info()         to service_role;
grant execute on function public.es_admin_export_sql(boolean)   to service_role;

comment on function public.es_admin_export_sql(boolean) is
    'Settings > Database & Backup: whole public schema as SQL (false = structure only, true = + all rows). Server only.';

commit;

notify pgrst, 'reload schema';
