"""EssenciaSmile predictive analytics — one forecasting run.

    python run_forecast.py --source simulated            demo data (sample_data/*.csv)
    python run_forecast.py --source live                 real records from Supabase
    python run_forecast.py --source live --dry-run       compute only, write nothing

Per branch and target (appointments, sales, inventory items) it builds the
daily series, evaluates seasonal naive / MLR / ARIMA / SARIMA with rolling
origin validation, selects the best model, forecasts the next 14 days and
stores everything in forecast_runs / forecast_values / forecast_metrics /
forecast_inventory. Exit code 0 = success, 1 = failed.
"""
from __future__ import annotations

import argparse
import json
import sys
import time
from datetime import date, datetime, timedelta
from zoneinfo import ZoneInfo

import pandas as pd

from es_forecast.config import (ACTUAL_DAYS, FOLD_HORIZON, FOLDS, HORIZON, METRICS, MIN_OBS, SAMPLE_DIR, TZ,
                                load_credentials)
from es_forecast.evaluate import evaluate_series, select_best
from es_forecast.extract import RawData, from_csv, from_supabase
from es_forecast.inventory import reorder_status
from es_forecast.models import ModelError, fit_forecast
from es_forecast.open_days import build_calendar
from es_forecast.series import build_series
from es_forecast.store import Store
from es_forecast.supa import Supa

LIVE_LOOKBACK_DAYS = 365


def _yesterday() -> date:
    return datetime.now(ZoneInfo(TZ)).date() - timedelta(days=1)


def _first_activity(raw: RawData) -> date | None:
    dates = [str(a.get("appointment_date"))[:10] for a in raw.appointments] \
        + [str(p.get("payment_date"))[:10] for p in raw.payments] \
        + [str(m.get("movement_date"))[:10] for m in raw.movements]
    dates = [d for d in dates if len(d) == 10 and d[4] == "-"]
    return date.fromisoformat(min(dates)) if dates else None


def _simulated_end(raw: RawData) -> date:
    truth = SAMPLE_DIR / "truth.json"
    if truth.exists():
        return date.fromisoformat(json.loads(truth.read_text(encoding="utf-8"))["end"])
    return date.fromisoformat(max(str(a["appointment_date"])[:10] for a in raw.appointments))


def forecast_series(key, y: pd.Series, future: pd.DatetimeIndex, future_open: pd.Series,
                    open_hist: pd.Series):
    """Evaluate, select and forecast one series → (metric rows, value rows, selected Forecast | None)."""
    branch_id, metric, item_id = key
    base = {"branch_id": branch_id, "metric": metric, "item_id": item_id}
    scores = evaluate_series(y)
    best = select_best(scores)
    metrics, values, selected = [], [], None

    hist = y.tail(ACTUAL_DAYS)
    for d, v in hist.items():
        values.append({**base, "kind": "actual", "model": None, "value_date": d.date(),
                       "is_open": bool(open_hist.get(d, False)), "yhat": v, "yhat_lower": None, "yhat_upper": None,
                       "is_selected": False})

    for s in scores:
        row = {**base, "model": s.model, "status": s.status, "n_obs": s.n_obs, "n_folds": s.n_folds,
               "mae": s.mae, "rmse": s.rmse, "mase": s.mase, "mape": s.mape,
               "is_selected": s.model == best, "model_order": s.order or None, "message": s.message or None}
        if s.status == "ok":
            try:
                f = fit_forecast(s.model, y, future, future_open, s.order_params)
            except ModelError as e:
                row.update(status="failed", is_selected=False, message=str(e))
                metrics.append(row)
                continue
            if s.model == best:
                selected = f
            for d in future:
                values.append({**base, "kind": "forecast", "model": s.model, "value_date": d.date(),
                               "is_open": bool(future_open[d]), "yhat": f.yhat[d],
                               "yhat_lower": None if f.lower is None else f.lower[d],
                               "yhat_upper": None if f.upper is None else f.upper[d],
                               "is_selected": s.model == best})
        metrics.append(row)

    if best is not None and selected is None:      # winner failed on the full series → no selection
        for r in metrics:
            r["is_selected"] = False
        for r in values:
            r["is_selected"] = False
    return metrics, values, selected


def main(argv: list[str] | None = None, store: Store | None = None, raw: RawData | None = None,
         end: date | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--source", choices=("live", "simulated"), required=True)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--metrics", default=",".join(METRICS))
    ap.add_argument("--triggered-by", default="scheduler")
    args = ap.parse_args(argv)
    wanted = {m.strip() for m in args.metrics.split(",") if m.strip()}

    if store is None:
        supa = Supa(*load_credentials())
        store = Store(None if args.dry_run else supa, args.dry_run)
    else:
        supa = None

    t0 = time.time()
    run_id = store.start_run(args.source, HORIZON, None, args.triggered_by)
    try:
        if raw is None:
            if args.source == "simulated":
                raw = from_csv(SAMPLE_DIR)
            else:
                supa = supa or Supa(*load_credentials())
                raw = from_supabase(supa, _yesterday() - timedelta(days=LIVE_LOOKBACK_DAYS))
        if end is None:
            end = _simulated_end(raw) if args.source == "simulated" else _yesterday()
        first = _first_activity(raw)
        start = max(first, end - timedelta(days=LIVE_LOOKBACK_DAYS)) if first else end - timedelta(days=27)
        start = min(start, end)

        calendar = build_calendar(raw.branches, raw.exceptions, raw.branch_audit, start, end + timedelta(days=HORIZON))
        series, report = build_series(raw, calendar, start, end, detect_missing=(args.source == "live"))
        future = pd.date_range(end + timedelta(days=1), periods=HORIZON, freq="D")
        stock = {(int(i["branch_id"]), int(i["item_id"])): i for i in raw.inventory if not i.get("archived_at")}

        counts = {"series": 0, "selected": 0, "insufficient": 0, "failed": 0}
        for key, y in series.items():
            if key[1] not in wanted:
                continue
            counts["series"] += 1
            cal_b = calendar[calendar.branch_id == key[0]].set_index("date")["is_open"]
            future_open = cal_b.reindex(future, fill_value=False).astype(bool)
            try:
                metrics, values, selected = forecast_series(key, y, future, future_open, cal_b)
            except Exception as e:  # one bad series must not stop the others
                counts["failed"] += 1
                store.add_metrics(run_id, [{"branch_id": key[0], "metric": key[1], "item_id": key[2],
                                            "model": "pipeline", "status": "failed", "message": str(e)[:500]}])
                continue
            store.add_metrics(run_id, metrics)
            store.add_values(run_id, values)
            if any(r["is_selected"] for r in metrics):
                counts["selected"] += 1
            elif not any(r["status"] == "ok" for r in metrics):
                counts["insufficient"] += 1
            if key[1] == "inventory" and (key[0], key[2]) in stock:
                item = stock[(key[0], key[2])]
                status = reorder_status(y, selected, item.get("stock_quantity"), item.get("min_stock"), future_open)
                store.add_inventory(run_id, [{"branch_id": key[0], "item_id": key[2],
                                              "item_name": item.get("item_name"), **status}])

        summary = {**counts, "start": start.isoformat(), "end": end.isoformat(),
                   "open_days": report["open_days"], "suspected_missing": report["suspected_missing"],
                   "min_obs": dict(MIN_OBS), "folds": FOLDS, "fold_horizon": FOLD_HORIZON,
                   "seconds": round(time.time() - t0, 1)}
        store.rows["run"]["last_observed_date"] = end.isoformat()
        if not store.dry_run:
            store.supa.patch("forecast_runs", {"run_id": run_id}, {"last_observed_date": end.isoformat()})
        store.finish(run_id, "success", summary)
        print(f"run {run_id} ({args.source}) success: {json.dumps(summary)}")
        return 0
    except Exception as e:
        store.finish(run_id, "failed", None, f"{type(e).__name__}: {e}")
        print(f"run {run_id} ({args.source}) FAILED: {type(e).__name__}: {e}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
