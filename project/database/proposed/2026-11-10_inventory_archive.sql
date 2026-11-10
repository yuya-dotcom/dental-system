-- =====================================================================
--  EssenciaSmile — inventory archive column                    MIGRATION
--  Run in: Supabase Dashboard > SQL Editor, BEFORE deploying the code that
--  uses it (and before testing it locally — local XAMPP uses the live DB).
--
--  WHAT IT CHANGES
--    Adds one nullable column, inventory.archived_at.
--      NULL      = active item (every existing item stays active)
--      timestamp = archived: hidden from lists/dropdowns, history kept
--    Plus a small index for the "active items of a branch" lookups.
--
--  WHY IT IS SAFE
--    • Nullable, no default rewrite, no data changed.
--    • The code that is live today never reads or writes this column, so
--      it keeps working unchanged before and after the deploy.
--    • RLS/grants: the table keeps its current deny-all RLS and grants;
--      a new column inherits them.
-- =====================================================================
begin;

alter table public.inventory
  add column if not exists archived_at timestamptz;

create index if not exists inventory_active_branch_idx
  on public.inventory (branch_id)
  where archived_at is null;

commit;

-- Tell the API about the new column right away (Supabase also does this
-- automatically; harmless to repeat).
notify pgrst, 'reload schema';

-- VERIFY (should return one row: archived_at | timestamp with time zone | YES)
-- select column_name, data_type, is_nullable
--   from information_schema.columns
--  where table_schema = 'public' and table_name = 'inventory' and column_name = 'archived_at';
--
-- ROLLBACK (only before the new code is deployed):
--   drop index if exists public.inventory_active_branch_idx;
--   alter table public.inventory drop column if exists archived_at;
