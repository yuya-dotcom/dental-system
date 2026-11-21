-- READ-ONLY. Lists portal accounts that were most likely CREATED by "Sign in with Google"
-- before 2026-11-11 (the date new Google-only accounts started getting the '!nopw:' marker
-- in patient_accounts.password_hash — see controllers/portal_loaders.php).
--
-- Why: those older accounts got a random password hash nobody knows, so the portal still
-- shows them an enabled Change Password (which asks for a current password they never had).
-- Email-registered accounts that later used Google keep their real password and must NOT
-- be changed — this query only lists candidates; it modifies nothing.
--
-- Heuristic: the Supabase Auth Google identity existed before (or within 10 minutes of) the
-- portal account row, and no Forgot Password reset is pending. Review every row by hand
-- before deciding anything.

SELECT pa.account_id,
       pa.email,
       pa.created_at                         AS portal_account_created,
       u.created_at                          AS google_identity_created,
       pa.created_at - u.created_at          AS gap,
       left(pa.password_hash, 6)             AS hash_prefix,      -- '$2y$10' = ordinary hash, '!nopw:' = already marked
       (pa.password_reset_token IS NOT NULL) AS reset_pending
FROM public.patient_accounts pa
JOIN auth.users u
  ON lower(u.email) = lower(pa.email)
WHERE (u.raw_app_meta_data ->> 'provider' = 'google'
       OR u.raw_app_meta_data -> 'providers' ? 'google')
  AND pa.password_hash NOT LIKE '!nopw:%'
  AND pa.created_at >= u.created_at - interval '10 minutes'
ORDER BY pa.created_at DESC;
