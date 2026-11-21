# EssenciaSmile — Predictive Analytics job

Forecasts daily **appointments**, **sales** and **inventory use** per branch for the next 14 days and stores the results in Supabase. The **Predictive Analytics** tab of `analytics.php` (`analytics.php#forecast`) shows them.

Plan and decisions: `../docs/superpowers/plans/2026-11-12-predictive-analytics.md`

## Setup (once)

1. Run `../database/proposed/2026-11-12_forecast_tables.sql` in the Supabase SQL Editor.
2. Install the libraries (Python 3.14 is used on the dev machine):
   ```
   python -m pip install --user -r requirements.txt
   ```
3. Credentials are read from `../config/secrets.php` through the PHP CLI. Nothing needs to be copied, and no key is ever stored in this folder.

This folder is not reachable from the web (`.htaccess`: `Require all denied`).

## Run

```
python run_forecast.py --source simulated     # demo data in sample_data/ (labelled SIMULATED everywhere)
python run_forecast.py --source live          # real records from Supabase
python run_forecast.py --source live --dry-run   # compute only, write nothing
```

`run_forecast.bat [live|simulated] [who]` does the same and appends to `../cron/logs/forecast.log`.

**Schedule (Windows Task Scheduler):** daily at 23:30, action `C:\xampp\htdocs\project\project\analytics\run_forecast.bat`, argument `live`. Owners can also click **Run forecast now** on the Analytics page.

**Demo data:** `python -m es_forecast.simulate` regenerates `sample_data/` (seed 2026). It always produces the same data. `truth.json` records the parameters it was generated from. The simulated rows are never written to clinic tables.

## What a run does

1. Reads branches, schedule exceptions, branch-edit history, staff logins, appointments, payments, invoices, inventory movements and stock. It only reads; it never writes clinic tables.
2. Builds a daily calendar per branch and marks each day open or closed. The weekly schedule as it was on that date comes first, then date exceptions override it. Closed days count as **missing**, not zero.
3. Builds daily series:
   - Appointments: completed or checked-in visits.
   - Sales: payments received.
   - Inventory: units "Used in treatment".

   For live data, an open day with no activity **and** no staff login is treated as *suspected missing*.
4. Per series, compares **seasonal naive** (baseline), **multiple linear regression**, **ARIMA** and **SARIMA (weekly)** using 4 rolling one-week tests. It reports MAE, RMSE, MASE and MAPE, and picks the lowest MASE; on a tie, the simpler model wins.
   - Models need ≥ 56 observed days (the baseline needs ≥ 14). Below that the series is marked `insufficient_data` and the dashboard shows "Collecting data".
   - A model that diverges on any test week is marked `failed`.
5. Forecasts 14 days ahead with the selected model and stores a 95% interval. Closed future days get no forecast.
6. Inventory: forecast use vs stock gives days of stock and a reorder flag.

## Tests

```
python -m pytest tests -q
```
The tests never use the network or the database.
