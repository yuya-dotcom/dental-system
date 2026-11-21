"""Rolling-origin evaluation, data-sufficiency gate and model selection (D7–D9).

For a series ending on day E the four fold origins are E-28, E-21, E-14, E-7:
each fold trains on everything up to its origin and forecasts the next 7 days.
Model orders (ARIMA/SARIMA) are chosen by AIC on data up to the FIRST origin
only, so no test day ever influences the model choice (no leakage).
"""
from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np
import pandas as pd

from .config import FOLD_HORIZON, FOLDS, MIN_OBS, MODEL_ORDER, SEASON
from .models import ModelError, fit_forecast, select_order

MIN_FOLD_TRAIN = {"seasonal_naive": 7, "mlr": 28, "arima": 28, "sarima": 28}
TIE = 0.01


def _pairs(a, p):
    a = np.asarray(a, dtype=float)
    p = np.asarray(p, dtype=float)
    ok = np.isfinite(a) & np.isfinite(p)
    return a[ok], p[ok]


def mae(a, p) -> float | None:
    a, p = _pairs(a, p)
    return float(np.mean(np.abs(a - p))) if len(a) else None


def rmse(a, p) -> float | None:
    a, p = _pairs(a, p)
    return float(np.sqrt(np.mean((a - p) ** 2))) if len(a) else None


def mase(a, p, y_train, m: int = SEASON) -> float | None:
    """MAE divided by the in-sample MAE of the seasonal naive forecast (lag m)."""
    err = mae(a, p)
    y = np.asarray(y_train, dtype=float)
    if err is None or len(y) <= m:
        return None
    d = np.abs(y[m:] - y[:-m])
    d = d[np.isfinite(d)]
    if not len(d) or float(np.mean(d)) == 0.0:
        return None
    return err / float(np.mean(d))


def mape_nonzero(a, p) -> float | None:
    a, p = _pairs(a, p)
    nz = a > 0
    if not nz.any():
        return None
    return float(np.mean(np.abs(a[nz] - p[nz]) / a[nz]) * 100)


@dataclass
class ModelScore:
    model: str
    status: str                 # ok | insufficient_data | failed
    n_obs: int
    n_folds: int
    mae: float | None
    rmse: float | None
    mase: float | None
    mape: float | None
    order: str
    message: str
    order_params: tuple = field(default=())   # passed to fit_forecast for the final forecast


def _mean(vals):
    vals = [v for v in vals if v is not None]
    return float(np.mean(vals)) if vals else None


def fold_origins(y: pd.Series) -> list[pd.Timestamp]:
    end = y.index[-1]
    return [end - pd.Timedelta(days=FOLD_HORIZON * (FOLDS - k)) for k in range(FOLDS)]


def evaluate_series(y: pd.Series) -> list[ModelScore]:
    n_obs = int(y.notna().sum())
    origins = fold_origins(y)
    scores = []
    for name in MODEL_ORDER:
        if n_obs < MIN_OBS[name]:
            scores.append(ModelScore(name, "insufficient_data", n_obs, 0, None, None, None, None, "",
                                     f"needs {MIN_OBS[name]} observed days, has {n_obs}"))
            continue
        try:
            order = select_order(name, y[y.index <= origins[0]])   # data before the first origin only
        except ModelError as e:
            scores.append(ModelScore(name, "failed", n_obs, 0, None, None, None, None, "", str(e)))
            continue

        folds, label, error = [], "", ""
        for origin in origins:
            train = y[y.index <= origin]
            test = y[(y.index > origin) & (y.index <= origin + pd.Timedelta(days=FOLD_HORIZON))]
            if train.notna().sum() < MIN_FOLD_TRAIN[name] or not test.notna().any():
                continue
            try:
                f = fit_forecast(name, train, test.index, pd.Series(True, index=test.index), order)
            except ModelError as e:
                # Unstable on any fold → the model is not trustworthy for this series
                error = str(e)
                break
            label = f.order
            folds.append((mae(test, f.yhat), rmse(test, f.yhat), mase(test, f.yhat, train), mape_nonzero(test, f.yhat)))

        if error or not folds:
            scores.append(ModelScore(name, "failed" if error else "insufficient_data", n_obs, 0,
                                     None, None, None, None, label, error or "no usable evaluation fold", order))
            continue
        cols = list(zip(*folds))
        scores.append(ModelScore(name, "ok", n_obs, len(folds), _mean(cols[0]), _mean(cols[1]),
                                 _mean(cols[2]), _mean(cols[3]), label, error, order))
    return scores


def select_best(scores: list[ModelScore]) -> str | None:
    """Lowest mean MASE; within TIE of the best, the simpler model wins (MODEL_ORDER)."""
    ok = [s for s in scores if s.status == "ok"]
    if not ok:
        return None
    rated = [s for s in ok if s.mase is not None]
    if not rated:
        return min(ok, key=lambda s: MODEL_ORDER.index(s.model)).model
    best = min(s.mase for s in rated)
    close = [s for s in rated if s.mase <= best + TIE]
    return min(close, key=lambda s: MODEL_ORDER.index(s.model)).model
