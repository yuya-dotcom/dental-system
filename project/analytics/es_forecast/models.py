"""Candidate forecasting models (plan decisions D6, D10).

  seasonal_naive  baseline: the same weekday's most recent observed value
  mlr             multiple linear regression (OLS) on open days:
                  weekday dummies (Monday = baseline) + linear trend
  arima           ARIMA(p,d,q)            via statsmodels SARIMAX (handles NaN)
  sarima          SARIMA(p,d,q)(P,D,Q)7   via statsmodels SARIMAX

All models: closed future days → NaN, values clipped at 0,
95% intervals for mlr/arima/sarima (none for the baseline).
"""
from __future__ import annotations

import itertools
import warnings
from dataclasses import dataclass

import numpy as np
import pandas as pd

from .config import SEASON

ARIMA_GRID = [(p, d, q) for p in (0, 1, 2) for d in (0, 1) for q in (0, 1, 2)]
SARIMA_GRID = [((p, d, q), (P, D, Q, SEASON))
               for p, d, q, P, D, Q in itertools.product((0, 1), (0, 1), (0, 1), (0, 1), (0, 1), (0, 1))]
NAIVE_LOOKBACK_WEEKS = 5
ALPHA = 0.05


class ModelError(Exception):
    """Any failure inside a model fit/forecast, with the original message."""


@dataclass
class Forecast:
    yhat: pd.Series
    lower: pd.Series | None
    upper: pd.Series | None
    order: str


def _quiet():
    ctx = warnings.catch_warnings()
    ctx.__enter__()
    warnings.simplefilter("ignore")
    return ctx


def _sarimax(y: pd.Series, order, seasonal_order=(0, 0, 0, 0)):
    from statsmodels.tsa.statespace.sarimax import SARIMAX
    diffed = order[1] > 0 or seasonal_order[1] > 0
    return SARIMAX(y.to_numpy(dtype=float), order=order, seasonal_order=seasonal_order,
                   trend="n" if diffed else "c",
                   enforce_stationarity=False, enforce_invertibility=False)


def select_order(name: str, y: pd.Series) -> tuple:
    """Lowest-AIC order from the plan's grid; () for models without orders."""
    if name not in ("arima", "sarima"):
        return ()
    grid = ARIMA_GRID if name == "arima" else SARIMA_GRID
    best, best_aic = None, np.inf
    ctx = _quiet()
    try:
        for cand in grid:
            order, seas = (cand, (0, 0, 0, 0)) if name == "arima" else cand
            try:
                res = _sarimax(y, order, seas).fit(disp=False, maxiter=50)
                aic = float(res.aic)
            except Exception:
                continue
            if np.isfinite(aic) and aic < best_aic:
                best, best_aic = cand, aic
    finally:
        ctx.__exit__(None, None, None)
    if best is None:
        raise ModelError(f"no {name} order could be fitted")
    return best


def _finish(yhat, lower, upper, future_open: pd.Series, order: str) -> Forecast:
    closed = ~future_open.to_numpy(dtype=bool)

    def fix(s):
        if s is None:
            return None
        arr = np.clip(np.asarray(s, dtype=float), 0, None)
        arr[closed] = np.nan
        return pd.Series(arr, index=future_open.index, dtype=float)

    return Forecast(fix(yhat), fix(lower), fix(upper), order)


def _seasonal_naive(y: pd.Series, future: pd.DatetimeIndex) -> np.ndarray:
    obs = y.dropna()
    floor = y.index[-1] - pd.Timedelta(weeks=NAIVE_LOOKBACK_WEEKS)
    out = []
    for f in future:
        same = obs[(obs.index.day_of_week == f.day_of_week) & (obs.index > floor)]
        out.append(float(same.iloc[-1]) if len(same) else np.nan)
    return np.array(out)


def _mlr_design(index: pd.DatetimeIndex, origin: pd.Timestamp, keep: list[int]) -> pd.DataFrame:
    X = pd.DataFrame(index=index)
    X["const"] = 1.0
    X["trend"] = (index - origin).days.astype(float)
    for dow in keep:
        X[f"dow{dow}"] = (index.day_of_week == dow).astype(float)
    return X


def _mlr(y: pd.Series, future: pd.DatetimeIndex):
    import statsmodels.api as sm
    obs = y.dropna()
    keep = [d for d in range(1, 7) if (obs.index.day_of_week == d).any()]   # Monday = baseline
    origin = y.index[0]
    X = _mlr_design(obs.index, origin, keep)
    res = sm.OLS(obs.to_numpy(dtype=float), X.to_numpy()).fit()
    frame = res.get_prediction(_mlr_design(future, origin, keep).to_numpy()).summary_frame(alpha=ALPHA)
    return frame["mean"].to_numpy(), frame["obs_ci_lower"].to_numpy(), frame["obs_ci_upper"].to_numpy()


SANITY_FACTOR = 10.0


def check_sane(name: str, mean: np.ndarray, y: pd.Series) -> None:
    """Reject runaway forecasts (non-finite, or > 10× the largest value ever observed).
    Unstable ARIMA/SARIMA fits can explode instead of failing; scoring them would
    put absurd errors (e.g. MASE in the billions) into the evaluation."""
    mean = np.asarray(mean, dtype=float)
    if not np.all(np.isfinite(mean)):
        raise ModelError(f"{name} produced non-finite forecasts")
    peak = float(np.nanmax(np.abs(y.to_numpy(dtype=float)))) if y.notna().any() else 0.0
    if np.max(np.abs(mean)) > SANITY_FACTOR * peak + 1.0:
        raise ModelError(f"{name} forecast diverged (max {np.max(np.abs(mean)):.3g} vs observed max {peak:.3g})")


def fit_forecast(name: str, y: pd.Series, future: pd.DatetimeIndex, future_open: pd.Series,
                 order: tuple) -> Forecast:
    """Fit `name` on y (daily, NaN = closed/missing) and forecast the dates in `future`,
    which must start the day after y ends."""
    try:
        if name == "seasonal_naive":
            return _finish(_seasonal_naive(y, future), None, None, future_open, "")
        if name == "mlr":
            mean, lo, hi = _mlr(y, future)
            return _finish(mean, lo, hi, future_open, "OLS weekday+trend")
        if name in ("arima", "sarima"):
            o, s = (order, (0, 0, 0, 0)) if name == "arima" else order
            steps = (future[-1] - y.index[-1]).days
            ctx = _quiet()
            try:
                res = _sarimax(y, o, s).fit(disp=False, maxiter=50)
                fc = res.get_forecast(steps)
                mean = np.asarray(fc.predicted_mean)[-len(future):]
                ci = np.asarray(fc.conf_int(alpha=ALPHA))[-len(future):]
            finally:
                ctx.__exit__(None, None, None)
            check_sane(name, mean, y)
            label = f"{tuple(o)}" if name == "arima" else f"{tuple(o)}{tuple(s)}"
            return _finish(mean, ci[:, 0], ci[:, 1], future_open, label)
        raise ModelError(f"unknown model {name}")
    except ModelError:
        raise
    except Exception as e:  # statsmodels / numpy / linear algebra failures
        raise ModelError(f"{name}: {type(e).__name__}: {e}") from e
