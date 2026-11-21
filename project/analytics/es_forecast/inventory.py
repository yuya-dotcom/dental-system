"""Inventory reorder status per item (plan decision D11)."""
from __future__ import annotations

import numpy as np
import pandas as pd

from .config import INV_AVG_WINDOW
from .models import Forecast


def reorder_status(series: pd.Series, forecast: Forecast | None, stock_on_hand: float, min_stock: float,
                   future_open: pd.Series) -> dict:
    open_ahead = int(future_open.sum())
    if forecast is not None and forecast.yhat.notna().any():
        use, basis = float(np.nansum(forecast.yhat.to_numpy(dtype=float))), "model"
    else:
        recent = series.dropna().tail(INV_AVG_WINDOW)
        use, basis = (float(recent.mean()) * open_ahead if len(recent) else 0.0), "average"
    per_day = use / open_ahead if open_ahead else 0.0
    stock = float(stock_on_hand or 0)
    floor = float(min_stock or 0)
    return {
        "stock_on_hand": stock,
        "min_stock": floor,
        "forecast_use": round(use, 2),
        "days_of_stock": round(stock / per_day, 1) if per_day > 0 else None,
        "needs_reorder": bool(stock - use <= floor),
        "basis": basis,
    }
