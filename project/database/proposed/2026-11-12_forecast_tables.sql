-- =====================================================================
--  EssenciaSmile — predictive analytics result tables          MIGRATION
--  Run in: Supabase Dashboard > SQL Editor. NOT applied yet.
--
--  WHAT IT ADDS (written ONLY by the Python job in project/analytics/,
--  read by analytics.php through controllers/forecast_controller.php)
--    forecast_runs       one row per run of run_forecast.py
--                        data_source 'live' | 'simulated' (simulated = demo
--                        data from CSV files, never real clinic records)
--    forecast_values     daily actuals the model saw (kind 'actual') and
--                        predictions (kind 'forecast', per model) with the
--                        95% interval; is_open = false marks closed days
--    forecast_metrics    rolling-origin evaluation per branch/metric/model:
--                        MAE, RMSE, MASE, MAPE, status ok | insufficient_data
--                        | failed, and which model was selected
--    forecast_inventory  per item: stock on hand, forecast use for the
--                        horizon, days of stock, needs_reorder
--
--  WHY IT IS SAFE
--    • New tables only; no existing table, row or policy changes.
--    • Results are tied to a run (on delete cascade): deleting old
--      forecast_runs rows removes their values/metrics/inventory too.
--    • RLS enabled with no policies (deny-all for anon/authenticated) like
--      the other server-only tables; the server key bypasses RLS.
-- =====================================================================
begin;

create table if not exists public.forecast_runs (
  run_id             bigint generated always as identity primary key,
  data_source        text        not null check (data_source in ('live', 'simulated')),
  status             text        not null default 'running' check (status in ('running', 'success', 'failed')),
  started_at         timestamptz not null default now(),
  finished_at        timestamptz,
  horizon_days       smallint    not null default 14,
  last_observed_date date,
  triggered_by       text,
  summary            jsonb,
  error              text
);
create index if not exists forecast_runs_source_status_idx
  on public.forecast_runs (data_source, status, started_at desc);

create table if not exists public.forecast_values (
  id          bigint generated always as identity primary key,
  run_id      bigint  not null references public.forecast_runs (run_id) on delete cascade,
  branch_id   integer not null,
  metric      text    not null check (metric in ('appointments', 'sales', 'inventory')),
  item_id     integer,
  kind        text    not null check (kind in ('actual', 'forecast')),
  model       text,
  value_date  date    not null,
  is_open     boolean not null default true,
  yhat        numeric,
  yhat_lower  numeric,
  yhat_upper  numeric,
  is_selected boolean not null default false
);
create index if not exists forecast_values_run_branch_metric_idx
  on public.forecast_values (run_id, branch_id, metric);

create table if not exists public.forecast_metrics (
  id          bigint generated always as identity primary key,
  run_id      bigint  not null references public.forecast_runs (run_id) on delete cascade,
  branch_id   integer not null,
  metric      text    not null check (metric in ('appointments', 'sales', 'inventory')),
  item_id     integer,
  model       text    not null,
  status      text    not null check (status in ('ok', 'insufficient_data', 'failed')),
  n_obs       integer not null default 0,
  n_folds     smallint not null default 0,
  mae         numeric,
  rmse        numeric,
  mase        numeric,
  mape        numeric,
  is_selected boolean not null default false,
  model_order text,
  message     text
);
create index if not exists forecast_metrics_run_branch_idx
  on public.forecast_metrics (run_id, branch_id, metric);

create table if not exists public.forecast_inventory (
  id            bigint generated always as identity primary key,
  run_id        bigint  not null references public.forecast_runs (run_id) on delete cascade,
  branch_id     integer not null,
  item_id       integer not null,
  item_name     text,
  stock_on_hand numeric,
  min_stock     numeric,
  forecast_use  numeric,
  days_of_stock numeric,
  needs_reorder boolean not null default false,
  basis         text    not null check (basis in ('model', 'average'))
);
create index if not exists forecast_inventory_run_branch_idx
  on public.forecast_inventory (run_id, branch_id);

alter table public.forecast_runs      enable row level security; -- server (service role) only
alter table public.forecast_values    enable row level security;
alter table public.forecast_metrics   enable row level security;
alter table public.forecast_inventory enable row level security;
revoke all on public.forecast_runs, public.forecast_values, public.forecast_metrics, public.forecast_inventory
  from anon, authenticated;

commit;
