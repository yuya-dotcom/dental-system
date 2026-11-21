# EssenciaSmile — Notification system setup

Email, SMS (UniSMS), Web Push and SMS OTP for patients. This file lives outside
`project/`, so the FTP deploy never uploads it.

## How it fits together

| Piece | File |
|---|---|
| Central service (events, templates, dedupe, logging, retry) | `project/controllers/notification_service.php` |
| Hook called after every appointment write | `notif_appointment()` in `project/controllers/notification_helper.php` |
| Email (PHPMailer, Gmail SMTP) | `project/controllers/mailer.php` |
| SMS (UniSMS) | `project/controllers/sms_helper.php` |
| Web Push (VAPID + aes128gcm, no library) | `project/controllers/webpush.php`, `project/sw.js`, `project/manifest.json` |
| SMS OTP | `project/controllers/verify_phone_controller.php` |
| Patient settings (email / SMS / push switches) | `project/api/portal_notification_settings.php`, `project/assets/js/portal-notify.js` |
| Admin page (System › Notifications) | `project/notification-records.php`, `project/api/notification_admin.php` |
| Day-before reminders | `project/cron/send_appointment_reminders.php`, `.github/workflows/reminders.yml` |
| Database | `project/database/proposed/2026-11-13_notification_system.sql` |

Messages go out only **after** the appointment write succeeds, after the HTTP
response has been sent, and a provider failure never touches the appointment.
Each message is first claimed in `notification_logs` under a unique `dedupe_key`,
so double clicks, repeated cron runs and overlapping requests cannot send twice.

## 1. Database (required first)

Run `project/database/proposed/2026-11-13_notification_system.sql` in the Supabase SQL Editor.
It is idempotent and creates `notification_logs`, `notification_preferences`,
`notification_templates` (13 default rows), `push_subscriptions` and `otp_verifications`. All five
have RLS on with no policies, and anon/authenticated access is revoked, so only the server key can use them.

It also clears the old plain-text `patient_accounts.phone_otp_code` values and copies the reminder
history from `sms_logs`, so no reminder is sent twice.

**Order matters:** the current code already uses `otp_verifications`. Until this SQL is applied,
SMS verification answers "temporarily unavailable" and no appointment email/SMS/push is sent.
The admin page shows a setup notice instead of the log.

Verify:
```sql
select count(*) from notification_templates;          -- 13
select relrowsecurity from pg_class where relname = 'otp_verifications';  -- true
```

## 2. Secrets (`project/config/secrets.php`, git-ignored)

| Key | Purpose |
|---|---|
| `supabase_url`, `supabase_key` | already present (server `sb_secret_` key) |
| `UNISMS_API_KEY`, `UNISMS_SENDER_ID` (constants) | already present |
| `smtp_username`, `smtp_app_password` | already present (Gmail app password) |
| `app_base_url` | **set on the server** to `https://essenciasmile.online` (links in emails and push) |
| `vapid_public_key`, `vapid_private_key_pem`, `vapid_subject` | Web Push; already generated locally |
| `otp_pepper` | HMAC key for OTP hashes; already generated locally |

Use the **same** VAPID keys and `otp_pepper` everywhere. If you change the VAPID keys, every existing push subscription stops working.
To generate them on a fresh machine: `php project/cron/generate_vapid_keys.php --subject=mailto:<clinic address>`
(it adds missing keys without overwriting anything and prints only the public key).
`secrets.php` is excluded from the FTP deploy, so upload it to the server once by hand.

## 3. Day-before reminders (GitHub Actions)

InfinityFree has no cron, and its anti-bot page blocks scripted HTTP calls, so the job runs on
GitHub Actions (`.github/workflows/reminders.yml`). It runs daily at 09:00 Asia/Manila and processes the appointments for the next day.

Add these repository secrets (Settings › Secrets and variables › Actions):
`SUPABASE_URL`, `SUPABASE_SECRET_KEY`, `UNISMS_API_KEY`, `UNISMS_SENDER_ID`, `SMTP_USERNAME`,
`SMTP_APP_PASSWORD`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY_PEM` (the whole PEM, line breaks included),
`VAPID_SUBJECT`, `APP_BASE_URL`.

- Scheduled workflows only run from the default branch (`main`).
- Test first with **Actions › Appointment reminders › Run workflow** (dry run is ticked by default).
- On Actions, the log shows only appointment codes and counts. Names, numbers and errors are in the admin Notification Log.
- Each run also refreshes the UniSMS delivery status (`GET /api/sms/{reference_id}`) of SMS sent in the last 48 h.
- Backup option: `project/cron/run_reminders.bat` with Windows Task Scheduler on a machine that holds `secrets.php`.

## 4. Web Push

- Patients opt in from **Profile › Notifications** or the "Get appointment reminders" banner. Permission is never requested on page load.
- Requires HTTPS (the live site) or `http://localhost`.
- **iPhone/iPad:** iOS 16.4+ only, and only after **Share › Add to Home Screen** and opening the app from the Home Screen. The switch explains this on iOS.
- Android Chrome, desktop Chrome/Edge/Firefox and macOS Safari 16+ work in the browser.
- Expired subscriptions (HTTP 404/410 from the push service) are deleted automatically.
- The service worker does not cache pages.

## 5. Email

Appointment emails and the patient email-verification link both use PHPMailer over Gmail SMTP.
Patient email verification is **not** Supabase Auth OTP: it is a hashed, 24-hour link
(`project/controllers/verify_email_controller.php`), and this work leaves it unchanged. Supabase Auth
is used only for "Sign in with Google", which sends no emails, so there is no Supabase Auth email template or
SMTP setting to configure for the current flows.

If Supabase Auth email OTP is adopted later, configure **Custom SMTP** in Supabase
(Authentication › Emails › SMTP Settings). Supabase's built-in sender is heavily rate-limited and is not meant for
production. Edit the "Magic Link / OTP" template so it shows `{{ .Token }}`.

Gmail limits: about 500 recipients a day from a personal account, and messages come from
`essencia.smile@gmail.com`. That is enough for a small clinic chain; a sending domain with SPF/DKIM would improve deliverability.

## 6. Message rules

- SMS and push never contain services, treatments or notes. Templates only accept the
  placeholders `{first_name} {date} {time} {branch} {branch_phone} {code} {old_date} {old_time}`.
- OTP codes are never stored, logged or returned. Only an HMAC is kept, and UniSMS's echo of the
  message text is stripped before anything is saved.
- Patient preferences: when no row exists, email and SMS are on and push is off.
- Retry (admin page) works only for `failed` rows, at most 3 attempts in total, and only while the appointment still
  matches the message. A network timeout leaves the row "In Progress" and it is never auto-retried,
  because the SMS may already have been delivered.

## 7. Staff Notification Center (Owner / Admin / Dentist)

The navbar bell, and **System › Notifications › Notification Center** (`project/notification-center.php`),
show clinic events to staff. This is separate from the patient channels above. It sends no email or SMS,
and nothing is shared with patient notifications.

| Piece | File |
|---|---|
| Events, visibility rules, read state, inventory sweep | `project/controllers/staff_notifications.php` |
| API for the bell and the page | `project/api/staff_notifications.php` |
| Bell + page UI | `project/partials/navbar.php`, `project/assets/js/es-notifications.js`, `project/assets/css/es-notifications.css` |
| Database | `project/database/proposed/2026-11-19_staff_notification_center.sql` |

**Run the SQL first.** Until you do, the bell only shows the "Waiting for You" inventory tasks, the page shows a
setup notice, and no events are recorded. Nothing errors.

- **Events:** appointment requested / booked / approved / cancelled / rescheduled / no-show; low stock,
  out of stock, expiring, expired, manual stock adjustment, purchase delivery; payment received, e-wallet payment,
  payment failed, payment reversed (removing a recorded payment is the "refund"); new portal account, medical
  information submitted; treatment added / completed / changed (status, dentist, service, tooth, date or stage only);
  branch hours changed, branch closed (Inactive or a closed-day exception), special opening;
  new / low-rated (1–2★) / complaint (1–2★ with a comment) feedback.
- **Priority:** critical = out of stock, payment failed. Important = new requests, cancellations,
  reschedules, low stock, expiry, payment reversal, patient medical info, branch hours and closures, low-rated feedback
  and complaints. Everything else is informational.
- **Who sees what** (checked on the server for every list, count and "mark as read"):
  - Owner: every branch.
  - Admin: own branch plus clinic-wide events (registrations). No feedback.
  - Dentist: appointments, treatments, stock alerts, branch operations and patient medical info for their branch.
    No billing, adjustments, deliveries, registrations or feedback.
  - Owner-assigned sidebar modules also apply. For example, an admin limited to Appointments sees only
    appointment and branch notifications.
  - Nobody is notified about their own action.
- **No duplicates:** every event has a unique `dedupe_key`. Stock alerts are re-checked after every stock change and by a
  sweep (at most every 10 min, triggered by the bell). An alert closes when the item recovers, so a later shortage
  is notified once more.
- **Read state** is per user. Mark All as Read stores a timestamp, so it is a single write no matter how many
  notifications there are. Notifications older than 30 days never count as unread.
- Feedback notifications never identify the patient or the visit. Medical-info notifications never contain the information itself.
