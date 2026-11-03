-- Phone OTP wrong-guess counter (controllers/verify_phone_controller.php).
-- A verified phone now completes registration on its own, so each 6-digit
-- code allows at most 5 guesses. Without this column the code still works
-- but falls back to one guess per code. Additive, non-destructive, safe to re-run.

ALTER TABLE public.patient_accounts
    ADD COLUMN IF NOT EXISTS phone_otp_attempts smallint NOT NULL DEFAULT 0;

-- Refresh PostgREST's schema cache so the API sees the new column immediately.
NOTIFY pgrst, 'reload schema';
