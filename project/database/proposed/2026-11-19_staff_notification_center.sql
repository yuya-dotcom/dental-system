-- =====================================================================
--  EssenciaSmile — Staff Notification Center (Owner / Admin / Dentist)
--  Run in: Supabase Dashboard > SQL Editor, BEFORE deploying the code.
--  Safe to run more than once (IF NOT EXISTS everywhere).
--  Until it runs, the staff bell shows only the "Waiting for You" tasks and
--  no event is recorded: every code path checks that these tables exist.
--
--  ADDS
--    staff_notifications       one row per clinic EVENT (not per recipient).
--                              Who may see it is decided on the server at read
--                              time from category + audience (roles) + branch,
--                              the reader's sidebar modules and actor_user_id
--                              (nobody is notified about their own action).
--                              dedupe_key is UNIQUE: re-running an event (double
--                              click, retry, overlapping sweeps) can't duplicate.
--                              Stock alerts use '…:open' keys that are renamed to
--                              '…:closed:<id>' when the item recovers, so a new
--                              shortage later is notified again, once.
--    staff_notification_reads  per-user read marks (Mark as Read).
--    staff_notification_state  per-user "Mark All as Read" time: everything
--                              created up to read_all_at counts as read.
--
--  REUSES (no duplicate tables)
--    patient_notifications / notification_logs stay the PATIENT side (portal
--    bell, email, SMS, push) — unchanged.
--
--  SECURITY
--    RLS on, no policies, anon/authenticated revoked — server-only, read and
--    written with the server key (like notification_logs). Messages never
--    hold medical details, payment details beyond an amount, or the identity
--    of a feedback author.
-- =====================================================================
begin;

create table if not exists public.staff_notifications (
  notification_id bigint generated always as identity primary key,
  dedupe_key      text        not null unique,
  category        text        not null
                  check (category in ('appointments', 'inventory', 'billing', 'patients',
                                      'treatments', 'branch', 'feedback')),
  event_type      text        not null,
  priority        text        not null default 'info'
                  check (priority in ('critical', 'important', 'info')),
  title           text        not null,
  message         text        not null,
  branch_id       integer     references public.branches (branch_id) on delete set null,
  link_url        text,                       -- relative staff page, e.g. billing-records.php?q=INV-0012
  audience        text[]      not null default '{owner,admin,dentist}',
  actor_user_id   integer,                    -- staff user who caused it (not notified); null = patient / system
  created_at      timestamptz not null default now()
);
create index if not exists staff_notifications_created_idx  on public.staff_notifications (created_at desc);
create index if not exists staff_notifications_branch_idx   on public.staff_notifications (branch_id, created_at desc);
create index if not exists staff_notifications_category_idx on public.staff_notifications (category, created_at desc);

create table if not exists public.staff_notification_reads (
  user_id         integer     not null references public.users (user_id) on delete cascade,
  notification_id bigint      not null references public.staff_notifications (notification_id) on delete cascade,
  read_at         timestamptz not null default now(),
  primary key (user_id, notification_id)
);

create table if not exists public.staff_notification_state (
  user_id     integer     primary key references public.users (user_id) on delete cascade,
  read_all_at timestamptz not null default now()
);

alter table public.staff_notifications      enable row level security;
alter table public.staff_notification_reads enable row level security;
alter table public.staff_notification_state enable row level security;
revoke all on public.staff_notifications, public.staff_notification_reads, public.staff_notification_state
  from anon, authenticated;

commit;

-- Check (should list the three tables with rowsecurity = true):
-- select tablename, rowsecurity from pg_tables
--  where schemaname = 'public' and tablename like 'staff_notification%';
