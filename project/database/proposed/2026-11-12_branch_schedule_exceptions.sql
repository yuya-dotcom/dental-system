-- =====================================================================
--  EssenciaSmile — branch schedule exceptions                   MIGRATION
--  Run in: Supabase Dashboard > SQL Editor. NOT applied yet.
--
--  WHAT IT ADDS
--    public.branch_schedule_exceptions — one row per (branch, date) that
--    differs from the branch's usual weekly schedule (branches.operating_days):
--      is_open = true   open on a day it is normally closed (e.g. a Sunday)
--      is_open = false  closed on a day it is normally open (e.g. a Monday
--                       off, a holiday)
--      reason           optional note shown to staff / patients (max 200)
--
--  WHO USES IT
--    • Booking (controllers/branch_config.php → dateUnavailableReason):
--      portal and admin calendars + every booking API follow the override.
--    • Predictive analytics: the history of which days each branch was
--      really open, so a closed day is not mistaken for zero demand and
--      future forecasts skip closed days. Past rows are therefore KEPT.
--
--  WHY IT IS SAFE
--    • New table only; nothing existing changes.
--    • The app works before AND after this runs: until it exists, booking
--      uses operating_days alone (as today) and Branch Records shows a
--      "run the SQL file" message in the exceptions window.
--    • RLS enabled with no policies (deny-all for anon/authenticated) like
--      the other server-only tables; the PHP server key bypasses RLS.
-- =====================================================================
begin;

create table if not exists public.branch_schedule_exceptions (
  exception_id   bigint generated always as identity primary key,
  branch_id      integer not null references public.branches (branch_id) on delete cascade,
  exception_date date    not null,
  is_open        boolean not null,
  reason         text,
  created_by     integer references public.users (user_id) on delete set null,
  created_at     timestamptz not null default now(),
  constraint branch_schedule_exceptions_one_per_day unique (branch_id, exception_date),
  constraint branch_schedule_exceptions_reason_len_chk
    check (reason is null or char_length(reason) <= 200)
);

create index if not exists branch_schedule_exceptions_date_idx
  on public.branch_schedule_exceptions (exception_date);

alter table public.branch_schedule_exceptions enable row level security; -- server (service role) only
revoke all on public.branch_schedule_exceptions from anon, authenticated;

commit;
