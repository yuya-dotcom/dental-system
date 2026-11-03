-- =============================================================
--  PROPOSED — NOT APPLIED. Run in the Supabase SQL Editor only after approval.
--
--  Inventory: database-level stock floor + low-stock alert history.
--  Non-destructive: adds a constraint, a trigger and one new table.
--  No existing rows are changed (verified 2026-10-27: no negative stock;
--  one item at 0 stays at 0 and is shown as unavailable).
--
--  The application already enforces the floor and uses compare-and-swap
--  updates (controllers/stock_helper.php); this adds the same rule in the
--  database so no other client (SQL editor, future API) can bypass it.
-- =============================================================

-- 1) Never negative (all current rows already satisfy this)
alter table public.inventory
    add constraint inventory_stock_nonnegative check (stock_quantity >= 0) not valid;
alter table public.inventory validate constraint inventory_stock_nonnegative;

-- 2) Floor: a DECREASE may not leave less than 1 unit.
--    Increases, and rows already at 0, are unaffected (no invented stock).
create or replace function public.inventory_enforce_floor()
returns trigger language plpgsql as $$
begin
    if new.stock_quantity < old.stock_quantity and new.stock_quantity < 1 then
        raise exception 'Stock floor: "%" must keep at least 1 unit (was %, tried %).',
            old.item_name, old.stock_quantity, new.stock_quantity
            using errcode = 'check_violation';
    end if;
    return new;
end $$;

drop trigger if exists inventory_enforce_floor on public.inventory;
create trigger inventory_enforce_floor
    before update of stock_quantity on public.inventory
    for each row execute function public.inventory_enforce_floor();

-- 3) Low-stock alert history: one OPEN alert per item at a time.
--    opened when stock falls to/below min_stock, resolved when it rises above.
create table if not exists public.inventory_stock_alerts (
    alert_id       bigint generated always as identity primary key,
    item_id        bigint not null references public.inventory(item_id) on delete cascade,
    branch_id      bigint references public.branches(branch_id) on delete set null,
    level          text   not null check (level in ('low', 'out')),
    stock_at_open  integer not null,
    min_stock      integer not null,
    opened_at      timestamptz not null default now(),
    resolved_at    timestamptz,
    stock_at_close integer
);
create unique index if not exists inventory_stock_alerts_one_open
    on public.inventory_stock_alerts (item_id) where resolved_at is null;
alter table public.inventory_stock_alerts enable row level security; -- server (service role) only

create or replace function public.inventory_track_alerts()
returns trigger language plpgsql as $$
declare is_low boolean := new.stock_quantity <= coalesce(new.min_stock, 0) or new.stock_quantity <= 0;
begin
    if is_low then
        -- open (no duplicate thanks to the partial unique index) or update level
        insert into public.inventory_stock_alerts (item_id, branch_id, level, stock_at_open, min_stock)
        values (new.item_id, new.branch_id, case when new.stock_quantity <= 0 then 'out' else 'low' end,
                new.stock_quantity, coalesce(new.min_stock, 0))
        on conflict (item_id) where resolved_at is null
        do update set level = excluded.level;
    else
        update public.inventory_stock_alerts
           set resolved_at = now(), stock_at_close = new.stock_quantity
         where item_id = new.item_id and resolved_at is null;
    end if;
    return new;
end $$;

drop trigger if exists inventory_track_alerts on public.inventory;
create trigger inventory_track_alerts
    after insert or update of stock_quantity, min_stock on public.inventory
    for each row execute function public.inventory_track_alerts();

-- Seed open alerts for items that are low right now (read-only on inventory)
insert into public.inventory_stock_alerts (item_id, branch_id, level, stock_at_open, min_stock)
select item_id, branch_id, case when stock_quantity <= 0 then 'out' else 'low' end, stock_quantity, coalesce(min_stock, 0)
  from public.inventory
 where status <> 'inactive' and (stock_quantity <= coalesce(min_stock, 0) or stock_quantity <= 0)
on conflict do nothing;
