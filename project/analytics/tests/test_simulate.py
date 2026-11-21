from collections import Counter
from datetime import date

import pandas as pd

from es_forecast import simulate
from es_forecast.extract import from_csv

END = date(2026, 11, 11)


def _per_day(raw, branch_id):
    return Counter(a["appointment_date"] for a in raw.appointments if a["branch_id"] == branch_id)


def test_same_seed_same_data():
    a, _ = simulate.generate(seed=7, days=60, end=END)
    b, _ = simulate.generate(seed=7, days=60, end=END)
    assert _per_day(a, 3) == _per_day(b, 3)
    assert len(a.payments) == len(b.payments)


def test_sundays_closed_except_swap():
    raw, truth = simulate.generate(seed=2026, days=180, end=END)
    swap_sunday = truth["swap"]["open_sunday"]
    for a in raw.appointments:
        if pd.Timestamp(a["appointment_date"]).day_of_week == 6:
            assert a["branch_id"] == 3 and a["appointment_date"] == swap_sunday
    assert any(a["appointment_date"] == swap_sunday for a in raw.appointments)
    assert not any(a["appointment_date"] == truth["swap"]["closed_monday"] and a["branch_id"] == 3 for a in raw.appointments)


def test_weekday_effect_recoverable():
    raw, truth = simulate.generate(seed=2026, days=180, end=END)
    counts = _per_day(raw, 3)
    by_dow = {}
    for d, n in counts.items():
        by_dow.setdefault(pd.Timestamp(d).day_of_week, []).append(n)
    sat = sum(by_dow[5]) / len(by_dow[5])
    tue = sum(by_dow[1]) / len(by_dow[1])
    expected = truth["weekday_multipliers"]["Sat"] / truth["weekday_multipliers"]["Tue"]
    assert abs(sat / tue - expected) / expected < 0.15


def test_csv_roundtrip(tmp_path):
    raw, truth = simulate.generate(seed=2026, days=30, end=END)
    simulate.write_csv(raw, truth, tmp_path)
    back = from_csv(tmp_path)
    for name in ("branches", "appointments", "payments", "invoices", "movements", "inventory", "exceptions"):
        assert len(getattr(back, name)) == len(getattr(raw, name)), name
    assert (tmp_path / "truth.json").exists()
