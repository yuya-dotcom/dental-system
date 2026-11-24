/* =============================================================
   EssenciaSmile — Analytics › Overview, Appointments, Revenue and
   Inventory tabs (analytics.php)

   Data: api/dashboard_stats.php — the Dashboard's own read-only endpoint
   (no new queries). It returns, per scope:
     trend        6 calendar months (this month + 5 before): appointments,
                  completed, cancelled, payments received
     appointments all recorded appointments by status (incl. no_show)
     sales        billed / collected / outstanding, paid / partial / unpaid
     inventory    live stock vs each item's minimum; stock_alerts (top 8)
     top_services the most-booked main services (all time)
   Owner: ?branch=N per clinic (comparison) and 0 for all clinics.
   Admin / dentist: the server ignores ?branch and returns their own clinic.

   Fair comparisons: month-on-month figures always compare the two most
   recent FULL months (e.g. October vs September). The current month is
   only shown as "so far", never compared. All-time and "now" figures are
   labelled as such.

   Forecast figures come from window.ESForecast (forecast-charts.js) and
   are only quoted when a real (non-demo) forecast exists for the scope.
   ============================================================= */
(function () {
    'use strict';

    const CFG = window.ES_ANALYTICS || { owner: false, multi: false, branches: {}, scopeName: null };
    const $ = (s, r = document) => r.querySelector(s);
    if (!$('#anPane-overview')) return;

    // ── Formatting ────────────────────────────────────────────
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const isNum = v => v !== null && v !== undefined && v !== '' && isFinite(v);
    const num = (n, d = 0) => isNum(n) ? Number(n).toLocaleString('en-PH', { maximumFractionDigits: d, minimumFractionDigits: d }) : '—';
    const peso = n => isNum(n) ? '₱' + num(Math.round(n)) : '—';
    const pct = (n, d = 0) => isNum(n) ? num(n, d) + '%' : '—';
    const plural = (n, one, many) => `${num(n)} ${Number(n) === 1 ? one : (many || one + 's')}`;
    const compact = n => { const a = Math.abs(n); return a >= 1e6 ? (n / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'M' : a >= 1e3 ? (n / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k' : String(Math.round(n)); };
    const MONTHS = { Jan: 'January', Feb: 'February', Mar: 'March', Apr: 'April', May: 'May', Jun: 'June', Jul: 'July', Aug: 'August', Sep: 'September', Oct: 'October', Nov: 'November', Dec: 'December' };
    const longMonth = l => MONTHS[String(l).slice(0, 3)] || String(l);
    const shortMonth = l => String(l).slice(0, 3);
    const WORDS = ['no', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];
    const FLAT = 5;          // |change| under 5% reads as "about the same"
    const BIG = 200;         // rises of 200%+ from a tiny base read as "up from N"
    const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
    const longDate = d => { const [y, m, dd] = d.split('-').map(Number); return new Date(Date.UTC(y, m - 1, dd)).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }); };
    const shortDate = d => { const [y, m, dd] = d.split('-').map(Number); return new Date(Date.UTC(y, m - 1, dd)).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', timeZone: 'UTC' }); };
    const utc = d => { const [y, m, dd] = d.split('-').map(Number); return Date.UTC(y, m - 1, dd); };
    const cap = s => String(s).charAt(0).toUpperCase() + String(s).slice(1);
    const andList = a => a.length < 2 ? (a[0] || '') : a.slice(0, -1).join(', ') + ' and ' + a[a.length - 1];
    const changePct = (now, before) => before ? (now - before) / before * 100 : null;

    /** "12% more than September (40)" / "about the same as September (40)" / "up from 1 in September". */
    function relPhrase(now, before, label, fmt, lessWord) {
        if (!before) return now ? `with none in ${label}` : '';
        const ch = (now - before) / before * 100;
        if (Math.abs(ch) < FLAT) return `about the same as ${label} (${fmt(before)})`;
        if (ch >= BIG) return `up from ${fmt(before)} in ${label}`;
        return `${num(Math.abs(ch))}% ${ch > 0 ? 'more' : (lessWord || 'fewer')} than ${label} (${fmt(before)})`;
    }
    /** Change pill. reverse = a rise is bad (cancellations); neutral = no good/bad colour. */
    function pill(now, before, opts = {}) {
        if (!before) return '';
        const ch = (now - before) / before * 100, flat = Math.abs(ch) < FLAT;
        if (flat) return '<span class="an-pill">About the same</span>';
        const good = opts.reverse ? ch < 0 : ch > 0;
        const cls = opts.neutral ? 'info' : good ? 'up' : 'down';
        const txt = ch >= BIG ? `Up from ${(opts.fmt || num)(before)}` : `${num(Math.abs(ch))}%`;
        return `<span class="an-pill ${cls}"><i class="feather-arrow-${ch > 0 ? 'up' : 'down'}"></i>${txt}</span>`;
    }
    /** Pill + "vs September" (or "September: 0" when there is nothing to compare). */
    function vs(now, before, label, opts = {}) {
        if (!before) return `<span>${esc(label)}: ${(opts.fmt || num)(before)}</span>`;
        const ch = (now - before) / before * 100;
        return `${pill(now, before, opts)} <span>${ch >= BIG ? 'in' : 'vs'} ${esc(label)}</span>`;
    }

    // ── Scope (shared clinic filter) ──────────────────────────
    const branchIds = Object.keys(CFG.branches || {}).map(Number).sort((a, b) => a - b);
    const nameOf = id => (CFG.branches || {})[id] || `Clinic ${id}`;
    const colorVar = id => `--an-c${(branchIds.indexOf(id) % 3) + 1}`;
    const sel = document.getElementById('fcBranch');
    let branch = 'all';
    if (sel) {
        // Same remembered choice as the forecast (es.forecast.ui.v1), set before custom-select wraps it
        let saved;
        try { saved = (JSON.parse(localStorage.getItem('es.forecast.ui.v1') || '{}') || {}).branch; } catch (e) { /* storage blocked */ }
        if (saved !== undefined && [...sel.options].some(o => o.value === String(saved))) sel.value = String(saved);
        branch = sel.value === 'all' ? 'all' : Number(sel.value);
    }
    const scopeKey = () => branch === 'all' ? 0 : branch;
    const allClinics = () => CFG.multi && branch === 'all';
    const scopeName = () => allClinics() ? 'all clinics' : branch === 'all' ? (CFG.scopeName || 'your clinic') : nameOf(branch);

    // ── Loading (cached per scope for this page view) ─────────
    const cache = {};
    function get(key) {
        if (cache[key]) return cache[key];
        let p;
        if (CFG.multi) {
            // Owner, several clinics: ONE request returns all clinics + each clinic
            // (?split=1, same figures) instead of one request per clinic.
            // Kept in `cache` too, so Refresh / Retry clear it with the rest.
            if (!cache.__split) {
                const s = fetch('api/dashboard_stats.php?split=1', { headers: { Accept: 'application/json' }, cache: 'no-store' })
                    .then(r => r.json())
                    .then(d => { if (!d.success) throw new Error(d.message || 'failed'); return d.data.scopes; });
                cache.__split = s;
                s.catch(() => { if (cache.__split === s) delete cache.__split; });
            }
            p = cache.__split.then(sc => { if (!sc[key]) throw new Error('missing'); return sc[key]; });
        } else {
            p = fetch('api/dashboard_stats.php' + (key ? '?branch=' + encodeURIComponent(key) : ''), { headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(r => r.json())
                .then(d => { if (!d.success) throw new Error(d.message || 'failed'); return d.data; });
        }
        cache[key] = p;
        p.catch(() => { if (cache[key] === p) delete cache[key]; });
        return p;
    }
    let scope = { state: 'loading' };             // { state: loading | ok | error, data }
    let clinics = null;                           // [{ id, data | null }] (owner, multi)
    let token = 0;
    function refresh() {
        const t = ++token;
        scope = { state: 'loading' };
        renderVisible();
        get(scopeKey()).then(d => { if (t === token) { scope = { state: 'ok', data: d }; renderVisible(); } })
            .catch(() => { if (t === token) { scope = { state: 'error' }; renderVisible(); } });
        if (CFG.multi && !clinics) {
            Promise.allSettled(branchIds.map(get)).then(rs => {
                clinics = rs.map((r, i) => ({ id: branchIds[i], data: r.status === 'fulfilled' ? r.value : null }));
                if (tab !== 'forecast') renderVisible();
            });
        }
        return get(scopeKey()).catch(() => null);
    }
    /** Month indexes in the 6-month trend: last full month, the one before, and the current partial month. */
    const months = d => { const L = d.trend.labels, n = L.length; return { i: n - 2, j: n - 3, cur: n - 1, L }; };

    // ── Markup helpers (theme cards, dashboard type scale) ────
    const kpi = o => `<div class="col-xxl-3 col-md-6"><div class="card stretch stretch-full an-kpi"><div class="card-body">
        <div class="an-kpi-top"><div class="an-kpi-label">${o.label}${o.note ? `<small>${o.note}</small>` : ''}</div><span class="an-ico ${o.tone}"><i class="${o.icon}"></i></span></div>
        <div class="an-kpi-value">${o.value}</div><div class="an-kpi-sub">${o.sub || '&nbsp;'}</div>
        ${o.spark ? `<div class="an-spark" id="${o.spark}" aria-hidden="true"></div>` : ''}
        ${o.bar !== undefined ? `<div class="progress ht-3"><div class="progress-bar" style="width:${Math.max(0, Math.min(100, o.bar))}%;background:var(${o.barColor || '--an-good'})"></div></div>` : ''}
        </div></div></div>`;
    const kpiSkel = () => `<div class="col-xxl-3 col-md-6"><div class="card stretch stretch-full an-kpi"><div class="card-body">
        <span class="dash-skel" style="width:55%"></span><div class="an-kpi-value"><span class="dash-skel" style="width:40%"></span></div><span class="dash-skel mt-2" style="width:70%"></span></div></div></div>`;
    const card = (title, sub, body, o = {}) => `<div class="card stretch stretch-full"><div class="card-header">
            <h5 class="card-title">${title}${sub ? ` <small>${sub}</small>` : ''}</h5>${o.action ? `<div class="card-header-action">${o.action}</div>` : ''}</div>
        <div class="card-body${o.flush ? ' p-0' : ''}">${body}</div>${o.after || ''}</div>`;
    const chartBox = (id, label, cls) => `<div class="an-chart${cls ? ' ' + cls : ''}" id="${id}" role="img" aria-label="${esc(label)}"></div>`;
    const chartState = (text, icon = 'feather-bar-chart-2') => `<div class="an-chart sm"><div class="an-chart-state"><i class="${icon}"></i>${text}</div></div>`;
    const legend = items => `<div class="dash-legend">${items.map(([v, label, kind]) =>
        `<span><i style="${kind === 'line' ? `width:16px;height:0;border-radius:0;border-top:2.5px solid var(${v})` : kind === 'dash' ? `width:16px;height:0;border-radius:0;border-top:2.5px dashed var(${v})` : `background:var(${v})`}"></i>${label}</span>`).join('')}</div>`;
    const table = (head, rows) => `<details class="dash-table"><summary>Show as table</summary><div class="table-responsive px-3 pb-3"><table class="table table-sm mb-0">
        <thead><tr>${head.map((h, k) => `<th${k ? ' class="text-end"' : ''}>${h}</th>`).join('')}</tr></thead>
        <tbody>${rows.map(r => `<tr>${r.map((c, k) => `<td${k ? ' class="text-end"' : ''}>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div></details>`;
    const state = (html, icon) => `<div class="card"><div class="an-state">${icon ? `<span class="an-ico t-slate"><i class="${icon}"></i></span>` : ''}${html}</div></div>`;
    // Loading: the same skeleton design as page navigation (assets/css/es-skeleton.css)
    const skChart = () => `<div class="sk-chart" aria-hidden="true">${[46, 62, 38, 74, 58, 84, 52, 68].map(h => `<span class="sk" style="height:${h}%"></span>`).join('')}</div>`;
    const skCard = () => `<div class="card stretch stretch-full"><div class="card-header"><span class="sk" style="width:210px;height:16px"></span></div><div class="card-body pb-2">${skChart()}</div></div>`;
    const loadingPane = () => `<div class="row">${[1, 2, 3, 4].map(kpiSkel).join('')}</div><div class="row" role="status" aria-label="Loading the clinic figures"><div class="col-xxl-8">${skCard()}</div><div class="col-xxl-4">${skCard()}</div></div>`;
    const errorPane = () => state(`<h2 class="an-h">The clinic figures could not be loaded</h2><div class="an-state-text">Check the connection, then try again.</div>
        <button type="button" class="dash-refresh mt-1" data-an-retry><i class="feather-refresh-cw"></i>Try again</button>`, 'feather-wifi-off');
    const monthCats = L => L.map((l, k) => k === L.length - 1 ? `${shortMonth(l)}${window.innerWidth < 576 ? '*' : ' (so far)'}` : shortMonth(l));
    /** Whole-number y axis for small counts (avoids ticks like 0.7 / 1.3 or a repeated "1"). */
    const intAxis = (o, values) => {
        const max = Math.max(0, ...values.filter(isNum));
        if (max <= 6) Object.assign(o.yaxis, { max: Math.max(1, Math.ceil(max)), tickAmount: Math.max(1, Math.ceil(max)), forceNiceScale: false });
        o.yaxis.labels.formatter = v => num(Math.round(v));
    };
    const insight = (tone, icon, title, text) => `<li class="an-insight"><span class="an-ico sm ${tone}"><i class="${icon}"></i></span><div><div class="an-insight-title">${title}</div><div class="an-insight-text">${text}</div></div></li>`;

    // ── Charts ────────────────────────────────────────────────
    const charts = {};
    function destroyCharts() {
        Object.keys(charts).forEach(id => { try { charts[id].chart.destroy(); } catch (e) { /* gone */ } charts[id].ro?.disconnect(); delete charts[id]; });
    }
    function base(type, height) {
        const ink = css('--an-muted') || '#6b7a90';
        return {
            chart: { type, height: window.innerWidth < 576 ? Math.min(height, 250) : height, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit',
                animations: { enabled: true, easing: 'easeout', speed: 350, animateGradually: { enabled: false } }, parentHeightOffset: 0 },
            grid: { borderColor: css('--an-line-soft') || '#eef2f7', strokeDashArray: 3, padding: { left: 8, right: 12, top: -6 } },
            dataLabels: { enabled: false }, legend: { show: false },
            xaxis: { labels: { style: { colors: ink, fontSize: '12px' }, rotate: 0, hideOverlappingLabels: true }, axisBorder: { show: false }, axisTicks: { show: false },
                tooltip: { enabled: false }, crosshairs: type === 'bar' ? { show: false } : {} },
            yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: ink, fontSize: '12px' } } },
            states: { active: { filter: { type: 'none' } }, hover: { filter: { type: 'darken', value: 0.92 } } },
            tooltip: { shared: true, intersect: false, followCursor: type === 'bar', theme: 'dark' },
        };
    }
    function mount(id, o) {
        const el = document.getElementById(id);
        if (!el) return;
        if (charts[id]) { try { charts[id].chart.destroy(); } catch (e) { /* gone */ } charts[id].ro?.disconnect(); }
        if (!window.ApexCharts) { el.innerHTML = '<div class="an-chart-state">The chart could not load; use “Show as table”.</div>'; return; }
        const ch = new ApexCharts(el, o);
        ch.render();
        let ro = null;
        if (window.ResizeObserver) {   // this ApexCharts build only re-measures on window resize
            let t = null;
            ro = new ResizeObserver(() => { clearTimeout(t); t = setTimeout(() => {
                const w = el.clientWidth, cur = ch.w?.globals?.svgWidth || 0;
                if (el.isConnected && w > 0 && Math.abs(w - cur) > 2) ch.updateOptions({ chart: { width: w } }, false, false);
            }, 120); });
            ro.observe(el);
        }
        charts[id] = { chart: ch, ro };
    }
    function spark(id, data, cats, colorName, fmt) {
        const c = css(colorName);
        mount(id, {
            chart: { type: 'area', height: 48, sparkline: { enabled: true }, animations: { enabled: false }, fontFamily: 'inherit' },
            series: [{ name: 'Value', data }], colors: [c],
            stroke: { curve: 'smooth', width: 2 },
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: .28, opacityTo: 0, stops: [0, 100] } },
            xaxis: { categories: cats }, yaxis: { min: 0 },
            tooltip: { theme: 'dark', fixed: { enabled: false }, x: { show: true }, y: { title: { formatter: () => '' }, formatter: v => fmt(v) }, marker: { show: false } },
        });
    }
    function donut(id, series, labels, colorNames, totalLabel, fmt = num) {
        const o = base('donut', 250);
        delete o.xaxis; delete o.yaxis; delete o.grid;
        Object.assign(o, {
            series, labels, colors: colorNames.map(css),
            stroke: { width: 2, colors: [css('--an-surface') || '#fff'] },
            plotOptions: { pie: { expandOnClick: false, donut: { size: '72%', labels: { show: true,
                name: { show: true, fontSize: '12px', color: css('--an-muted'), offsetY: 18 },
                value: { show: true, fontSize: '22px', fontWeight: 700, color: css('--an-ink'), offsetY: -14, formatter: v => fmt(Number(v)) },
                total: { show: true, showAlways: true, label: totalLabel, fontSize: '12px', color: css('--an-muted'), formatter: w => fmt(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } },
            tooltip: { theme: 'dark', y: { formatter: v => fmt(v) } },
        });
        o.chart.height = 250;
        mount(id, o);
    }
    const legendList = rows => {
        const t = rows.reduce((a, r) => a + r[2], 0);
        return `<ul class="an-legend-list">${rows.map(([color, label, n, fmt]) => `<li><span class="an-key" style="background:var(${color})"></span>${label}<span class="n">${(fmt || num)(n)}</span><span class="p">${t ? pct(n / t * 100) : '—'}</span></li>`).join('')}</ul>`;
    };

    // ── Clinic rows (owner) ───────────────────────────────────
    const NOSHOW_MIN_DUE = 10, NOSHOW_FLAG = 10, DROP_FLAG = 20;
    function clinicRows() {
        return (clinics || []).map(c => {
            if (!c.data) return { id: c.id, ok: false, flags: [] };
            const d = c.data, T = d.trend, { i, j, L } = months(d), ap = d.appointments;
            const a = T.appointments[i], b = T.appointments[j], r = T.revenue[i], q = T.revenue[j];
            const due = ap.completed + ap.no_show, ns = due ? ap.no_show / due * 100 : null;
            const flags = [], aCh = changePct(a, b), rCh = changePct(r, q);
            if (aCh !== null && aCh <= -DROP_FLAG) flags.push(`Appointments fell ${num(-aCh)}% from ${longMonth(L[j])}`);
            if (rCh !== null && rCh <= -DROP_FLAG) flags.push(`Payments fell ${num(-rCh)}%`);
            if (ns !== null && due >= NOSHOW_MIN_DUE && ns >= NOSHOW_FLAG) flags.push(`${pct(ns)} of due visits were no-shows`);
            return { id: c.id, ok: true, d, a, b, r, q, done: T.completed[i], ns, due, low: d.inventory.low, out: d.inventory.out, flags, err: d.errors || {} };
        });
    }
    function clinicTable() {
        if (!CFG.multi) return '';
        if (!clinics) return card('How the clinics compare', 'loading…', `<div class="card-body">${skChart()}</div>`, { flush: true });
        const rows = clinicRows(), ok = rows.filter(r => r.ok);
        if (!ok.length) return card('How the clinics compare', '', '<div class="an-state">The clinic figures could not be loaded.<button type="button" class="dash-refresh" data-an-retry><i class="feather-refresh-cw"></i>Try again</button></div>', { flush: true });
        const { i, j, L } = months(ok[0].d), mon = longMonth(L[i]);
        const maxA = Math.max(1, ...ok.map(r => r.a)), maxR = Math.max(1, ...ok.map(r => r.r));
        const bar = (v, max, txt, colorName) => `<span class="an-bar-cell"><span class="an-track" aria-hidden="true"><i style="width:${Math.max(v > 0 ? 3 : 0, v / max * 100)}%;background:var(${colorName})"></i></span><span class="an-val">${txt}</span></span>`;
        const body = rows.map(r => {
            const name = `<button type="button" class="an-clinic" data-an-clinic="${r.id}" title="Show only ${esc(nameOf(r.id))}"><span class="an-dot" style="background:var(${colorVar(r.id)})"></span>${esc(nameOf(r.id))}</button>`;
            if (!r.ok) return `<tr><td>${name}</td><td colspan="6" class="l text-muted">Figures could not be loaded for this clinic.</td></tr>`;
            const stock = r.err.inventory ? '—' : r.out || r.low
                ? [r.out ? `<span class="an-pill down">${num(r.out)} out</span>` : '', r.low ? `<span class="an-pill warn">${num(r.low)} low</span>` : ''].join(' ') : '<span class="text-muted">None</span>';
            const status = r.flags.length ? `<span class="an-pill warn"><i class="feather-alert-circle"></i>Needs a look</span><div class="an-flags mt-1">${r.flags.map(f => `<div>${f}</div>`).join('')}</div>`
                : !r.a && !r.r && !r.b && !r.q ? '<span class="an-pill">No activity yet</span>' : '<span class="an-pill up"><i class="feather-check"></i>On track</span>';
            return `<tr class="${branch === r.id ? 'is-current' : ''}">
                <td>${name}</td>
                <td data-label="Appointments, ${esc(mon)}"><span class="an-cell">${r.err.appointments ? '—' : bar(r.a, maxA, num(r.a), colorVar(r.id))}${r.b && !r.err.appointments ? `<span class="an-subval">${vs(r.a, r.b, shortMonth(L[j]))}</span>` : ''}</span></td>
                <td data-label="Completed"><span class="an-cell">${r.err.appointments || !r.a ? '—' : pct(r.done / r.a * 100)}</span></td>
                <td data-label="Payments, ${esc(mon)}"><span class="an-cell">${r.err.payments ? '—' : bar(r.r, maxR, peso(r.r), colorVar(r.id))}${r.q && !r.err.payments ? `<span class="an-subval">${vs(r.r, r.q, shortMonth(L[j]), { fmt: peso })}</span>` : ''}</span></td>
                <td data-label="No-show rate, all time"><span class="an-cell">${r.ns === null ? '—' : pct(r.ns)}${r.due ? `<span class="an-subval">of ${num(r.due)} due visits</span>` : ''}</span></td>
                <td data-label="Low stock, now"><span class="an-cell">${stock}</span></td>
                <td class="l">${status}</td></tr>`;
        }).join('');
        return card('How the clinics compare', `${esc(mon)}, except where noted`, `<div class="table-responsive"><table class="an-ctable"><thead><tr><th>Clinic</th><th>Appointments<small>${esc(mon)}</small></th><th>Completed<small>${esc(mon)}</small></th>
            <th>Payments received<small>${esc(mon)}</small></th><th>No-show rate<small>all time</small></th><th>Low stock<small>right now</small></th><th class="l">Status</th></tr></thead><tbody>${body}</tbody></table></div>`,
            { flush: true, after: `<p class="an-note pt-3">“Needs a look” when appointments or payments fell ${DROP_FLAG}% or more from ${esc(longMonth(L[j]))}, or ${NOSHOW_FLAG}% or more of at least ${NOSHOW_MIN_DUE} due visits were no-shows. Select a clinic to focus the page on it.</p>` });
    }

    // ── Overview ──────────────────────────────────────────────
    let ovMetric = 'appointments';
    function highlights(d) {
        const T = d.trend, { i, j, L } = months(d), errs = d.errors || {}, mon = longMonth(L[i]), pmon = longMonth(L[j]), out = [];
        if (!errs.appointments) {
            const a = T.appointments[i], b = T.appointments[j];
            if (a || b) {
                const ch = changePct(a, b), flat = ch !== null && Math.abs(ch) < FLAT;
                const [tone, icon, word] = ch === null ? ['t-blue', 'feather-calendar', 'Appointments'] : flat ? ['t-slate', 'feather-minus', 'Appointments steady'] : ch > 0 ? ['t-green', 'feather-trending-up', 'Appointments up'] : ['t-red', 'feather-trending-down', 'Appointments down'];
                out.push(insight(tone, icon, word, `<b>${num(a)}</b> in ${mon}, ${relPhrase(a, b, pmon, num)}.`));
                const x = T.cancelled[i];
                if (a && x / a >= .15) out.push(insight('t-amber', 'feather-x-circle', 'Many cancellations', `<b>${num(x)} of ${num(a)}</b> appointments in ${mon} were cancelled (${pct(x / a * 100)}).`));
            } else out.push(insight('t-slate', 'feather-calendar', 'No appointments yet', `None were recorded in ${pmon} or ${mon}.`));
        }
        if (!errs.payments) {
            const r = T.revenue[i], q = T.revenue[j];
            if (r || q) {
                const ch = changePct(r, q), flat = ch !== null && Math.abs(ch) < FLAT;
                const [tone, icon, word] = ch === null ? ['t-blue', 'feather-credit-card', 'Payments received'] : flat ? ['t-slate', 'feather-minus', 'Payments steady'] : ch > 0 ? ['t-green', 'feather-trending-up', 'Payments up'] : ['t-red', 'feather-trending-down', 'Payments down'];
                out.push(insight(tone, icon, word, `<b>${peso(r)}</b> received in ${mon}, ${relPhrase(r, q, pmon, peso, 'less')}.`));
            }
        }
        if (allClinics() && clinics) clinicRows().filter(r => r.flags.length).slice(0, 2)
            .forEach(r => out.push(insight('t-amber', 'feather-map-pin', `${esc(nameOf(r.id))} needs a look`, andList(r.flags) + '.')));
        if (!errs.inventory && d.inventory.total) {
            const o = d.inventory.out, l = d.inventory.low;
            if (o) out.push(insight('t-red', 'feather-alert-octagon', `${plural(o, 'material')} out of stock`, `Reorder ${o === 1 ? 'it' : 'them'} first${l ? `; ${num(l)} more ${l === 1 ? 'is' : 'are'} running low` : ''}.`));
            else if (l) out.push(insight('t-amber', 'feather-alert-triangle', `${plural(l, 'material')} running low`, 'At or below the minimum stock level right now.'));
            else out.push(insight('t-green', 'feather-check-circle', 'Stock is healthy', 'Every material is above its minimum level.'));
        }
        const fb = window.ESForecast ? window.ESForecast.brief() : null;
        if (fb && fb.state === 'ok' && fb.source !== 'simulated' && fb.hasRun && fb.appointments) {
            const f = fb.appointments, P = fb.period;
            if (f.ready) out.push(insight('t-orange', 'feather-trending-up', `Next ${P} days <span class="an-fc-tag">Forecast</span>`,
                `About <b>${plural(Math.round(f.next), 'visit')}</b> expected, ${f.prev ? relPhrase(f.next, f.prev, `the previous ${P} days`, num) : `with none recorded in the previous ${P} days`}.${f.early ? ' An early estimate.' : ''}`));
            else out.push(insight('t-slate', 'feather-clock', 'Forecast not ready yet', `It starts once ${allClinics() ? 'a clinic has' : 'the clinic has'} ${num(f.need)} days of records${allClinics() ? '' : ` (${num(f.nObs)} so far)`}.`));
        }
        return out.slice(0, 6);
    }
    function renderOverview() {
        const el = $('#anPane-overview');
        if (scope.state === 'loading') { el.innerHTML = loadingPane(); return; }
        if (scope.state === 'error') { el.innerHTML = errorPane(); return; }
        const d = scope.data, T = d.trend, s = d.sales, errs = d.errors || {}, { i, j, cur, L } = months(d);
        const mon = longMonth(L[i]), pmon = longMonth(L[j]);
        const a = T.appointments[i], b = T.appointments[j], c = T.completed[i], r = T.revenue[i], q = T.revenue[j];
        const rate = s.billed ? s.collected / s.billed * 100 : null;
        const kpis = [
            kpi({ label: `Appointments`, note: mon, icon: 'feather-calendar', tone: 't-blue', value: errs.appointments ? '—' : num(a), sub: errs.appointments ? 'Could not be read' : vs(a, b, pmon), spark: errs.appointments ? null : 'spk-apt' }),
            kpi({ label: 'Completion rate', note: mon, icon: 'feather-check-circle', tone: 't-teal', value: errs.appointments || !a ? '—' : pct(c / a * 100),
                sub: errs.appointments ? 'Could not be read' : a ? `${num(c)} of ${plural(a, 'appointment')} completed` : 'No appointments that month', spark: errs.appointments ? null : 'spk-done' }),
            kpi({ label: 'Payments received', note: mon, icon: 'feather-credit-card', tone: 't-violet', value: errs.payments ? '—' : peso(r), sub: errs.payments ? 'Could not be read' : vs(r, q, pmon, { fmt: peso }), spark: errs.payments ? null : 'spk-rev' }),
            kpi({ label: 'Unpaid balances', note: 'as of today', icon: 'feather-alert-circle', tone: 't-amber', value: errs.invoices ? '—' : peso(s.outstanding),
                sub: errs.invoices ? 'Could not be read' : `${plural(s.partial + s.unpaid, 'invoice')} not fully paid${rate === null ? '' : `; ${pct(rate)} of billing collected`}`, bar: errs.invoices || rate === null ? undefined : rate, barColor: '--an-good' }),
        ].join('');

        // Performance trend (by clinic for the owner's all-clinics view, otherwise by outcome)
        const byClinic = allClinics() && clinics && clinics.some(x => x.data);
        const seg = `<div class="an-seg" role="group" aria-label="Trend measure"><button type="button" data-an-ov="appointments" aria-pressed="${ovMetric === 'appointments'}">Appointments</button><button type="button" data-an-ov="revenue" aria-pressed="${ovMetric === 'revenue'}">Revenue</button></div>`;
        const okClinics = byClinic ? clinics.filter(x => x.data) : [];
        const trendLegend = byClinic ? legend(okClinics.map(x => [colorVar(x.id), esc(nameOf(x.id)), 'line']))
            : ovMetric === 'appointments' ? legend([['--an-hist', 'All appointments', 'line'], ['--an-c2', 'Completed', 'line'], ['--an-cancel', 'Cancelled', 'line']]) : legend([['--an-hist', 'Payments received', 'line']]);
        const trendErr = ovMetric === 'appointments' ? errs.appointments : errs.payments;
        const trendVals = byClinic ? okClinics.flatMap(x => x.data.trend[ovMetric === 'revenue' ? 'revenue' : 'appointments']) : T[ovMetric === 'revenue' ? 'revenue' : 'appointments'];
        const trendEmpty = !trendErr && !trendVals.some(v => v > 0);
        const trendCard = card('Performance trend', `${byClinic ? 'by clinic, ' : ''}last 6 months; ${shortMonth(L[cur])} is so far`,
            (trendErr ? chartState('These records could not be read just now.') : trendEmpty ? chartState(ovMetric === 'revenue' ? 'No payments in the last 6 months.' : 'No appointments in the last 6 months.')
                : chartBox('ovTrend', 'Performance trend, last 6 months') + `<div class="mt-2">${trendLegend}</div>`), { action: seg });
        const hl = highlights(d);
        const hlCard = card('Highlights', `${esc(mon)} vs ${esc(pmon)}`, `<ul class="an-insights">${hl.join('') || insight('t-slate', 'feather-info', 'Nothing to report yet', 'Highlights appear as appointments and payments are recorded.')}</ul>`, { flush: true });

        // Row 3: clinic bars + table (owner) or outcome / invoice donuts
        let row3 = '';
        if (CFG.multi) {
            const bars = clinics && clinics.some(x => x.data) ? `<div class="col-xl-6">${card('Appointments by clinic', esc(mon), chartBox('ovCliApt', `Appointments by clinic, ${mon}`, 'sm'))}</div>
                <div class="col-xl-6">${card('Payments by clinic', esc(mon), chartBox('ovCliRev', `Payments by clinic, ${mon}`, 'sm'))}</div>` : '';
            row3 = `<div class="row">${bars}<div class="col-12">${clinicTable()}</div></div>`;
        } else {
            const other = Math.max(0, a - c - T.cancelled[i]);
            row3 = `<div class="row"><div class="col-xl-6">${card('Appointment outcomes', esc(mon), a ? chartBox('ovOutcome', `Appointment outcomes, ${mon}`, 'sm') + legendList([['--an-hist', 'Completed', c], ['--an-cancel', 'Cancelled', T.cancelled[i]], ['--an-other', 'Other (booked, checked in, no-show)', other]]) : chartState(`No appointments in ${mon}.`), { flush: !!a })}</div>
                <div class="col-xl-6">${card('Invoice status', 'all invoices', s.invoices ? chartBox('ovInvoices', 'Invoices by payment status', 'sm') + legendList([['--an-good', 'Paid in full', s.paid], ['--an-warn', 'Partly paid', s.partial], ['--an-bad', 'Unpaid', s.unpaid]]) : chartState('No invoices yet.'), { flush: !!s.invoices })}</div></div>`;
        }

        // Row 4: forecast teaser + stock alerts
        const pv = window.ESForecast ? window.ESForecast.preview() : null;
        const fs = window.ESForecast ? window.ESForecast.state() : null;
        let fcBody;
        if (!pv) fcBody = fs && (fs.loadState === 'loading' || fs.loadState === 'idle') ? `<div class="an-chart sm">${skChart()}</div>` : chartState('No forecast is available yet. See the Forecast tab.', 'feather-trending-up');
        else if (!pv.hist.some(h => h.y !== null) && !pv.fc.length) fcBody = chartState('No visits recorded in the last 4 weeks.', 'feather-trending-up');
        else fcBody = chartBox('ovForecast', 'Recorded visits and forecast', 'sm');
        const fcAction = `${legend([['--an-hist', 'Recorded', 'line'], ['--an-fc', 'Forecast', 'dash']])}<button type="button" class="an-link" data-an-goto="forecast">Open forecast <i class="feather-chevron-right"></i></button>`;
        const fcSub = pv ? `last 4 weeks and the next ${pv.period} days${pv.source === 'simulated' ? ', demo data' : ''}` : '';
        const fcCard = card(`Visits ahead <span class="an-fc-tag ms-1">Forecast</span>`, fcSub, fcBody, { action: fcAction });
        const alerts = (d.stock_alerts || []).slice(0, 5);
        const stockBody = errs.inventory ? '<div class="an-state">Stock levels could not be read just now.</div>'
            : alerts.length ? `<ul class="an-legend-list">${alerts.map(x => `<li><span class="an-ico sm ${x.level === 'out' ? 't-red' : 't-amber'}"><i class="feather-package"></i></span><span><span class="d-block fw-semibold">${esc(x.name)}</span><span class="fs-11 text-muted">${allClinics() ? esc(x.branch) + ', ' : ''}${num(x.qty)} left, minimum ${num(x.min)}</span></span><span class="n">${x.level === 'out' ? '<span class="an-pill down">Out</span>' : '<span class="an-pill warn">Low</span>'}</span></li>`).join('')}</ul>`
            : '<div class="an-state"><span class="an-ico t-green"><i class="feather-check"></i></span>Every material is above its minimum stock.</div>';
        const inv = d.inventory;
        const stockTop = errs.inventory || !inv.total ? '' : `<div class="px-4 pt-3 pb-3 border-bottom">
            <div class="d-flex justify-content-between align-items-baseline mb-2"><span class="fs-12 text-muted">Materials above their minimum</span><span class="fw-bold text-dark">${num(inv.in_stock)} of ${num(inv.total)}</span></div>
            <div class="an-prop" role="img" aria-label="Stock levels">${[['--an-good', inv.in_stock], ['--an-warn', inv.low], ['--an-bad', inv.out]].map(([cv, v]) => v ? `<i style="width:${v / inv.total * 100}%;background:var(${cv})"></i>` : '').join('')}</div>
            <div class="dash-legend mt-2"><span><i style="background:var(--an-good)"></i>${num(inv.in_stock)} OK</span><span><i style="background:var(--an-warn)"></i>${num(inv.low)} low</span><span><i style="background:var(--an-bad)"></i>${num(inv.out)} out</span></div></div>`;
        const stockCard = card('Stock alerts', 'right now', stockTop + stockBody, { flush: true, action: '<button type="button" class="an-link" data-an-goto="inventory">Inventory <i class="feather-chevron-right"></i></button>' });

        el.innerHTML = `<div class="row">${kpis}</div>
            <div class="row"><div class="col-xxl-8">${trendCard}</div><div class="col-xxl-4">${hlCard}</div></div>
            ${row3}
            <div class="row"><div class="col-xxl-8">${fcCard}</div><div class="col-xxl-4">${stockCard}</div></div>`;

        // Charts
        const cats = L.map(shortMonth);
        if (!errs.appointments) { spark('spk-apt', T.appointments, cats, '--an-hist', num); spark('spk-done', T.completed, cats, '--an-c2', num); }
        if (!errs.payments) spark('spk-rev', T.revenue, cats, '--an-c3', peso);
        if (!trendErr && !trendEmpty) {
            const o = base('area', 300);
            const key = ovMetric === 'revenue' ? 'revenue' : 'appointments', fmt = key === 'revenue' ? peso : num;
            const series = byClinic ? okClinics.map(x => ({ name: nameOf(x.id), data: x.data.trend[key] }))
                : key === 'revenue' ? [{ name: 'Payments received', data: T.revenue }]
                : [{ name: 'All appointments', data: T.appointments }, { name: 'Completed', data: T.completed }, { name: 'Cancelled', data: T.cancelled }];
            const colors = byClinic ? okClinics.map(x => css(colorVar(x.id))) : key === 'revenue' ? [css('--an-hist')] : [css('--an-hist'), css('--an-c2'), css('--an-cancel')];
            Object.assign(o, { series, colors, stroke: { curve: 'smooth', width: 2.5 }, markers: { size: 0, hover: { size: 5 } },
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: series.length > 1 ? .12 : .25, opacityTo: 0, stops: [0, 95] } } });
            o.xaxis.categories = monthCats(L);
            if (key === 'revenue') o.yaxis.labels.formatter = v => '₱' + compact(v); else intAxis(o, series.flatMap(x => x.data));
            o.tooltip.y = { formatter: v => fmt(v) };
            mount('ovTrend', o);
        }
        if (CFG.multi && clinics && document.getElementById('ovCliApt')) {
            const ok = clinics.filter(x => x.data);
            const bar = (id, key, fmt, axisFmt) => {
                const mi = months(ok[0].data).i;
                const o = base('bar', 260);
                Object.assign(o, {
                    series: [{ name: key === 'revenue' ? 'Payments received' : 'Appointments', data: ok.map(x => x.data.trend[key][mi]) }],
                    colors: ok.map(x => css(colorVar(x.id))),
                    plotOptions: { bar: { distributed: true, columnWidth: '42%', borderRadius: 5, dataLabels: { position: 'top' } } },
                    dataLabels: { enabled: true, offsetY: -20, formatter: v => fmt(v), style: { fontSize: '12px', fontWeight: 600, colors: [css('--an-ink')] } },
                });
                o.xaxis.categories = ok.map(x => nameOf(x.id));
                o.yaxis.labels.formatter = axisFmt;
                if (key !== 'revenue') intAxis(o, ok.map(x => x.data.trend[key][mi]));
                o.tooltip = { theme: 'dark', y: { formatter: v => fmt(v) } };
                o.grid.padding.top = 10;
                mount(id, o);
            };
            bar('ovCliApt', 'appointments', num, v => num(v));
            bar('ovCliRev', 'revenue', peso, v => '₱' + compact(v));
        } else if (!CFG.multi) {
            if (a) donut('ovOutcome', [c, T.cancelled[i], Math.max(0, a - c - T.cancelled[i])], ['Completed', 'Cancelled', 'Other'], ['--an-hist', '--an-cancel', '--an-other'], 'Appointments');
            if (s.invoices) donut('ovInvoices', [s.paid, s.partial, s.unpaid], ['Paid in full', 'Partly paid', 'Unpaid'], ['--an-good', '--an-warn', '--an-bad'], 'Invoices');
        }
        if (pv && document.getElementById('ovForecast')) {
            const o = base('area', 260), fcLine = pv.fc.map(f => ({ x: utc(f.d), y: f.y }));
            const lastH = pv.hist.find(h => h.d === pv.last);
            if (fcLine.length && lastH && lastH.y !== null) fcLine.unshift({ x: utc(pv.last), y: lastH.y });
            Object.assign(o, {
                series: [{ name: 'Recorded', data: pv.hist.map(h => ({ x: utc(h.d), y: h.y })) }, { name: 'Forecast', data: fcLine }],
                colors: [css('--an-hist'), css('--an-fc')],
                stroke: { curve: 'straight', width: [2.5, 2.5], dashArray: [0, 6] },
                fill: { type: ['gradient', 'solid'], opacity: [1, 0], gradient: { shadeIntensity: 1, opacityFrom: .22, opacityTo: 0, stops: [0, 95] } },
                markers: { size: 0, hover: { size: 4 } },
                annotations: fcLine.length ? { xaxis: [{ x: utc(pv.last) + 432e5, borderColor: css('--an-other'), strokeDashArray: 4,
                    label: { text: 'Forecast starts', borderColor: 'transparent', position: 'top', orientation: 'horizontal', textAnchor: 'start', offsetX: 4,
                        style: { background: 'transparent', color: css('--an-muted'), fontSize: '11px' } } }] } : {},
            });
            Object.assign(o.xaxis, { type: 'datetime', tickAmount: window.innerWidth < 768 ? 4 : 7,
                labels: { ...o.xaxis.labels, datetimeUTC: true, formatter: (v, ts) => shortDate(new Date(ts ?? v).toISOString().slice(0, 10)) } });
            intAxis(o, pv.hist.map(h => h.y).concat(pv.fc.map(f => f.y)));
            o.tooltip.x = { format: 'MMM d' };
            o.tooltip.y = { formatter: v => v === null || v === undefined ? '—' : num(v, 1) };
            mount('ovForecast', o);
        }
    }

    // ── Appointments ──────────────────────────────────────────
    function renderAppointments() {
        const el = $('#anPane-appointments');
        if (scope.state === 'loading') { el.innerHTML = loadingPane(); return; }
        if (scope.state === 'error') { el.innerHTML = errorPane(); return; }
        const d = scope.data, T = d.trend, ap = d.appointments, { i, j, cur, L } = months(d);
        if ((d.errors || {}).appointments) { el.innerHTML = state('Appointment records could not be read just now. Try again in a moment.', 'feather-alert-circle'); return; }
        const mon = longMonth(L[i]), pmon = longMonth(L[j]);
        const a = T.appointments[i], b = T.appointments[j], c = T.completed[i], x = T.cancelled[i];
        const due = ap.completed + ap.no_show;
        const kpis = [
            kpi({ label: 'Appointments', note: mon, icon: 'feather-calendar', tone: 't-blue', value: num(a), sub: vs(a, b, pmon), spark: 'spk-a1' }),
            kpi({ label: 'Completed', note: mon, icon: 'feather-check-circle', tone: 't-teal', value: num(c), sub: a ? `${pct(c / a * 100)} of appointments` : 'No appointments that month', spark: 'spk-a2' }),
            kpi({ label: 'Cancelled', note: mon, icon: 'feather-x-circle', tone: 't-red', value: num(x), sub: T.cancelled[j] ? vs(x, T.cancelled[j], pmon, { reverse: true }) : a ? `${pct(x / a * 100)} of appointments` : '&nbsp;', spark: 'spk-a3' }),
            kpi({ label: 'No-show rate', note: 'all recorded visits', icon: 'feather-user-x', tone: 't-amber', value: due ? pct(ap.no_show / due * 100) : '—', sub: due ? `${num(ap.no_show)} of ${plural(due, 'due visit')}` : 'No completed visits yet' }),
        ].join('');
        const other = T.appointments.map((t, k) => Math.max(0, t - T.completed[k] - T.cancelled[k]));
        const has = T.appointments.some(v => v > 0);
        const monthly = card('Appointments by month', `${esc(cap(scopeName()))}, last 6 months; ${shortMonth(L[cur])} is so far`,
            (has ? chartBox('apMonthly', 'Appointments by month: completed, cancelled and other') : chartState('No appointments in the last 6 months.')),
            { action: legend([['--an-hist', 'Completed'], ['--an-cancel', 'Cancelled'], ['--an-other', 'Other']]),
              after: table(['Month', 'Total', 'Completed', 'Cancelled', 'Other'], L.map((l, k) => [esc(l) + (k === cur ? ' (so far)' : ''), num(T.appointments[k]), num(T.completed[k]), num(T.cancelled[k]), num(other[k])])) });
        const booked = ap.pending + ap.confirmed;
        const statusRows = [['--an-hist', 'Completed', ap.completed], ['--an-cancel', 'Cancelled', ap.cancelled], ['--an-warn', 'No-show', ap.no_show], ['--an-c3', 'Booked, not yet due', booked], ['--an-other', 'Checked in', ap.checked_in]];
        const statusCard = card('Status breakdown', `all ${num(ap.total)} recorded`, ap.total ? chartBox('apStatus', 'Appointments by status', 'sm') + legendList(statusRows) : chartState('No appointments recorded yet.'), { flush: !!ap.total });
        const svc = d.top_services || [];
        const maxS = Math.max(1, ...svc.map(s => s.count));
        const svcCard = card('Most booked services', 'all time', svc.length ? `<ul class="an-barlist">${svc.map(s => `<li><div class="row1"><span>${esc(s.name)}</span><span class="n">${num(s.count)}</span></div><span class="an-track"><i style="width:${s.count / maxS * 100}%"></i></span></li>`).join('')}</ul>` : '<div class="an-state">No services booked yet.</div>', { flush: true });
        let row3;
        if (CFG.multi && clinics && clinics.some(y => y.data)) {
            const ok = clinics.filter(y => y.data);
            row3 = `<div class="row"><div class="col-xxl-8">${card('Appointments by clinic', `last 6 months; ${shortMonth(L[cur])} is so far`, chartBox('apClinics', 'Appointments by clinic and month'),
                { action: legend(ok.map(y => [colorVar(y.id), esc(nameOf(y.id))])), after: table(['Month', ...ok.map(y => esc(nameOf(y.id)))], L.map((l, k) => [esc(l), ...ok.map(y => num(y.data.trend.appointments[k]))])) })}</div>
                <div class="col-xxl-4">${svcCard}</div></div>`;
        } else row3 = `<div class="row"><div class="col-xxl-6">${svcCard}</div><div class="col-xxl-6">${card('What this means', '', `<ul class="an-insights">${apptNotes(d).join('')}</ul>`, { flush: true })}</div></div>`;
        el.innerHTML = `<div class="row">${kpis}</div><div class="row"><div class="col-xxl-8">${monthly}</div><div class="col-xxl-4">${statusCard}</div></div>${row3}`;

        const cats = L.map(shortMonth);
        spark('spk-a1', T.appointments, cats, '--an-hist', num);
        spark('spk-a2', T.completed, cats, '--an-c2', num);
        spark('spk-a3', T.cancelled, cats, '--an-cancel', num);
        if (has) {
            const o = base('bar', 300);
            Object.assign(o, { series: [{ name: 'Completed', data: T.completed }, { name: 'Cancelled', data: T.cancelled }, { name: 'Other', data: other }],
                colors: [css('--an-hist'), css('--an-cancel'), css('--an-other')], plotOptions: { bar: { columnWidth: '42%', borderRadius: 4 } } });
            o.chart.stacked = true;
            o.xaxis.categories = monthCats(L);
            intAxis(o, T.appointments);
            mount('apMonthly', o);
        }
        if (ap.total) donut('apStatus', statusRows.map(rw => rw[2]), statusRows.map(rw => rw[1]), statusRows.map(rw => rw[0]), 'Appointments');
        if (document.getElementById('apClinics')) {
            const ok = clinics.filter(y => y.data), o = base('bar', 300);
            Object.assign(o, { series: ok.map(y => ({ name: nameOf(y.id), data: y.data.trend.appointments })), colors: ok.map(y => css(colorVar(y.id))),
                plotOptions: { bar: { columnWidth: '58%', borderRadius: 3 } }, stroke: { show: true, width: 3, colors: ['transparent'] } });
            o.xaxis.categories = monthCats(L);
            intAxis(o, ok.flatMap(y => y.data.trend.appointments));
            mount('apClinics', o);
        }
    }
    function apptNotes(d) {
        const T = d.trend, ap = d.appointments, { i, j, cur, L } = months(d), out = [];
        const mon = longMonth(L[i]), pmon = longMonth(L[j]);
        const full = L.map((_, k) => k).filter(k => k < cur);
        const best = full.reduce((a, k) => T.appointments[k] > (a === null ? -1 : T.appointments[a]) ? k : a, null);
        if (best !== null && T.appointments[best] > 0) out.push(insight('t-blue', 'feather-award', 'Busiest full month', `${longMonth(L[best])}, with <b>${plural(T.appointments[best], 'appointment')}</b>.`));
        if (T.appointments[i]) {
            const cr = T.cancelled[i] / T.appointments[i] * 100;
            const prevTxt = T.appointments[j] ? (T.appointments[j] < 10 ? `${num(T.cancelled[j])} of ${num(T.appointments[j])}` : pct(T.cancelled[j] / T.appointments[j] * 100)) + ` in ${pmon}` : '';
            out.push(insight(cr >= 15 ? 't-amber' : 't-slate', 'feather-x-circle', `${pct(cr)} cancelled in ${mon}`, `${num(T.cancelled[i])} of ${num(T.appointments[i])} appointments${prevTxt ? `, against ${prevTxt}` : ''}.`));
        }
        const due = ap.completed + ap.no_show;
        if (due) out.push(insight(ap.no_show / due * 100 >= NOSHOW_FLAG ? 't-amber' : 't-green', 'feather-user-x', `${pct(ap.no_show / due * 100)} no-shows`, `${num(ap.no_show)} of ${plural(due, 'due visit')} across all records.`));
        if (!out.length) out.push(insight('t-slate', 'feather-info', 'Nothing recorded yet', 'Notes appear here as appointments are booked and completed.'));
        return out;
    }

    // ── Revenue ───────────────────────────────────────────────
    function renderRevenue() {
        const el = $('#anPane-revenue');
        if (scope.state === 'loading') { el.innerHTML = loadingPane(); return; }
        if (scope.state === 'error') { el.innerHTML = errorPane(); return; }
        const d = scope.data, T = d.trend, s = d.sales, errs = d.errors || {}, { i, j, cur, L } = months(d);
        const mon = longMonth(L[i]), pmon = longMonth(L[j]);
        const full = L.map((_, k) => k).filter(k => k < cur);
        const sum = full.reduce((t, k) => t + T.revenue[k], 0), avg = sum / full.length;
        const rate = s.billed ? s.collected / s.billed * 100 : null;
        const kpis = [
            kpi({ label: 'Payments received', note: mon, icon: 'feather-credit-card', tone: 't-violet', value: errs.payments ? '—' : peso(T.revenue[i]), sub: errs.payments ? 'Could not be read' : vs(T.revenue[i], T.revenue[j], pmon, { fmt: peso }), spark: errs.payments ? null : 'spk-r1' }),
            kpi({ label: 'Monthly average', note: `last ${WORDS[full.length] || full.length} full months`, icon: 'feather-bar-chart-2', tone: 't-blue', value: errs.payments ? '—' : peso(avg), sub: errs.payments ? '' : `${peso(sum)} in total` }),
            kpi({ label: 'Collection rate', note: 'all invoices', icon: 'feather-percent', tone: 't-teal', value: errs.invoices || rate === null ? '—' : pct(rate), sub: errs.invoices ? 'Could not be read' : `${peso(s.collected)} of ${peso(s.billed)} billed`, bar: errs.invoices || rate === null ? undefined : rate, barColor: rate !== null && rate < 80 ? '--an-warn' : '--an-good' }),
            kpi({ label: 'Unpaid balances', note: 'as of today', icon: 'feather-alert-circle', tone: 't-amber', value: errs.invoices ? '—' : peso(s.outstanding), sub: errs.invoices ? 'Could not be read' : `${plural(s.partial + s.unpaid, 'invoice')} not fully paid` }),
        ].join('');
        const has = !errs.payments && T.revenue.some(v => v > 0);
        const monthly = card('Payments received by month', `money actually received, last 6 months; ${shortMonth(L[cur])} is so far`,
            errs.payments ? chartState('Payments could not be read just now.') : has ? chartBox('rvMonthly', 'Payments received by month') : chartState('No payments in the last 6 months.'),
            { action: legend([['--an-hist', 'Full month'], ['--an-other', `${shortMonth(L[cur])}, so far`]]),
              after: errs.payments ? '' : table(['Month', 'Payments received'], L.map((l, k) => [esc(l) + (k === cur ? ' (so far)' : ''), peso(T.revenue[k])])) });
        const inv = errs.invoices ? card('Invoice status', '', '<div class="an-state">Invoices could not be read just now.</div>')
            : card('Invoice status', `all ${num(s.invoices)} invoices`, s.invoices ? chartBox('rvInvoices', 'Invoices by payment status', 'sm') + legendList([['--an-good', 'Paid in full', s.paid], ['--an-warn', 'Partly paid', s.partial], ['--an-bad', 'Unpaid', s.unpaid]]) : chartState('No invoices yet.'),
                { flush: !!s.invoices, after: `<div class="an-card-foot d-flex justify-content-between"><span>Still owed</span><b class="text-dark">${peso(s.outstanding)}</b></div>` });
        let row3 = '';
        if (CFG.multi && clinics && clinics.some(x => x.data && !(x.data.errors || {}).payments)) {
            const ok = clinics.filter(x => x.data && !(x.data.errors || {}).payments);
            const shareTotal = ok.reduce((t, x) => t + x.data.trend.revenue[months(x.data).i], 0);
            row3 = `<div class="row"><div class="col-xxl-8">${card('Payments by clinic', `last 6 months; ${shortMonth(L[cur])} is so far`, chartBox('rvClinics', 'Payments by clinic and month'),
                { action: legend(ok.map(x => [colorVar(x.id), esc(nameOf(x.id)), 'line'])), after: table(['Month', ...ok.map(x => esc(nameOf(x.id)))], L.map((l, k) => [esc(l), ...ok.map(x => peso(x.data.trend.revenue[k]))])) })}</div>
                <div class="col-xxl-4">${card('Share of payments', esc(mon), shareTotal ? chartBox('rvShare', `Share of ${mon} payments by clinic`, 'sm') + legendList(ok.map(x => [colorVar(x.id), esc(nameOf(x.id)), x.data.trend.revenue[months(x.data).i], peso])) : chartState(`No payments in ${mon}.`), { flush: !!shareTotal })}</div></div>`;
        }
        el.innerHTML = `<div class="row">${kpis}</div><div class="row"><div class="col-xxl-8">${monthly}</div><div class="col-xxl-4">${inv}</div></div>${row3}`;

        if (!errs.payments) spark('spk-r1', T.revenue, L.map(shortMonth), '--an-c3', peso);
        if (has) {
            const o = base('bar', 300);
            Object.assign(o, { series: [{ name: 'Payments received', data: T.revenue }], colors: L.map((_, k) => css(k === cur ? '--an-other' : '--an-hist')),
                plotOptions: { bar: { distributed: true, columnWidth: '42%', borderRadius: 5, dataLabels: { position: 'top' } } },
                dataLabels: { enabled: window.innerWidth >= 768, offsetY: -20, formatter: v => v ? '₱' + compact(v) : '', style: { fontSize: '11px', fontWeight: 600, colors: [css('--an-muted')] } } });
            o.xaxis.categories = monthCats(L);
            o.yaxis.labels.formatter = v => '₱' + compact(v);
            o.tooltip = { theme: 'dark', y: { formatter: v => peso(v) } };
            o.grid.padding.top = 10;
            mount('rvMonthly', o);
        }
        if (!errs.invoices && s.invoices) donut('rvInvoices', [s.paid, s.partial, s.unpaid], ['Paid in full', 'Partly paid', 'Unpaid'], ['--an-good', '--an-warn', '--an-bad'], 'Invoices');
        if (document.getElementById('rvClinics')) {
            const ok = clinics.filter(x => x.data && !(x.data.errors || {}).payments), o = base('area', 300);
            Object.assign(o, { series: ok.map(x => ({ name: nameOf(x.id), data: x.data.trend.revenue })), colors: ok.map(x => css(colorVar(x.id))),
                stroke: { curve: 'smooth', width: ok.map(x => branch === 'all' || branch === x.id ? 2.5 : 1.5) }, markers: { size: 0, hover: { size: 5 } },
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: .12, opacityTo: 0, stops: [0, 95] } } });
            o.xaxis.categories = monthCats(L);
            o.yaxis.labels.formatter = v => '₱' + compact(v);
            o.tooltip.y = { formatter: v => peso(v) };
            mount('rvClinics', o);
            if (document.getElementById('rvShare')) donut('rvShare', ok.map(x => x.data.trend.revenue[months(x.data).i]), ok.map(x => nameOf(x.id)), ok.map(x => colorVar(x.id)), 'Total', peso);
        }
    }

    // ── Inventory ─────────────────────────────────────────────
    function renderInventory() {
        const el = $('#anPane-inventory');
        if (scope.state === 'loading') { el.innerHTML = loadingPane(); return; }
        if (scope.state === 'error') { el.innerHTML = errorPane(); return; }
        const d = scope.data, inv = d.inventory, alerts = d.stock_alerts || [], errs = d.errors || {};
        const fs = window.ESForecast ? window.ESForecast.state() : null;
        const mat = window.ESForecast ? window.ESForecast.materials() : null;
        const soon = mat ? mat.rows.filter(r => r.attention).length : null;
        const kpis = errs.inventory ? '' : [
            kpi({ label: 'Materials tracked', note: 'right now', icon: 'feather-package', tone: 't-blue', value: num(inv.total), sub: `${num(inv.in_stock)} above their minimum` }),
            kpi({ label: 'Running low', note: 'at or below minimum', icon: 'feather-alert-triangle', tone: 't-amber', value: num(inv.low), sub: inv.total ? `${pct(inv.low / inv.total * 100)} of materials` : '&nbsp;' }),
            kpi({ label: 'Out of stock', note: 'right now', icon: 'feather-alert-octagon', tone: 't-red', value: num(inv.out), sub: inv.out ? 'Reorder these first' : 'Nothing has run out' }),
            kpi({ label: 'Reorder soon', note: `next ${fs ? fs.period : 14} days`, icon: 'feather-shopping-cart', tone: 't-orange', value: soon === null ? '—' : num(soon),
                sub: `<span class="an-fc-tag">Forecast</span><span>${soon === null ? 'needs a forecast run' : mat.source === 'simulated' ? 'from demo data' : 'would reach minimum stock'}</span>` }),
        ].join('');
        const health = errs.inventory ? card('Stock health', '', '<div class="an-state">Stock levels could not be read just now.</div>')
            : card('Stock health', 'right now', inv.total ? chartBox('ivHealth', 'Materials by stock level', 'sm') + legendList([['--an-good', 'Above minimum', inv.in_stock], ['--an-warn', 'Running low', inv.low], ['--an-bad', 'Out of stock', inv.out]])
                : '<div class="an-state">No materials are recorded yet.</div>', { flush: true });
        const restock = errs.inventory ? '' : card('Needs restocking', `${esc(cap(scopeName()))}, compared with each material’s own minimum`,
            alerts.length ? `<div class="table-responsive"><table class="table table-hover mb-0 an-table"><thead><tr><th>Material</th>${allClinics() ? '<th class="an-hide-sm">Clinic</th>' : ''}<th class="text-end">In stock</th><th class="text-end">Minimum</th><th class="text-end">Status</th></tr></thead>
                <tbody>${alerts.map(a => `<tr><td><span class="text-dark fw-semibold">${esc(a.name)}</span>${a.code ? `<div class="fs-11 text-muted">${esc(a.code)}</div>` : ''}</td>${allClinics() ? `<td class="an-hide-sm">${esc(a.branch)}</td>` : ''}
                    <td class="text-end">${num(a.qty)}</td><td class="text-end text-muted">${num(a.min)}</td><td class="text-end">${a.level === 'out' ? '<span class="an-pill down">Out of stock</span>' : '<span class="an-pill warn">Running low</span>'}</td></tr>`).join('')}</tbody></table></div>
                ${inv.low + inv.out > alerts.length ? `<p class="an-note pt-3">Showing the ${alerts.length} most urgent of ${num(inv.low + inv.out)}.</p>` : ''}`
                : '<div class="an-state"><span class="an-ico t-green"><i class="feather-check"></i></span>Every material is above its minimum stock.</div>', { flush: true });

        let use;
        if (!mat) use = fs && (fs.loadState === 'loading' || fs.loadState === 'idle') ? `<div class="card-body">${skChart()}</div>`
            : '<div class="an-state"><span class="an-state-text">Material use is recorded by the forecast job. Nothing is available yet; see the Forecast tab.</span></div>';
        else if (!mat.rows.length) use = '<div class="an-state">No material use was recorded in the last 4 weeks.</div>';
        else {
            const rows = mat.rows.slice(0, 8), max = Math.max(...rows.map(r => r.used));
            use = `<div class="table-responsive"><table class="table table-hover mb-0 an-table"><thead><tr><th>Material</th><th class="text-end">Used, last 4 weeks</th><th class="text-end">Change</th><th class="text-end an-hide-sm">Days of stock left</th><th class="text-end">Outlook</th></tr></thead><tbody>
                ${rows.map(r => `<tr class="an-pick" data-an-material="${r.branch}|${r.item}" title="Open this material’s forecast"><td><span class="text-dark fw-semibold">${esc(r.name)}</span>${allClinics() ? `<div class="fs-11 text-muted">${esc(r.branchName)}</div>` : ''}</td>
                    <td class="text-end"><span class="an-bar-cell"><span class="an-track" aria-hidden="true"><i style="width:${r.used / max * 100}%"></i></span><span class="an-val">${num(r.used, r.used % 1 ? 1 : 0)}</span></span></td>
                    <td class="text-end">${r.ch === null ? '<span class="text-muted">—</span>' : pill(r.used, r.prev, { neutral: true })}</td>
                    <td class="text-end an-hide-sm">${r.days === null ? '—' : r.days > 365 ? '365+' : num(r.days)}</td>
                    <td class="text-end">${r.attention ? '<span class="an-pill down">Reorder soon</span>' : '<span class="an-pill up">OK</span>'}</td></tr>`).join('')}</tbody></table></div>
                <p class="an-note pt-3">Units are each material’s own (pieces, ml, and so on), so compare a material with itself, not with others. Select a row to open its forecast.</p>`;
        }
        const useSub = mat ? `recorded use in the 4 weeks to ${longDate(mat.last)}, against the 4 weeks before${mat.source === 'simulated' ? ' (demo data)' : ''}` : 'last 4 weeks';
        const useCard = card('Material use', useSub, use, { flush: true, action: '<button type="button" class="an-link" data-an-matfc>Open the material forecast <i class="feather-chevron-right"></i></button>' });
        // Inventory enhancement: expiry watch, stock changes (30 days), replenishment pipeline
        const x = d.inventory_extra;
        let extraRow = '';
        if (x && !x.error) {
            const e = x.expiry, c = x.changes, o = x.orders, t = x.transfers;
            const expBody = e.items.length ? `<div class="table-responsive"><table class="table table-hover mb-0 an-table"><thead><tr><th>Material</th><th class="text-end">Expired</th><th class="text-end">Expiring</th><th class="text-end">Earliest</th></tr></thead><tbody>
                ${e.items.map(i => `<tr><td><span class="text-dark fw-semibold">${esc(i.name)}</span>${allClinics() ? `<div class="fs-11 text-muted">${esc(i.branch)}</div>` : ''}</td>
                    <td class="text-end">${i.expired ? `<span class="an-pill down">${num(i.expired)}</span>` : '<span class="text-muted">—</span>'}</td>
                    <td class="text-end">${i.soon ? `<span class="an-pill warn">${num(i.soon)}</span>` : '<span class="text-muted">—</span>'}</td>
                    <td class="text-end">${esc(longDate(i.next))}</td></tr>`).join('')}</tbody></table></div>
                ${e.item_count > e.items.length ? `<p class="an-note pt-3">Showing ${e.items.length} of ${num(e.item_count)} materials.</p>` : ''}`
                : '<div class="an-state"><span class="an-ico t-green"><i class="feather-check"></i></span>Nothing is expired or expiring soon.</div>';
            const expCard = card('Expiry watch', `expired, or expiring within ${e.days} days`, expBody, { flush: true, action: '<a class="an-link" href="inventory-records.php?expiry=soon">Inventory <i class="feather-chevron-right"></i></a>' });
            const line = (label, v, cls) => `<li class="d-flex justify-content-between py-2 border-bottom"><span>${label}</span><strong class="${cls || ''}">${v}</strong></li>`;
            const chgCard = card('Stock changes', 'last 30 days, besides treatment use', `<ul class="list-unstyled mb-0 px-4 pb-3 small">
                ${line('Received from suppliers', '+' + num(c.purchased), 'text-success')}
                ${line('Transferred in / out', `+${num(c.transfer_in)} / −${num(c.transfer_out)}`)}
                ${line('Manual stock in / out', `+${num(c.manual_in)} / −${num(c.manual_out)}`)}
                ${line('Count adjustments (net)', (c.adjusted > 0 ? '+' : c.adjusted < 0 ? '−' : '') + num(Math.abs(c.adjusted)))}
                ${line(`Written off <span class="text-muted">(damaged ${num(c.by_type.damaged)} · expired ${num(c.by_type.expired)} · wastage ${num(c.by_type.wastage)})</span>`, '−' + num(c.written_off), c.written_off ? 'text-danger' : '')}
                </ul>`, { flush: true, action: '<a class="an-link" href="inventory-stock-movement.php">History <i class="feather-chevron-right"></i></a>' });
            const repCard = card('Replenishment', 'orders and transfers in progress', `<ul class="list-unstyled mb-0 px-4 pb-3 small">
                ${line('Purchase orders awaiting delivery', num(o.open))}
                ${line('Units still due from suppliers', num(o.units_due))}
                ${line('Deliveries overdue', num(o.overdue), o.overdue ? 'text-danger' : '')}
                ${line('Transfers waiting for approval', num(t.pending))}
                ${line('Transfers approved / in transit', `${num(t.approved)} / ${num(t.in_transit)}`)}
                </ul>`, { flush: true, action: '<a class="an-link" href="inventory-procurement.php?tab=orders&status=open">Procurement <i class="feather-chevron-right"></i></a>' });
            extraRow = `<div class="row"><div class="col-xxl-5">${expCard}</div><div class="col-xxl-4 col-md-6">${chgCard}</div><div class="col-xxl-3 col-md-6">${repCard}</div></div>`;
        }
        el.innerHTML = `${kpis ? `<div class="row">${kpis}</div>` : ''}<div class="row"><div class="col-xxl-4">${health}</div><div class="col-xxl-8">${restock}</div></div>${extraRow}<div class="row"><div class="col-12">${useCard}</div></div>`;
        if (!errs.inventory && inv.total) donut('ivHealth', [inv.in_stock, inv.low, inv.out], ['Above minimum', 'Running low', 'Out of stock'], ['--an-good', '--an-warn', '--an-bad'], 'Materials');
    }

    // ── Context line ──────────────────────────────────────────
    function renderContext() {
        const el = $('#anContext');
        if (!el) return;
        if (tab === 'forecast') { el.innerHTML = `<i class="feather-trending-up"></i><span>Forecast for <b>${esc(scopeName())}</b></span>`; return; }
        if (scope.state !== 'ok') { el.innerHTML = ''; return; }
        const { i, j, L } = months(scope.data);
        el.innerHTML = `<i class="feather-calendar"></i><span>Comparing <b>${longMonth(L[i])}</b> with <b>${longMonth(L[j])}</b>, the latest full months, for <b>${esc(scopeName())}</b></span>`;
    }

    // ── Tabs ──────────────────────────────────────────────────
    const TABS = ['overview', 'appointments', 'revenue', 'inventory', 'forecast'];
    const PANE = t => t === 'forecast' ? 'forecast' : 'anPane-' + t;
    const TAB_KEY = 'es.analytics.tab.v2';
    let tab = null;
    function renderPane(t) {
        destroyCharts();
        if (t === 'overview') renderOverview();
        else if (t === 'appointments') renderAppointments();
        else if (t === 'revenue') renderRevenue();
        else if (t === 'inventory') renderInventory();
    }
    function renderVisible() {
        renderContext();
        if (tab && tab !== 'forecast') renderPane(tab);
    }
    function showTab(t, focusTab) {
        if (t === 'materials') t = 'inventory';           // old link
        if (!TABS.includes(t)) t = 'overview';
        tab = t;
        TABS.forEach(x => {
            const b = document.getElementById('anTab-' + x), p = document.getElementById(PANE(x));
            b.setAttribute('aria-selected', String(x === t));
            b.tabIndex = x === t ? 0 : -1;
            p.hidden = x !== t;
        });
        if (focusTab) document.getElementById('anTab-' + t).focus();
        try { localStorage.setItem(TAB_KEY, t); } catch (e) { /* storage blocked */ }
        try { history.replaceState(null, '', location.pathname + location.search + '#' + t); } catch (e) { /* ignore */ }
        renderContext();
        if (t === 'forecast') { destroyCharts(); document.getElementById('forecast').dispatchEvent(new Event('fc:shown')); }
        else renderPane(t);
    }
    const tablist = $('.an-tabs');
    tablist.addEventListener('click', e => { const b = e.target.closest('[data-an-tab]'); if (b) showTab(b.dataset.anTab); });
    tablist.addEventListener('keydown', e => {
        const k = TABS.indexOf(tab);
        const go = { ArrowRight: k + 1, ArrowLeft: k - 1, Home: 0, End: TABS.length - 1 }[e.key];
        if (go === undefined) return;
        e.preventDefault();
        showTab(TABS[(go + TABS.length) % TABS.length], true);
    });
    window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (TABS.includes(h) && h !== tab) showTab(h); });

    // ── Events ────────────────────────────────────────────────
    function setBranch(v) {
        const next = v === 'all' ? 'all' : Number(v);
        if (next === branch) return;
        branch = next;
        refresh();
    }
    /** Pick a clinic in the shared header filter (the forecast listens to the same change event). */
    function choose(id) {
        if (!sel) return;
        sel.value = String(id);
        if (window.refreshCustomSelects) window.refreshCustomSelects();
        sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    document.addEventListener('change', e => { if (e.target.id === 'fcBranch') setBranch(e.target.value); });
    document.addEventListener('click', e => {
        const t = e.target.closest('[data-an-retry],[data-an-clinic],[data-an-material],[data-an-matfc],[data-an-ov],[data-an-goto]');
        if (!t) return;
        if (t.hasAttribute('data-an-retry')) { clinics = null; Object.keys(cache).forEach(k => delete cache[k]); refresh(); return; }
        if (t.dataset.anOv) { ovMetric = t.dataset.anOv; renderPane('overview'); return; }
        if (t.dataset.anGoto) { showTab(t.dataset.anGoto); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
        if (t.dataset.anClinic) { choose(Number(t.dataset.anClinic)); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
        if (t.dataset.anMaterial) {
            const [b, item] = t.dataset.anMaterial.split('|').map(Number);
            if (sel && branch !== b) choose(b);
            if (window.ESForecast) window.ESForecast.focus('inventory', item);
            showTab('forecast');
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        if (t.hasAttribute('data-an-matfc')) { if (window.ESForecast) window.ESForecast.focus('inventory'); showTab('forecast'); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });
    // Refresh: reload the clinic figures and the forecast
    const refreshBtn = document.getElementById('anRefresh');
    if (refreshBtn) refreshBtn.addEventListener('click', () => {
        Object.keys(cache).forEach(k => delete cache[k]);
        clinics = null;
        refreshBtn.disabled = true; refreshBtn.classList.add('an-spin');
        if (window.ESForecast) window.ESForecast.reload();
        refresh().finally(() => { refreshBtn.disabled = false; refreshBtn.classList.remove('an-spin'); if (window.esToast) window.esToast('Figures refreshed.', 'success'); });
    });
    // The forecast loaded or changed: update the parts that quote it
    document.addEventListener('fc:data', () => {
        const fs = window.ESForecast?.state();
        const fb = window.ESForecast?.brief();
        // The forecast may have corrected a remembered clinic that no longer exists
        if (sel && fb?.hasRun && fs && String(fs.branch) !== String(branch) && [...sel.options].some(o => o.value === String(fs.branch))) { setBranch(fs.branch); return; }
        if (scope.state === 'ok' && (tab === 'overview' || tab === 'inventory')) renderPane(tab);
    });
    // Theme switch: chart colours come from CSS variables
    new MutationObserver(() => { if (tab && tab !== 'forecast' && scope.state === 'ok') renderPane(tab); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    let lastNarrow = window.innerWidth < 576;
    window.addEventListener('resize', () => {
        const narrow = window.innerWidth < 576;
        if (narrow !== lastNarrow && tab && tab !== 'forecast' && scope.state === 'ok') { lastNarrow = narrow; renderPane(tab); }
    });

    // ── Start ─────────────────────────────────────────────────
    let first = location.hash.slice(1);
    if (first === 'materials') first = 'inventory';
    if (!TABS.includes(first)) { try { first = localStorage.getItem(TAB_KEY); } catch (e) { first = null; } }
    refresh();
    const start = () => showTab(TABS.includes(first) ? first : 'overview');
    // After custom-select has wrapped the header / toolbar selects (it runs on DOMContentLoaded)
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => setTimeout(start, 0)); else start();
})();
