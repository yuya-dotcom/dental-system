from datetime import date

import pandas as pd
import pytest

import run_forecast
from es_forecast import simulate
from es_forecast.inventory import reorder_status
from es_forecast.models import Forecast
from es_forecast.store import Store
from tests.fixtures import live_like_raw

END = date(2026, 11, 11)


def test_simulated_run_produces_forecasts_for_every_branch_and_metric():
    raw, _ = simulate.generate(seed=2026, days=90, end=END)
    store = Store(None, dry_run=True)
    code = run_forecast.main(["--source", "simulated", "--dry-run", "--metrics", "appointments,sales"],
                             store=store, raw=raw, end=END)
    assert code == 0
    assert store.rows["run"]["status"] == "success"
    for b in (1, 2, 3):
        for m in ("appointments", "sales"):
            sel = [r for r in store.rows["metrics"] if r["branch_id"] == b and r["metric"] == m and r["is_selected"]]
            assert len(sel) == 1, (b, m)
            fc = [r for r in store.rows["values"] if r["branch_id"] == b and r["metric"] == m
                  and r["kind"] == "forecast" and r["is_selected"]]
            assert len(fc) == 14
            assert all(r["yhat"] is not None for r in fc if r["is_open"])
            assert all(r["yhat"] is None for r in fc if not r["is_open"])
    assert all(r["data_source"] == "simulated" for r in [store.rows["run"]])


def test_live_like_short_series_is_insufficient():
    store = Store(None, dry_run=True)
    code = run_forecast.main(["--source", "live", "--dry-run"], store=store, raw=live_like_raw(), end=END)
    assert code == 0
    metrics = store.rows["metrics"]
    assert metrics
    assert not any(r["status"] == "ok" and r["model"] in ("mlr", "arima", "sarima") for r in metrics)
    gated = {(r["branch_id"], r["metric"]) for r in metrics} - \
        {(r["branch_id"], r["metric"]) for r in metrics if r["status"] == "ok"}
    assert (2, "appointments") in gated
    for b, m in gated:
        assert not [r for r in store.rows["values"] if r["kind"] == "forecast" and (r["branch_id"], r["metric"]) == (b, m)]


def test_run_marked_failed_on_exception(monkeypatch):
    def boom(*a, **k):
        raise RuntimeError("series exploded")

    monkeypatch.setattr(run_forecast, "build_series", boom)
    store = Store(None, dry_run=True)
    code = run_forecast.main(["--source", "live", "--dry-run"], store=store, raw=live_like_raw(), end=END)
    assert code == 1
    assert store.rows["run"]["status"] == "failed"
    assert "series exploded" in store.rows["run"]["error"]


def test_reorder_flag():
    fut = pd.date_range("2026-11-12", periods=14, freq="D")
    op = pd.Series(True, index=fut)
    f = Forecast(pd.Series(15 / 14, index=fut), None, None, "")
    st = reorder_status(pd.Series([1.0] * 28), f, 30, 20, op)
    assert st["needs_reorder"] is True and st["basis"] == "model"
    assert st["forecast_use"] == pytest.approx(15)
    assert st["days_of_stock"] == pytest.approx(28)
    st2 = reorder_status(pd.Series([2.0] * 28), None, 100, 20, op)
    assert st2["basis"] == "average" and st2["forecast_use"] == pytest.approx(28) and st2["needs_reorder"] is False
