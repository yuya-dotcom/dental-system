"""Small hand-made RawData for series tests (one week, 2026-11-09 Mon … 11-15 Sun)."""
from es_forecast.extract import RawData

BRANCHES = [
    {"branch_id": 1, "branch_name": "Caloocan City", "operating_days": [1, 2, 3, 4, 5, 6]},
    {"branch_id": 2, "branch_name": "Mandaluyong City", "operating_days": [1, 2, 3, 4, 5, 6]},
    {"branch_id": 3, "branch_name": "Quezon City", "operating_days": [1, 2, 3, 4, 5, 6]},
]


def week_raw() -> RawData:
    return RawData(
        branches=BRANCHES,
        exceptions=[],
        branch_audit=[],
        logins=[{"branch_id": 3, "created_at": f"2026-11-{d:02d}T01:00:00+00:00"} for d in (9, 10, 11, 13, 14)],
        appointments=[
            {"branch_id": 3, "appointment_date": "2026-11-09", "status": "completed"},
            {"branch_id": 3, "appointment_date": "2026-11-09", "status": "checked_in"},
            {"branch_id": 3, "appointment_date": "2026-11-09", "status": "cancelled"},
            {"branch_id": 3, "appointment_date": "2026-11-10", "status": "pending"},
            {"branch_id": 3, "appointment_date": "2026-11-11", "status": "completed"},
        ],
        invoices=[{"invoice_id": 10, "branch_id": 3}, {"invoice_id": 11, "branch_id": 1}],
        payments=[
            {"invoice_id": 10, "amount": 1500, "payment_date": "2026-11-10"},
            {"invoice_id": 10, "amount": 500, "payment_date": "2026-11-10"},
            {"invoice_id": 11, "amount": 900, "payment_date": "2026-11-11"},
        ],
        movements=[
            {"item_id": 7, "branch_id": 3, "quantity_change": -2, "reason": "Used in treatment #4", "movement_date": d}
            for d in ("2026-11-09", "2026-11-10", "2026-11-11", "2026-11-13", "2026-11-14")
        ] + [
            {"item_id": 7, "branch_id": 3, "quantity_change": 50, "reason": "Restock", "movement_date": "2026-11-12"},
            {"item_id": 7, "branch_id": 3, "quantity_change": -1, "reason": "Tooth", "movement_date": "2026-11-12"},
            {"item_id": 8, "branch_id": 3, "quantity_change": -1, "reason": "Used in treatment #5", "movement_date": "2026-11-09"},
        ],
        inventory=[{"item_id": 7, "item_name": "Gloves", "branch_id": 3, "stock_quantity": 40, "min_stock": 10, "archived_at": None}],
    )


def live_like_raw() -> RawData:
    """Shaped like the live DB on 2026-11-12: QC activity on 20 scattered days,
    Caloocan on 4 days in November, Mandaluyong nothing at all."""
    from datetime import date, timedelta
    qc_days = [date(2026, 5, 27) + timedelta(days=8 * i) for i in range(20)]
    cal_days = [date(2026, 11, 3), date(2026, 11, 4), date(2026, 11, 6), date(2026, 11, 7)]
    appts = [{"branch_id": 3, "appointment_date": d.isoformat(), "status": "completed"} for d in qc_days for _ in range(2)]
    appts += [{"branch_id": 1, "appointment_date": d.isoformat(), "status": "completed"} for d in cal_days]
    logins = [{"branch_id": b, "created_at": f"{d.isoformat()}T01:00:00+00:00"}
              for b, days in ((3, qc_days), (1, cal_days)) for d in days]
    return RawData(branches=BRANCHES, exceptions=[], branch_audit=[], logins=logins, appointments=appts,
                   invoices=[{"invoice_id": 1, "branch_id": 3}],
                   payments=[{"invoice_id": 1, "amount": 1500, "payment_date": qc_days[-1].isoformat()}],
                   movements=[], inventory=[])
