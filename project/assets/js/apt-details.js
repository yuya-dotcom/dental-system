/* =============================================================
   EssenciaSmile — shared appointment-details loader
   File: assets/js/apt-details.js
   Used by: Appointment Records (View Details) and Appointment Schedule
   (View Details / Edit prefill). Load AFTER es-ajax.js.

   One request per appointment: api/appointments.php?id=X returns the
   appointment with its services embedded (and, with ?with=records, the
   treatment and invoice) in a single database round trip.

   Cache (in memory, this tab and this page only):
   - Never written to localStorage / sessionStorage, so nothing is shared
     between accounts, branches or tabs, and it is gone on navigation or
     sign-out. The server still checks login + branch on every request.
   - Fresh for FRESH_MS: reopening the same appointment renders at once
     with no request.
   - Older than that: the cached copy is shown at once and refreshed in the
     background; the modal re-renders only if the data changed (picks up
     edits made by other staff).
   - Dropped completely after any write on the page (es-ajax fires
     'es:mutation' for every POST / PUT / PATCH / DELETE), so the page's own
     edits are never shown stale; also after the tab was hidden > FRESH_MS.
   - Concurrent requests for the same appointment share one fetch.

   API:
     AptDetails.open(id, { records, onData, onError })
       → onData(apt, { cached, revalidated }) may be called twice:
         once with the cached copy, then again if the refresh differs.
       → returns true when cached data was shown synchronously.
     AptDetails.get(id, { records, fresh }) → Promise<apt>
     AptDetails.prefetch(id, { records })
     AptDetails.invalidate(id) / AptDetails.clear()
   ============================================================= */
(function () {
  'use strict';

  const FRESH_MS = 30 * 1000;   // reuse without asking the server
  const MAX_ENTRIES = 60;       // small LRU: a busy day's worth of opens

  const cache = new Map();      // key → { data, at, json }
  const inflight = new Map();   // key → Promise<entry>
  let generation = 0;           // bumped by clear(): late answers from before a write are discarded

  const keyOf = (id, records) => String(parseInt(id, 10)) + (records ? '|r' : '|b');

  function remember(key, data, json) {
    cache.delete(key);
    cache.set(key, { data, json, at: Date.now() });
    while (cache.size > MAX_ENTRIES) cache.delete(cache.keys().next().value);
  }

  function request(id, records, background) {
    const key = keyOf(id, records);
    if (inflight.has(key)) return inflight.get(key);
    const gen = generation;
    const headers = background ? { 'X-ES-Silent': '1' } : {};
    const url = 'api/appointments.php?id=' + encodeURIComponent(parseInt(id, 10)) + (records ? '&with=records' : '');
    const p = fetch(url, { credentials: 'same-origin', cache: 'no-store', headers })
      .then((r) => {
        if (r.status === 401) { location.href = 'login.php'; throw new Error('Please sign in again.'); }
        if (r.status === 403) throw new Error('You do not have access to this appointment.');
        return r.json().catch(() => ({ success: false, message: 'Unexpected server response.' }));
      })
      .then((j) => {
        if (!j || !j.success || !j.data) throw new Error((j && j.message) || 'Could not load appointment.');
        const json = JSON.stringify(j.data);
        // Only cache when no write happened while this was in flight
        if (gen === generation) remember(key, j.data, json);
        return { data: j.data, json };
      })
      .finally(() => { if (inflight.get(key) === p) inflight.delete(key); });
    inflight.set(key, p);
    return p;
  }

  const AptDetails = {
    /** Cached entry or null (no request). */
    peek(id, opts) {
      const e = cache.get(keyOf(id, opts && opts.records));
      return e ? e.data : null;
    },

    /** Promise of the appointment; uses a fresh cache entry unless opts.fresh. */
    get(id, opts) {
      opts = opts || {};
      const e = cache.get(keyOf(id, opts.records));
      if (!opts.fresh && e && Date.now() - e.at < FRESH_MS) return Promise.resolve(e.data);
      return request(id, !!opts.records, false).then((x) => x.data);
    },

    /** Warm the cache (e.g. on hover of a View button). Errors are ignored. */
    prefetch(id, opts) {
      const records = !!(opts && opts.records);
      const e = cache.get(keyOf(id, records));
      if (e && Date.now() - e.at < FRESH_MS) return;
      request(id, records, true).catch(() => {});
    },

    /**
     * Show an appointment in a modal: cached copy immediately (no spinner),
     * then a background refresh when it is older than FRESH_MS.
     * Returns true when onData ran synchronously from the cache.
     */
    open(id, opts) {
      opts = opts || {};
      const records = !!opts.records;
      const key = keyOf(id, records);
      const e = cache.get(key);
      const onData = opts.onData || function () {};
      const onError = opts.onError || function () {};
      const token = {};            // ignore answers once another appointment was opened
      AptDetails._current = token;

      if (e) {
        onData(e.data, { cached: true, revalidated: false });
        if (Date.now() - e.at >= FRESH_MS) {
          const shown = e.json;
          request(id, records, true)
            .then((x) => { if (AptDetails._current === token && x.json !== shown) onData(x.data, { cached: false, revalidated: true }); })
            .catch(() => { /* keep showing the cached copy */ });
        }
        return true;
      }
      request(id, records, false)
        .then((x) => { if (AptDetails._current === token) onData(x.data, { cached: false, revalidated: false }); })
        .catch((err) => { if (AptDetails._current === token) onError(err); });
      return false;
    },

    invalidate(id) {
      cache.delete(keyOf(id, true));
      cache.delete(keyOf(id, false));
    },

    clear() {
      generation++;
      cache.clear();
    },

    _current: null,
  };

  // Any write on this page → nothing cached can be trusted any more.
  document.addEventListener('es:mutation', () => AptDetails.clear());
  // Leaving the tab for a while: start from the server again on return.
  let hiddenAt = 0;
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) hiddenAt = Date.now();
    else if (hiddenAt && Date.now() - hiddenAt > FRESH_MS) AptDetails.clear();
  });

  // Warm the cache when the pointer rests on a View Details trigger
  // (markup: data-apt-prefetch="<id>" [data-apt-records]).
  let hoverTimer = 0;
  document.addEventListener('pointerover', (ev) => {
    const el = ev.target && ev.target.closest && ev.target.closest('[data-apt-prefetch]');
    if (!el) return;
    clearTimeout(hoverTimer);
    hoverTimer = setTimeout(() => {
      AptDetails.prefetch(el.getAttribute('data-apt-prefetch'), { records: el.hasAttribute('data-apt-records') });
    }, 120);
  });
  document.addEventListener('pointerout', (ev) => {
    if (ev.target && ev.target.closest && ev.target.closest('[data-apt-prefetch]')) clearTimeout(hoverTimer);
  });
  // …and as soon as a row's action menu opens (Bootstrap 5 events bubble).
  document.addEventListener('show.bs.dropdown', (ev) => {
    const menu = ev.target && ev.target.closest && ev.target.closest('.dropdown');
    const el = menu && menu.querySelector('[data-apt-prefetch]');
    if (el) AptDetails.prefetch(el.getAttribute('data-apt-prefetch'), { records: el.hasAttribute('data-apt-records') });
  });

  window.AptDetails = AptDetails;
})();
