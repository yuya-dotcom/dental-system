-- =============================================================
--  2026-11-16 — System Evaluation 2 (Owner side)
--  Dentist / Branch / Account Records — new columns.
--
--  Run once in the Supabase SQL editor. Safe to re-run (IF NOT EXISTS).
--  Nothing is dropped or renamed: the existing single-value columns
--  (dentists.full_name, branches.address) stay and are still written,
--  so every other page keeps reading them unchanged.
--
--  Until this runs, the three pages keep working: the new fields are
--  shown empty and saving them reports that this update is needed.
-- =============================================================

-- ── Dentists: name parts, age, gender, email ──────────────────
alter table public.dentists
  add column if not exists first_name  text,
  add column if not exists middle_name text,
  add column if not exists last_name   text,
  add column if not exists suffix      text,
  add column if not exists age         smallint,
  add column if not exists gender      text,
  add column if not exists email       text;

do $$ begin
  alter table public.dentists add constraint dentists_age_range
    check (age is null or age between 18 and 100);
exception when duplicate_object then null; end $$;

do $$ begin
  alter table public.dentists add constraint dentists_gender_values
    check (gender is null or gender in ('Male', 'Female', 'Non-binary', 'Another gender', 'Prefer not to say'));
exception when duplicate_object then null; end $$;

-- ── Branches: structured address (address stays = the joined text) ──
alter table public.branches
  add column if not exists street   text,
  add column if not exists province text,
  add column if not exists city     text,
  add column if not exists barangay text,
  add column if not exists zip_code text;

-- ── Users: contact number + sidebar module permissions ────────
--  allowed_modules NULL  = every module the role allows (current behaviour).
--  allowed_modules = '{appointments,treatments}' = only those modules.
--  The role still caps access: a module the role can't use is never granted.
alter table public.users
  add column if not exists contact_number  text,
  add column if not exists gender          text,
  add column if not exists allowed_modules text[];

do $$ begin
  alter table public.users add constraint users_gender_values
    check (gender is null or gender in ('Male', 'Female', 'Non-binary'));
exception when duplicate_object then null; end $$;

-- PostgREST: reload the schema cache so the new columns are visible at once
notify pgrst, 'reload schema';
