import math
from datetime import date

from es_forecast.open_days import build_calendar
from es_forecast.series import build_series
from tests.fixtures import week_raw

START, END = date(2026, 11, 9), date(2026, 11, 15)


def _build(detect_missing=False):
    raw = week_raw()
    cal = build_calendar(raw.branches, raw.exceptions, raw.branch_audit, START, END)
    return build_series(raw, cal, START, END, detect_missing=detect_missing)


def test_appointments_counts_completed_and_checked_in_only():
    s, _ = _build()
    a = s[(3, "appointments", None)]
    assert a["2026-11-09"] == 2
    assert a["2026-11-10"] == 0
    assert a["2026-11-11"] == 1


def test_sales_use_payment_date_and_invoice_branch():
    s, _ = _build()
    assert s[(3, "sales", None)]["2026-11-10"] == 2000
    assert s[(1, "sales", None)]["2026-11-11"] == 900
    assert s[(3, "sales", None)]["2026-11-11"] == 0


def test_inventory_counts_only_used_in_treatment_outflows():
    s, _ = _build()
    inv = s[(3, "inventory", 7)]
    assert inv["2026-11-09"] == 2
    assert inv["2026-11-12"] == 0          # restock and "Tooth" ignored
    assert (3, "inventory", 8) not in s    # fewer than 5 usage days


def test_closed_day_is_nan_open_empty_day_is_zero():
    s, rep = _build()
    a = s[(3, "appointments", None)]
    assert math.isnan(a["2026-11-15"])     # Sunday closed
    assert a["2026-11-12"] == 0            # open, nothing happened
    assert len(a) == 7 and a.index.freqstr == "D"
    assert rep["open_days"][3] == 6


def test_suspected_missing_day_is_nan_only_when_detect_missing():
    s, rep = _build(detect_missing=True)
    assert math.isnan(s[(3, "appointments", None)]["2026-11-12"])   # no activity, no login
    assert s[(3, "appointments", None)]["2026-11-13"] == 0          # login, no visits → real zero
    assert rep["suspected_missing"][3] == 1
    s2, _ = _build(detect_missing=False)
    assert s2[(3, "appointments", None)]["2026-11-12"] == 0


def test_branch_without_rows_gives_all_nan_series():
    s, rep = _build(detect_missing=True)
    a = s[(2, "appointments", None)]
    assert len(a) == 7 and a.isna().all()
    assert rep["suspected_missing"][2] == 6
