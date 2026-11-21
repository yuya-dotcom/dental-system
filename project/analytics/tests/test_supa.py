import pytest

from es_forecast.supa import Supa, SupaError


class FakeResp:
    def __init__(self, status, body):
        self.status_code = status
        self._body = body
        self.text = str(body)

    def json(self):
        return self._body


class FakeSession:
    def __init__(self, responses):
        self.responses = list(responses)
        self.calls = []

    def request(self, method, url, params=None, json=None, headers=None, timeout=None):
        self.calls.append({"method": method, "url": url, "params": dict(params or {}), "json": json, "headers": headers})
        return self.responses.pop(0)


def test_select_all_pages_until_short_page():
    s = FakeSession([FakeResp(200, [{}] * 1000), FakeResp(200, [{}] * 1000), FakeResp(200, [{}] * 3)])
    rows = Supa("https://x.supabase.co", "k", session=s).select_all("appointments", {"select": "*"})
    assert len(rows) == 2003
    assert [c["params"]["offset"] for c in s.calls] == [0, 1000, 2000]
    assert all(c["headers"]["apikey"] == "k" for c in s.calls)


def test_http_error_raises_supaerror():
    s = FakeSession([FakeResp(404, {"message": "x"})])
    with pytest.raises(SupaError) as e:
        Supa("https://x.supabase.co", "k", session=s).select_all("t", {})
    assert e.value.status == 404


def test_insert_chunks_rows():
    s = FakeSession([FakeResp(201, []), FakeResp(201, []), FakeResp(201, [])])
    Supa("https://x.supabase.co", "k", session=s).insert("t", [{"a": i} for i in range(1200)], chunk=500)
    assert [c["method"] for c in s.calls] == ["POST"] * 3
    assert [len(c["json"]) for c in s.calls] == [500, 500, 200]
