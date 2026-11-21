"""Daily target series per branch (plan decisions D4, D5).

Each series is a float pd.Series on a daily DatetimeIndex:
  NaN  — branch closed, or (live only) open day with no activity and no staff login
  0.0  — branch open, nothing recorded
  n    — the day's value
"""
from __future__ import annotations

from collections import defaultdict
from datetime import date

import numpy as np
import pandas as pd

from .config import INV_MIN_USAGE_DAYS
from .extract import RawData
from .open_days import local_date

SeriesKey = tuple  # (branch_id, metric, item_id | None)

COUNTED_STATUSES = {"completed", "checked_in"}
USAGE_PREFIX = "used in treatment"


def _d(v) -> str:
    return str(v)[:10]


def _num(v) -> float:
    try:
        return float(v)
    except (TypeError, ValueError):
        return 0.0


def build_series(raw: RawData, calendar: pd.DataFrame, start: date, end: date,
                 detect_missing: bool) -> tuple[dict, dict]:
    idx = pd.date_range(start, end, freq="D", name="date")
    lo, hi = start.isoformat(), end.isoformat()
    in_range = lambda d: lo <= d <= hi  # noqa: E731

    appts: dict = defaultdict(float)
    for a in raw.appointments:
        d = _d(a.get("appointment_date"))
        if a.get("status") in COUNTED_STATUSES and in_range(d):
            appts[(int(a["branch_id"]), d)] += 1

    inv_branch = {int(i["invoice_id"]): int(i["branch_id"]) for i in raw.invoices if i.get("branch_id") is not None}
    sales: dict = defaultdict(float)
    for p in raw.payments:
        d = _d(p.get("payment_date"))
        bid = inv_branch.get(int(p["invoice_id"])) if p.get("invoice_id") is not None else None
        if bid is not None and in_range(d):
            sales[(bid, d)] += _num(p.get("amount"))

    usage: dict = defaultdict(float)
    for m in raw.movements:
        d = _d(m.get("movement_date"))
        q = _num(m.get("quantity_change"))
        if q < 0 and str(m.get("reason") or "").strip().lower().startswith(USAGE_PREFIX) and in_range(d):
            usage[(int(m["branch_id"]), int(m["item_id"]), d)] += -q

    logins = set()
    for row in raw.logins:
        if row.get("branch_id") is not None:
            logins.add((int(row["branch_id"]), local_date(row["created_at"]).isoformat()))

    activity = {k for k, v in appts.items() if v} | {k for k, v in sales.items() if v} \
        | {(b, d) for (b, _i, d), v in usage.items() if v}

    series: dict = {}
    report = {"open_days": {}, "suspected_missing": {}}
    for bid, cal in calendar.groupby("branch_id"):
        bid = int(bid)
        open_map = dict(zip(cal["date"].dt.strftime("%Y-%m-%d"), cal["is_open"]))
        days = [d.strftime("%Y-%m-%d") for d in idx]
        is_open = np.array([bool(open_map.get(d, False)) for d in days])
        missing = np.array([detect_missing and o and (bid, d) not in activity and (bid, d) not in logins
                            for d, o in zip(days, is_open)])
        usable = is_open & ~missing
        report["open_days"][bid] = int(is_open.sum())
        report["suspected_missing"][bid] = int(missing.sum())

        def make(values):
            arr = np.where(usable, np.asarray(values, dtype=float), np.nan)
            return pd.Series(arr, index=idx, dtype=float)

        series[(bid, "appointments", None)] = make([appts.get((bid, d), 0.0) for d in days])
        series[(bid, "sales", None)] = make([sales.get((bid, d), 0.0) for d in days])

        items = sorted({i for (b, i, _d2) in usage if b == bid})
        for item in items:
            vals = [usage.get((bid, item, d), 0.0) for d in days]
            if sum(1 for v, u in zip(vals, usable) if v > 0 and u) >= INV_MIN_USAGE_DAYS:
                series[(bid, "inventory", item)] = make(vals)
    return series, report
