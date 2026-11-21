"""Writes one forecasting run to the four forecast_* tables (never anything else).

Rows are buffered and written when the run finishes, so a run that fails
halfway leaves only its forecast_runs row (status 'failed'); the dashboard
reads 'success' runs only.
"""
from __future__ import annotations

import math
from datetime import date, datetime, timezone

import numpy as np

from .supa import Supa

TABLES = {"values": "forecast_values", "metrics": "forecast_metrics", "inventory": "forecast_inventory"}


def clean(v):
    """JSON-safe value: NaN/inf → None, numpy → Python, dates → ISO."""
    if isinstance(v, (np.floating, float)):
        v = float(v)
        return None if math.isnan(v) or math.isinf(v) else v
    if isinstance(v, np.integer):
        return int(v)
    if isinstance(v, np.bool_):
        return bool(v)
    if isinstance(v, (date, datetime)):
        return v.isoformat()
    if isinstance(v, dict):
        return {k: clean(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [clean(x) for x in v]
    return v


class Store:
    def __init__(self, supa: Supa | None, dry_run: bool):
        if not dry_run and supa is None:
            raise ValueError("a Supa client is required unless dry_run")
        self.supa = supa
        self.dry_run = dry_run
        self.rows: dict = {"run": None, "values": [], "metrics": [], "inventory": []}

    def start_run(self, source: str, horizon: int, last_date: date | None, triggered_by: str) -> int:
        # started_at from the same clock as finished_at (not the DB default now())
        run = clean({"data_source": source, "status": "running", "horizon_days": horizon,
                     "started_at": datetime.now(timezone.utc),
                     "last_observed_date": last_date, "triggered_by": triggered_by})
        self.rows["run"] = dict(run)
        if self.dry_run:
            return 0
        return int(self.supa.insert("forecast_runs", [run], returning=True)[0]["run_id"])

    def _add(self, kind: str, run_id: int, rows: list[dict]) -> None:
        self.rows[kind].extend(clean({**r, "run_id": run_id}) for r in rows)

    def add_values(self, run_id: int, rows: list[dict]) -> None:
        self._add("values", run_id, rows)

    def add_metrics(self, run_id: int, rows: list[dict]) -> None:
        self._add("metrics", run_id, rows)

    def add_inventory(self, run_id: int, rows: list[dict]) -> None:
        self._add("inventory", run_id, rows)

    def finish(self, run_id: int, status: str, summary: dict | None, error: str | None = None) -> None:
        if status == "success" and not self.dry_run:
            for kind, table in TABLES.items():
                if self.rows[kind]:
                    self.supa.insert(table, self.rows[kind])
        done = clean({"status": status, "summary": summary, "error": (error or None) and str(error)[:2000],
                      "finished_at": datetime.now(timezone.utc)})
        self.rows["run"].update(done)
        if not self.dry_run:
            self.supa.patch("forecast_runs", {"run_id": run_id}, done)
