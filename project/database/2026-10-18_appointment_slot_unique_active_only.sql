-- Let a cancelled / no-show appointment's time slot be booked again.
--
-- The old constraint made (branch_id, appointment_date, appointment_time) unique
-- across ALL rows, so a cancelled appointment kept its slot locked forever, even
-- though availability (getBookedRanges) already ignores cancelled and no_show
-- rows. Booking that "free" slot failed with "that time slot was just taken".
--
-- The replacement keeps the same double-booking protection, but only for
-- appointments that still occupy the chair. No data is changed or removed.
-- Run once in the Supabase SQL editor. Safe to re-run.

ALTER TABLE public.appointments
    DROP CONSTRAINT IF EXISTS appointments_branch_id_appointment_date_appointment_time_key;
-- (in case it was created as a plain unique index instead of a constraint)
DROP INDEX IF EXISTS public.appointments_branch_id_appointment_date_appointment_time_key;

CREATE UNIQUE INDEX IF NOT EXISTS appointments_branch_id_appointment_date_appointment_time_active_key
    ON public.appointments (branch_id, appointment_date, appointment_time)
    WHERE status NOT IN ('cancelled', 'no_show');

NOTIFY pgrst, 'reload schema';
