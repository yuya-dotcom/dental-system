import math

import numpy as np
import pandas as pd
import pytest

from es_forecast import evaluate
from es_forecast.evaluate import ModelScore, evaluate_series, mae, mape_nonzero, mase, rmse, select_best

PATTERN = [10, 6, 7, 8, 9, 14, np.nan]


def weekly(days, noise=1.0, seed=3):
    idx = pd.date_range("2026-01-05", periods=days, freq="D")
    rng = np.random.default_rng(seed)
    return pd.Series([PATTERN[d.day_of_week] + rng.normal(0, noise) for d in idx], index=idx, dtype=float)


def test_metrics_known_values():
    a, p = [2, 4, 6], [1, 4, 8]
    assert mae(a, p) == pytest.approx(1.0)
    assert rmse(a, p) == pytest.approx(math.sqrt(5 / 3))


def test_mape_skips_zero_actuals():
    assert mape_nonzero([0, 10], [5, 12]) == pytest.approx(20.0)
    assert mape_nonzero([0, 0], [1, 2]) is None


def test_mase_scale_is_seasonal_naive_error():
    y_train = pd.Series([1, 2, 3, 4, 5, 6, 7, 3, 4, 5, 6, 7, 8, 9], dtype=float)   # |y_t - y_t-7| = 2
    assert mase([10, 10], [12, 8], y_train) == pytest.approx(1.0)
    assert mase([10], [12], pd.Series([5.0] * 14)) is None                          # zero scale


def test_gate_marks_short_series_insufficient():
    y = weekly(30)
    y.iloc[:6] = np.nan                       # ~20 observed days
    assert 14 <= y.notna().sum() < 56
    scores = {s.model: s for s in evaluate_series(y)}
    assert scores["seasonal_naive"].status == "ok"
    for m in ("mlr", "arima", "sarima"):
        assert scores[m].status == "insufficient_data"


def test_order_selection_uses_only_pre_origin_data(monkeypatch):
    seen = []
    real = evaluate.select_order

    def spy(name, y):
        seen.append(y.index[-1])
        return real(name, y)

    monkeypatch.setattr(evaluate, "select_order", spy)
    y = weekly(120)
    evaluate_series(y)
    first_origin = y.index[-1] - pd.Timedelta(days=28)
    assert seen and all(t <= first_origin for t in seen)


def test_tie_prefers_simpler_model():
    s = lambda m, v: ModelScore(m, "ok", 100, 4, 1.0, 1.0, v, None, "", "", ())  # noqa: E731
    assert select_best([s("sarima", 0.800), s("mlr", 0.805), s("arima", 0.9)]) == "mlr"
    assert select_best([s("sarima", 0.70), s("mlr", 0.80)]) == "sarima"
    assert select_best([ModelScore("arima", "failed", 100, 0, None, None, None, None, "", "x", ())]) is None


def test_full_series_scores_every_model():
    scores = {s.model: s for s in evaluate_series(weekly(120))}
    assert set(scores) == {"seasonal_naive", "mlr", "arima", "sarima"}
    assert all(s.status == "ok" and s.n_folds == 4 and s.mase is not None for s in scores.values())


def test_model_failing_any_fold_is_failed(monkeypatch):
    real = evaluate.fit_forecast
    calls = {"n": 0}

    def flaky(name, y, future, future_open, order):
        if name == "arima":
            calls["n"] += 1
            if calls["n"] == 2:
                raise evaluate.ModelError("arima forecast diverged")
        return real(name, y, future, future_open, order)

    monkeypatch.setattr(evaluate, "fit_forecast", flaky)
    scores = {s.model: s for s in evaluate_series(weekly(120))}
    assert scores["arima"].status == "failed"
    assert "diverged" in scores["arima"].message
    assert scores["mlr"].status == "ok"
