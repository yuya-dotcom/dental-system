-- =============================================================
--  EssenciaSmile — Patient notifications + clinic announcements
--  Run once in Supabase → SQL Editor. Safe to re-run (IF NOT EXISTS),
--  creates new objects only, never alters or drops existing ones.
--
--  Access model: the PHP backend talks to Supabase with the service
--  role key (server-side only), which bypasses RLS. RLS is enabled with
--  NO policies for anon/authenticated, so these tables can never be read
--  or written directly from a browser with the anon key. Per-patient
--  scoping is enforced in api/portal_notifications.php via the session.
-- =============================================================

create table if not exists public.patient_notifications (
    notification_id bigint generated always as identity primary key,
    account_id      integer not null
                    references public.patient_accounts(account_id) on delete cascade,
    category        text    not null
                    check (category in ('appointment','billing','treatment','account','announcement')),
    event_type      text    not null,
    title           text    not null,
    message         text    not null,
    link_page       text,               -- portal page id: appointments, billing, treatments, ...
    dedupe_key      text    not null,   -- one notification per (account, event)
    is_read         boolean not null default false,
    read_at         timestamptz,
    created_at      timestamptz not null default now(),
    constraint patient_notifications_dedupe_uq unique (account_id, dedupe_key)
);

create index if not exists patient_notifications_account_created_idx
    on public.patient_notifications (account_id, created_at desc);

create index if not exists patient_notifications_account_unread_idx
    on public.patient_notifications (account_id) where is_read = false;

alter table public.patient_notifications enable row level security;


-- Clinic announcements (branch closures, holiday schedules, hours changes).
-- Staff insert rows here; patients receive them in the bell on next load.
create table if not exists public.clinic_announcements (
    announcement_id bigint generated always as identity primary key,
    title           text    not null,
    message         text    not null,
    branch_id       integer references public.branches(branch_id) on delete set null, -- null = all branches
    is_active       boolean not null default true,
    starts_at       timestamptz not null default now(),
    ends_at         timestamptz,
    created_at      timestamptz not null default now()
);

create index if not exists clinic_announcements_active_idx
    on public.clinic_announcements (is_active, starts_at desc);

alter table public.clinic_announcements enable row level security;

-- Example (edit before running; do not publish invented information):
-- insert into public.clinic_announcements (title, message, branch_id, ends_at)
-- values ('Holiday closure', 'All branches are closed on Dec 25. Regular hours resume Dec 26.', null, '2026-12-26');
