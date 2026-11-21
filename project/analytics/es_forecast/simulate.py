"""SIMULATED clinic history for development and the defense demo (plan D2, Task 5).

Nothing here is real clinic data. Every generated record is marked
(appointment codes "SIM-…", item names "SIM …", ids in the 9000+ range),
the files live only in analytics/sample_data/, and the forecasting job
tags every result made from them with data_source = 'simulated'.

The ground truth (base levels, weekday pattern, trend, usage rates) is
saved to truth.json so the pipeline can be checked against known values.

Run:  python -m es_forecast.simulate      (writes sample_data/, seed 2026)
"""
from __future__ import annotations

import json
from datetime import date, datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

import numpy as np

from .config import SAMPLE_DIR, TZ
from .extract import RawData, to_csv
from .open_days import build_calendar

BRANCHES = [
    {"branch_id": 1, "branch_name": "Caloocan City", "operating_days": [1, 2, 3, 4, 5, 6], "status": "active"},
    {"branch_id": 2, "branch_name": "Mandaluyong City", "operating_days": [1, 2, 3, 4, 5, 6], "status": "active"},
    {"branch_id": 3, "branch_name": "Quezon City", "operating_days": [1, 2, 3, 4, 5, 6], "status": "active"},
]
BASE = {3: 12.0, 2: 9.0, 1: 7.0}                      # completed appointments per open day
WEEKDAY_MULT = {"Mon": 1.10, "Tue": 0.85, "Wed": 0.90, "Thu": 0.95, "Fri": 1.05, "Sat": 1.35, "Sun": 1.00}
TREND_PER_WEEK = 0.004                                # +0.4% per week
PAY_MEDIAN, PAY_SIGMA, PAY_STEP, PAY_MIN = 1500.0, 0.6, 50.0, 300.0
ITEMS = [  # (name, units per appointment)
    ("SIM Gloves (pair)", 2.0),
    ("SIM Mask", 1.0),
    ("SIM Anesthetic cartridge", 0.3),
    ("SIM Composite syringe", 0.15),
    ("SIM Cotton roll pack", 0.5),
]
HOLIDAYS = [(12, 8), (12, 25), (1, 1)]
DOW_NAMES = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]


def _yesterday() -> date:
    return datetime.now(ZoneInfo(TZ)).date() - timedelta(days=1)


def item_id(branch_id: int, k: int) -> int:
    return 9000 + branch_id * 10 + k


def generate(seed: int = 2026, days: int = 180, end: date | None = None) -> tuple[RawData, dict]:
    end = end or _yesterday()
    start = end - timedelta(days=days - 1)
    rng = np.random.default_rng(seed)

    # Closures: holidays for every branch + one Sunday/Monday swap at Quezon City
    exceptions = []
    for y in {start.year, end.year}:
        for m, d in HOLIDAYS:
            hd = date(y, m, d)
            if start <= hd <= end and hd.weekday() != 6:
                exceptions += [{"branch_id": b["branch_id"], "exception_date": hd.isoformat(), "is_open": False,
                                "reason": "SIM holiday"} for b in BRANCHES]
    swap_sun = end - timedelta(days=(end.weekday() + 1) % 7 + 42)   # a Sunday ~6 weeks before the end
    swap_mon = swap_sun + timedelta(days=1)
    exceptions += [
        {"branch_id": 3, "exception_date": swap_sun.isoformat(), "is_open": True, "reason": "SIM swap"},
        {"branch_id": 3, "exception_date": swap_mon.isoformat(), "is_open": False, "reason": "SIM swap"},
    ]

    cal = build_calendar(BRANCHES, exceptions, [], start, end)
    appointments, invoices, payments, movements = [], [], [], []
    inv_id = 900000
    for row in cal.itertuples(index=False):
        if not row.is_open:
            continue
        d = row.date.date()
        t_weeks = (d - start).days / 7.0
        lam = BASE[row.branch_id] * WEEKDAY_MULT[DOW_NAMES[d.weekday()]] * (1 + TREND_PER_WEEK) ** t_weeks
        n = int(rng.poisson(lam))
        iso = d.isoformat()
        for k in range(n):
            appointments.append({"branch_id": row.branch_id, "appointment_date": iso, "status": "completed",
                                 "appointment_code": f"SIM-{row.branch_id}-{iso}-{k}"})
            inv_id += 1
            amount = max(PAY_MIN, round(PAY_MEDIAN * float(np.exp(rng.normal(0, PAY_SIGMA))) / PAY_STEP) * PAY_STEP)
            invoices.append({"invoice_id": inv_id, "branch_id": row.branch_id})
            payments.append({"invoice_id": inv_id, "amount": amount, "payment_date": iso})
        for k, (_name, rate) in enumerate(ITEMS, start=1):
            used = int(rng.poisson(n * rate)) if n else 0
            if used:
                movements.append({"item_id": item_id(row.branch_id, k), "branch_id": row.branch_id,
                                  "quantity_change": -used, "reason": "Used in treatment #SIM", "movement_date": iso})

    inventory = [{"item_id": item_id(b["branch_id"], k), "item_name": name, "branch_id": b["branch_id"],
                  "stock_quantity": int(rng.integers(60, 401)), "min_stock": 20, "archived_at": None}
                 for b in BRANCHES for k, (name, _r) in enumerate(ITEMS, start=1)]

    raw = RawData(branches=BRANCHES, exceptions=exceptions, branch_audit=[], logins=[],
                  appointments=appointments, invoices=invoices, payments=payments,
                  movements=movements, inventory=inventory)
    truth = {
        "note": "SIMULATED data for development/demo only - not clinic records.",
        "seed": seed, "start": start.isoformat(), "end": end.isoformat(), "days": days,
        "base_per_open_day": {str(k): v for k, v in BASE.items()},
        "weekday_multipliers": WEEKDAY_MULT, "trend_per_week": TREND_PER_WEEK,
        "payment": {"median": PAY_MEDIAN, "sigma": PAY_SIGMA, "step": PAY_STEP, "min": PAY_MIN},
        "items": [{"name": n, "units_per_appointment": r} for n, r in ITEMS],
        "swap": {"open_sunday": swap_sun.isoformat(), "closed_monday": swap_mon.isoformat()},
    }
    return raw, truth


def write_csv(raw: RawData, truth: dict, folder: Path) -> None:
    to_csv(raw, folder)
    (Path(folder) / "truth.json").write_text(json.dumps(truth, indent=2), encoding="utf-8")


if __name__ == "__main__":
    r, t = generate()
    write_csv(r, t, SAMPLE_DIR)
    print(f"Simulated {len(r.appointments)} appointments, {len(r.payments)} payments, "
          f"{len(r.movements)} usage rows ({t['start']} .. {t['end']}) -> {SAMPLE_DIR}")
