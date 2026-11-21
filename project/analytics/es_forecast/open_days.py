"""Which days each branch was (or will be) open — plan decision D5.

Open on a date = the branch's weekly operating days *as they were on that
date* (rebuilt from the audit log of branch edits), overridden by any
branch_schedule_exceptions row for that exact date.
Weekday numbers follow the PHP/Postgres convention: 0 = Sunday … 6 = Saturday.
"""
from __future__ import annotations

import json
from datetime import date, datetime, timedelta
from zoneinfo import ZoneInfo

import pandas as pd

from .config import TZ

DEFAULT_DAYS = [1, 2, 3, 4, 5, 6]


def _as_obj(v):
    if isinstance(v, str):
        try:
            return json.loads(v)
        except json.JSONDecodeError:
            return None
    return v


def _days(v) -> list[int] | None:
    v = _as_obj(v)
    if isinstance(v, str):
        v = [p for p in v.strip("{}").split(",") if p.strip()]
    if not isinstance(v, (list, tuple)):
        return None
    out = sorted({int(x) for x in v if str(x).strip().isdigit() and 0 <= int(x) <= 6})
    return out or None


def _truthy(v) -> bool:
    if isinstance(v, str):
        return v.strip().lower() in ("true", "t", "1", "yes")
    return bool(v)


def local_date(ts: str) -> date:
    """ISO timestamp → calendar date in Manila."""
    dt = datetime.fromisoformat(str(ts).replace("Z", "+00:00"))
    if dt.tzinfo is None:
        return dt.date()
    return dt.astimezone(ZoneInfo(TZ)).date()


def pg_weekday(d: date) -> int:
    return (d.weekday() + 1) % 7


def _schedule_changes(branch_id: int, branch_audit: list[dict]) -> list[tuple[date, list[int]]]:
    """(change date, days BEFORE the change), newest first."""
    out = []
    for row in branch_audit:
        bid = row.get("entity_id", row.get("branch_id"))
        try:
            if int(bid) != branch_id:
                continue
        except (TypeError, ValueError):
            continue
        before = _as_obj(row.get("before_data")) or {}
        after = _as_obj(row.get("after_data")) or {}
        if "operating_days" not in after:
            continue
        days = _days(before.get("operating_days")) if isinstance(before, dict) else None
        out.append((local_date(row["created_at"]), days or DEFAULT_DAYS))
    out.sort(key=lambda t: t[0], reverse=True)
    return out


def build_calendar(branches: list[dict], exceptions: list[dict], branch_audit: list[dict],
                   start: date, end: date) -> pd.DataFrame:
    exc = {(int(e["branch_id"]), str(e["exception_date"])[:10]): _truthy(e["is_open"]) for e in exceptions}
    rows = []
    n = (end - start).days + 1
    for b in branches:
        bid = int(b["branch_id"])
        current = _days(b.get("operating_days")) or DEFAULT_DAYS
        changes = _schedule_changes(bid, branch_audit)
        for i in range(n):
            d = start + timedelta(days=i)
            days = current
            for changed_on, before in changes:     # newest → oldest
                if changed_on > d:
                    days = before
                else:
                    break
            key = (bid, d.isoformat())
            is_open = exc[key] if key in exc else pg_weekday(d) in days
            rows.append({"branch_id": bid, "date": pd.Timestamp(d), "is_open": bool(is_open)})
    return pd.DataFrame(rows, columns=["branch_id", "date", "is_open"])
