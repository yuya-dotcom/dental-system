from datetime import date

import pandas as pd

from es_forecast.open_days import build_calendar


def _open(cal, branch_id, d):
    row = cal[(cal.branch_id == branch_id) & (cal.date == pd.Timestamp(d))]
    assert len(row) == 1
    return bool(row.iloc[0].is_open)


def test_weekly_days_default_mon_to_sat():
    cal = build_calendar([{"branch_id": 1, "operating_days": None}], [], [], date(2026, 11, 1), date(2026, 11, 30))
    assert len(cal) == 30
    assert _open(cal, 1, "2026-11-15") is False   # Sunday
    assert _open(cal, 1, "2026-11-16") is True    # Monday


def test_exception_overrides_weekly():
    exc = [{"branch_id": 1, "exception_date": "2026-11-15", "is_open": True}]
    cal = build_calendar([{"branch_id": 1, "operating_days": [1, 2, 3, 4, 5, 6]}], exc, [], date(2026, 11, 1), date(2026, 11, 30))
    assert _open(cal, 1, "2026-11-15") is True
    assert _open(cal, 1, "2026-11-22") is False


def test_audit_history_reconstructs_past_schedule():
    branches = [{"branch_id": 3, "operating_days": [0, 2, 3, 4, 5, 6]}]
    audit = [{"entity_id": "3", "branch_id": 3, "created_at": "2026-11-12T06:43:24+00:00",
              "before_data": {"operating_days": [1, 2, 3, 4, 5, 6]},
              "after_data": {"operating_days": [0, 2, 3, 4, 5, 6]}}]
    cal = build_calendar(branches, [], audit, date(2026, 11, 1), date(2026, 11, 30))
    assert _open(cal, 3, "2026-11-10") is True    # Mon before the change
    assert _open(cal, 3, "2026-11-08") is False   # Sun before the change
    assert _open(cal, 3, "2026-11-15") is True    # Sun after
    assert _open(cal, 3, "2026-11-16") is False   # Mon after
