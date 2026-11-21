/* =============================================================
   assets/js/es-ajax.js — shared AJAX layer for the admin portal
   (load after admin.js; no dependencies besides Bootstrap's JS)

   1. Partial page updates ("regions")
      Every records card that holds a .list-toolbar is a region, plus any
      element with data-es-region="name". Filtering, sorting, pagination and
      the refresh after an action fetch the SAME page URL from the server
      (normal PHP render: same validation, permissions and branch scoping)
      and swap only the regions — no full page reload.
        esNavigate(url)  go to another state of the list (pushes history)
        esRefresh()      re-fetch the current URL (keeps page, filters, sort)
      Falls back to a normal navigation / reload when a page has no regions
      or the server answer doesn't match (e.g. session expired → login).

   2. Show / Hide Filters — every .list-toolbar becomes a collapsible panel
      with a toggle in its card header (active filter count on the toggle).
      Filters are HIDDEN by default; "Show Filters" opens them, remembered
      per page for the browser session. The state is applied before the
      first paint (inline script in partials/sidebar.php → html.es-ft-hidden
      unless the page was opened), so the server-rendered filters never
      flash in and then collapse when this script wraps them.
      AJAX region swaps re-wrap the new toolbar with the current state.

   3. Loading feedback — a thin top progress bar for requests, a spinner
      over the results (table) area of the region being refreshed — the
      filter toolbar stays visible and usable — a busy state on the button that
      started a save, and a "Processing…" pill when no button is visible.

   4. Duplicate-submit guard — an identical POST/PUT/PATCH/DELETE that is
      still in flight is not sent again (the caller gets the same answer).

   5. esToast(message, type) — kept for existing callers; on staff pages it
      shows a SweetAlert message (the system uses no toast pop-ups).
   ============================================================= */
(function () {
  'use strict';
  if (window.EsAjax) return;

  const doc = document;
  const PARTIAL_HEADER = 'X-ES-Partial';
  const SILENT_URL = /check_session\.php/;           // background pings: no indicator
  let regionSeq = 0, loadSeq = 0, loadCtl = null;

  /* ── tiny helpers ───────────────────────────────────────── */
  const ss = {
    get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* storage blocked */ } },
  };
  const el = (tag, cls, html) => { const n = doc.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; return n; };
  const samePath = (u) => u.origin === location.origin && u.pathname === location.pathname;

  /* ── top progress bar (ref-counted) ─────────────────────── */
  let barCount = 0, barEl = null, barTimer = null;
  function bar(delta) {
    barCount = Math.max(0, barCount + delta);
    if (!barEl) { barEl = el('div', 'es-topbar'); barEl.setAttribute('aria-hidden', 'true'); doc.body.appendChild(barEl); }
    clearTimeout(barTimer);
    if (barCount > 0) barEl.classList.add('on');
    else barTimer = setTimeout(() => barEl.classList.remove('on'), 150);
  }

  /* ── "Processing…" pill (ref-counted) ───────────────────── */
  let pillCount = 0, pillEl = null;
  function pill(delta) {
    pillCount = Math.max(0, pillCount + delta);
    if (!pillEl) {
      pillEl = el('div', 'es-processing', '<span class="es-spin" aria-hidden="true"></span><span>Processing…</span>');
      pillEl.setAttribute('role', 'status'); pillEl.setAttribute('aria-live', 'polite');
      doc.body.appendChild(pillEl);
    }
    pillEl.classList.toggle('on', pillCount > 0);
  }

  /* ── toast ──────────────────────────────────────────────── */
  // Staff pages use SweetAlert for every message (no toast pop-ups). The old
  // corner toast is kept only as a fallback when SweetAlert isn't loaded.
  // A message that arrives while another alert is open waits for it to close.
  const swalQueue = [];
  function swalNext() {
    if (!swalQueue.length) return;
    if (window.Swal.isVisible()) { setTimeout(swalNext, 400); return; }
    const [message, type] = swalQueue.shift();
    const tone = { success: 'success', error: 'danger' }[type];
    const glyph = { success: 'feather-check', error: 'feather-alert-circle' }[type] || 'feather-info';
    const title = { success: 'Done', error: 'Something Went Wrong' }[type] || 'Notice';
    const p = doc.createElement('p'); p.className = 'es-swal-text'; p.textContent = message;
    window.Swal.fire({
      icon: type === 'success' ? 'success' : type === 'error' ? 'error' : 'info',
      iconHtml: '<i class="' + glyph + ' es-swal-glyph"></i>',
      title, html: p.outerHTML, confirmButtonText: 'OK',
      buttonsStyling: !window.ES_SWAL_TONE,
      customClass: window.ES_SWAL_TONE ? (tone ? window.ES_SWAL_TONE(tone) : window.ES_SWAL_CLASS) : undefined,
    }).then(() => setTimeout(swalNext, 0));
  }
  function toast(message, type) {
    if (window.Swal && typeof window.Swal.fire === 'function') {
      swalQueue.push([String(message || ''), type || 'info']);
      if (swalQueue.length === 1) swalNext();
      return;
    }
    let box = doc.getElementById('esToastBox');
    if (!box) { box = el('div', 'es-toast-box'); box.id = 'esToastBox'; box.setAttribute('aria-live', 'polite'); doc.body.appendChild(box); }
    const icon = { success: 'feather-check-circle', error: 'feather-alert-circle', info: 'feather-info' }[type] || 'feather-info';
    const t = el('div', 'es-toast es-toast-' + (type || 'info'));
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    t.append(el('i', icon), el('span'));
    t.lastChild.textContent = message;
    const x = el('button', 'es-toast-x', '&times;'); x.type = 'button'; x.setAttribute('aria-label', 'Dismiss');
    x.onclick = () => t.remove();
    t.append(x);
    box.append(t);
    setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, type === 'error' ? 6000 : 3500);
  }

  /* ── busy button tracking ───────────────────────────────── */
  let lastBtn = null, lastBtnAt = 0;
  doc.addEventListener('click', (e) => {
    const b = e.target.closest && e.target.closest('button, .btn, a.dropdown-item, [role="button"]');
    if (b) { lastBtn = b; lastBtnAt = Date.now(); }
  }, true);
  function visible(n) { return n && n.isConnected && n.offsetParent !== null && !n.closest('.swal2-container'); }
  function claimButton() {
    const b = lastBtn;
    if (!b || Date.now() - lastBtnAt > 1500 || !visible(b) || b.classList.contains('dropdown-item')) return null;
    if (b.classList.contains('es-btn-busy') || b.querySelector('.spinner-border, .spinner-grow')) return 'own';
    b.classList.add('es-btn-busy'); b.setAttribute('aria-busy', 'true');
    return b;
  }
  function releaseButton(b) { if (b && b !== 'own') { b.classList.remove('es-btn-busy'); b.removeAttribute('aria-busy'); } }

  /* ── fetch wrapper: indicators + duplicate guard ────────── */
  const nativeFetch = window.fetch.bind(window);
  const inflight = new Map();
  window.fetch = function (input, init) {
    init = init || {};
    const url = typeof input === 'string' ? input : (input && input.url) || String(input);
    const method = String(init.method || (input && input.method) || 'GET').toUpperCase();
    const hdrs = new Headers(init.headers || (input && input.headers) || undefined);
    const silent = SILENT_URL.test(url) || hdrs.has('X-ES-Silent') || hdrs.has(PARTIAL_HEADER);
    const mutating = !['GET', 'HEAD', 'OPTIONS'].includes(method);

    let key = null;
    if (mutating && (typeof init.body === 'string' || init.body == null)) {
      key = method + ' ' + url + ' ' + (init.body || '');
      if (inflight.has(key)) return inflight.get(key).then((r) => r.clone());
    }

    let btn = null;
    if (!silent) {
      bar(1);
      if (mutating) { btn = claimButton(); if (!btn) pill(1); }
    }
    const p = nativeFetch(input, init);
    const done = () => {
      if (key) inflight.delete(key);
      if (!silent) { bar(-1); if (mutating) { if (btn) releaseButton(btn); else pill(-1); } }
      // Any write (status change, edit, check-in, payment…) may change data a
      // page has cached — e.g. assets/js/apt-details.js drops its entries.
      if (mutating) { try { doc.dispatchEvent(new CustomEvent('es:mutation', { detail: { url, method } })); } catch (e) { /* old browser */ } }
    };
    if (key) inflight.set(key, p.then((r) => r.clone()));
    p.then(done, done);
    return p;
  };

  /* ── regions ────────────────────────────────────────────── */
  // key → element. Explicit [data-es-region] first, then the card around each .list-toolbar.
  function regionsIn(root) {
    const map = new Map();
    root.querySelectorAll('[data-es-region]').forEach((n) => map.set('r:' + n.getAttribute('data-es-region'), n));
    let i = 0;
    root.querySelectorAll('.list-toolbar').forEach((tb) => {
      if (tb.closest('[data-es-static]')) return;   // client-side filtered panels (e.g. Service Materials)
      const card = tb.closest('[data-es-region]') || tb.closest('.card') || tb;
      if ([...map.values()].includes(card)) return;
      map.set('card:' + (i++), card);
    });
    return map;
  }
  const isListRegion = (n) => !n.hasAttribute('data-es-aux');
  function enabled() { return [...regionsIn(doc).values()].some(isListRegion); }

  function setBusy(on) {
    regionsIn(doc).forEach((n) => { if (isListRegion(n)) { n.classList.add('es-region'); n.classList.toggle('es-busy', on); n.setAttribute('aria-busy', on ? 'true' : 'false'); } });
    trackSpinner(on);
  }

  // Keep the region spinner in the middle of the part of the region that is
  // on screen (long tables scrolled to the bottom, horizontally scrolled
  // tables, small screens). Updates CSS variables only — no layout change.
  let spinRaf = 0;
  function placeSpinners() {
    spinRaf = 0;
    const vw = window.innerWidth || doc.documentElement.clientWidth;
    const vh = window.innerHeight || doc.documentElement.clientHeight;
    doc.querySelectorAll('.es-region.es-busy').forEach((n) => {
      const r = n.getBoundingClientRect();
      if (!r.width || !r.height) return;
      // The results area (the table's card body) — not the filter toolbar
      const body = [...n.children].find((c) => c.classList.contains('card-body'));
      const a = body ? body.getBoundingClientRect() : r;
      const area = a.width && a.height ? a : r;
      const top = Math.max(area.top, 0), bottom = Math.min(area.bottom, vh);
      const left = Math.max(area.left, 0), right = Math.min(area.right, vw);
      const pad = 24;   // keep the 30px spinner fully inside the area
      const clampTo = (v, lo, hi) => Math.min(Math.max(v, lo + pad), Math.max(lo + pad, hi - pad));
      const y = bottom > top ? (top + bottom) / 2 : (area.top + area.bottom) / 2;
      const x = right > left ? (left + right) / 2 : (area.left + area.right) / 2;
      n.style.setProperty('--es-spin-y', (clampTo(y, area.top, area.bottom) - r.top) + 'px');
      n.style.setProperty('--es-spin-x', (clampTo(x, area.left, area.right) - r.left) + 'px');
    });
  }
  const queueSpinners = () => { if (!spinRaf) spinRaf = requestAnimationFrame(placeSpinners); };
  let spinTracking = false;
  function trackSpinner(on) {
    if (on) {
      placeSpinners();
      if (!spinTracking) {
        spinTracking = true;
        window.addEventListener('scroll', queueSpinners, { passive: true, capture: true });
        window.addEventListener('resize', queueSpinners, { passive: true });
      }
    } else {
      if (spinTracking) {
        spinTracking = false;
        window.removeEventListener('scroll', queueSpinners, { passive: true, capture: true });
        window.removeEventListener('resize', queueSpinners, { passive: true });
      }
      if (spinRaf) { cancelAnimationFrame(spinRaf); spinRaf = 0; }
    }
  }

  // Page number of an URL (1 when absent)
  const pageOf = (u) => Math.max(1, parseInt(new URL(u, location.href).searchParams.get('page') || '1', 10) || 1);
  // A region whose table shows only the "no records" row
  function looksEmpty(regions) {
    for (const n of regions.values()) {
      if (!n.querySelector('.list-toolbar') && n.getAttribute('data-es-region') !== 'list') continue;   // only the paged list
      const rows = n.querySelectorAll('table tbody tr');
      if (!rows.length) continue;
      if ([...rows].every((tr) => tr.children.length === 1 && tr.children[0].hasAttribute('colspan'))) return true;
    }
    return false;
  }

  function load(url, opts) {
    opts = Object.assign({ push: true, scroll: false }, opts);
    const target = new URL(url, location.href);
    if (!samePath(target) || !enabled()) { location.href = target.href; return Promise.resolve(false); }

    if (loadCtl) loadCtl.abort();          // newest request wins (no out-of-order swaps)
    const ctl = loadCtl = new AbortController();
    const mine = ++loadSeq;
    setBusy(true); bar(1);

    return nativeFetch(target.href, { headers: { [PARTIAL_HEADER]: '1' }, credentials: 'same-origin', cache: 'no-store', signal: ctl.signal })
      .then((res) => {
        // Redirected away (session expired, unauthorized): follow it for real
        if (res.redirected && !samePath(new URL(res.url))) { location.href = res.url; throw new Error('redirect'); }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then((html) => {
        if (mine !== loadSeq) return false;
        const next = new DOMParser().parseFromString(html, 'text/html');
        const fresh = regionsIn(next), cur = regionsIn(doc);
        const keys = [...cur.keys()];
        if (!keys.length || keys.some((k) => !fresh.has(k))) { location.href = target.href; return false; }

        // Last record of the last page removed → step back a page
        const pg = pageOf(target.href);
        if (pg > 1 && looksEmpty(fresh)) {
          target.searchParams.set('page', String(pg - 1));
          return load(target.href, Object.assign({}, opts, { push: false, replace: true }));
        }

        const active = doc.activeElement && doc.activeElement.id ? doc.activeElement.id : null;
        const caret = active && doc.activeElement.selectionStart != null ? doc.activeElement.selectionStart : null;
        keys.forEach((k) => {
          const old = cur.get(k);
          if (old.querySelector('.dropdown-menu.show') && !isListRegion(old)) return;   // e.g. bell open: leave it
          const n = doc.importNode(fresh.get(k), true);
          old.replaceWith(n);
        });
        if (next.title) doc.title = next.title;
        if (opts.push) history.pushState({ es: 1 }, '', target.href);
        else history.replaceState({ es: 1 }, '', target.href);

        initRegions(doc);
        if (active) {
          const f = doc.getElementById(active);
          if (f && f.closest('.es-region')) { try { f.focus({ preventScroll: true }); if (caret != null && f.setSelectionRange) f.setSelectionRange(caret, caret); } catch (e) { /* not focusable */ } }
        }
        if (opts.scroll) {
          const first = [...regionsIn(doc).values()].find(isListRegion);
          if (first && first.getBoundingClientRect().top < 0) first.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
        doc.dispatchEvent(new CustomEvent('es:updated', { detail: { url: target.href } }));
        return true;
      })
      .catch((err) => {
        if (err && (err.name === 'AbortError' || err.message === 'redirect')) return false;
        if (mine === loadSeq) toast('Could not load the records. Please check your connection and try again.', 'error');
        return false;
      })
      .finally(() => { bar(-1); if (mine === loadSeq) setBusy(false); });
  }

  /* ── Show / Hide Filters ────────────────────────────────── */
  // State per page: '1' = the user chose Show Filters; anything else = hidden.
  // partials/sidebar.php reads the same key before the toolbar is painted.
  const ftKey = () => 'esFilters:' + location.pathname;
  function filtersOpen() { return ss.get(ftKey()) === '1'; }
  function setFiltersOpen(open) {
    ss.set(ftKey(), open ? '1' : '0');
    doc.querySelectorAll('.es-filter-panel').forEach((p) => applyPanel(p, open, true));
  }
  function applyPanel(panel, open, animate) {
    const inner = panel.firstElementChild;
    panel.classList.toggle('no-anim', !animate);
    panel.classList.toggle('open', open);
    if (open) inner.removeAttribute('inert'); else inner.setAttribute('inert', '');
    if (!animate) panel.classList.toggle('settled', open);
    else if (!open) panel.classList.remove('settled');
    const btn = panel._esToggle;
    if (btn) {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.querySelector('.es-ft-label').textContent = open ? 'Hide Filters' : 'Show Filters';
      btn.title = open ? 'Hide the filters' : 'Show the filters';
      btn.classList.toggle('active', open);
    }
  }
  function initFilters(root) {
    const open = filtersOpen();
    root.querySelectorAll('.list-toolbar').forEach((tb) => {
      if (tb.closest('.es-filter-panel') || tb.hasAttribute('data-es-no-collapse')) return;
      const panel = el('div', 'es-filter-panel');
      const inner = el('div', 'es-filter-inner');
      panel.id = 'esFilters' + (++regionSeq);
      tb.replaceWith(panel); inner.append(tb); panel.append(inner);
      panel.addEventListener('transitionend', (e) => { if (e.target === panel && panel.classList.contains('open')) panel.classList.add('settled'); });

      // Active filter count: the server-computed badge of the Reset control
      const countEl = tb.querySelector('.list-active-count');
      const count = countEl ? parseInt(countEl.textContent, 10) || 0 : 0;

      const btn = el('button', 'btn btn-sm es-filter-toggle',
        '<i class="feather-sliders" aria-hidden="true"></i><span class="es-ft-label">Show Filters</span>' +
        (count ? '<span class="es-ft-count" title="Active filters">' + count + '</span>' : '') +
        '<i class="feather-chevron-down es-ft-caret" aria-hidden="true"></i>');
      btn.type = 'button';
      btn.setAttribute('aria-controls', panel.id);
      btn.addEventListener('click', () => setFiltersOpen(!panel.classList.contains('open')));
      panel._esToggle = btn;

      const card = panel.closest('.card');
      const header = card && [...card.children].find((c) => c.classList.contains('card-header'));
      if (header) {
        const wrap = el('div', 'es-ft-wrap'); wrap.append(btn);
        const action = [...header.children].find((c) => c.classList.contains('card-header-action'));
        action ? header.insertBefore(wrap, action) : header.append(wrap);
      } else {
        const wrap = el('div', 'es-ft-wrap es-ft-standalone'); wrap.append(btn);
        panel.before(wrap);
      }
      applyPanel(panel, open, false);   // no animation on load / after an AJAX swap
    });
    // The panels now hold the state: drop the pre-paint "hidden" class
    doc.documentElement.classList.remove('es-ft-hidden');
  }

  /* ── (re)initialise widgets inside swapped regions ──────── */
  function initRegions(root) {
    regionsIn(root).forEach((n) => { if (isListRegion(n)) n.classList.add('es-region'); });
    initFilters(root);
    if (window.enhanceCustomSelects) window.enhanceCustomSelects();
    if (window.enhanceDatePickers) window.enhanceDatePickers();
    if (window.bootstrap && bootstrap.Tooltip) {
      root.querySelectorAll('.es-region [data-bs-toggle="tooltip"]').forEach((t) => { try { bootstrap.Tooltip.getOrCreateInstance(t); } catch (e) { /* ignore */ } });
    }
  }

  /* ── same-page links inside regions → partial load ──────── */
  doc.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target || a.hasAttribute('download') || a.hasAttribute('data-es-full') || a.hasAttribute('data-bs-toggle')) return;
    const raw = a.getAttribute('href');
    if (!raw || raw.startsWith('#') || raw.startsWith('javascript:')) return;
    if (!a.closest('.es-region')) return;
    const u = new URL(raw, location.href);
    if (!samePath(u) || !enabled()) return;
    e.preventDefault();
    // Tooltip from the clicked link would otherwise stay on screen
    if (window.bootstrap && bootstrap.Tooltip) doc.querySelectorAll('.tooltip.show').forEach((t) => t.remove());
    load(u.href, { push: true, scroll: !!a.closest('.pagination-common-style') });
  });

  window.addEventListener('popstate', () => { if (enabled()) load(location.href, { push: false }); });

  /* ── modal button snapshot (what a full reload used to restore) ── */
  const pristine = new WeakMap();
  function snapshotModals() {
    doc.querySelectorAll('.modal button, .modal .btn').forEach((b) => { if (!pristine.has(b)) pristine.set(b, { html: b.innerHTML, disabled: b.disabled }); });
  }
  function restoreModals() {
    doc.querySelectorAll('.modal button, .modal .btn').forEach((b) => {
      const s = pristine.get(b);
      if (!s) return;
      if (b.innerHTML !== s.html) b.innerHTML = s.html;
      if ('disabled' in b && b.disabled !== s.disabled) b.disabled = s.disabled;
      b.classList.remove('es-btn-busy');
    });
  }
  function closeModals() {
    if (!window.bootstrap || !bootstrap.Modal) return;
    doc.querySelectorAll('.modal.show').forEach((m) => { const i = bootstrap.Modal.getInstance(m); if (i) i.hide(); });
  }

  /* ── public API ─────────────────────────────────────────── */
  function refresh() {
    closeModals();
    restoreModals();
    if (!enabled()) { location.reload(); return Promise.resolve(false); }
    return load(location.href, { push: false });
  }
  function navigate(url) { return enabled() ? load(url, { push: true }) : (location.href = url, Promise.resolve(false)); }

  window.EsAjax = { load, refresh, navigate, enabled, toast, bar, pill };
  window.esRefresh = refresh;
  window.esNavigate = navigate;
  window.esToast = toast;

  function boot() {
    try { history.replaceState(Object.assign({}, history.state, { es: 1 }), ''); } catch (e) { /* ignore */ }
    initRegions(doc);
    snapshotModals();
  }
  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', boot); else boot();
})();
