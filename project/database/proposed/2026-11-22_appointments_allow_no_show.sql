-- =====================================================================
--  EssenciaSmile — allow the 'no_show' appointment status
--  Run in: Supabase Dashboard > SQL Editor. Safe to run more than once.
--
--  WHY
--    The app marks ended, never-checked-in appointments as 'no_show'
--    (controllers/appointment_lifecycle.php → aptSweepNoShows, plus staff
--    "Mark as No Show"), and the double-booking index already ignores
--    'no_show' (2026-10-18_appointment_slot_unique_active_only.sql).
--    But the live CHECK constraint appointments_status_check was never
--    given that value, so every sweep fails with:
--      new row for relation "appointments" violates check constraint
--      "appointments_status_check"
--    and missed appointments stay "pending"/"confirmed" forever.
--
--  WHAT IT DOES
--    Reads the CURRENT allowed values from the live constraint, adds
--    'no_show', and recreates the constraint with that list — so no value
--    that is allowed today is lost. Does nothing if 'no_show' is already
--    allowed. Changes no rows.
-- =====================================================================

begin;

do $$
declare
    v_def    text;
    v_values text[];
    v_list   text;
begin
    select pg_get_constraintdef(c.oid)
      into v_def
      from pg_constraint c
     where c.conrelid = 'public.appointments'::regclass
       and c.conname  = 'appointments_status_check';

    if v_def is null then
        raise notice 'appointments_status_check does not exist — nothing to change (no_show is already accepted).';
        return;
    end if;

    -- every quoted literal in the definition = the allowed values
    select array_agg(distinct m[1] order by m[1])
      into v_values
      from regexp_matches(v_def, '''([^'']+)''', 'g') as m;

    if 'no_show' = any (coalesce(v_values, '{}')) then
        raise notice 'no_show is already allowed: %', v_def;
        return;
    end if;
    if v_values is null or array_length(v_values, 1) is null then
        raise exception 'Could not read the allowed values from: % — change it by hand.', v_def;
    end if;

    v_values := array_append(v_values, 'no_show'::text);
    select string_agg(quote_literal(v), ', ' order by v) into v_list from unnest(v_values) v;

    alter table public.appointments drop constraint appointments_status_check;
    execute format('alter table public.appointments add constraint appointments_status_check check (status in (%s))', v_list);
    raise notice 'appointments_status_check now allows: %', v_list;
end $$;

commit;
