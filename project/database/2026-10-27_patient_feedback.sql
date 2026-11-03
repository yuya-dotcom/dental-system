-- =============================================================
--  EssenciaSmile — Patient feedback (one entry per appointment)
--  Run once in Supabase → SQL Editor. Safe to re-run (IF NOT EXISTS),
--  creates new objects only, never alters or drops existing ones.
--
--  Access model: same as patient_notifications. The PHP backend uses the
--  service role key (server-side only); RLS is enabled with NO policies,
--  so browsers can never read or write this table directly.
--    • Patients submit through api/portal_feedback.php (session-scoped).
--    • The Owner reads it through feedback-records.php (owner-only).
--
--  appointment_feedback_appointment_uq is what makes "one feedback per
--  appointment" hold even for concurrent or hand-crafted requests.
-- =============================================================

create table if not exists public.patient_feedback (
    feedback_id     bigint generated always as identity primary key,
    appointment_id  integer  not null
                    references public.appointments(appointment_id) on delete restrict,
    patient_id      integer  not null
                    references public.patients(patient_id) on delete restrict,
    account_id      integer
                    references public.patient_accounts(account_id) on delete set null,
    branch_id       integer
                    references public.branches(branch_id) on delete set null,
    category        text     not null check (category in ('dentist','clinic')),
    -- Resolved on the server from the appointment (never from the browser);
    -- null for clinic feedback.
    dentist_id      integer
                    references public.dentists(dentist_id) on delete set null,
    rating          smallint not null check (rating between 1 and 5),
    message         text     check (message is null or char_length(message) <= 1000),
    created_at      timestamptz not null default now(),
    constraint patient_feedback_appointment_uq unique (appointment_id),
    constraint patient_feedback_dentist_ck check (
        (category = 'dentist' and dentist_id is not null) or
        (category = 'clinic'  and dentist_id is null)
    )
);

create index if not exists patient_feedback_created_idx
    on public.patient_feedback (created_at desc);

create index if not exists patient_feedback_patient_idx
    on public.patient_feedback (patient_id);

alter table public.patient_feedback enable row level security;
