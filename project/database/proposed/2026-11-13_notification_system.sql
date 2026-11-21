-- =====================================================================
--  EssenciaSmile — Notification system (email, SMS, push, SMS OTP)
--  Run in: Supabase Dashboard > SQL Editor, BEFORE deploying the code.
--  Safe to run more than once (IF NOT EXISTS / ON CONFLICT everywhere).
--
--  ADDS
--    notification_logs         one row per message per channel (email | sms | push).
--                              dedupe_key is UNIQUE: claiming the row before sending
--                              is what guarantees a message is never sent twice.
--    notification_preferences  per portal account: email / sms / push on or off.
--                              No row = defaults (email on, sms on, push off).
--    notification_templates    editable subject / body per event + channel
--                              (the code has built-in defaults if a row is missing).
--    push_subscriptions        Web Push endpoints per portal account.
--    otp_verifications         SMS OTP codes, stored ONLY as an HMAC hash, with
--                              expiry, attempt counter and send history (rate limits).
--
--  REUSES (no duplicate tables)
--    patient_notifications / clinic_announcements  in-portal bell (unchanged)
--    sms_logs  kept as history; its reminder rows are copied into
--              notification_logs so tomorrow's reminders are not sent twice.
--  No notification_queue table: the nightly reminder job computes what is
--  due from appointments, and dedupe keys make every run safe to repeat.
--
--  SECURITY
--    All five tables: RLS on, no policies, anon/authenticated revoked —
--    server-only, read and written with the server key (like sms_logs).
--    Plain-text SMS codes are cleared from patient_accounts.phone_otp_code
--    (step 6); the new code never writes that column again.
-- =====================================================================
begin;

-- ── 1. notification_logs ─────────────────────────────────────────────
create table if not exists public.notification_logs (
  log_id                bigint generated always as identity primary key,
  dedupe_key            text        not null unique,
  notification_type     text        not null
                        check (notification_type in ('appointment_confirmed', 'appointment_cancelled',
                               'appointment_rescheduled', 'appointment_reminder', 'otp', 'announcement')),
  channel               text        not null check (channel in ('email', 'sms', 'push')),
  patient_id            integer     references public.patients (patient_id) on delete set null,
  account_id            integer     references public.patient_accounts (account_id) on delete set null,
  appointment_id        integer     references public.appointments (appointment_id) on delete set null,
  branch_id             integer     references public.branches (branch_id) on delete set null,
  recipient             text,                 -- email address, +63 number, or push endpoint host
  subject               text,
  message               text,                 -- never contains an OTP code
  status                text        not null default 'sending'
                        check (status in ('sending', 'sent', 'failed', 'skipped')),
  attempts              smallint    not null default 1,
  provider_reference_id text,
  provider_status       text,                 -- SMS: UniSMS pending | retrying | sent | failed
  status_checked_at     timestamptz,
  provider_response     jsonb,                -- reference / status only, never the message text
  failure_reason        text,
  sent_at               timestamptz,
  created_at            timestamptz not null default now(),
  updated_at            timestamptz not null default now()
);
create index if not exists notification_logs_created_idx     on public.notification_logs (created_at desc);
create index if not exists notification_logs_status_idx      on public.notification_logs (status, created_at desc);
create index if not exists notification_logs_appointment_idx on public.notification_logs (appointment_id);
create index if not exists notification_logs_patient_idx     on public.notification_logs (patient_id);
create index if not exists notification_logs_branch_idx      on public.notification_logs (branch_id, created_at desc);

-- ── 2. notification_preferences ──────────────────────────────────────
create table if not exists public.notification_preferences (
  account_id    integer     primary key references public.patient_accounts (account_id) on delete cascade,
  email_enabled boolean     not null default true,
  sms_enabled   boolean     not null default true,
  push_enabled  boolean     not null default false,
  updated_at    timestamptz not null default now()
);

-- ── 3. notification_templates ────────────────────────────────────────
-- Placeholders: {first_name} {date} {time} {branch} {branch_phone} {code}
--               {old_date} {old_time} {reason_line} {title} {message}
create table if not exists public.notification_templates (
  template_id       bigint generated always as identity primary key,
  notification_type text        not null,
  channel           text        not null check (channel in ('email', 'sms', 'push')),
  subject           text,                    -- email subject / push title
  message_body      text        not null,
  is_active         boolean     not null default true,
  updated_by        text,
  updated_at        timestamptz not null default now(),
  constraint notification_templates_type_channel_uq unique (notification_type, channel)
);

insert into public.notification_templates (notification_type, channel, subject, message_body) values
  ('appointment_confirmed', 'email', 'Your appointment is confirmed',
   'Hi {first_name}, your appointment at EssenciaSmile {branch} is confirmed. We look forward to seeing you.'),
  ('appointment_confirmed', 'sms', null,
   'EssenciaSmile: Hi {first_name}, your appointment on {date} at {time}, {branch} branch is confirmed. Ref {code}.'),
  ('appointment_confirmed', 'push', 'Appointment confirmed',
   'Your appointment on {date} at {time} is confirmed.'),
  ('appointment_cancelled', 'email', 'Your appointment was cancelled',
   'Hi {first_name}, your appointment at EssenciaSmile {branch} has been cancelled.{reason_line} You can book a new schedule anytime in the Patient Portal.'),
  ('appointment_cancelled', 'sms', null,
   'EssenciaSmile: Hi {first_name}, your appointment on {date} at {time}, {branch} branch has been cancelled. Ref {code}.'),
  ('appointment_cancelled', 'push', 'Appointment cancelled',
   'Your appointment on {date} at {time} has been cancelled.'),
  ('appointment_rescheduled', 'email', 'Your appointment was rescheduled',
   'Hi {first_name}, your appointment at EssenciaSmile {branch} has a new schedule.'),
  ('appointment_rescheduled', 'sms', null,
   'EssenciaSmile: Hi {first_name}, your appointment is moved to {date} at {time}, {branch} branch. Ref {code}.'),
  ('appointment_rescheduled', 'push', 'Appointment rescheduled',
   'Your appointment is moved to {date} at {time}.'),
  ('appointment_reminder', 'email', 'Reminder: your appointment is tomorrow',
   'Hi {first_name}, this is a reminder of your appointment tomorrow at EssenciaSmile {branch}.'),
  ('appointment_reminder', 'sms', null,
   'EssenciaSmile: Hi {first_name}, reminder of your appointment tomorrow, {date} at {time}, {branch} branch. Ref {code}.'),
  ('appointment_reminder', 'push', 'Appointment tomorrow',
   'Reminder: your appointment is tomorrow, {date} at {time}.'),
  ('announcement', 'push', '{title}', '{message}')
on conflict (notification_type, channel) do nothing;

-- ── 4. push_subscriptions ────────────────────────────────────────────
create table if not exists public.push_subscriptions (
  subscription_id bigint generated always as identity primary key,
  account_id      integer     not null references public.patient_accounts (account_id) on delete cascade,
  endpoint        text        not null unique,
  p256dh          text        not null,
  auth            text        not null,
  user_agent      text,
  failure_count   smallint    not null default 0,
  last_success_at timestamptz,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);
create index if not exists push_subscriptions_account_idx on public.push_subscriptions (account_id);

-- ── 5. otp_verifications (SMS OTP only — email verification is a separate, hashed link) ──
create table if not exists public.otp_verifications (
  otp_id        bigint generated always as identity primary key,
  account_id    integer     not null references public.patient_accounts (account_id) on delete cascade,
  purpose       text        not null check (purpose in ('phone_verify', 'phone_change')),
  mobile_number text        not null,         -- +639XXXXXXXXX the code was texted to
  otp_hash      text        not null,         -- HMAC-SHA256, never the code itself
  expires_at    timestamptz not null,
  attempts      smallint    not null default 0,
  max_attempts  smallint    not null default 5,
  request_ip    text,
  last_sent_at  timestamptz not null default now(),
  verified_at   timestamptz,
  consumed_at   timestamptz,                  -- set on success, on replacement, or when burned
  created_at    timestamptz not null default now()
);
create index if not exists otp_verifications_account_idx on public.otp_verifications (account_id, created_at desc);
create index if not exists otp_verifications_mobile_idx  on public.otp_verifications (mobile_number, created_at desc);
create index if not exists otp_verifications_ip_idx      on public.otp_verifications (request_ip, created_at desc);
create index if not exists otp_verifications_active_idx  on public.otp_verifications (account_id, purpose)
  where consumed_at is null;

-- ── 6. Remove plain-text SMS codes left from the old flow ────────────
update public.patient_accounts set phone_otp_code = null where phone_otp_code is not null;

-- ── 7. Carry existing SMS reminder history over (no double reminders) ─
insert into public.notification_logs (dedupe_key, notification_type, channel, patient_id, appointment_id,
                                      recipient, message, status, attempts, provider_response, failure_reason,
                                      sent_at, created_at, updated_at)
select 'appointment_reminder:sms:' || substring(s.dedupe_key from 10),   -- 'reminder:<id>:<slot>'
       'appointment_reminder', 'sms', s.patient_id, s.appointment_id, s.phone, s.message,
       s.status, s.attempts, s.provider_response, s.error,
       case when s.status = 'sent' then s.updated_at end, s.created_at, s.updated_at
from public.sms_logs s
where s.dedupe_key like 'reminder:%'
on conflict (dedupe_key) do nothing;

-- ── 8. Server-only access ────────────────────────────────────────────
alter table public.notification_logs        enable row level security;
alter table public.notification_preferences enable row level security;
alter table public.notification_templates   enable row level security;
alter table public.push_subscriptions       enable row level security;
alter table public.otp_verifications        enable row level security;
revoke all on public.notification_logs, public.notification_preferences, public.notification_templates,
              public.push_subscriptions, public.otp_verifications
  from anon, authenticated;

commit;

notify pgrst, 'reload schema';
