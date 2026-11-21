-- =====================================================================
--  EssenciaSmile — Inventory enhancement                         MIGRATION
--  Run in: Supabase Dashboard > SQL Editor (one run, whole file).
--  Local XAMPP uses the LIVE database, so run this BEFORE testing the new
--  inventory code locally.
--
--  WHAT IT ADDS (everything is additive — no table, column or row is removed)
--    1. suppliers                         supplier records
--    2. inventory_items                   item MASTER (name, category, unit,
--                                         cost, supplier, expiry tracking)
--    3. inventory  (+ master_id, reorder_qty)
--                                         existing rows become the per-branch
--                                         balance of a master item; backfilled
--                                         by item name (15 items × 3 branches + 1 archived)
--    4. inventory_movements (+ type, stock before/after, reference, user,
--                            batch, request_key)  old rows keep their data;
--                                         their type is derived from reason
--    5. inventory_batches                 lot / expiry / quantity per batch
--    6. inv_apply_movement()              ONE atomic stock change: locks the
--                                         item row, checks the rules, updates
--                                         stock + status + batches and writes
--                                         the movement in the same transaction
--    7. stock_transfers (+ items)         branch-to-branch transfers
--    8. purchase_requests / purchase_orders / purchase_receipts (+ items)
--    9. inventory_stock_alerts            low / out-of-stock alert history,
--                                         one OPEN alert per item (no dupes)
--   10. RLS on every new table (server-only, like the existing tables) and
--       EXECUTE on the new functions for service_role only.
--
--  WHY IT IS SAFE
--    • Existing ids, foreign keys and columns are untouched; every existing
--      query keeps working (inventory.item_name/category are kept and kept in
--      sync with the master by a trigger).
--    • The new PHP code falls back to the old behaviour while this file has
--      not been run (it detects the missing function).
--    • Stock floor: the app keeps "1 unit must remain" for normal use;
--      write-offs (adjustment / damaged / expired / wastage) may reach 0.
--      The database only enforces "never negative".
-- =====================================================================
begin;

-- ---------------------------------------------------------------------
-- 1) Suppliers
-- ---------------------------------------------------------------------
create table if not exists public.suppliers (
    supplier_id     bigint generated always as identity primary key,
    supplier_name   text        not null check (btrim(supplier_name) <> ''),
    contact_person  text,
    phone           text,
    email           text,
    address         text,
    notes           text,
    is_active       boolean     not null default true,
    created_at      timestamptz not null default now(),
    updated_at      timestamptz not null default now()
);
create unique index if not exists suppliers_name_uq on public.suppliers (lower(btrim(supplier_name)));

-- ---------------------------------------------------------------------
-- 2) Item master
-- ---------------------------------------------------------------------
create table if not exists public.inventory_items (
    master_id     bigint generated always as identity primary key,
    item_name     text        not null check (btrim(item_name) <> ''),
    category      text,
    unit          text        not null default 'pcs',
    unit_cost     numeric(12,2) check (unit_cost is null or unit_cost >= 0),
    supplier_id   bigint      references public.suppliers(supplier_id) on delete set null,
    track_expiry  boolean     not null default false,
    created_at    timestamptz not null default now(),
    updated_at    timestamptz not null default now()
);
create unique index if not exists inventory_items_name_uq on public.inventory_items (lower(btrim(item_name)));

-- ---------------------------------------------------------------------
-- 3) inventory = branch balance of a master item
-- ---------------------------------------------------------------------
alter table public.inventory add column if not exists master_id   bigint references public.inventory_items(master_id);
alter table public.inventory add column if not exists reorder_qty integer not null default 0;
do $$ begin
    alter table public.inventory add constraint inventory_reorder_qty_nonneg check (reorder_qty >= 0);
exception when duplicate_object then null; end $$;
do $$ begin
    alter table public.inventory add constraint inventory_stock_nonnegative check (stock_quantity >= 0) not valid;
exception when duplicate_object then null; end $$;
alter table public.inventory validate constraint inventory_stock_nonnegative;

-- Backfill: one master per distinct (trimmed, case-insensitive) item name
insert into public.inventory_items (item_name, category)
select distinct on (lower(btrim(item_name))) btrim(item_name), category
  from public.inventory
 order by lower(btrim(item_name)), (archived_at is not null), item_id
on conflict do nothing;

update public.inventory i
   set master_id = m.master_id
  from public.inventory_items m
 where i.master_id is null
   and lower(btrim(i.item_name)) = lower(btrim(m.item_name));

create index if not exists inventory_master_idx on public.inventory (master_id);
-- One ACTIVE balance row per item per branch (archived rows may repeat)
create unique index if not exists inventory_branch_master_active_uq
    on public.inventory (branch_id, master_id)
 where master_id is not null and archived_at is null;

-- Any insert that arrives without a master (old code paths) is linked to /
-- creates the master by name, so the master never falls behind.
create or replace function public.inventory_link_master()
returns trigger language plpgsql as $$
declare v_master bigint;
begin
    if new.master_id is null and new.item_name is not null then
        select master_id into v_master from public.inventory_items
         where lower(btrim(item_name)) = lower(btrim(new.item_name));
        if v_master is null then
            insert into public.inventory_items (item_name, category)
            values (btrim(new.item_name), new.category)
            returning master_id into v_master;
        end if;
        new.master_id := v_master;
    end if;
    return new;
end $$;
drop trigger if exists inventory_link_master on public.inventory;
create trigger inventory_link_master
    before insert on public.inventory
    for each row execute function public.inventory_link_master();

-- Master name / category changes are copied to every branch row
-- (existing pages, the forecast job and treatments read inventory.item_name).
create or replace function public.inventory_items_sync()
returns trigger language plpgsql as $$
begin
    if new.item_name is distinct from old.item_name or new.category is distinct from old.category then
        update public.inventory
           set item_name = new.item_name, category = new.category, updated_at = now()
         where master_id = new.master_id;
    end if;
    return new;
end $$;
drop trigger if exists inventory_items_sync on public.inventory_items;
create trigger inventory_items_sync
    after update on public.inventory_items
    for each row execute function public.inventory_items_sync();

-- ---------------------------------------------------------------------
-- 4) Movement details
-- ---------------------------------------------------------------------
alter table public.inventory_movements add column if not exists movement_type text;
alter table public.inventory_movements add column if not exists stock_before  integer;
alter table public.inventory_movements add column if not exists stock_after   integer;
alter table public.inventory_movements add column if not exists reference_no  text;
alter table public.inventory_movements add column if not exists user_id       bigint;
alter table public.inventory_movements add column if not exists batch_id      bigint;
alter table public.inventory_movements add column if not exists batch_detail  jsonb;
alter table public.inventory_movements add column if not exists request_key   text;

-- Type of the existing rows, from their reason (before/after stay NULL: unknown)
update public.inventory_movements set movement_type = case
        when reason ilike 'used in treatment%' or reason ilike 'used in appointment%' then 'usage'
        when reason ilike 'reversed%'                                               then 'reversal'
        when quantity_change >= 0                                                   then 'stock_in'
        else 'stock_out' end
 where movement_type is null;

do $$ begin
    alter table public.inventory_movements add constraint inventory_movements_type_chk check (movement_type in (
        'stock_in','stock_out','adjustment','damaged','expired','wastage',
        'usage','reversal','transfer_out','transfer_in','purchase'));
exception when duplicate_object then null; end $$;

-- A repeated submission (double click, retry) can never log twice
create unique index if not exists inventory_movements_request_key_uq
    on public.inventory_movements (request_key) where request_key is not null;
create index if not exists inventory_movements_item_idx on public.inventory_movements (item_id, created_at desc);
create index if not exists inventory_movements_type_idx on public.inventory_movements (movement_type, movement_date);

-- ---------------------------------------------------------------------
-- 5) Batches (lot / expiry). Only items with track_expiry use them in the UI,
--    but any item may have batches. Units not in a batch = "no batch info".
--    Invariant kept by inv_apply_movement: sum(remaining) <= stock_quantity.
-- ---------------------------------------------------------------------
create table if not exists public.inventory_batches (
    batch_id            bigint generated always as identity primary key,
    item_id             bigint      not null references public.inventory(item_id) on delete cascade,
    branch_id           bigint      references public.branches(branch_id) on delete set null,
    lot_number          text,
    expiry_date         date,
    manufactured_date   date,
    quantity_received   integer     not null check (quantity_received > 0),
    quantity_remaining  integer     not null check (quantity_remaining >= 0),
    source              text        not null default 'manual'
                                    check (source in ('opening','manual','purchase','transfer')),
    reference_no        text,
    received_at         timestamptz not null default now(),
    created_at          timestamptz not null default now(),
    check (quantity_remaining <= quantity_received),
    check (manufactured_date is null or expiry_date is null or manufactured_date <= expiry_date)
);
create index if not exists inventory_batches_item_idx   on public.inventory_batches (item_id) where quantity_remaining > 0;
create index if not exists inventory_batches_expiry_idx on public.inventory_batches (expiry_date) where quantity_remaining > 0;

do $$ begin
    alter table public.inventory_movements add constraint inventory_movements_batch_fk
        foreign key (batch_id) references public.inventory_batches(batch_id) on delete set null;
exception when duplicate_object then null; end $$;

-- ---------------------------------------------------------------------
-- 6) The one atomic stock change
--    p_change      signed quantity (+ in, − out)
--    p_type        movement type (see the check above)
--    p_allow_zero  true for write-offs: may take the item to 0; otherwise a
--                  decrease must leave at least 1 unit (the clinic's floor)
--    p_batch_id    decrease: take from this batch only
--    p_batches     increase: [{lot_number, expiry_date, manufactured_date,
--                  quantity, source}] — new batches (sum ≤ p_change; the
--                  rest is stock without batch info)
--    p_request_key same key twice = the first result is returned, nothing new
--  Decreases without a batch use FEFO: unexpired batches (earliest expiry
--  first) → units without batch info → expired batches. Type 'expired'
--  takes expired batches first.
--  Returns {ok, movement_id, before, after, duplicate?} or {ok:false, code, message}.
-- ---------------------------------------------------------------------
create or replace function public.inv_apply_movement(
    p_item_id       bigint,
    p_change        integer,
    p_type          text,
    p_reason        text,
    p_user_id       bigint  default null,
    p_user_name     text    default null,
    p_reference     text    default null,
    p_batch_id      bigint  default null,
    p_batches       jsonb   default null,
    p_request_key   text    default null,
    p_allow_zero    boolean default false,
    p_movement_date date    default null
) returns jsonb
language plpgsql security definer set search_path = public as $$
declare
    r          public.inventory%rowtype;
    v_before   integer;
    v_after    integer;
    v_need     integer;
    v_take     integer;
    v_sum      integer;
    v_untrack  integer;
    v_detail   jsonb := '[]'::jsonb;
    v_mov      bigint;
    v_bid      bigint;
    v_unit     text;
    v_pass     integer;
    v_in_batch integer := 0;
    b          record;
    e          jsonb;
    v_dup      record;
begin
    if p_request_key is not null then
        select movement_id, stock_before, stock_after into v_dup
          from public.inventory_movements where request_key = p_request_key;
        if found then
            return jsonb_build_object('ok', true, 'duplicate', true, 'movement_id', v_dup.movement_id,
                                      'before', v_dup.stock_before, 'after', v_dup.stock_after);
        end if;
    end if;
    if p_change is null or p_change = 0 then
        return jsonb_build_object('ok', false, 'code', 'zero', 'message', 'Quantity must not be zero.');
    end if;
    if p_type is null or p_type not in ('stock_in','stock_out','adjustment','damaged','expired','wastage',
                                        'usage','reversal','transfer_out','transfer_in','purchase') then
        return jsonb_build_object('ok', false, 'code', 'type', 'message', 'Unknown movement type.');
    end if;

    select * into r from public.inventory where item_id = p_item_id for update;
    if not found then
        return jsonb_build_object('ok', false, 'code', 'not_found', 'message', 'Item not found.');
    end if;
    if r.archived_at is not null then
        return jsonb_build_object('ok', false, 'code', 'archived',
            'message', format('"%s" is archived. Restore it in Inventory first.', r.item_name));
    end if;
    select coalesce(m.unit, 'pcs') into v_unit from public.inventory_items m where m.master_id = r.master_id;
    v_unit := coalesce(v_unit, 'pcs');

    v_before := r.stock_quantity;
    v_after  := v_before + p_change;
    if v_after < 0 then
        return jsonb_build_object('ok', false, 'code', 'insufficient', 'before', v_before,
            'message', format('"%s" has only %s %s in stock (requested %s).', r.item_name, v_before, v_unit, -p_change));
    end if;
    if p_change < 0 and not p_allow_zero and v_after < 1 then
        return jsonb_build_object('ok', false, 'code', 'floor', 'before', v_before,
            'message', format('"%s" must keep at least 1 %s in stock. Available: %s, requested: %s, usable now: %s.',
                              r.item_name, v_unit, v_before, -p_change, greatest(v_before - 1, 0)));
    end if;

    begin   -- sub-block: any error below undoes everything, incl. a racing duplicate key
        if p_change > 0 and p_batches is not null and jsonb_typeof(p_batches) = 'array' then
            for e in select * from jsonb_array_elements(p_batches) loop
                v_take := coalesce((e->>'quantity')::integer, 0);
                continue when v_take <= 0;
                v_in_batch := v_in_batch + v_take;
                if v_in_batch > p_change then
                    raise exception 'Batch quantities (%) exceed the quantity received (%).', v_in_batch, p_change
                        using errcode = 'check_violation';
                end if;
                insert into public.inventory_batches (item_id, branch_id, lot_number, expiry_date, manufactured_date,
                                                      quantity_received, quantity_remaining, source, reference_no)
                values (p_item_id, r.branch_id, nullif(btrim(e->>'lot_number'), ''),
                        nullif(e->>'expiry_date', '')::date, nullif(e->>'manufactured_date', '')::date,
                        v_take, v_take, coalesce(nullif(e->>'source', ''), 'manual'), p_reference)
                returning batch_id into v_bid;
                v_detail := v_detail || jsonb_build_object('batch_id', v_bid, 'lot_number', nullif(btrim(e->>'lot_number'), ''),
                                                           'expiry_date', nullif(e->>'expiry_date', ''), 'quantity', v_take);
            end loop;
        elsif p_change < 0 then
            v_need := -p_change;
            if p_batch_id is not null then
                select * into b from public.inventory_batches
                 where batch_id = p_batch_id and item_id = p_item_id for update;
                if not found then
                    raise exception 'That batch does not belong to this item.' using errcode = 'check_violation';
                end if;
                if b.quantity_remaining < v_need then
                    raise exception 'Batch % has only % left.', coalesce(b.lot_number, '#' || b.batch_id), b.quantity_remaining
                        using errcode = 'check_violation';
                end if;
                update public.inventory_batches set quantity_remaining = quantity_remaining - v_need where batch_id = b.batch_id;
                v_detail := v_detail || jsonb_build_object('batch_id', b.batch_id, 'lot_number', b.lot_number,
                                                           'expiry_date', b.expiry_date, 'quantity', v_need);
                v_need := 0;
            else
                select coalesce(sum(quantity_remaining), 0) into v_sum
                  from public.inventory_batches where item_id = p_item_id and quantity_remaining > 0;
                v_untrack := greatest(v_before - v_sum, 0);
                -- pass 1: unexpired (or expired, for type 'expired'); pass 2: no batch info; pass 3: the rest
                for v_pass in 1..3 loop
                    exit when v_need <= 0;
                    if v_pass = 2 then
                        v_take := least(v_untrack, v_need);
                        v_need := v_need - v_take;
                        continue;
                    end if;
                    for b in
                        select * from public.inventory_batches
                         where item_id = p_item_id and quantity_remaining > 0
                           and ( (v_pass = 1 and p_type <> 'expired' and (expiry_date is null or expiry_date >= current_date))
                              or (v_pass = 1 and p_type =  'expired' and expiry_date < current_date)
                              or (v_pass = 3) )
                         order by expiry_date nulls last, batch_id
                         for update
                    loop
                        exit when v_need <= 0;
                        v_take := least(b.quantity_remaining, v_need);
                        update public.inventory_batches set quantity_remaining = quantity_remaining - v_take where batch_id = b.batch_id;
                        v_detail := v_detail || jsonb_build_object('batch_id', b.batch_id, 'lot_number', b.lot_number,
                                                                   'expiry_date', b.expiry_date, 'quantity', v_take);
                        v_need := v_need - v_take;
                    end loop;
                end loop;
            end if;
        end if;

        update public.inventory
           set stock_quantity = v_after,
               status = case when v_after <= 0 then 'out_of_stock'
                             when v_after <= coalesce(min_stock, 0) then 'low_stock'
                             else 'in_stock' end,
               updated_at = now()
         where item_id = p_item_id;

        insert into public.inventory_movements (item_id, branch_id, quantity_change, reason, performed_by,
                movement_date, created_at, movement_type, stock_before, stock_after, reference_no, user_id,
                batch_id, batch_detail, request_key)
        values (p_item_id, r.branch_id, p_change, coalesce(nullif(btrim(p_reason), ''), p_type),
                coalesce(nullif(btrim(p_user_name), ''), 'System'),
                coalesce(p_movement_date, (now() at time zone 'Asia/Manila')::date), now(), p_type, v_before, v_after,
                nullif(btrim(p_reference), ''), p_user_id,
                case when jsonb_array_length(v_detail) = 1 then (v_detail->0->>'batch_id')::bigint end,
                case when jsonb_array_length(v_detail) > 0 then v_detail end,
                p_request_key)
        returning movement_id into v_mov;
    exception
        when unique_violation then
            select movement_id, stock_before, stock_after into v_dup
              from public.inventory_movements where request_key = p_request_key;
            if found then
                return jsonb_build_object('ok', true, 'duplicate', true, 'movement_id', v_dup.movement_id,
                                          'before', v_dup.stock_before, 'after', v_dup.stock_after);
            end if;
            raise;
        when check_violation then
            return jsonb_build_object('ok', false, 'code', 'rule', 'before', v_before, 'message', sqlerrm);
    end;

    return jsonb_build_object('ok', true, 'movement_id', v_mov, 'before', v_before, 'after', v_after,
                              'batches', v_detail);
end $$;

-- ---------------------------------------------------------------------
-- Helper: the active balance row of a master item in a branch, created
-- (stock 0) when the branch does not stock it yet. Used by transfers and
-- purchase receiving.
-- ---------------------------------------------------------------------
create or replace function public.inv_branch_item(p_master_id bigint, p_branch_id bigint)
returns bigint language plpgsql security definer set search_path = public as $$
declare v_id bigint; m public.inventory_items%rowtype; v_next integer;
begin
    select item_id into v_id from public.inventory
     where master_id = p_master_id and branch_id = p_branch_id and archived_at is null
     order by item_id limit 1;
    if v_id is not null then return v_id; end if;

    select * into m from public.inventory_items where master_id = p_master_id;
    if not found then raise exception 'Item master % not found.', p_master_id; end if;

    perform pg_advisory_xact_lock(hashtext('inventory_item_code'));
    select coalesce(max(nullif(regexp_replace(item_code, '\D', '', 'g'), '')::integer), 0) + 1 into v_next
      from public.inventory;
    insert into public.inventory (item_code, item_name, category, branch_id, stock_quantity, min_stock,
                                  status, master_id, reorder_qty, created_at, updated_at)
    values ('ITEM-' || lpad(v_next::text, 4, '0'), m.item_name, m.category, p_branch_id, 0, 0,
            'out_of_stock', p_master_id, 0, now(), now())
    returning item_id into v_id;
    return v_id;
end $$;

-- ---------------------------------------------------------------------
-- 7) Branch-to-branch transfers
--    pending → approved (owner) → in_transit (stock leaves the source)
--            → received (stock arrives at the destination)
--    pending / approved → rejected (owner) or cancelled (requester / owner)
-- ---------------------------------------------------------------------
create sequence if not exists public.stock_transfer_no_seq;
create table if not exists public.stock_transfers (
    transfer_id         bigint generated always as identity primary key,
    transfer_no         text not null unique default ('TRF-' || lpad(nextval('public.stock_transfer_no_seq')::text, 5, '0')),
    from_branch_id      bigint not null references public.branches(branch_id),
    to_branch_id        bigint not null references public.branches(branch_id),
    status              text   not null default 'pending'
                               check (status in ('pending','approved','in_transit','received','rejected','cancelled')),
    reason              text   not null check (btrim(reason) <> ''),
    notes               text,
    requested_by        bigint, requested_by_name  text, requested_at  timestamptz not null default now(),
    approved_by         bigint, approved_by_name   text, approved_at   timestamptz,
    dispatched_by       bigint, dispatched_by_name text, dispatched_at timestamptz,
    received_by         bigint, received_by_name   text, received_at   timestamptz,
    rejected_by         bigint, rejected_by_name   text, rejected_at   timestamptz,
    rejection_reason    text,
    cancelled_by        bigint, cancelled_by_name  text, cancelled_at  timestamptz,
    request_key         text unique,                      -- a repeated submit creates nothing new
    updated_at          timestamptz not null default now(),
    check (from_branch_id <> to_branch_id)
);
create index if not exists stock_transfers_status_idx on public.stock_transfers (status);

create table if not exists public.stock_transfer_items (
    line_id         bigint generated always as identity primary key,
    transfer_id     bigint  not null references public.stock_transfers(transfer_id) on delete cascade,
    master_id       bigint  not null references public.inventory_items(master_id),
    from_item_id    bigint  not null references public.inventory(item_id),
    to_item_id      bigint  references public.inventory(item_id),
    quantity        integer not null check (quantity > 0),
    batch_id        bigint  references public.inventory_batches(batch_id) on delete set null,
    sent_detail     jsonb,
    unique (transfer_id, master_id)
);

-- Dispatch: deducts every line from the source (all or nothing)
create or replace function public.inv_transfer_dispatch(p_transfer_id bigint, p_user_id bigint, p_user_name text)
returns jsonb language plpgsql security definer set search_path = public as $$
declare t public.stock_transfers%rowtype; l record; res jsonb;
begin
    select * into t from public.stock_transfers where transfer_id = p_transfer_id for update;
    if not found then return jsonb_build_object('ok', false, 'message', 'Transfer not found.'); end if;
    if t.status = 'in_transit' then return jsonb_build_object('ok', true, 'duplicate', true); end if;
    if t.status <> 'approved' then
        return jsonb_build_object('ok', false, 'message', 'Only an approved transfer can be sent.');
    end if;
    for l in select * from public.stock_transfer_items where transfer_id = p_transfer_id order by line_id loop
        res := public.inv_apply_movement(l.from_item_id, -l.quantity, 'transfer_out',
                 'Sent to another branch (' || t.transfer_no || ')', p_user_id, p_user_name, t.transfer_no,
                 l.batch_id, null, 'trf:' || p_transfer_id || ':out:' || l.line_id, false, null);
        if not coalesce((res->>'ok')::boolean, false) then
            raise exception '%', res->>'message' using errcode = 'P0001';
        end if;
        update public.stock_transfer_items set sent_detail = coalesce(res->'batches', '[]'::jsonb) where line_id = l.line_id;
    end loop;
    update public.stock_transfers
       set status = 'in_transit', dispatched_by = p_user_id, dispatched_by_name = p_user_name,
           dispatched_at = now(), updated_at = now()
     where transfer_id = p_transfer_id;
    return jsonb_build_object('ok', true);
exception when sqlstate 'P0001' then
    return jsonb_build_object('ok', false, 'message', sqlerrm);
end $$;

-- Receive: adds every line to the destination, keeping lot / expiry
create or replace function public.inv_transfer_receive(p_transfer_id bigint, p_user_id bigint, p_user_name text)
returns jsonb language plpgsql security definer set search_path = public as $$
declare t public.stock_transfers%rowtype; l record; res jsonb; v_to bigint; v_batches jsonb;
begin
    select * into t from public.stock_transfers where transfer_id = p_transfer_id for update;
    if not found then return jsonb_build_object('ok', false, 'message', 'Transfer not found.'); end if;
    if t.status = 'received' then return jsonb_build_object('ok', true, 'duplicate', true); end if;
    if t.status <> 'in_transit' then
        return jsonb_build_object('ok', false, 'message', 'Only a transfer that is in transit can be received.');
    end if;
    for l in select * from public.stock_transfer_items where transfer_id = p_transfer_id order by line_id loop
        v_to := public.inv_branch_item(l.master_id, t.to_branch_id);
        select coalesce(jsonb_agg(jsonb_build_object('lot_number', x->>'lot_number', 'expiry_date', x->>'expiry_date',
                                                     'quantity', (x->>'quantity')::integer, 'source', 'transfer')), '[]'::jsonb)
          into v_batches
          from jsonb_array_elements(coalesce(l.sent_detail, '[]'::jsonb)) x;
        res := public.inv_apply_movement(v_to, l.quantity, 'transfer_in',
                 'Received from another branch (' || t.transfer_no || ')', p_user_id, p_user_name, t.transfer_no,
                 null, v_batches, 'trf:' || p_transfer_id || ':in:' || l.line_id, false, null);
        if not coalesce((res->>'ok')::boolean, false) then
            raise exception '%', res->>'message' using errcode = 'P0001';
        end if;
        update public.stock_transfer_items set to_item_id = v_to where line_id = l.line_id;
    end loop;
    update public.stock_transfers
       set status = 'received', received_by = p_user_id, received_by_name = p_user_name,
           received_at = now(), updated_at = now()
     where transfer_id = p_transfer_id;
    return jsonb_build_object('ok', true);
exception when sqlstate 'P0001' then
    return jsonb_build_object('ok', false, 'message', sqlerrm);
end $$;

-- ---------------------------------------------------------------------
-- 8) Procurement
--    Purchase request (branch) → approved by owner → Purchase order to a
--    supplier → received (fully or in parts) → stock in + batches.
-- ---------------------------------------------------------------------
create sequence if not exists public.purchase_request_no_seq;
create sequence if not exists public.purchase_order_no_seq;
create sequence if not exists public.purchase_receipt_no_seq;

create table if not exists public.purchase_requests (
    pr_id             bigint generated always as identity primary key,
    pr_no             text not null unique default ('PR-' || lpad(nextval('public.purchase_request_no_seq')::text, 5, '0')),
    branch_id         bigint not null references public.branches(branch_id),
    status            text   not null default 'pending'
                             check (status in ('pending','approved','rejected','ordered','cancelled')),
    reason            text   not null check (btrim(reason) <> ''),
    needed_by         date,
    requested_by      bigint, requested_by_name text, requested_at timestamptz not null default now(),
    approved_by       bigint, approved_by_name  text, approved_at  timestamptz,
    rejected_by       bigint, rejected_by_name  text, rejected_at  timestamptz,
    rejection_reason  text,
    cancelled_by      bigint, cancelled_by_name text, cancelled_at timestamptz,
    request_key       text unique,
    updated_at        timestamptz not null default now()
);
create table if not exists public.purchase_request_items (
    line_id    bigint generated always as identity primary key,
    pr_id      bigint  not null references public.purchase_requests(pr_id) on delete cascade,
    master_id  bigint  not null references public.inventory_items(master_id),
    quantity   integer not null check (quantity > 0),
    notes      text,
    unique (pr_id, master_id)
);

create table if not exists public.purchase_orders (
    po_id             bigint generated always as identity primary key,
    po_no             text not null unique default ('PO-' || lpad(nextval('public.purchase_order_no_seq')::text, 5, '0')),
    supplier_id       bigint not null references public.suppliers(supplier_id),
    branch_id         bigint not null references public.branches(branch_id),     -- deliver to
    pr_id             bigint references public.purchase_requests(pr_id) on delete set null,
    status            text   not null default 'ordered'
                             check (status in ('ordered','partially_received','received','cancelled')),
    order_date        date   not null default ((now() at time zone 'Asia/Manila')::date),
    expected_date     date,
    notes             text,
    created_by        bigint, created_by_name   text, created_at  timestamptz not null default now(),
    cancelled_by      bigint, cancelled_by_name text, cancelled_at timestamptz, cancel_reason text,
    request_key       text unique,
    updated_at        timestamptz not null default now()
);
create index if not exists purchase_orders_status_idx on public.purchase_orders (status);
create table if not exists public.purchase_order_items (
    line_id            bigint generated always as identity primary key,
    po_id              bigint  not null references public.purchase_orders(po_id) on delete cascade,
    master_id          bigint  not null references public.inventory_items(master_id),
    quantity_ordered   integer not null check (quantity_ordered > 0),
    quantity_received  integer not null default 0 check (quantity_received >= 0),
    unit_cost          numeric(12,2) not null default 0 check (unit_cost >= 0),
    check (quantity_received <= quantity_ordered),
    unique (po_id, master_id)
);
do $$ begin
    alter table public.purchase_requests add column if not exists po_id bigint references public.purchase_orders(po_id) on delete set null;
end $$;

create table if not exists public.purchase_receipts (
    receipt_id        bigint generated always as identity primary key,
    receipt_no        text not null unique default ('RCV-' || lpad(nextval('public.purchase_receipt_no_seq')::text, 5, '0')),
    po_id             bigint not null references public.purchase_orders(po_id) on delete cascade,
    delivery_ref      text,
    notes             text,
    received_by       bigint, received_by_name text, received_at timestamptz not null default now(),
    request_key       text unique
);
create table if not exists public.purchase_receipt_items (
    id                 bigint generated always as identity primary key,
    receipt_id         bigint  not null references public.purchase_receipts(receipt_id) on delete cascade,
    line_id            bigint  not null references public.purchase_order_items(line_id),
    item_id            bigint  not null references public.inventory(item_id),
    quantity           integer not null check (quantity > 0),
    lot_number         text,
    expiry_date        date,
    manufactured_date  date,
    movement_id        bigint references public.inventory_movements(movement_id) on delete set null
);

-- Receive a delivery: p_lines = [{line_id, quantity, lot_number, expiry_date, manufactured_date}]
create or replace function public.inv_po_receive(p_po_id bigint, p_lines jsonb, p_delivery_ref text, p_notes text,
                                                p_user_id bigint, p_user_name text, p_request_key text)
returns jsonb language plpgsql security definer set search_path = public as $$
declare
    po public.purchase_orders%rowtype; l jsonb; li public.purchase_order_items%rowtype;
    v_qty integer; v_item bigint; v_rcpt bigint; v_rno text; res jsonb; v_total integer := 0; v_open integer; v_batches jsonb;
begin
    if p_request_key is not null then
        select receipt_id, receipt_no into v_rcpt, v_rno from public.purchase_receipts where request_key = p_request_key;
        if found then return jsonb_build_object('ok', true, 'duplicate', true, 'receipt_id', v_rcpt, 'receipt_no', v_rno); end if;
    end if;
    select * into po from public.purchase_orders where po_id = p_po_id for update;
    if not found then return jsonb_build_object('ok', false, 'message', 'Purchase order not found.'); end if;
    if po.status not in ('ordered', 'partially_received') then
        return jsonb_build_object('ok', false, 'message', 'This purchase order is ' || replace(po.status, '_', ' ') || '; nothing can be received.');
    end if;

    insert into public.purchase_receipts (po_id, delivery_ref, notes, received_by, received_by_name, request_key)
    values (p_po_id, nullif(btrim(p_delivery_ref), ''), nullif(btrim(p_notes), ''), p_user_id, p_user_name, p_request_key)
    returning receipt_id, receipt_no into v_rcpt, v_rno;

    for l in select * from jsonb_array_elements(coalesce(p_lines, '[]'::jsonb)) loop
        v_qty := coalesce((l->>'quantity')::integer, 0);
        continue when v_qty <= 0;
        select * into li from public.purchase_order_items
         where line_id = (l->>'line_id')::bigint and po_id = p_po_id for update;
        if not found then raise exception 'A delivered line does not belong to this order.' using errcode = 'P0001'; end if;
        if li.quantity_received + v_qty > li.quantity_ordered then
            raise exception 'Received quantity is more than what is still due (% left) for one of the items.',
                li.quantity_ordered - li.quantity_received using errcode = 'P0001';
        end if;
        v_item := public.inv_branch_item(li.master_id, po.branch_id);
        v_batches := case when nullif(btrim(l->>'lot_number'), '') is not null or nullif(l->>'expiry_date', '') is not null
                          then jsonb_build_array(jsonb_build_object('lot_number', l->>'lot_number', 'expiry_date', l->>'expiry_date',
                                   'manufactured_date', l->>'manufactured_date', 'quantity', v_qty, 'source', 'purchase'))
                          end;
        res := public.inv_apply_movement(v_item, v_qty, 'purchase',
                 'Delivery for ' || po.po_no || coalesce(' (' || nullif(btrim(p_delivery_ref), '') || ')', ''),
                 p_user_id, p_user_name, po.po_no, null, v_batches, 'rcv:' || v_rcpt || ':' || li.line_id, false, null);
        if not coalesce((res->>'ok')::boolean, false) then
            raise exception '%', res->>'message' using errcode = 'P0001';
        end if;
        update public.purchase_order_items set quantity_received = quantity_received + v_qty where line_id = li.line_id;
        insert into public.purchase_receipt_items (receipt_id, line_id, item_id, quantity, lot_number, expiry_date, manufactured_date, movement_id)
        values (v_rcpt, li.line_id, v_item, v_qty, nullif(btrim(l->>'lot_number'), ''),
                nullif(l->>'expiry_date', '')::date, nullif(l->>'manufactured_date', '')::date, (res->>'movement_id')::bigint);
        -- Latest purchase price and supplier become the item's defaults
        update public.inventory_items
           set unit_cost = li.unit_cost, supplier_id = coalesce(supplier_id, po.supplier_id), updated_at = now()
         where master_id = li.master_id;
        v_total := v_total + v_qty;
    end loop;

    if v_total = 0 then raise exception 'Enter the quantity delivered for at least one item.' using errcode = 'P0001'; end if;

    select coalesce(sum(quantity_ordered - quantity_received), 0) into v_open
      from public.purchase_order_items where po_id = p_po_id;
    update public.purchase_orders
       set status = case when v_open = 0 then 'received' else 'partially_received' end, updated_at = now()
     where po_id = p_po_id;
    return jsonb_build_object('ok', true, 'receipt_id', v_rcpt, 'receipt_no', v_rno, 'received', v_total,
                              'status', case when v_open = 0 then 'received' else 'partially_received' end);
exception when sqlstate 'P0001' or check_violation then
    return jsonb_build_object('ok', false, 'message', sqlerrm);
end $$;

-- ---------------------------------------------------------------------
-- 9) Stock alert history: one OPEN alert per item; opened when stock falls
--    to/below min_stock (or 0), resolved when it rises above or is archived.
-- ---------------------------------------------------------------------
create table if not exists public.inventory_stock_alerts (
    alert_id       bigint generated always as identity primary key,
    item_id        bigint  not null references public.inventory(item_id) on delete cascade,
    branch_id      bigint  references public.branches(branch_id) on delete set null,
    level          text    not null check (level in ('low', 'out')),
    stock_at_open  integer not null,
    min_stock      integer not null,
    opened_at      timestamptz not null default now(),
    resolved_at    timestamptz,
    stock_at_close integer
);
create unique index if not exists inventory_stock_alerts_one_open
    on public.inventory_stock_alerts (item_id) where resolved_at is null;

create or replace function public.inventory_track_alerts()
returns trigger language plpgsql as $$
declare is_low boolean := new.archived_at is null
                          and (new.stock_quantity <= coalesce(new.min_stock, 0) or new.stock_quantity <= 0);
begin
    if is_low then
        insert into public.inventory_stock_alerts (item_id, branch_id, level, stock_at_open, min_stock)
        values (new.item_id, new.branch_id, case when new.stock_quantity <= 0 then 'out' else 'low' end,
                new.stock_quantity, coalesce(new.min_stock, 0))
        on conflict (item_id) where resolved_at is null
        do update set level = excluded.level, min_stock = excluded.min_stock;
    else
        update public.inventory_stock_alerts
           set resolved_at = now(), stock_at_close = new.stock_quantity
         where item_id = new.item_id and resolved_at is null;
    end if;
    return new;
end $$;
drop trigger if exists inventory_track_alerts on public.inventory;
create trigger inventory_track_alerts
    after insert or update of stock_quantity, min_stock, archived_at on public.inventory
    for each row execute function public.inventory_track_alerts();

-- Seed open alerts for items that are low right now (inventory unchanged)
insert into public.inventory_stock_alerts (item_id, branch_id, level, stock_at_open, min_stock)
select item_id, branch_id, case when stock_quantity <= 0 then 'out' else 'low' end, stock_quantity, coalesce(min_stock, 0)
  from public.inventory
 where archived_at is null and (stock_quantity <= coalesce(min_stock, 0) or stock_quantity <= 0)
on conflict do nothing;

-- ---------------------------------------------------------------------
-- 10) Security: server-only tables (RLS on, no policies) and functions
--     callable by the server key only.
-- ---------------------------------------------------------------------
alter table public.suppliers              enable row level security;
alter table public.inventory_items        enable row level security;
alter table public.inventory_batches      enable row level security;
alter table public.stock_transfers        enable row level security;
alter table public.stock_transfer_items   enable row level security;
alter table public.purchase_requests      enable row level security;
alter table public.purchase_request_items enable row level security;
alter table public.purchase_orders        enable row level security;
alter table public.purchase_order_items   enable row level security;
alter table public.purchase_receipts      enable row level security;
alter table public.purchase_receipt_items enable row level security;
alter table public.inventory_stock_alerts enable row level security;

revoke all on function public.inv_apply_movement(bigint,integer,text,text,bigint,text,text,bigint,jsonb,text,boolean,date) from public, anon, authenticated;
revoke all on function public.inv_branch_item(bigint,bigint)                    from public, anon, authenticated;
revoke all on function public.inv_transfer_dispatch(bigint,bigint,text)         from public, anon, authenticated;
revoke all on function public.inv_transfer_receive(bigint,bigint,text)          from public, anon, authenticated;
revoke all on function public.inv_po_receive(bigint,jsonb,text,text,bigint,text,text) from public, anon, authenticated;
grant execute on function public.inv_apply_movement(bigint,integer,text,text,bigint,text,text,bigint,jsonb,text,boolean,date) to service_role;
grant execute on function public.inv_branch_item(bigint,bigint)                    to service_role;
grant execute on function public.inv_transfer_dispatch(bigint,bigint,text)         to service_role;
grant execute on function public.inv_transfer_receive(bigint,bigint,text)          to service_role;
grant execute on function public.inv_po_receive(bigint,jsonb,text,text,bigint,text,text) to service_role;

commit;

notify pgrst, 'reload schema';

-- =====================================================================
-- VERIFY (each should return what is noted)
--   select count(*) from public.inventory_items;                       -- 16 (15 items + the archived "test item")
--   select count(*) from public.inventory where master_id is null;     -- 0
--   select movement_type, count(*) from public.inventory_movements group by 1;  -- usage 68, stock_in/out …
--   select count(*) from public.inventory_stock_alerts where resolved_at is null; -- items low right now
--
-- ROLLBACK (only before any new data is entered; drops the new objects):
--   drop function if exists public.inv_po_receive, public.inv_transfer_receive, public.inv_transfer_dispatch,
--        public.inv_branch_item, public.inv_apply_movement;
--   drop trigger if exists inventory_track_alerts on public.inventory;
--   drop trigger if exists inventory_link_master on public.inventory;
--   drop table if exists public.purchase_receipt_items, public.purchase_receipts, public.purchase_order_items,
--        public.purchase_request_items, public.stock_transfer_items, public.stock_transfers,
--        public.inventory_stock_alerts, public.inventory_batches cascade;
--   alter table public.purchase_requests drop column if exists po_id;
--   drop table if exists public.purchase_orders, public.purchase_requests cascade;
--   alter table public.inventory_movements drop column if exists movement_type, drop column if exists stock_before,
--        drop column if exists stock_after, drop column if exists reference_no, drop column if exists user_id,
--        drop column if exists batch_id, drop column if exists batch_detail, drop column if exists request_key;
--   alter table public.inventory drop column if exists master_id, drop column if exists reorder_qty;
--   drop table if exists public.inventory_items, public.suppliers cascade;
-- =====================================================================
