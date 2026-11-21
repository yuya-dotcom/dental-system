import numpy as np
import pandas as pd
import pytest

from es_forecast.models import Forecast, ModelError, fit_forecast, select_order

PATTERN = [10, 6, 7, 8, 9, 14, np.nan]   # Mon..Sun (Sunday closed)


def weekly(weeks=10, start="2026-01-05", noise=0.0, trend=0.0, seed=1):
    idx = pd.date_range(start, periods=weeks * 7, freq="D")   # 2026-01-05 is a Monday
    rng = np.random.default_rng(seed)
    vals = [PATTERN[d.day_of_week] + trend * i + (rng.normal(0, noise) if noise else 0) for i, d in enumerate(idx)]
    return pd.Series(vals, index=idx, dtype=float)


def future_of(y, n=14):
    fut = pd.date_range(y.index[-1] + pd.Timedelta(days=1), periods=n, freq="D")
    return fut, pd.Series([d.day_of_week != 6 for d in fut], index=fut)


def test_seasonal_naive_repeats_last_week():
    y = weekly(4)
    y.iloc[-7] = 99.0                        # last Monday
    fut, op = future_of(y)
    f = fit_forecast("seasonal_naive", y, fut, op, ())
    assert isinstance(f, Forecast)
    assert f.yhat.iloc[0] == 99.0            # next Monday = last Monday
    assert f.yhat.iloc[7] == 99.0            # Monday after that too
    assert f.lower is None and f.upper is None


def test_mlr_recovers_weekday_pattern():
    y = weekly(8)
    fut, op = future_of(y)
    f = fit_forecast("mlr", y, fut, op, ())
    for d, v in f.yhat.items():
        if d.day_of_week != 6:
            assert abs(v - PATTERN[d.day_of_week]) < 1e-6


def test_closed_future_days_are_nan():
    y = weekly(10, noise=0.5)
    fut, op = future_of(y)
    for name in ("seasonal_naive", "mlr", "arima"):
        order = select_order(name, y) if name == "arima" else ()
        f = fit_forecast(name, y, fut, op, order)
        assert f.yhat[~op].isna().all(), name
        assert f.yhat[op].notna().all(), name


def test_forecasts_never_negative():
    y = weekly(10, trend=-0.25)              # falls below zero within the horizon
    fut, op = future_of(y, 28)
    f = fit_forecast("mlr", y, fut, op, ())
    assert (f.yhat.dropna() >= 0).all()
    assert (f.lower.dropna() >= 0).all()


def test_model_failure_is_contained():
    y = pd.Series(0.0, index=pd.date_range("2026-01-05", periods=70, freq="D"))
    fut, op = future_of(y)
    for name in ("arima", "sarima"):
        try:
            order = select_order(name, y)
            f = fit_forecast(name, y, fut, op, order)
            assert isinstance(f, Forecast)
        except ModelError:
            pass


def test_explosive_forecast_rejected():
    from es_forecast.models import check_sane
    y = pd.Series([5.0, 6.0, np.nan, 7.0])
    check_sane("sarima", np.array([5.0, 9.0]), y)              # plausible → no error
    with pytest.raises(ModelError):
        check_sane("sarima", np.array([5.0, 1e9]), y)          # runaway → rejected
    with pytest.raises(ModelError):
        check_sane("arima", np.array([5.0, np.inf]), y)
