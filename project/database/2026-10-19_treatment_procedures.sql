-- =============================================================
--  2026-10-19 — Tooth-centered Start Treatment
--  Additive only: one new table, one new nullable column.
--  Run once in the Supabase SQL Editor.
-- =============================================================

-- 1. Procedure lines per treatment (tooth + service + surfaces + price snapshot)
CREATE TABLE IF NOT EXISTS public.treatment_procedures (
  procedure_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  treatment_id integer NOT NULL REFERENCES public.treatments(treatment_id),
  service_id   integer NOT NULL REFERENCES public.services(service_id),
  tooth_number varchar,                     -- NULL for services that don't need a tooth
  surfaces     varchar,                     -- e.g. 'MO' (B/M/O/L/D), optional
  unit_price   numeric NOT NULL,            -- configured base_price when the line was added
  status       varchar NOT NULL DEFAULT 'planned'
               CHECK (status IN ('planned', 'completed', 'cancelled')),
  created_by   integer REFERENCES public.users(user_id),
  created_at   timestamptz NOT NULL DEFAULT now()
);

-- One line per treatment + service + tooth (prevents duplicate charges)
CREATE UNIQUE INDEX IF NOT EXISTS treatment_procedures_uniq
  ON public.treatment_procedures (treatment_id, service_id, COALESCE(tooth_number, ''));

-- 2. Optional dental-chart outcome a service suggests on completion
--    (suggestion only — the dentist must confirm before the chart changes)
ALTER TABLE public.services
  ADD COLUMN IF NOT EXISTS chart_outcome varchar
  CHECK (chart_outcome IS NULL OR chart_outcome IN
         ('healthy', 'filled', 'decay', 'crown', 'impacted', 'impacted_ne', 'missing'));
