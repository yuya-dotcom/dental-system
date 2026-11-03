-- =============================================================
--  2026-10-20 — Discount vouchers on invoices (applied in Billing)
--  Additive only: one new table, two new invoice columns.
--  Run once in the Supabase SQL Editor.
--
--  How the amounts relate after this:
--    invoices.subtotal_amount  = charge before discounts (procedure prices)
--    invoices.discount_amount  = sum of invoice_discounts.discount_amount
--    invoices.total_amount     = subtotal_amount - discount_amount  (amount owed)
--    invoices.balance          = total_amount - amount_paid  (unchanged)
-- =============================================================

-- 1. Charge before discounts + total discount on each invoice
ALTER TABLE public.invoices
  ADD COLUMN IF NOT EXISTS subtotal_amount numeric,
  ADD COLUMN IF NOT EXISTS discount_amount numeric NOT NULL DEFAULT 0
    CHECK (discount_amount >= 0);

-- Existing invoices had no discounts: their subtotal is their total
UPDATE public.invoices
   SET subtotal_amount = total_amount
 WHERE subtotal_amount IS NULL;

-- 2. Vouchers applied to an invoice (several per invoice, each voucher once)
CREATE TABLE IF NOT EXISTS public.invoice_discounts (
  invoice_discount_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  invoice_id      integer NOT NULL REFERENCES public.invoices(invoice_id) ON DELETE CASCADE,
  voucher_id      integer NOT NULL REFERENCES public.discount_vouchers(voucher_id),
  -- Snapshot of the voucher when applied (later voucher edits don't change past invoices)
  voucher_code    varchar NOT NULL,
  voucher_name    varchar,
  discount_type   varchar NOT NULL CHECK (discount_type IN ('percentage', 'fixed')),
  discount_value  numeric NOT NULL,
  discount_amount numeric NOT NULL CHECK (discount_amount >= 0),
  applied_by      integer REFERENCES public.users(user_id),
  applied_at      timestamptz NOT NULL DEFAULT now(),
  UNIQUE (invoice_id, voucher_id)
);

-- Usage counts per voucher (usage_limit checks)
CREATE INDEX IF NOT EXISTS invoice_discounts_voucher_idx
  ON public.invoice_discounts (voucher_id);
