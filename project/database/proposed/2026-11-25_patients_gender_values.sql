-- 2026-11-25 — let patients.gender hold every option the Patient Portal offers.
--
-- The live constraint patients_gender_check only allows Male / Female /
-- Prefer not to say, but Create Account offers "Non-binary" and Edit Profile
-- also offers "Another gender" (GENDER_OPTIONS in controllers/name_helper.php).
-- Those values made the patients insert/update fail: registration now rolls
-- back with "We could not finish creating your account" instead of silently
-- losing the profile, and this migration removes the failure itself.
--
-- Widening only: every row that passes the old check passes the new one.

alter table public.patients drop constraint if exists patients_gender_check;

alter table public.patients add constraint patients_gender_check
  check (gender is null or gender in ('Male', 'Female', 'Non-binary', 'Another gender', 'Prefer not to say'));

notify pgrst, 'reload schema';
