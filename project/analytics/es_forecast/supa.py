"""Minimal Supabase PostgREST client (read with paging, insert, patch)."""
from __future__ import annotations

import requests

PAGE = 1000


class SupaError(Exception):
    def __init__(self, status: int, message: str):
        super().__init__(f"Supabase {status}: {message}")
        self.status = status
        self.message = message


class Supa:
    def __init__(self, url: str, key: str, session=None, timeout: int = 30):
        self.base = url.rstrip("/") + "/rest/v1/"
        self.key = key
        self.session = session or requests.Session()
        self.timeout = timeout

    def _headers(self, extra: dict | None = None) -> dict:
        # sb_secret_ keys are not JWTs: apikey header only
        h = {"apikey": self.key, "Accept": "application/json", "Content-Type": "application/json"}
        if extra:
            h.update(extra)
        return h

    def _call(self, method: str, table: str, params=None, json=None, headers=None):
        r = self.session.request(method, self.base + table, params=params, json=json,
                                 headers=self._headers(headers), timeout=self.timeout)
        if r.status_code >= 400:
            try:
                body = r.json()
                msg = body.get("message") or body.get("error") or r.text
            except Exception:
                msg = r.text
            raise SupaError(r.status_code, str(msg)[:300])
        return r

    def select_all(self, table: str, params: dict) -> list[dict]:
        rows: list[dict] = []
        offset = 0
        while True:
            page = self._call("GET", table, params={**params, "limit": PAGE, "offset": offset}).json()
            rows.extend(page)
            if len(page) < PAGE:
                return rows
            offset += PAGE

    def insert(self, table: str, rows: list[dict], chunk: int = 500, returning: bool = False) -> list[dict]:
        out: list[dict] = []
        prefer = {"Prefer": "return=representation" if returning else "return=minimal"}
        for i in range(0, len(rows), chunk):
            r = self._call("POST", table, json=rows[i:i + chunk], headers=prefer)
            if returning:
                out.extend(r.json())
        return out

    def patch(self, table: str, match: dict, values: dict) -> None:
        params = {k: f"eq.{v}" for k, v in match.items()}
        self._call("PATCH", table, params=params, json=values, headers={"Prefer": "return=minimal"})
