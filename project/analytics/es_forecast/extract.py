"""Raw rows for the forecasting job — from Supabase (live) or CSV (simulated).

Only the columns named in plan decisions D4/D5 are read. Nothing here writes.
"""
from __future__ import annotations

import math
from dataclasses import dataclass, fields
from datetime import date
from pathlib import Path

import pandas as pd

from .supa import Supa, SupaError


@dataclass
class RawData:
    branches: list[dict]
    exceptions: list[dict]
    branch_audit: list[dict]
    logins: list[dict]
    appointments: list[dict]
    invoices: list[dict]
    payments: list[dict]
    movements: list[dict]
    inventory: list[dict]


def from_supabase(supa: Supa, since: date) -> RawData:
    s = since.isoformat()
    try:
        exceptions = supa.select_all("branch_schedule_exceptions",
                                     {"select": "branch_id,exception_date,is_open", "exception_date": f"gte.{s}"})
    except SupaError:
        exceptions = []   # table not created → weekly schedule only
    return RawData(
        branches=supa.select_all("branches", {"select": "branch_id,branch_name,operating_days,status", "order": "branch_id"}),
        exceptions=exceptions,
        branch_audit=supa.select_all("audit_logs", {
            "select": "entity_id,branch_id,created_at,before_data,after_data",
            "module": "eq.branches", "order": "created_at"}),
        logins=supa.select_all("audit_logs", {
            "select": "branch_id,created_at", "action": "eq.LOGIN_SUCCESS", "created_at": f"gte.{s}"}),
        appointments=supa.select_all("appointments", {
            "select": "branch_id,appointment_date,status", "appointment_date": f"gte.{s}"}),
        invoices=supa.select_all("invoices", {"select": "invoice_id,branch_id"}),
        payments=supa.select_all("payments", {
            "select": "invoice_id,amount,payment_date", "payment_date": f"gte.{s}"}),
        movements=supa.select_all("inventory_movements", {
            "select": "item_id,branch_id,quantity_change,reason,movement_date", "movement_date": f"gte.{s}"}),
        inventory=supa.select_all("inventory", {
            "select": "item_id,item_name,branch_id,stock_quantity,min_stock,archived_at"}),
    )


def _clean(v):
    if isinstance(v, float) and math.isnan(v):
        return None
    return v


def from_csv(folder: Path) -> RawData:
    data = {}
    for f in fields(RawData):
        path = Path(folder) / f"{f.name}.csv"
        if not path.exists() or path.stat().st_size == 0:
            data[f.name] = []
            continue
        try:
            df = pd.read_csv(path, dtype=object)
        except pd.errors.EmptyDataError:     # empty table written without a header
            data[f.name] = []
            continue
        data[f.name] = [{k: _clean(v) for k, v in row.items()} for row in df.to_dict("records")]
    return RawData(**data)


def to_csv(raw: RawData, folder: Path) -> None:
    folder = Path(folder)
    folder.mkdir(parents=True, exist_ok=True)
    import json
    for f in fields(RawData):
        rows = getattr(raw, f.name)
        rows = [{k: (json.dumps(v) if isinstance(v, (list, dict)) else v) for k, v in r.items()} for r in rows]
        pd.DataFrame(rows).to_csv(folder / f"{f.name}.csv", index=False)
