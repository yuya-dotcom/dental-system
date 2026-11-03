-- Parent / Guardian details for the patient portal profile (My Profile → Basic Info).
-- Additive and non-destructive: nullable columns only, no data is changed or removed.
-- Run once in the Supabase SQL editor. Safe to re-run.

ALTER TABLE public.patient_profiles
    ADD COLUMN IF NOT EXISTS guardian_name     text,
    ADD COLUMN IF NOT EXISTS guardian_relation text,
    ADD COLUMN IF NOT EXISTS guardian_phone    text,
    ADD COLUMN IF NOT EXISTS guardian_address  text;

-- Refresh PostgREST's schema cache so the API sees the new columns immediately.
NOTIFY pgrst, 'reload schema';
