from datetime import date, datetime

from es_forecast.store import Store


def test_run_times_come_from_one_clock():
    s = Store(None, dry_run=True)
    s.start_run("simulated", 14, date(2026, 11, 11), "test")
    s.finish(0, "success", {"series": 1})
    run = s.rows["run"]
    started = datetime.fromisoformat(run["started_at"])
    finished = datetime.fromisoformat(run["finished_at"])
    assert started.tzinfo is not None and finished >= started
