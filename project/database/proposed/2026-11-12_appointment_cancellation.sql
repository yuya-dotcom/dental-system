-- =====================================================================
--  EssenciaSmile — appointment cancellation details              MIGRATION
--  Run in: Supabase Dashboard > SQL Editor. NOT applied yet.
--
--  WHAT IT CHANGES
--    Adds six nullable columns to public.appointments:
--      cancelled_at             when the cancellation was submitted
--      cancelled_by_type        'patient' (Patient Portal) | 'staff'
--      cancelled_by_user_id     staff user who cancelled  (users.user_id)
--      cancelled_by_account_id  portal account that cancelled (patient_accounts.account_id)
--      cancelled_by_name        name shown in "Cancelled By" at the time
--                               (e.g. "Maria Santos (Admin)")
--      cancellation_reason      the reason that was typed (max 500 chars)
--    Plus one BEFORE UPDATE trigger:
--      • a row that is already cancelled keeps its cancellation details —
--        a later unrelated update can't overwrite or clear them;
--      • a row that becomes cancelled without details (any path that does
--        not fill them) still gets cancelled_at = now(); who/why stay NULL
--        and the app shows "Not recorded" — nothing is made up.
--
--  WHY IT IS SAFE
--    • Nullable columns, no default rewrite, no existing row changed.
--    • The app works before AND after this runs: until then it keeps the
--      details in the appointment notes line ("Cancelled by … on … —
--      Reason: …") and reads them from there; after it, it writes the
--      columns too and reads them first. Older cancellations keep showing
--      what their notes line recorded, or "Not recorded".
--    • RLS/grants: new columns inherit the table's current (deny-all) RLS
--      and grants. The trigger function is SECURITY INVOKER with a fixed
--      search_path and is owned by postgres; EXECUTE is revoked from
--      PUBLIC/anon/authenticated like the other trigger functions.
-- =====================================================================
begin;

alter table public.appointments
  add column if not exists cancelled_at            timestamptz,
  add column if not exists cancelled_by_type       text,
  add column if not exists cancelled_by_user_id    integer,
  add column if not exists cancelled_by_account_id integer,
  add column if not exists cancelled_by_name       text,
  add column if not exists cancellation_reason     text;

do $$
begin
  if not exists (select 1 from pg_constraint where conname = 'appointments_cancelled_by_type_chk') then
    alter table public.appointments
      add constraint appointments_cancelled_by_type_chk
      check (cancelled_by_type is null or cancelled_by_type in ('patient', 'staff'));
  end if;
  if not exists (select 1 from pg_constraint where conname = 'appointments_cancellation_reason_len_chk') then
    alter table public.appointments
      add constraint appointments_cancellation_reason_len_chk
      check (cancellation_reason is null or char_length(cancellation_reason) <= 500);
  end if;
  if not exists (select 1 from pg_constraint where conname = 'appointments_cancelled_by_user_fk') then
    alter table public.appointments
      add constraint appointments_cancelled_by_user_fk
      foreign key (cancelled_by_user_id) references public.users (user_id) on delete set null;
  end if;
  if not exists (select 1 from pg_constraint where conname = 'appointments_cancelled_by_account_fk') then
    alter table public.appointments
      add constraint appointments_cancelled_by_account_fk
      foreign key (cancelled_by_account_id) references public.patient_accounts (account_id) on delete set null;
  end if;
end $$;

create or replace function public.fn_appointments_keep_cancellation()
returns trigger
language plpgsql
security invoker
set search_path = public, pg_temp
as $$
begin
  if old.status = 'cancelled' and new.status = 'cancelled' then
    -- Already cancelled: the original details stay as they were
    new.cancelled_at            := coalesce(old.cancelled_at, new.cancelled_at);
    new.cancelled_by_type       := coalesce(old.cancelled_by_type, new.cancelled_by_type);
    new.cancelled_by_user_id    := coalesce(old.cancelled_by_user_id, new.cancelled_by_user_id);
    new.cancelled_by_account_id := coalesce(old.cancelled_by_account_id, new.cancelled_by_account_id);
    new.cancelled_by_name       := coalesce(old.cancelled_by_name, new.cancelled_by_name);
    new.cancellation_reason     := coalesce(old.cancellation_reason, new.cancellation_reason);
  elsif new.status = 'cancelled' and old.status is distinct from 'cancelled' and new.cancelled_at is null then
    new.cancelled_at := now();
  end if;
  return new;
end $$;

revoke all on function public.fn_appointments_keep_cancellation() from public, anon, authenticated;

drop trigger if exists trg_appointments_keep_cancellation on public.appointments;
create trigger trg_appointments_keep_cancellation
  before update on public.appointments
  for each row execute function public.fn_appointments_keep_cancellation();

commit;

-- Tell the API about the new columns right away (Supabase also does this
-- on its own within a few seconds).
notify pgrst, 'reload schema';

-- Check (read-only): expect 6 rows
-- select column_name, data_type from information_schema.columns
--  where table_schema = 'public' and table_name = 'appointments'
--    and column_name like 'cancel%' order by column_name;
