-- =============================================================
--  EssenciaSmile — Outgoing SMS log (day-before appointment reminders)
--  Run once in Supabase → SQL Editor. Safe to re-run (IF NOT EXISTS),
--  creates new objects only, never alters or drops existing data.
--
--  dedupe_key is UNIQUE: one row per reminder, e.g.
--      reminder:{appointment_id}:{Y-m-d}T{H:i}
--  so a cron run that repeats (or overlaps) never sends twice.
--
--  Access model: written only by the PHP backend with the service role
--  key (server-side). RLS is enabled with NO policies, so anon /
--  authenticated clients get no access through the API.
-- =============================================================

create table if not exists public.sms_logs (
    sms_id            bigint generated always as identity primary key,
    dedupe_key        text     not null unique,
    sms_type          text     not null
                      check (sms_type in ('appointment_reminder')),
    appointment_id    integer  references public.appointments(appointment_id),
    patient_id        integer  references public.patients(patient_id),
    phone             text,
    message           text,
    status            text     not null default 'sending'
                      check (status in ('sending', 'sent', 'failed', 'skipped')),
    attempts          smallint not null default 1,
    error             text,
    provider_response jsonb,
    created_at        timestamptz not null default now(),
    updated_at        timestamptz not null default now()
);

-- The table may already exist from an earlier run; make sure the unique
-- constraint on dedupe_key is present (deduplication depends on it).
do $$
begin
    if not exists (
        select 1
        from pg_constraint c
        join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any (c.conkey)
        where c.conrelid = 'public.sms_logs'::regclass
          and c.contype in ('u', 'p')
          and array_length(c.conkey, 1) = 1
          and a.attname = 'dedupe_key'
    ) then
        alter table public.sms_logs
            add constraint sms_logs_dedupe_key_key unique (dedupe_key);
    end if;
end $$;

create index if not exists sms_logs_appointment_idx
    on public.sms_logs (appointment_id);

alter table public.sms_logs enable row level security;


-- -------------------------------------------------------------
--  Verification (read-only): should list a UNIQUE constraint on
--  dedupe_key and the two CHECK constraints.
-- -------------------------------------------------------------
-- select conname, contype, pg_get_constraintdef(oid)
-- from pg_constraint
-- where conrelid = 'public.sms_logs'::regclass;
