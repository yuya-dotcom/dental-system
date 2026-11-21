# Predictive Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Forecast daily appointments, sales and inventory consumption per branch (Novaliches/Quezon City, Mandaluyong, Caloocan) with statistically evaluated models, and show the results — clearly separated from real transactions — in the Dashboard Analytics page.

**Architecture:** A standalone Python batch job (`project/analytics/`) reads Supabase over REST, builds a daily open-day calendar per branch, fits competing models (seasonal naive, ARIMA, SARIMA, multiple linear regression), evaluates them with rolling-origin validation, and writes runs/forecasts/metrics into three new Supabase tables. PHP only reads those tables and draws them with Chart.js. Until the clinic has real history, the job runs on clearly labelled, seeded simulated data kept in CSV files that never touch operational tables.

**Tech Stack:** Python 3.14 (fallback 3.12) · pandas · numpy · statsmodels · requests · pytest · native PHP 8 · Supabase PostgREST · Chart.js (already used by analytics.php)

**Spec:** No separate spec file — the agreed decisions are in "Decisions" below (from the 2026-11-12 planning conversation; summarised in memory `predictive-analytics-plan`).

## Decisions (the spec)

| # | Decision |
|---|---|
| D1 | Live DB holds only test data (46 appointments, 12 payments); the clinic has no historical records. The system starts from zero and accumulates. |
| D2 | Development/demo data is **simulated**: generated in Python with a fixed seed, stored as CSV in `project/analytics/sample_data/`, never written to operational tables. Every stored result carries `data_source = 'live' \| 'simulated'`. |
| D3 | Granularity is **daily per branch**. Weekly seasonality only (m = 7). No yearly seasonality is claimed. |
| D4 | Targets: **appointments** = appointments with status `completed` or `checked_in` per `appointment_date`; **sales** = `payments.amount` per `payment_date` (cash basis; branch via `invoices.branch_id`); **inventory** = units consumed per item per day = −`inventory_movements.quantity_change` where `quantity_change < 0` and `reason` starts with `Used in treatment`. |
| D5 | **Closed days are missing (NaN), not zero.** Open/closed per date = weekly `operating_days` as of that date (reconstructed from `audit_logs` module `branches`, `before_data/after_data.operating_days`) overridden by `branch_schedule_exceptions`. Live only: an open day with zero activity AND no `LOGIN_SUCCESS` audit row for that branch is "suspected missing" → NaN, counted in the run report. |
| D6 | Candidate models: `seasonal_naive` (baseline, y[t−7]), `arima`, `sarima` (m = 7), `mlr` (OLS on open days: weekday dummies + linear trend). Holt-Winters is out of scope. |
| D7 | Data-sufficiency gate (non-NaN days in the series): `seasonal_naive` ≥ 14; `arima`, `sarima`, `mlr` ≥ 56. A series below 14 gets status `insufficient_data` and no forecast. |
| D8 | Evaluation: rolling origin, 4 folds, each fold forecasts the next 7 calendar days, origins 7 days apart, ending at the last observed date. Metrics per fold on open days: MAE, RMSE, MASE (scale = in-sample mean \|y[t] − y[t−7]\| over non-NaN pairs), MAPE only over days with actual > 0. Model orders are chosen by AIC on data **before the earliest fold origin** (no leakage), then refit at each origin. |
| D9 | Selection: lowest mean MASE across folds; ties (within 0.01) go to the simpler model in this order: seasonal_naive < mlr < arima < sarima. |
| D10 | Forecast horizon: 14 calendar days after the last observed date. Future closed days are stored with `is_open = false` and `yhat = null`. Intervals: 95% from statsmodels for arima/sarima/mlr; none for seasonal_naive. Values are clipped at 0. |
| D11 | Inventory reorder: `stock_on_hand` = `inventory.stock_quantity`; forecast use = sum of the selected model's 14-day forecast (fallback: mean daily use over the last 28 open days × open days ahead); `needs_reorder` when `stock_on_hand − forecast_use ≤ min_stock`; `days_of_stock` = stock_on_hand ÷ mean daily forecast use. Only items with ≥ 5 usage days in the series are modelled. |
| D12 | Architecture: standalone Python scripts (no FastAPI). Scheduled with a `.bat` + Windows Task Scheduler like `project/cron/`. Owners get a "Run forecast now" button. |
| D13 | Dashboard: actual = solid line, forecast = dashed line + shaded interval, closed days shown as gaps, a badge `SIMULATED DATA` or `Collecting data: n / 56 days`, a model-metrics table per branch/metric, and an inventory reorder table. Admins/dentists see their own branch only; owners see all and can compare branches. |

## Global Constraints

- Never INSERT/UPDATE/DELETE operational tables (`appointments`, `payments`, `invoices`, `inventory*`, `treatments*`) from Python. Python writes only `forecast_runs`, `forecast_values`, `forecast_metrics`, `forecast_inventory`.
- The local config points at the LIVE Supabase. Every Python test uses fakes/fixtures; only `run_forecast.py` without `--dry-run` writes, and only to the four forecast tables.
- The Supabase URL/key are read from `project/config/secrets.php` via the PHP CLI (`php -r`); no key is copied into Python files, CSVs, logs or the repo.
- `project/analytics/` is under the web root: it gets an `.htaccess` with `Require all denied` (same as `project/config/.htaccess`).
- New SQL goes in `project/database/proposed/` and is run by the user, never by the agent.
- Modal/Swal buttons: primary action LEFT, cancel/secondary RIGHT.
- Timezone for "today" and dates: `Asia/Manila`.
- No git commits unless the user asks (repo convention for this release branch).
- All user-visible text on the dashboard must make predicted vs actual unmistakable (labels say "Forecast", never "Sales"/"Appointments" alone on predicted series).

## Review Focus

1. **Live data far below the gate (today's reality)** — every live series must finish with status `insufficient_data`, no forecast rows, and the dashboard shows "Collecting data: n / 56 days" instead of a chart that looks like a prediction. → Task 8 `test_live_like_short_series_is_insufficient`, Task 9 render check.
2. **A branch with no rows at all (Mandaluyong has 0 appointments)** — the series is all-NaN; the run must not crash and must report that branch as `insufficient_data`. → Task 4 `test_branch_without_rows_gives_all_nan_series`.
3. **Constant or all-zero series makes statsmodels raise / warn (singular matrix, non-convergence)** — the model is recorded as `failed` with the message, other models and series continue. → Task 6 `test_model_failure_is_contained`.
4. **An admin/dentist of branch X must never see branch Y's forecasts** — enforced in the PHP controller, not in JS. → Task 9 `forecast_controller` branch-scope check.
5. **The Python job dies mid-run** — `forecast_runs.status` becomes `failed` (or stays `running` past 30 min → treated as failed), and the dashboard keeps showing the last `success` run with its timestamp. → Task 8 `test_run_marked_failed_on_exception`, Task 9 "latest successful run" query.

---

## File Structure

```
project/analytics/                 Python batch job (web access denied)
  .htaccess                        Require all denied
  requirements.txt                 pandas, numpy, statsmodels, requests, pytest
  README.md                        setup + how to run (short)
  es_forecast/
    __init__.py
    config.py                      secrets via php CLI; constants D7–D11
    supa.py                        REST client: select_all(), insert(), patch()
    open_days.py                   open/closed calendar per branch (D5)
    extract.py                     RawData from Supabase or from CSV
    series.py                      RawData + calendar → daily series dict (D4, D5)
    simulate.py                    seeded simulated RawData → sample_data/*.csv
    models.py                      seasonal_naive / arima / sarima / mlr
    evaluate.py                    metrics, rolling origin, gate, selection (D7–D9)
    inventory.py                   reorder status (D11)
    store.py                       writes run/values/metrics/inventory rows
  run_forecast.py                  CLI entry point
  run_forecast.bat                 Task Scheduler wrapper (logs to cron/logs/)
  sample_data/                     generated CSVs + truth.json (simulated only)
  tests/                           pytest, no network
project/database/proposed/2026-11-12_forecast_tables.sql
project/controllers/forecast_controller.php   read-only queries, branch scoping
project/api/forecast_run.php                  owner-only: start job, poll status
project/analytics.php                         + "Predictive Analytics" section
project/assets/js/forecast-charts.js          Chart.js rendering
```

---

### Task 1: Forecast storage tables (SQL)

**Files:**
- Create: `project/database/proposed/2026-11-12_forecast_tables.sql`

**Interfaces:**
- Produces (columns later tasks rely on):
  - `forecast_runs(run_id bigint identity pk, data_source text check in ('live','simulated'), status text check in ('running','success','failed'), started_at timestamptz default now(), finished_at timestamptz, horizon_days smallint, last_observed_date date, triggered_by text, summary jsonb, error text)`
  - `forecast_values(id bigint identity pk, run_id fk → forecast_runs on delete cascade, branch_id int, metric text check in ('appointments','sales','inventory'), item_id int null, kind text check in ('actual','forecast'), model text null, value_date date, is_open boolean, yhat numeric null, yhat_lower numeric null, yhat_upper numeric null, is_selected boolean default false)` + index `(run_id, branch_id, metric)`
  - `forecast_metrics(id bigint identity pk, run_id fk cascade, branch_id int, metric text, item_id int null, model text, status text check in ('ok','insufficient_data','failed'), n_obs int, n_folds smallint, mae numeric, rmse numeric, mase numeric, mape numeric, is_selected boolean default false, model_order text, message text)`
  - `forecast_inventory(id bigint identity pk, run_id fk cascade, branch_id int, item_id int, item_name text, stock_on_hand numeric, min_stock numeric, forecast_use numeric, days_of_stock numeric, needs_reorder boolean, basis text check in ('model','average'))`
  - All four: RLS enabled, no policies, `revoke all … from anon, authenticated`. Header comment in the same style as `2026-11-12_branch_schedule_exceptions.sql`.

- [ ] **Step 1:** Write the SQL file (single `begin; … commit;`, `create table if not exists`).
- [ ] **Step 2:** User runs it in the Supabase SQL Editor.
- [ ] **Step 3: Verify** — `php` one-liner using `supabase_request('forecast_runs','GET',[],'select=run_id&limit=1')` for each table returns `error = null` and `data = []`.

### Task 2: Python scaffold, config and REST client

**Files:**
- Create: `project/analytics/.htaccess`, `requirements.txt`, `README.md`, `es_forecast/__init__.py`, `es_forecast/config.py`, `es_forecast/supa.py`
- Test: `project/analytics/tests/test_supa.py`

**Interfaces:**
- Produces:
  - `config.load_credentials() -> tuple[str, str]` — runs `php -r 'echo json_encode(array_intersect_key(require "<abs>/config/secrets.php", ["supabase_url"=>1,"supabase_key"=>1]));'`; raises `RuntimeError("Supabase credentials not found")` if either is empty.
  - constants: `TZ = "Asia/Manila"`, `SEASON = 7`, `MIN_OBS = {"seasonal_naive": 14, "arima": 56, "sarima": 56, "mlr": 56}`, `FOLDS = 4`, `FOLD_HORIZON = 7`, `HORIZON = 14`, `INV_MIN_USAGE_DAYS = 5`, `INV_AVG_WINDOW = 28`, `MODEL_ORDER = ["seasonal_naive", "mlr", "arima", "sarima"]`
  - `class Supa(url: str, key: str, session=None)` with `select_all(table: str, params: dict) -> list[dict]` (pages with `limit=1000&offset=…` until a short page), `insert(table: str, rows: list[dict], chunk: int = 500) -> list[dict]`, `patch(table: str, match: dict, values: dict) -> None`. Header `apikey: <key>` only (sb_secret keys are not JWTs). Raises `SupaError(status, message)` on HTTP ≥ 400.

- [ ] **Step 1: Install check** — `python -m pip install -r project/analytics/requirements.txt` then `python -c "import statsmodels, pandas; print(statsmodels.__version__)"`. If statsmodels has no wheel for 3.14, install Python 3.12 and create `project/analytics/.venv` with it; record which in README.
- [ ] **Step 2: Write failing tests** in `test_supa.py` with a fake session object:
  - `test_select_all_pages_until_short_page` — fake returns 1000, 1000, 3 rows → result length 2003, three requests with offsets 0/1000/2000.
  - `test_http_error_raises_supaerror` — fake status 404 body `{"message":"x"}` → `SupaError` with status 404.
  - `test_insert_chunks_rows` — 1200 rows, chunk 500 → 3 POSTs.
- [ ] **Step 3:** Run `pytest project/analytics/tests/test_supa.py -v` → FAIL (module missing).
- [ ] **Step 4:** Implement `config.py`, `supa.py`, `.htaccess` (`Require all denied`).
- [ ] **Step 5:** Run tests → PASS. Run `python -c "from es_forecast.config import load_credentials; u,k=load_credentials(); print(u.startswith('https://'), bool(k))"` → `True True` (never print the key).

### Task 3: Open-day calendar

**Files:**
- Create: `project/analytics/es_forecast/open_days.py`
- Test: `project/analytics/tests/test_open_days.py`

**Interfaces:**
- Consumes: plain lists of dicts shaped like the Supabase rows (`branches`, `branch_schedule_exceptions`, `audit_logs` with `module='branches'`).
- Produces: `build_calendar(branches: list[dict], exceptions: list[dict], branch_audit: list[dict], start: date, end: date) -> pd.DataFrame` with columns `branch_id:int, date:datetime64, is_open:bool`, one row per branch per calendar day (inclusive).

Weekly days as of a date: start from the branch's current `operating_days` (default `[1..6]` when null) and walk the branch's audit rows newest → oldest; for every row whose `after_data` has `operating_days` and `created_at` (Manila date) is **after** the date, replace the current set with that row's `before_data.operating_days`. Python weekday mapping: Postgres/PHP 0 = Sunday → `(date.weekday() + 1) % 7`. Then an exception row for (branch, date) overrides with `is_open`.

- [ ] **Step 1: Failing tests:**
  - `test_weekly_days_default_mon_to_sat` — branch with `operating_days=None` → Sundays closed, Mondays open.
  - `test_exception_overrides_weekly` — Sunday exception `is_open=True` → that Sunday open; next Sunday still closed.
  - `test_audit_history_reconstructs_past_schedule` — current `[0,2,3,4,5,6]`, one audit row on 2026-11-12 with before `[1..6]` → 2026-11-10 (Mon) open, 2026-11-16 (Mon) closed, 2026-11-15 (Sun) open.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.

### Task 4: Raw data extraction and daily series

**Files:**
- Create: `project/analytics/es_forecast/extract.py`, `project/analytics/es_forecast/series.py`
- Test: `project/analytics/tests/test_series.py`, fixtures in `tests/fixtures.py`

**Interfaces:**
- Produces:
  - `@dataclass RawData: branches, exceptions, branch_audit, logins, appointments, invoices, payments, movements, inventory: list[dict]` (logins = `audit_logs` rows with `action='LOGIN_SUCCESS'`, fields `branch_id, created_at`).
  - `extract.from_supabase(supa: Supa, since: date) -> RawData` — selects only the columns named in D4/D5.
  - `extract.from_csv(folder: Path) -> RawData` — one CSV per field name (`appointments.csv`, …); JSON-array columns (`operating_days`, `before_data`, `after_data`) stored as JSON text.
  - `SeriesKey = tuple[int, str, int | None]` → `(branch_id, metric, item_id)`.
  - `series.build_series(raw: RawData, calendar: pd.DataFrame, start: date, end: date, detect_missing: bool) -> tuple[dict[SeriesKey, pd.Series], dict]` — each series is a float `pd.Series` on a daily `DatetimeIndex` (freq `D`), NaN on closed days (and suspected-missing days when `detect_missing`), 0.0 on open days with no activity; inventory series only for items with ≥ `INV_MIN_USAGE_DAYS` usage days. The second value is a report: `{"suspected_missing": {branch_id: n}, "open_days": {branch_id: n}}`.

- [ ] **Step 1: Failing tests** (fixture with branches 1–3, a week of rows):
  - `test_appointments_counts_completed_and_checked_in_only` — cancelled/pending excluded.
  - `test_sales_use_payment_date_and_invoice_branch`.
  - `test_inventory_counts_only_used_in_treatment_outflows` — `IN Restock` and `OUT Tooth` ignored.
  - `test_closed_day_is_nan_open_empty_day_is_zero`.
  - `test_suspected_missing_day_is_nan_only_when_detect_missing` — open day, zero rows, no login → NaN with `True`, 0.0 with `False`.
  - `test_branch_without_rows_gives_all_nan_series` — with `detect_missing=True`, Mandaluyong (no rows, no logins) → series exists, all NaN (Review Focus 2).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.

### Task 5: Simulated data generator

**Files:**
- Create: `project/analytics/es_forecast/simulate.py`; output `project/analytics/sample_data/*.csv`, `sample_data/truth.json`
- Test: `project/analytics/tests/test_simulate.py`

**Interfaces:**
- Produces: `simulate.generate(seed: int = 2026, days: int = 180, end: date | None = None) -> tuple[RawData, dict]` and `simulate.write_csv(raw: RawData, truth: dict, folder: Path) -> None`. `end` defaults to yesterday (Manila).

Ground truth (written to `truth.json`, values fixed here so tests can check recovery):
- Branch base completed appointments per open day: `{3: 12.0, 2: 9.0, 1: 7.0}` (3 = Quezon City/Novaliches, 2 = Mandaluyong, 1 = Caloocan); weekday multipliers Mon 1.10, Tue 0.85, Wed 0.90, Thu 0.95, Fri 1.05, Sat 1.35; trend +0.4% per week; counts ~ Poisson(base × mult × trend).
- Each appointment → one invoice + one payment, amount ~ lognormal(median ₱1,500, sigma 0.6) rounded to ₱50.
- Five items per branch (`item_id` 9001–9005, names "SIM Gloves (pair)", "SIM Mask", "SIM Anesthetic cartridge", "SIM Composite syringe", "SIM Cotton roll pack"); usage per appointment 2, 1, 0.3, 0.15, 0.5 → daily units ~ Poisson(appointments × rate); stock_on_hand 60–400, min_stock 20.
- Closures: Sundays closed; 3 holiday exceptions closed (Dec 8, Dec 25, Jan 1 when in range) for all branches; 2 swap exceptions for branch 3 (one Sunday open, the following Monday closed).
- Every simulated id/name is prefixed or ranged so it can't be mistaken for a real record (`appointment_code` `SIM-…`).

- [ ] **Step 1: Failing tests:**
  - `test_same_seed_same_data` — two runs, identical appointment counts per day.
  - `test_sundays_closed_except_swap` — no appointments on Sundays except the swap Sunday of branch 3.
  - `test_weekday_effect_recoverable` — Saturday mean ÷ Tuesday mean for branch 3 within 1.35/0.85 ± 15%.
  - `test_csv_roundtrip` — `write_csv` then `extract.from_csv` → same row counts per table.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS. **Step 5:** Generate the committed sample: `python -m es_forecast.simulate` writes `sample_data/` (seed 2026).

### Task 6: Forecasting models

**Files:**
- Create: `project/analytics/es_forecast/models.py`
- Test: `project/analytics/tests/test_models.py`

**Interfaces:**
- Produces:
  - `@dataclass Forecast: yhat: pd.Series, lower: pd.Series | None, upper: pd.Series | None, order: str`
  - `select_order(name: str, y: pd.Series) -> tuple` — AIC grid: arima `(p,d,q)` p,q ∈ {0,1,2}, d ∈ {0,1}; sarima `(p,d,q)(P,D,Q,7)` p,q,P,Q ∈ {0,1}, d,D ∈ {0,1}; uses `statsmodels.tsa.statespace.sarimax.SARIMAX` (handles NaN). Returns `()` for seasonal_naive/mlr.
  - `fit_forecast(name: str, y: pd.Series, future: pd.DatetimeIndex, future_open: pd.Series, order: tuple) -> Forecast` — `future_open` is bool per future date; closed future dates get NaN yhat; values clipped at 0.
    - seasonal_naive: value of the same weekday's most recent non-NaN observation (≤ 5 weeks back), no interval.
    - mlr: `statsmodels.api.OLS` on non-NaN days, features = 6 weekday dummies (Monday baseline) + trend (days since series start); 95% `obs_ci` from `get_prediction().summary_frame(alpha=0.05)`.
    - arima/sarima: `SARIMAX(...).fit(disp=False)`, `get_forecast(len(future))`, 95% `conf_int`.
  - `class ModelError(Exception)` — wraps any statsmodels/numpy failure, message kept.

- [ ] **Step 1: Failing tests:**
  - `test_seasonal_naive_repeats_last_week`.
  - `test_mlr_recovers_weekday_pattern` — synthetic noiseless weekday pattern → yhat equal to the pattern within 1e-6.
  - `test_closed_future_days_are_nan`.
  - `test_forecasts_never_negative`.
  - `test_model_failure_is_contained` — all-zero series to `arima` either returns a Forecast or raises `ModelError`, never another exception type (Review Focus 3).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.

### Task 7: Evaluation, gate and model selection

**Files:**
- Create: `project/analytics/es_forecast/evaluate.py`
- Test: `project/analytics/tests/test_evaluate.py`

**Interfaces:**
- Consumes: `models.select_order`, `models.fit_forecast`, `models.ModelError`, config constants.
- Produces:
  - `mae(a, p) -> float`, `rmse(a, p) -> float`, `mase(a, p, y_train, m=7) -> float | None` (None when the scale is 0 or undefined), `mape_nonzero(a, p) -> float | None` — all ignore NaN pairs.
  - `@dataclass ModelScore: model: str, status: str, n_obs: int, n_folds: int, mae, rmse, mase, mape: float | None, order: str, message: str`
  - `evaluate_series(y: pd.Series) -> list[ModelScore]` — applies the gate (D7), orders from data before the first origin (D8), 4 folds × 7 days.
  - `select_best(scores: list[ModelScore]) -> str | None` — D9; None when no model has status `ok`.

- [ ] **Step 1: Failing tests:**
  - `test_metrics_known_values` — a=[2,4,6], p=[1,4,8] → MAE 1.0, RMSE √(5/3).
  - `test_mape_skips_zero_actuals`.
  - `test_gate_marks_short_series_insufficient` — 20 non-NaN days → seasonal_naive `ok`, others `insufficient_data`.
  - `test_order_selection_uses_only_pre_origin_data` — spy on `select_order`: the series passed ends before the first fold origin.
  - `test_tie_prefers_simpler_model`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.

### Task 8: Inventory reorder, storage and the CLI run

**Files:**
- Create: `project/analytics/es_forecast/inventory.py`, `es_forecast/store.py`, `project/analytics/run_forecast.py`, `project/analytics/run_forecast.bat`
- Test: `project/analytics/tests/test_run.py`

**Interfaces:**
- Consumes: everything above.
- Produces:
  - `inventory.reorder_status(series: pd.Series, forecast: Forecast | None, stock_on_hand: float, min_stock: float, future_open: pd.Series) -> dict` (keys = `forecast_inventory` columns except ids; D11).
  - `store.Store(supa: Supa | None, dry_run: bool)` with `start_run(source, horizon, last_date, triggered_by) -> int`, `add_values(rows)`, `add_metrics(rows)`, `add_inventory(rows)`, `finish(run_id, status, summary, error=None)`. In dry-run it collects rows in memory (`.rows` dict) and returns run_id 0.
  - CLI: `python run_forecast.py --source {live,simulated} [--dry-run] [--triggered-by NAME]`. Exit code 0 on success, 1 on failure. Stores the last 90 days of actuals (`kind='actual'`) and every eligible model's 14-day forecast (`kind='forecast'`, `is_selected` on the winner). Live runs use `detect_missing=True`; simulated runs `False`.
  - `run_forecast.bat`: `cd /d %~dp0` then runs the CLI with `--source live`, appending to `project/cron/logs/forecast.log` (same style as `cron/run_no_shows.bat`).

- [ ] **Step 1: Failing tests** (Store in dry-run, data from `simulate.generate`):
  - `test_simulated_run_produces_forecasts_for_every_branch_and_metric` — 3 branches × (appointments, sales) have a selected model and 14 forecast rows; no NaN yhat on open days.
  - `test_live_like_short_series_is_insufficient` — RawData from `tests/fixtures.py` shaped like today's live data (QC 20 active days, Mandaluyong none) → every metrics row `insufficient_data` or seasonal_naive only where ≥ 14 obs; no forecast rows for gated series (Review Focus 1).
  - `test_run_marked_failed_on_exception` — monkeypatch `build_series` to raise → `finish` called with `failed` and the message; exit code 1 (Review Focus 5).
  - `test_reorder_flag` — stock 30, min 20, forecast use 15 → `needs_reorder True`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run `pytest project/analytics/tests -v` → all PASS.
- [ ] **Step 5: Real runs:** `python run_forecast.py --source simulated` then `--source live`. Verify in Supabase: two `forecast_runs` rows with `status='success'`; simulated has forecasts; live shows `insufficient_data` (expected today).

### Task 9: Dashboard section (PHP + Chart.js)

**Files:**
- Create: `project/controllers/forecast_controller.php`, `project/assets/js/forecast-charts.js`
- Modify: `project/analytics.php` (new section below the existing charts; bump script versions)

**Interfaces:**
- Consumes: Task 1 tables.
- Produces:
  - `getLatestForecastRun(string $source): ?array` — newest `status='success'` run for that source; a `running` run older than 30 min is ignored.
  - `getForecastBundle(int $runId, array $branchIds): array` — `['values' => …, 'metrics' => …, 'inventory' => …]` filtered with `branch_id=in.(…)`.
  - `forecastBranchScope(): array` — owner → all branch ids; admin/dentist → `[$_SESSION['branch_id']]` only (Review Focus 4). The filter is applied in the PHP query, never in JS.
  - Page data: `window.ES_FORECAST = {source, run: {run_id, finished_at, last_observed_date}, values, metrics, inventory, minObs: 56}`.

UI (D13): source toggle "Live data / Simulated demo" (owner and admin), branch selector (owner only), three tabs Appointments · Sales · Inventory. Chart: actual solid line, selected forecast dashed + shaded 95% band, gaps on closed days, a vertical "Today" divider, legend labels "Actual" / "Forecast (model)". Badges: `SIMULATED DATA` (amber) on every simulated view; `Collecting data: n / 56 days` instead of the chart when the selected series is `insufficient_data`. Metrics table: model, MAE, RMSE, MASE, MAPE, selected ✓, plus a one-line "MASE < 1 means better than repeating last week". Inventory table: item, stock, forecast use (14 days), days of stock, reorder badge. "Last run: <date time>" always visible.

- [ ] **Step 1:** Implement controller + section + JS.
- [ ] **Step 2: Verify** with the CLI render harness (`$_SESSION` forged, page included, output grepped): owner sees 3 branches in `ES_FORECAST`; admin with `branch_id=1` sees only branch 1 values; with no forecast tables/runs the page renders the "No forecast run yet" state without PHP warnings.
- [ ] **Step 3:** User checks in the browser: simulated view shows dashed forecast + band + badge; live view shows "Collecting data".

### Task 10: Run-now button and scheduling

**Files:**
- Create: `project/api/forecast_run.php`
- Modify: `project/analytics.php`, `project/assets/js/forecast-charts.js`, `project/analytics/README.md`

**Interfaces:**
- `POST api/forecast_run.php {action: 'start', source}` — owner only; refuses when a run is `running` and younger than 30 min; starts `run_forecast.py` detached (`popen('start /B "" <python> <script> --source … --triggered-by <user> > log 2>&1', 'r')`) and returns `{success, message}`. `{action: 'status'}` returns the newest run's `status, started_at, finished_at, error`.
- Button "Run forecast now" (owner) → Swal confirm (primary left) → polls `status` every 5 s up to 10 min → reloads the section on `success`, shows the error on `failed`.

- [ ] **Step 1:** Implement endpoint + button.
- [ ] **Step 2: Verify:** non-owner POST → `Unauthorized`; owner start → new `running` row then `success`; second start while running → refused message.
- [ ] **Step 3:** README: Task Scheduler entry (daily 23:30, `run_forecast.bat`), and that the live run is expected to report `insufficient_data` until ~8 weeks of real use.

---

## Out of scope (deliberately)

Holt-Winters/ETS, Prophet, ML models, yearly seasonality, weekly/monthly models, forecasting cancelled/no-show rates, and the methodology write-up (done together after Task 8 using the real outputs).
