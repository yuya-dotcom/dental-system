-- Patient Portal "Forgot Password" tokens (portal-login.php → portal-reset-password.php).
-- Same pattern as email verification: only a SHA-256 hash of the emailed token
-- is stored, with a UTC expiry. Additive and non-destructive: nullable columns only.
-- Run once in the Supabase SQL editor. Safe to re-run.

ALTER TABLE public.patient_accounts
    ADD COLUMN IF NOT EXISTS password_reset_token      text,
    ADD COLUMN IF NOT EXISTS password_reset_expires_at timestamp without time zone;

CREATE INDEX IF NOT EXISTS patient_accounts_password_reset_token_idx
    ON public.patient_accounts (password_reset_token)
    WHERE password_reset_token IS NOT NULL;

-- Refresh PostgREST's schema cache so the API sees the new columns immediately.
NOTIFY pgrst, 'reload schema';
