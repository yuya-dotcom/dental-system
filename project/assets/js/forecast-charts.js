/* =============================================================
   EssenciaSmile — Analytics › Forecast tab (analytics.php)

   Layout (theme cards, same type scale as dashboard.php):
     KPI row    expected total · daily average · busiest day · trend
     row        chart (recorded = solid blue, forecast = dashed orange,
                likely range = shaded) | "What this means" (headline + notes)
     stock      stock outlook (materials, one clinic)
     clinics    forecast per clinic (owner, all clinics)
     details    "How this forecast was made": method, accuracy in plain
                words, history per clinic, every model's test results

   Data:   api/forecast_data.php (controllers/forecast_controller.php), already
           limited to the branches this user may see. Loaded right after the
           page paints (Overview quotes it) and kept per data source.
   Charts: ApexCharts (self-hosted on this page).
   Shared: window.ESForecast — read-only summaries for the other tabs
           (assets/js/analytics-history.js); 'fc:data' is dispatched on
           document after every render.

   Everything shown is computed from stored run results: recorded actuals,
   the SELECTED model's forecast and its 95% interval, the rolling-origin
   evaluation metrics and the inventory reorder rows. When a value can't
   be computed the page says why instead of estimating it.
   ============================================================= */
(function () {
    'use strict';

    const pane = document.getElementById('forecast');
    const app = document.getElementById('fcApp');
    if (!pane || !app) return;
    const $ = (s, r = document) => r.querySelector(s);

    // ── Vocabulary ────────────────────────────────────────────
    const MODELS = {
        seasonal_naive: { name: 'Seasonal Naive', cat: 'Baseline: repeats last week' },
        mlr: { name: 'Multiple Linear Regression', cat: 'Weekday pattern + trend' },
        arima: { name: 'ARIMA', cat: 'Time series' },
        sarima: { name: 'SARIMA (Weekly)', cat: 'Time series with a weekly cycle' },
        pipeline: { name: 'Data processing', cat: 'Pipeline' },
    };
    const MODEL_ORDER = ['seasonal_naive', 'mlr', 'arima', 'sarima', 'pipeline'];
    const STAT_MODELS = ['mlr', 'arima', 'sarima'];
    const CATS = {
        appointments: { label: 'Appointments', title: 'Visits: recorded and expected', what: 'completed or checked-in visits', unit: 'visit' },
        sales: { label: 'Revenue', title: 'Payments: recorded and expected', what: 'payments received', unit: null },
        inventory: { label: 'Materials', title: 'Material use: recorded and expected', what: 'units used in treatment', unit: 'unit' },
    };
    const HIST_DAYS = 56;                    // daily chart: last 8 weeks of history
    const UI_KEY = 'es.forecast.ui.v1';

    // ── Formatting ────────────────────────────────────────────
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const isNum = v => v !== null && v !== undefined && v !== '' && isFinite(v);
    const num = (n, d = 0) => isNum(n) ? Number(n).toLocaleString('en-PH', { maximumFractionDigits: d, minimumFractionDigits: d }) : '—';
    const peso = (n, d = 0) => isNum(n) ? '₱' + num(n, d) : '—';
    const pct = (n, d = 0) => isNum(n) ? num(n, d) + '%' : '—';
    const compact = n => {
        const a = Math.abs(n);
        if (a >= 1e6) return (n / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'M';
        if (a >= 1e3) return (n / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k';
        return String(Math.round(n * 10) / 10);
    };
    const utc = iso => { const [y, m, d] = iso.split('-').map(Number); return Date.UTC(y, m - 1, d); };
    const iso = ms => new Date(ms).toISOString().slice(0, 10);
    const addDays = (d, n) => iso(utc(d) + n * 864e5);
    const daysBetween = (a, b) => Math.round((utc(b) - utc(a)) / 864e5);
    const fmtD = (d, o) => new Date(utc(d)).toLocaleDateString('en-PH', { ...o, timeZone: 'UTC' });
    const dayLabel = d => fmtD(d, { weekday: 'short', month: 'short', day: 'numeric' });
    const shortDate = d => fmtD(d, { month: 'short', day: 'numeric' });
    const longDate = d => fmtD(d, { month: 'short', day: 'numeric', year: 'numeric' });
    const weekday = d => fmtD(d, { weekday: 'long' });
    const stamp = ts => ts ? new Date(ts).toLocaleString('en-PH', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
    const plural = (n, one, many) => `${num(n)} ${Number(n) === 1 ? one : (many || one + 's')}`;
    const keyOf = (b, m, i) => `${b}|${m}|${i ?? ''}`;
    const valFmt = (metric, v, d) => metric === 'sales' ? peso(v, d ?? 0) : num(v, d ?? (metric === 'inventory' ? 1 : 0));
    const change = (now, before) => before > 0 ? (now - before) / before * 100 : null;
    const isDark = () => document.documentElement.classList.contains('app-skin-dark');
    const FLAT = 5;                          // |change| below 5% reads as "steady"
    const andList = a => a.length < 2 ? (a[0] || '') : a.slice(0, -1).join(', ') + ' and ' + a[a.length - 1];

    // ── State ─────────────────────────────────────────────────
    let source = window.ES_FORECAST_SOURCE === 'simulated' ? 'simulated' : 'live';
    let D = null;                          // payload of the current source
    let loadState = 'idle';                // idle | loading | ok | error
    let loading = null;                    // in-flight request (shared by section-open + prefetch)
    const cache = {};                      // source -> payload (this page view only)
    let I = null;
    const charts = [], observers = [];
    const ui = (() => { try { return JSON.parse(localStorage.getItem(UI_KEY) || '{}') || {}; } catch (e) { return {}; } })();
    const st = {
        branch: ui.branch ?? 'all', metric: CATS[ui.metric] ? ui.metric : 'appointments',
        period: Number(ui.period) || 14, view: ui.view === 'weekly' ? 'weekly' : 'daily', item: null, showAllStock: false,
    };
    const saveUi = () => { try { localStorage.setItem(UI_KEY, JSON.stringify({ branch: st.branch, metric: st.metric, period: st.period, view: st.view })); } catch (e) { /* storage blocked */ } };

    function index(data) {
        const values = {}, metrics = {};
        (data.values || []).forEach(r => { (values[keyOf(r.branch_id, r.metric, r.item_id)] ||= []).push(r); });
        (data.metrics || []).forEach(r => { (metrics[keyOf(r.branch_id, r.metric, r.item_id)] ||= []).push(r); });
        const branchIds = Object.keys(data.branches || {}).map(Number).sort((a, b) => a - b);
        const H = data.run?.horizon_days || 14;
        return {
            values, metrics, branchIds, H, last: data.run?.last_observed_date || null,
            inventory: data.inventory || [], names: data.branches || {},
            multi: !!data.isOwner && branchIds.length > 1,
            periods: [...new Set([7, H].filter(p => p <= H))].sort((a, b) => a - b),
            mins: data.modelMinObs || { seasonal_naive: 14, mlr: data.minObs, arima: data.minObs, sarima: data.minObs },
        };
    }
    function normaliseState() {
        if (st.branch !== 'all') st.branch = Number(st.branch);
        if (st.branch === 'all' && !I.multi) st.branch = I.branchIds[0];
        if (st.branch !== 'all' && !I.branchIds.includes(st.branch)) st.branch = I.multi ? 'all' : I.branchIds[0];
        if (!I.periods.includes(st.period)) st.period = I.H;
        if (st.metric === 'inventory' && st.branch !== 'all') {
            const items = itemsOf(st.branch);
            if (!items.some(i => i.id === st.item)) st.item = (items.find(i => i.attention) || items[0])?.id ?? null;
        }
    }
    const branchName = b => I.names[b] || `Clinic ${b}`;
    const scopeIds = () => st.branch === 'all' ? I.branchIds : [st.branch];
    const scopeLabel = () => st.branch === 'all' ? 'All clinics' : esc(branchName(st.branch));
    const statMin = () => Math.max(...STAT_MODELS.map(m => I.mins[m] || D.minObs));
    const baseMin = () => I.mins.seasonal_naive || 14;
    const periodEnd = () => addDays(I.last, st.period);

    // ── Series ────────────────────────────────────────────────
    function single(b, metric, item) {
        const k = keyOf(b, metric, metric === 'inventory' ? item : null);
        const rows = I.values[k] || [];
        const mrows = I.metrics[k] || [];
        const sel = mrows.find(r => r.is_selected) || null;
        const actual = rows.filter(r => r.kind === 'actual')
            .map(r => ({ d: r.value_date, y: r.is_open && isNum(r.yhat) ? Number(r.yhat) : null, open: !!r.is_open }));
        const forecast = sel ? rows.filter(r => r.kind === 'forecast').map(r => ({
            d: r.value_date, open: !!r.is_open,
            y: isNum(r.yhat) ? Math.max(0, Number(r.yhat)) : null,
            lo: isNum(r.yhat_lower) ? Math.max(0, Number(r.yhat_lower)) : null,
            hi: isNum(r.yhat_upper) ? Number(r.yhat_upper) : null,
        })) : [];
        const nObs = mrows.length ? Math.max(0, ...mrows.map(r => r.n_obs || 0)) : 0;
        return { key: k, b, metric, item, actual, forecast, mrows, sel, nObs, band: forecast.some(f => f.lo !== null && f.hi !== null) };
    }
    /** Owner "All clinics": per-date sums over the branches that HAVE a forecast (like for like). */
    function combined(metric) {
        const parts = I.branchIds.map(b => single(b, metric, null));
        const withModel = parts.filter(p => p.sel);
        const use = withModel.length ? withModel : parts;
        const sum = (list, field) => {
            const m = new Map();
            list.forEach(p => p[field].forEach(r => {
                const cur = m.get(r.d) || { d: r.d, y: null, open: false, lo: null, hi: null };
                if (r.y !== null) cur.y = (cur.y || 0) + r.y;
                cur.open = cur.open || r.open;
                m.set(r.d, cur);
            }));
            return [...m.values()].sort((a, b) => a.d < b.d ? -1 : 1);
        };
        return {
            combined: true, metric, parts, missing: parts.filter(p => !p.sel).map(p => p.b),
            actual: sum(use, 'actual'), forecast: withModel.length ? sum(withModel, 'forecast') : [],
            sel: withModel.length ? { model: 'combined' } : null, band: false,
            nObs: parts.reduce((t, p) => t + p.nObs, 0), mrows: [],
        };
    }
    const current = () => st.metric === 'inventory'
        ? (st.branch === 'all' || st.item === null ? null : single(st.branch, 'inventory', st.item))
        : st.branch === 'all' ? combined(st.metric) : single(st.branch, st.metric, null);
    const inPeriod = s => s.forecast.filter(f => f.d <= periodEnd());
    const prevPeriod = s => s.actual.filter(a => a.d > addDays(I.last, -st.period));
    const total = rows => rows.reduce((t, r) => t + (r.y ?? 0), 0);
    const openFc = rows => rows.filter(r => r.open && r.y !== null);
    const isEarly = s => s.combined ? s.parts.some(p => p.sel && p.nObs < statMin()) : s.nObs < statMin();

    // ── Inventory helpers ─────────────────────────────────────
    function itemsOf(b) {
        const inv = {};
        I.inventory.filter(r => r.branch_id === b).forEach(r => { inv[r.item_id] = r; });
        return Object.keys(I.metrics).filter(k => k.startsWith(`${b}|inventory|`))
            .map(k => Number(k.split('|')[2]))
            .map(id => ({ id, name: inv[id]?.item_name || `Item #${id}`, attention: inv[id] ? needsAttention(inv[id]) : false }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }
    /** Future open/closed calendar of a branch, from any stored forecast of that branch. */
    function futureOpen(b) {
        for (const k of Object.keys(I.values)) {
            if (!k.startsWith(b + '|')) continue;
            const f = I.values[k].filter(r => r.kind === 'forecast');
            if (f.length) return f.map(r => ({ d: r.value_date, open: !!r.is_open }));
        }
        return null;
    }
    /** Forecast use of a stock row over the chosen period. */
    function invUse(row) {
        const s = single(row.branch_id, 'inventory', row.item_id);
        if (row.basis === 'model' && s.sel && s.forecast.length) return total(inPeriod(s));
        if (st.period >= I.H) return isNum(row.forecast_use) ? Number(row.forecast_use) : null;
        // 'average' basis = recent average × open days, so it scales exactly with open days
        const cal = futureOpen(row.branch_id);
        if (!cal || !isNum(row.forecast_use)) return null;
        const all = cal.filter(c => c.open).length, part = cal.filter(c => c.open && c.d <= periodEnd()).length;
        return all ? Number(row.forecast_use) * part / all : 0;
    }
    function needsAttention(row) {
        const use = invUse(row);
        if (use === null) return !!row.needs_reorder;
        return Number(row.stock_on_hand || 0) - use <= Number(row.min_stock || 0);
    }
    const scopedStock = () => I.inventory.filter(r => scopeIds().includes(r.branch_id))
        .map(r => ({ ...r, use: invUse(r), attention: needsAttention(r) }));

    // ── Building blocks ───────────────────────────────────────
    /** KPI card (dashboard card). fc = mark it as a forecast figure. */
    const fig = (label, value, sub, icon = 'feather-trending-up', tone = 't-orange', fc = true) => `<div class="col-xxl-3 col-md-6"><div class="card stretch stretch-full an-kpi"><div class="card-body">
        <div class="an-kpi-top"><div class="an-kpi-label">${label}${fc ? '<div class="mt-1"><span class="an-fc-tag">Forecast</span></div>' : ''}</div><span class="an-ico ${tone}"><i class="${icon}"></i></span></div>
        <div class="an-kpi-value">${value}</div><div class="an-kpi-sub">${sub || '&nbsp;'}</div></div></div></div>`;
    /**
     * A card. opts: action (header controls), after (below the body), flush (no body padding:
     * tables and lists), bare (a plain sub-section inside "How this forecast was made").
     */
    function card(title, sub, body, opts = {}) {
        if (opts.bare) {
            return `<section class="an-sec${opts.cls ? ' ' + opts.cls : ''}"><h3 class="an-h3">${title}${sub ? ` <span class="an-sub d-inline ms-1">${sub}</span>` : ''}</h3>
                ${opts.action ? `<div class="an-pad mb-2">${opts.action}</div>` : ''}<div class="${opts.flush ? '' : 'an-pad'}">${body}</div>${opts.after || ''}</section>`;
        }
        return `<div class="card stretch stretch-full${opts.cls ? ' ' + opts.cls : ''}"><div class="card-header">
                <h5 class="card-title">${title}${sub ? ` <small>${sub}</small>` : ''}</h5>
                ${opts.action ? `<div class="card-header-action">${opts.action}</div>` : ''}</div>
            <div class="card-body${opts.flush ? ' p-0' : ''}">${body}</div>${opts.after || ''}</div>`;
    }
    const kv = (k, v, cls) => `<div class="dash-kv"><span class="text-muted">${k}</span><span class="v${cls ? ' ' + cls : ''}">${v}</span></div>`;
    const badge = (tone, text) => `<span class="badge bg-soft-${tone} text-${tone}">${text}</span>`;
    const empty = (text, h) => `<div class="an-chart" style="min-height:${h || 280}px"><div class="an-chart-state"><i class="feather-bar-chart-2"></i>${text}</div></div>`;
    /** Change vs the previous period. neutral = no good/bad colour (material use). */
    const arrow = (ch, neutral) => {
        if (ch === null) return '';
        const flat = Math.abs(ch) < FLAT;
        const pill = flat || neutral ? '' : ch > 0 ? 'up' : 'down';
        return `<span class="an-pill ${pill}">${flat ? 'About the same' : `<i class="feather-arrow-${ch > 0 ? 'up' : 'down'}"></i>${num(Math.abs(ch))}%`}</span>`;
    };
    /** Figure sub-line: "+8% vs the previous 14 days" / "About the same as the previous 14 days". */
    const vsLine = (ch, label, neutral) => ch === null ? '' : Math.abs(ch) < FLAT
        ? `<span class="an-pill">About the same</span> as ${label}` : `${arrow(ch, neutral)} vs ${label}`;
    /** Rising / Steady / Falling, from the same ±5% rule as everywhere on this page. */
    function trendPill(ch, neutral) {
        if (ch === null) return '';
        const flat = Math.abs(ch) < FLAT;
        const word = flat ? 'Steady' : ch > 0 ? 'Rising' : 'Falling';
        const cls = flat ? '' : neutral ? 'info' : ch > 0 ? 'up' : 'down';
        return ` <span class="an-pill ${cls}" title="Compared with the previous ${st.period} days; changes under ${FLAT}% count as steady">${word}</span>`;
    }

    // ── Toolbar + notices ─────────────────────────────────────
    function statusBadge() {
        if (D.setup) return badge('secondary', 'Not set up');
        if (D.running || runState.active) return badge('primary', 'Running');
        if (D.lastFailed) return badge('danger', 'Last run failed');
        if (!D.run) return badge('secondary', 'No forecast yet');
        const age = (Date.parse(D.serverTime || new Date().toISOString()) - Date.parse(D.run.finished_at)) / 36e5;
        return age > 48 ? badge('warning', 'Outdated') : badge('success', 'Up to date');
    }
    function syncHeader() {
        const set = (id, v) => { const el = document.getElementById(id); if (el && el.value !== String(v)) el.value = String(v); };
        set('fcBranch', st.branch);
        set('fcMetric', st.metric);
        const per = document.getElementById('fcPeriod');
        if (per && I) {
            const want = I.periods.map(String).join(',');
            if ([...per.options].map(o => o.value).join(',') !== want) per.innerHTML = I.periods.map(p => `<option value="${p}">Next ${p} days</option>`).join('');
            per.value = String(st.period);
        }
        if (window.refreshCustomSelects) window.refreshCustomSelects();
        if (runBtn) runBtn.disabled = runState.active || !!D?.setup;
        pane.querySelectorAll('.an-seg [data-src]').forEach(b => {
            b.setAttribute('aria-pressed', String(b.dataset.src === source));
            b.disabled = runState.active;
        });
        const status = $('#fcStatus');
        if (status) status.innerHTML = D ? `${statusBadge()}${D.run ? `<span>Generated ${esc(stamp(D.run.finished_at))}</span>` : ''}` : '';
    }
    function renderNotice() {
        const n = [];
        if (D?.source === 'simulated') n.push(`<div class="alert alert-warning py-2 fs-13 mb-3" role="note"><i class="feather-alert-circle me-1"></i>
            Demo data. These results use generated (simulated) clinic history to show how forecasting works, not real EssenciaSmile records.</div>`);
        if (D?.lastFailed) n.push(`<div class="alert alert-danger py-2 fs-13 mb-3" role="alert"><i class="feather-alert-triangle me-1"></i>
            The latest run (${esc(stamp(D.lastFailed.started_at))}) failed: ${esc(String(D.lastFailed.error).slice(0, 200))}${D.run ? ' The last successful forecast is shown.' : ''}</div>`);
        $('#fcNotice').innerHTML = n.join('');
    }

    // ── Headline + figures ────────────────────────────────────
    function coverage(s) {
        const ids = s.combined ? I.branchIds : [s.b];
        const open = ids.reduce((t, b) => t + (D.run.open_days?.[b] || 0), 0);
        const obs = s.combined ? s.parts.reduce((t, p) => t + p.nObs, 0) : s.nObs;
        return { open, obs, ratio: open ? Math.min(100, obs / open * 100) : null };
    }
    function headline(s) {
        const m = st.metric, P = st.period;
        if (m === 'inventory') {
            const rows = scopedStock();
            if (!rows.length) return '<span class="an-quiet">No material has enough recorded use yet to project its stock.</span>';
            const att = rows.filter(r => r.attention);
            const where = r => st.branch === 'all' ? ` at ${esc(branchName(r.branch_id))}` : '';
            if (!att.length) return `None of the ${plural(rows.length, 'tracked material')} should reach its minimum stock in the next ${P} days.`;
            const names = att.slice(0, 2).map(r => esc(r.item_name) + where(r)).join(' and ');
            const more = att.length > 2 ? `, and ${plural(att.length - 2, 'other material')},` : '';
            return `${names}${more} ${att.length === 1 ? 'is' : 'are'} expected to reach minimum stock within the next ${P} days, so a reorder is worth planning.`;
        }
        if (!s.sel || !s.forecast.length) {
            return `There is no forecast yet. ${s.combined ? readinessAll(m) : readinessSentence(s)}`;
        }
        const T = total(inPeriod(s)), prev = total(prevPeriod(s)), ch = change(T, prev);
        const what = m === 'appointments' ? `about ${plural(Math.round(T), 'visit')}` : `about ${peso(T)} in payments`;
        const where = s.combined ? 'across the clinics' : `at ${esc(branchName(s.b))}`;
        let rel = '';
        if (ch === null) rel = prev === 0 ? `; nothing was recorded in the previous ${P} days` : '';
        else if (Math.abs(ch) < FLAT) rel = `, about the same as the previous ${P} days (${valFmt(m, prev)})`;
        else rel = `, ${num(Math.abs(ch))}% ${ch > 0 ? 'more' : (m === 'appointments' ? 'fewer' : 'less')} than the previous ${P} days (${valFmt(m, prev)})`;
        let out = `Expect ${what} ${where} over the next ${P} days${rel}.${trendPill(ch)}`;
        if (isEarly(s)) out += ` <span class="an-quiet">An early estimate from only a few weeks of records.</span>`;
        if (s.combined && s.missing.length) out += ` <span class="an-quiet">Not counting ${esc(andList(s.missing.map(branchName)))}, which ${s.missing.length === 1 ? 'has' : 'have'} no forecast yet.</span>`;
        return out;
    }
    function statCards(s) {
        const m = st.metric, P = st.period;
        if (m === 'inventory') return inventoryCards(s);
        const cov = coverage(s);
        const covFig = fig('Days with records', `${num(cov.obs)}<small>of ${num(statMin())} needed</small>`,
            s.combined ? `${s.parts.filter(p => p.nObs >= statMin()).length} of ${I.branchIds.length} clinics are ready` : `${num(cov.open)} open days in the history`,
            'feather-database', 't-slate', false);
        const lbl = m === 'appointments' ? 'Expected visits' : 'Expected payments';
        const icon = m === 'appointments' ? 'feather-calendar' : 'feather-credit-card';
        const peakLbl = st.view === 'weekly' ? 'Busiest week' : 'Busiest day';
        if (!s.sel || !s.forecast.length) {
            const na = (label, ic) => fig(label, '<span class="text-muted">—</span>', 'Needs more recorded days', ic, 't-slate');
            return na(lbl, icon) + na('Daily average', 'feather-activity') + na(peakLbl, 'feather-sun') + covFig;
        }
        const fc = inPeriod(s), open = openFc(fc), T = total(fc), prev = total(prevPeriod(s)), ch = change(T, prev);
        const avg = open.length ? T / open.length : null;
        let peakMain = '—', peakVal = null;
        if (st.view === 'weekly') {
            const wk = weeks(s).filter(w => w.f > 0).sort((a, b) => (b.a + b.f) - (a.a + a.f))[0];
            if (wk) { peakMain = 'Week of ' + esc(shortDate(wk.start)); peakVal = wk.a + wk.f; }
        } else {
            const pk = open.reduce((a, r) => (!a || r.y > a.y ? r : a), null);
            if (pk) { peakMain = esc(dayLabel(pk.d)); peakVal = pk.y; }
        }
        const flat = ch !== null && Math.abs(ch) < FLAT;
        const trend = ch === null ? fig('Trend', '<span class="text-muted">—</span>', `nothing recorded in the previous ${P} days`, 'feather-minus', 't-slate')
            : fig('Trend', flat ? 'Steady' : ch > 0 ? 'Rising' : 'Falling', `${arrow(ch)} vs the previous ${P} days (${valFmt(m, prev)})`,
                flat ? 'feather-minus' : ch > 0 ? 'feather-trending-up' : 'feather-trending-down', flat ? 't-slate' : ch > 0 ? 't-green' : 't-red');
        return fig(lbl, m === 'sales' ? peso(T) : num(T), `over the next ${P} days`, icon, 't-orange')
            + fig('Daily average', m === 'sales' ? peso(avg) : num(avg, 1), `on ${plural(open.length, 'open day')} of the next ${P}`, 'feather-activity', 't-orange')
            + fig(peakLbl, peakMain, peakVal === null ? '' : `about ${valFmt(m, peakVal)}`, 'feather-sun', 't-orange')
            + trend;
    }
    function inventoryCards(s) {
        const rows = scopedStock(), P = st.period;
        if (!rows.length) {
            const na = (label, ic) => fig(label, '<span class="text-muted">—</span>', 'No recorded material use yet', ic, 't-slate');
            return na('Expected use', 'feather-package') + na('Reorder soon', 'feather-alert-triangle') + na('Used up fastest', 'feather-zap') + na('Projected by a model', 'feather-layers');
        }
        let first;
        const row = s ? rows.find(r => r.item_id === st.item) : null;
        if (s && s.sel) {
            const T = total(inPeriod(s)), prev = total(prevPeriod(s)), ch = change(T, prev);
            first = fig(`Expected use<small>${esc(row?.item_name || 'this material')}</small>`, `${num(T, 1)}<small>units</small>`, ch === null ? '' : vsLine(ch, `the previous ${P} days`, true), 'feather-package', 't-orange');
        } else if (row && row.use !== null) {
            first = fig(`Expected use<small>${esc(row.item_name)}</small>`, `${num(row.use, 1)}<small>units</small>`, 'From the recent 4-week average', 'feather-package', 't-orange');
        } else {
            first = fig('Materials projected', num(rows.length), `over the next ${P} days`, 'feather-package', 't-orange');
        }
        const att = rows.filter(r => r.attention);
        const top = rows.map(r => ({ r, ratio: Number(r.stock_on_hand) > 0 ? (r.use ?? 0) / Number(r.stock_on_hand) : (r.use > 0 ? Infinity : 0) }))
            .filter(x => x.r.use !== null && x.r.use > 0).sort((a, b) => b.ratio - a.ratio)[0];
        const modelled = rows.filter(r => r.basis === 'model').length;
        return first
            + fig('Reorder soon', num(att.length), `of ${plural(rows.length, 'material')}, within ${P} days`, 'feather-alert-triangle', att.length ? 't-red' : 't-green')
            + fig('Used up fastest', top ? esc(top.r.item_name) : '—',
                top ? `${top.ratio === Infinity ? 'none in stock' : pct(Math.min(999, top.ratio * 100)) + ' of its stock'} in ${P} days${st.branch === 'all' ? ', ' + esc(branchName(top.r.branch_id)) : ''}` : '', 'feather-zap', 't-amber')
            + fig('Projected by a model', pct(modelled / rows.length * 100), `${modelled} of ${rows.length}; the rest use a 4-week average`, 'feather-layers', 't-slate', false);
    }

    // ── Weekly aggregation (Mon–Sun) ──────────────────────────
    function weeks(s) {
        const map = new Map();
        const monday = d => addDays(d, -((new Date(utc(d)).getUTCDay() + 6) % 7));
        const add = (r, f) => {
            const w = monday(r.d);
            const cur = map.get(w) || { start: w, a: 0, f: 0, days: 0 };
            if (r.y !== null) cur[f] += r.y;
            cur.days += 1;
            map.set(w, cur);
        };
        s.actual.forEach(r => add(r, 'a'));
        inPeriod(s).forEach(r => add(r, 'f'));
        const list = [...map.values()].sort((a, b) => a.start < b.start ? -1 : 1);
        if (list.length && list[0].days < 7) list.shift();      // partial first week of history
        return list;
    }

    // ── Main chart panel (chart + "What this means") ──────────
    function mainCard(s) {
        const m = st.metric;
        if (m === 'inventory' && st.branch === 'all') return stockCard('Stock outlook');
        let action = '';
        if (m === 'inventory') {
            const items = itemsOf(st.branch);
            if (!items.length) return card(CATS[m].title, scopeLabel(), empty('No material use recorded for this clinic yet.'));
            action = `<select id="fcItem" class="js-custom-select dash-branch" data-cs-filter aria-label="Material">${items.map(i =>
                `<option value="${i.id}" ${i.id === st.item ? 'selected' : ''}>${esc(i.name)}</option>`).join('')}</select>`;
        }
        const bars = m === 'inventory' || st.view === 'weekly';
        const legend = `<div class="dash-legend">
            <span><i class="${bars ? '' : 'k-line'}" style="${bars ? 'background:var(--dash-c1)' : ''}"></i>Recorded</span>
            <span><i class="${bars ? '' : 'k-dash'}" style="${bars ? 'background:var(--dash-c3)' : ''}"></i>Forecast</span>
            ${!bars && s.band ? '<span><i class="k-band"></i>Likely range</span>' : ''}</div>`;
        const toggle = m !== 'inventory' ? `<div class="an-toggle" role="group" aria-label="Show by">
            <button type="button" data-fc-view="daily" aria-pressed="${st.view === 'daily'}">Daily</button>
            <button type="button" data-fc-view="weekly" aria-pressed="${st.view === 'weekly'}">Weekly</button></div>` : '';
        const sub = `${scopeLabel()}, ${st.view === 'weekly' && m !== 'inventory' ? 'weekly totals' : 'last 8 weeks'} and the next ${st.period} days`;
        if (!s.actual.some(a => a.y !== null)) return card(CATS[m].title, sub, empty(`No recorded ${CATS[m].what} in this run’s history yet.`), { action: action + toggle });

        const notes = [];
        if (!s.sel) notes.push(`No forecast yet: ${s.combined ? 'no clinic has enough recorded history.' : `${num(s.nObs)} of ${statMin()} days with records so far.`} Only recorded history is shown.`);
        else if (isEarly(s)) notes.push(`Early estimate: the full models start at ${statMin()} days with records, so for now each weekday repeats its latest value.`);
        if (s.combined && s.sel && s.missing.length) notes.push(`Excludes ${esc(andList(s.missing.map(branchName)))} (no forecast yet). Likely ranges are not added across clinics.`);
        if (!bars && s.band) notes.push('The shaded likely range is where the actual value should fall 95 times out of 100.');
        const after = `${notes.length ? `<p class="an-note">${notes.join(' ')}</p>` : ''}
            <details class="dash-table"><summary>Show as table</summary><div class="table-responsive px-3 pb-3">${forecastTable(s)}</div></details>`;
        return card(CATS[m].title, sub, `<div class="an-chart" id="fcMainChart" role="img" aria-label="${CATS[m].title}"></div>`,
            { action: legend + action + toggle, after });
    }
    function forecastTable(s) {
        const m = st.metric, fc = inPeriod(s);
        if (!fc.length) return '<div class="text-muted fs-12 py-2">No forecast days to list.</div>';
        return `<table class="table table-sm mb-0"><thead><tr><th>Date</th><th class="text-end">Forecast</th>${s.band ? '<th class="text-end">Likely range (95%)</th>' : ''}</tr></thead><tbody>
            ${fc.map(f => `<tr><td>${esc(dayLabel(f.d))}</td><td class="text-end">${!f.open || f.y === null ? '<span class="text-muted">Closed</span>' : valFmt(m, f.y, m === 'sales' ? 0 : 1)}</td>
            ${s.band ? `<td class="text-end">${f.open && f.lo !== null ? `${valFmt(m, f.lo)} – ${valFmt(m, f.hi)}` : '—'}</td>` : ''}</tr>`).join('')}</tbody></table>`;
    }

    // ── Charts ────────────────────────────────────────────────
    const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
    function baseOpts(type) {
        const ink = css('--an-muted') || '#647080';
        return {
            chart: { type, height: window.innerWidth < 576 ? 250 : 300, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit', animations: { enabled: false }, parentHeightOffset: 0 },
            grid: { borderColor: css('--an-line-soft') || '#eef1f3', strokeDashArray: 0, padding: { left: 6, right: 10 } },
            dataLabels: { enabled: false }, legend: { show: false },
            xaxis: { labels: { style: { colors: ink, fontSize: '12px' } }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false }, crosshairs: type === 'bar' ? { show: false } : {} },
            yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: ink, fontSize: '12px' } } },
            states: { active: { filter: { type: 'none' } } },
            tooltip: { theme: 'dark', followCursor: type === 'bar' },
        };
    }
    const dateAxis = o => Object.assign(o.xaxis, { type: 'datetime', tickAmount: window.innerWidth < 768 ? 4 : 8,
        labels: { ...o.xaxis.labels, datetimeUTC: true, rotate: 0, hideOverlappingLabels: true, formatter: (v, ts) => shortDate(iso(ts ?? v)) } });
    const yFmt = m => v => v === null || v === undefined ? '' : m === 'sales' ? '₱' + compact(v)
        : m === 'inventory' ? compact(v) : (Number.isInteger(Math.round(v * 10) / 10) ? String(Math.round(v)) : (Math.round(v * 10) / 10).toFixed(1));
    function tooltipFor(s) {
        const a = new Map(s.actual.map(r => [r.d, r])), f = new Map(s.forecast.map(r => [r.d, r])), m = s.metric;
        return ({ seriesIndex, dataPointIndex, w }) => {
            const x = w.globals.seriesX[seriesIndex]?.[dataPointIndex];
            if (x === undefined) return '';
            const d = iso(x), ar = a.get(d), fr = d > I.last ? f.get(d) : null, rows = [];
            if (ar) rows.push(ar.y === null ? `<div class="m">${ar.open ? 'Not recorded' : 'Closed'}</div>` : `<div class="r"><span>Recorded</span><b>${valFmt(m, ar.y)}</b></div>`);
            if (fr) {
                if (!fr.open || fr.y === null) rows.push('<div class="m">Closed, no forecast</div>');
                else {
                    rows.push(`<div class="r"><span>Forecast</span><b>${valFmt(m, fr.y, m === 'sales' ? 0 : 1)}</b></div>`);
                    if (fr.lo !== null && fr.hi !== null && !s.combined) rows.push(`<div class="r m"><span>Likely range</span><span>${valFmt(m, fr.lo)} – ${valFmt(m, fr.hi)}</span></div>`);
                }
            }
            return `<div class="an-tip"><div class="mb-1"><b>${esc(dayLabel(d))}</b></div>${rows.join('') || '<div class="m">No data</div>'}</div>`;
        };
    }
    function drawMain(s) {
        const el = document.getElementById('fcMainChart');
        if (!el || !s) return;
        const m = s.metric, c1 = css('--dash-c1'), c3 = css('--dash-c3'), ink = css('--an-muted');
        const end = periodEnd(), from = addDays(I.last, -(HIST_DAYS - 1));
        // A dashed divider where the forecast starts (a shaded window would hide the likely range)
        const zone = { x: utc(I.last) + 432e5, borderColor: css('--an-other') || '#c3cad4', strokeDashArray: 4,
            label: { text: 'Forecast starts', borderColor: 'transparent', position: 'top', orientation: 'horizontal', textAnchor: 'start', offsetX: 4, offsetY: -2,
                style: { background: 'transparent', color: ink, fontSize: '11px' } } };

        if (m !== 'inventory' && st.view === 'weekly') {
            const wk = weeks(s), o = baseOpts('bar');
            Object.assign(o, {
                series: [{ name: 'Recorded', data: wk.map(w => Math.round(w.a * 100) / 100) }, { name: 'Forecast', data: wk.map(w => Math.round(w.f * 100) / 100) }],
                colors: [c1, c3], plotOptions: { bar: { columnWidth: '52%', borderRadius: 3 } }, stroke: { show: true, width: 2, colors: ['transparent'] },
                tooltip: { shared: true, intersect: false, followCursor: true, custom: ({ dataPointIndex }) => {
                    const w = wk[dataPointIndex];
                    return w ? `<div class="an-tip"><div class="mb-1"><b>Week of ${esc(longDate(w.start))}</b></div>
                        ${w.a ? `<div class="r"><span>Recorded</span><b>${valFmt(m, w.a)}</b></div>` : ''}${w.f ? `<div class="r"><span>Forecast</span><b>${valFmt(m, w.f)}</b></div>` : ''}
                        ${w.days < 7 ? `<div class="m">Covers ${w.days} of 7 days</div>` : ''}</div>` : '';
                } },
            });
            o.chart.stacked = true;
            o.xaxis.categories = wk.map(w => shortDate(w.start));
            o.yaxis.labels.formatter = yFmt(m);
            return mount(el, o);
        }
        const hist = s.actual.filter(a => a.d >= from), fc = inPeriod(s);
        if (m === 'inventory') {
            const o = baseOpts('bar');
            Object.assign(o, {
                series: [{ name: 'Recorded', data: hist.map(a => ({ x: utc(a.d), y: a.y })) }, { name: 'Forecast', data: fc.map(f => ({ x: utc(f.d), y: f.open ? f.y : null })) }],
                colors: [c1, c3], plotOptions: { bar: { columnWidth: '70%', borderRadius: 2 } },
                annotations: fc.length ? { xaxis: [zone] } : {}, tooltip: { shared: true, intersect: false, followCursor: true, custom: tooltipFor(s) },
            });
            o.chart.stacked = true;
            dateAxis(o);
            o.yaxis.labels.formatter = yFmt(m);
            return mount(el, o);
        }
        // The bundled ApexCharts build has no 'rangeArea' type, so the 95% band is drawn
        // as an SVG layer from the chart's own scale (drawBand); an invisible series of
        // the upper bounds keeps the y-axis tall enough to contain it.
        const band = s.band ? fc.filter(f => f.open && f.lo !== null && f.hi !== null) : [];
        const lastA = hist.find(a => a.d === I.last);
        const fcLine = fc.map(f => ({ x: utc(f.d), y: f.open ? f.y : null }));
        if (fcLine.length && lastA && lastA.y !== null) fcLine.unshift({ x: utc(I.last), y: lastA.y });   // join the lines
        const series = [{ name: 'Recorded', data: hist.map(a => ({ x: utc(a.d), y: a.y })) }];
        if (fcLine.length) series.push({ name: 'Forecast', data: fcLine });
        if (band.length) series.push({ name: 'Likely range (upper)', data: fc.map(f => ({ x: utc(f.d), y: f.open ? f.hi : null })) });
        const o = baseOpts('line'), paint = ctx => drawBand(ctx, fc, c3);
        Object.assign(o, {
            series, colors: [c1, c3, 'transparent'],
            stroke: { curve: 'straight', width: [2.5, 2.5, 0], dashArray: [0, 6, 0] },
            markers: { size: 0, hover: { size: 5 }, strokeColors: 'transparent' },
            annotations: fcLine.length ? { xaxis: [zone] } : {},
            tooltip: { shared: true, intersect: false, custom: tooltipFor(s) },
        });
        o.chart.events = band.length ? { mounted: paint, updated: paint } : {};
        dateAxis(o);
        o.yaxis.labels.formatter = yFmt(m);
        if (m === 'appointments') {
            const top = Math.max(0, ...hist.map(a => a.y ?? 0), ...fc.map(f => (band.length ? f.hi : f.y) ?? 0));
            if (top <= 6) Object.assign(o.yaxis, { max: Math.max(1, Math.ceil(top)), tickAmount: Math.max(1, Math.ceil(top)), forceNiceScale: false });
        }
        const ch = mount(el, o);
        if (band.length) ch.__fcPaint = paint;
    }
    /** Shaded 95% interval behind the lines; one polygon per run of open days. */
    function drawBand(ctx, fc, color) {
        const g = ctx.w.globals, inner = ctx.el.querySelector('.apexcharts-inner');
        if (!inner || !g.gridWidth) return;
        inner.querySelectorAll('.an-band').forEach(n => n.remove());
        const minY = Array.isArray(g.minYArr) ? g.minYArr[0] : g.minY, maxY = Array.isArray(g.maxYArr) ? g.maxYArr[0] : g.maxY;
        const px = x => (x - g.minX) / ((g.maxX - g.minX) || 1) * g.gridWidth;
        const py = y => g.gridHeight - (y - minY) / ((maxY - minY) || 1) * g.gridHeight;
        const runs = [[]];
        fc.forEach(f => { if (f.open && f.lo !== null && f.hi !== null) runs[runs.length - 1].push(f); else if (runs[runs.length - 1].length) runs.push([]); });
        runs.filter(r => r.length).forEach(r => {
            const half = r.length === 1 ? 0.35 * 864e5 : 0;
            const xs = r.length === 1 ? [utc(r[0].d) - half, utc(r[0].d) + half] : r.map(f => utc(f.d));
            const pts = r.length === 1 ? [r[0], r[0]] : r;
            const up = pts.map((f, i) => `${px(xs[i])},${py(f.hi)}`);
            const lo = pts.map((f, i) => `${px(xs[i])},${py(f.lo)}`).reverse();
            const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            poly.setAttribute('points', up.concat(lo).join(' '));
            poly.setAttribute('class', 'an-band');
            poly.setAttribute('fill', color);
            poly.setAttribute('fill-opacity', '0.2');
            inner.insertBefore(poly, inner.querySelector('.apexcharts-series, .apexcharts-line-series') || inner.firstChild);
        });
    }
    /**
     * Render a chart and keep it fitted to its card: this ApexCharts build only re-measures
     * on window resize, so a container that changes width after first paint (header filters,
     * scrollbar, sidebar toggle) would leave the plot narrower than the card.
     */
    function mount(el, o) {
        const ch = new ApexCharts(el, o);
        Promise.resolve(ch.render()).then(() => { if (ch.__fcPaint && el.isConnected) ch.__fcPaint(ch); });
        charts.push(ch);
        if (window.ResizeObserver) {
            let t = null;
            const fit = () => {
                if (!el.isConnected) return;
                const w = el.clientWidth, cur = ch.w?.globals?.svgWidth || 0;
                if (w > 0 && Math.abs(w - cur) > 2) {
                    const p = ch.updateOptions({ chart: { width: w } }, false, false);
                    if (ch.__fcPaint) Promise.resolve(p).then(() => ch.__fcPaint(ch));
                } else if (ch.__fcPaint) ch.__fcPaint(ch);
            };
            const ro = new ResizeObserver(() => { clearTimeout(t); t = setTimeout(fit, 120); });
            ro.observe(el);
            observers.push(ro);
        }
        return ch;
    }

    // ── "What this means" ─────────────────────────────────────
    function insightsCard(s) {
        const list = (st.metric === 'inventory' ? inventoryInsights() : seriesInsights(s)).slice(0, 4);
        return card('What this means', '', `<div class="an-lead">${headline(s || { actual: [], forecast: [] })}</div>
            ${list.length ? list.join('') : '<p class="an-note pt-3">Notes appear once a forecast is available.</p>'}`, { flush: true });
    }
    const row = (icon, tone, html) => `<div class="dash-alert-row"><i class="${icon} text-${tone}"></i><span>${html}</span></div>`;
    function seriesInsights(s) {
        const m = st.metric, out = [], noun = m === 'appointments' ? 'Visits' : 'Payments';
        if (!s.sel) {
            out.push(row('feather-clock', 'warning', s.combined ? `No clinic has enough history for a forecast yet. ${readinessAll(m)}` : readinessSentence(s)));
            out.push(row('feather-info', 'primary', 'Every day of bookings, check-ins and payments recorded in the system counts toward this.'));
            return out;
        }
        const fc = inPeriod(s), T = total(fc), prev = total(prevPeriod(s)), ch = change(T, prev), early = isEarly(s);
        if (early) out.push(row('feather-alert-circle', 'warning', '<b>Early estimate.</b> Only a few weeks are recorded so far, so treat the changes as a rough guide.'));
        if (ch !== null) {
            const flat = Math.abs(ch) < FLAT;
            out.push(row(flat ? 'feather-minus' : ch > 0 ? 'feather-trending-up' : 'feather-trending-down', early || flat ? 'primary' : ch > 0 ? 'success' : 'danger',
                `${noun} should <b>${flat ? 'hold steady' : (ch > 0 ? 'rise ' : 'fall ') + num(Math.abs(ch)) + '%'}</b>: ${valFmt(m, T)} expected, against ${valFmt(m, prev)} in the previous ${st.period} days.`));
        }
        const open = openFc(fc);
        if (open.length >= 2) {
            const byDay = {};
            open.forEach(r => { (byDay[weekday(r.d)] ||= []).push(r.y); });
            const top = Object.entries(byDay).map(([w, v]) => [w, v.reduce((a, b) => a + b, 0) / v.length]).sort((a, b) => b[1] - a[1])[0];
            const pk = open.reduce((a, r) => (!a || r.y > a.y ? r : a), null);
            out.push(row('feather-calendar', 'primary', `Busiest day: <b>${esc(dayLabel(pk.d))}</b> (about ${valFmt(m, pk.y)}). ${top[0]}s are the busiest on average${m === 'appointments' ? ', worth checking staffing' : ''}.`));
        }
        if (s.combined) {
            const r = s.parts.filter(p => p.sel).map(p => ({ b: p.b, ch: change(total(inPeriod(p)), total(prevPeriod(p))) })).filter(x => x.ch !== null).sort((a, b) => b.ch - a.ch);
            if (r.length > 1) out.push(row('feather-map-pin', 'primary', `Strongest outlook: <b>${esc(branchName(r[0].b))}</b> (${r[0].ch >= 0 ? '+' : '−'}${num(Math.abs(r[0].ch))}%). Weakest: <b>${esc(branchName(r[r.length - 1].b))}</b> (${r[r.length - 1].ch >= 0 ? '+' : '−'}${num(Math.abs(r[r.length - 1].ch))}%).`));
            return out;
        }
        const sel = s.sel;
        if (isNum(sel.mase) && sel.model !== 'seasonal_naive') {
            out.push(Number(sel.mase) < 1
                ? row('feather-check-circle', 'success', `In tests on past weeks, this forecast was <b>${num((1 - sel.mase) * 100)}% more accurate</b> than simply repeating last week.`)
                : row('feather-alert-circle', 'warning', 'In tests on past weeks, this forecast was no better than simply repeating last week, so use it as a rough guide.'));
        }
        const miss = D.run.suspected_missing?.[s.b];
        if (miss > 0) out.push(row('feather-alert-triangle', 'warning', `${plural(miss, 'open day')} had no activity or staff login, so ${miss === 1 ? 'it was' : 'they were'} treated as missing.`));
        return out;
    }
    function inventoryInsights() {
        const rows = scopedStock(), out = [];
        if (!rows.length) return [row('feather-clock', 'warning', 'No material has enough recorded use for a stock outlook yet.')];
        const where = r => st.branch === 'all' ? ` (${esc(branchName(r.branch_id))})` : '';
        const att = rows.filter(r => r.attention);
        out.push(att.length
            ? row('feather-alert-triangle', 'danger', `<b>${att.slice(0, 2).map(r => esc(r.item_name) + where(r)).join(', ')}</b>${att.length > 2 ? ` and ${att.length - 2} more` : ''} would reach minimum stock within ${st.period} days.`)
            : row('feather-check-circle', 'success', `No material reaches its minimum stock in the next ${st.period} days.`));
        const low = rows.filter(r => isNum(r.days_of_stock)).sort((a, b) => a.days_of_stock - b.days_of_stock)[0];
        if (low) out.push(row('feather-battery', low.days_of_stock <= st.period ? 'warning' : 'primary', `Shortest supply: <b>${esc(low.item_name)}</b>${where(low)}, about ${plural(Math.round(low.days_of_stock), 'day')} left at the expected rate.`));
        const rising = rows.filter(r => r.basis === 'model').map(r => {
            const s = single(r.branch_id, 'inventory', r.item_id);
            return { r, ch: s.sel ? change(total(inPeriod(s)), total(prevPeriod(s))) : null };
        }).filter(x => x.ch !== null && x.ch >= 20).sort((a, b) => b.ch - a.ch)[0];
        if (rising) out.push(row('feather-trending-up', 'warning', `<b>${esc(rising.r.item_name)}</b>${where(rising.r)} use is expected to rise ${num(rising.ch)}%.`));
        const avg = rows.filter(r => r.basis === 'average').length;
        if (avg) out.push(row('feather-info', 'primary', `${plural(avg, 'material')} ${avg === 1 ? 'is' : 'are'} projected from the last 4 weeks’ average (too little history for a model).`));
        return out;
    }

    // ── Readiness ─────────────────────────────────────────────
    function openPerWeek(b) {
        const open = D.run.open_days?.[b], start = D.run.history_start;
        if (!open || !start) return null;
        const span = daysBetween(start, I.last) + 1;
        return span > 0 ? open / span * 7 : null;
    }
    function readinessSentence(s) {
        const need = statMin() - s.nObs, b = esc(branchName(s.b));
        if (need <= 0) return `${b} has enough history for every model.`;
        const opw = openPerWeek(s.b);
        return `${b} has <b>${plural(s.nObs, 'day')}</b> with records; a reliable forecast needs ${statMin()}, so <b>${plural(need, 'more day')}</b> ${need === 1 ? 'is' : 'are'} needed${opw ? ` (about ${plural(Math.ceil(need / opw), 'week')} at the current schedule)` : ''}.`;
    }
    function readinessAll(metric) {
        const ready = I.branchIds.filter(b => single(b, metric, null).nObs >= statMin()).length;
        return `${ready} of ${I.branchIds.length} clinics have the ${statMin()} days with records that a reliable forecast needs.`;
    }
    const readyBadge = n => n >= statMin() ? badge('success', 'Ready') : n >= baseMin() ? badge('warning', 'Baseline only') : badge('secondary', 'Collecting data');
    function readinessCard(s) {
        const m = st.metric;
        if (st.branch === 'all' || (m === 'inventory' && !s)) {
            const rows = scopeIds().map(b => {
                const n = m === 'inventory'
                    ? Math.max(0, ...Object.keys(I.metrics).filter(k => k.startsWith(`${b}|inventory|`)).map(k => Math.max(0, ...I.metrics[k].map(r => r.n_obs || 0))))
                    : single(b, m, null).nObs;
                return `<div class="dash-kv"><span class="text-muted">${esc(branchName(b))}</span><span class="d-flex align-items-center gap-2"><span class="v">${num(n)} of ${statMin()}</span>${readyBadge(n)}</span></div>`;
            }).join('');
            return card('History available', 'days with records', rows + `<p class="an-sub mt-2 mb-0">The full models need ${statMin()} days with records; the simple baseline needs ${baseMin()}.</p>`, { bare: true });
        }
        const cov = coverage(s), miss = D.run.suspected_missing?.[s.b];
        const reqs = MODEL_ORDER.filter(k => I.mins[k]).map(k => {
            const need = I.mins[k], ok = s.nObs >= need;
            return `<div class="dash-kv"><span class="text-muted">${esc(MODELS[k].name)} <span class="fs-11">(${need} days)</span></span>${ok ? badge('success', 'Met') : badge('secondary', `${need - s.nObs} to go`)}</div>`;
        }).join('');
        return card('History available', m === 'inventory' ? esc(itemsOf(s.b).find(i => i.id === s.item)?.name || '') : esc(branchName(s.b)), `
            ${kv('Days with records', `${num(s.nObs)} <span class="fs-11 text-muted">of ${statMin()} needed</span>`)}
            ${kv('Open days in the history', cov.open ? num(cov.open) : '—')}
            ${kv('Coverage', cov.ratio === null ? '—' : pct(cov.ratio))}
            ${D.run.history_start ? kv('History window', `${esc(shortDate(D.run.history_start))} – ${esc(shortDate(I.last))}`) : ''}
            ${miss ? kv('Suspected missing days', plural(miss, 'day'), 'text-warning') : ''}
            <div class="d-flex justify-content-between align-items-center fs-12 mt-3"><span class="text-muted">Readiness</span>${readyBadge(s.nObs)}</div>
            <div class="an-prop mt-2"><i style="width:${Math.min(100, s.nObs / statMin() * 100)}%;background:var(${s.nObs >= statMin() ? '--an-good' : '--an-warn'})"></i></div>
            ${s.nObs < statMin() ? `<p class="an-sub mb-3">${readinessSentence(s)}</p>` : ''}
            <h4 class="an-h3 mt-2 mb-1">Minimum history per method</h4>${reqs}`, { bare: true });
    }

    // ── "How this forecast was made" ──────────────────────────
    const glossary = () => `<div class="an-explain">
        <p><b>How it is tested.</b> Each method is trained on the recorded history up to a cut-off date, then asked to forecast ${D.evalFolds} separate ${D.foldDays}-day stretches it has not seen. Its forecasts are compared with what was actually recorded. The final forecast uses everything up to ${esc(longDate(I.last))}.</p>
        <p><b>How the method is chosen.</b> The method with the smallest typical miss compared with simply repeating last week wins; ties go to the simpler method.</p>
        <p><b>Reading the table.</b> Average miss (MAE) is the typical difference per day. Large-miss weighted (RMSE) counts big misses more heavily. Versus last week (MASE) below 1 means better than repeating last week. Percent miss (MAPE) is the average miss as a percentage.</p></div>`;
    function modelsCard(s) {
        const m = st.metric, unit = m === 'sales' ? 'pesos' : m === 'inventory' ? 'units' : 'visits';
        const head = `<th class="text-end" title="Average miss per day (MAE)">Average miss</th><th class="text-end an-hide-sm" title="Root mean squared error (RMSE)">Large-miss weighted</th>
            <th class="text-end" title="Mean absolute scaled error (MASE); below 1 beats repeating last week">Vs last week</th><th class="text-end an-hide-sm" title="Mean absolute percentage error (MAPE)">Percent miss</th>`;
        if (st.branch === 'all' || (m === 'inventory' && !s)) {
            if (m === 'inventory') return card('Methods tested', '', '<p class="an-sub py-2 mb-0">Choose a clinic and a material to see how each method did.</p>', { bare: true, after: glossary() });
            const body = scopeIds().map(b => {
                const mr = I.metrics[keyOf(b, m, null)] || [], sel = mr.find(r => r.is_selected), ok = mr.filter(r => r.status === 'ok').length;
                return `<tr><td class="text-dark">${esc(branchName(b))}</td><td>${sel ? esc(MODELS[sel.model]?.name || sel.model) : '<span class="text-muted">None selected</span>'}</td>
                    <td class="text-end">${sel && isNum(sel.mase) ? num(sel.mase, 2) : '—'}</td><td class="text-end">${sel && isNum(sel.mape) ? pct(sel.mape, 1) : '—'}</td>
                    <td class="text-end">${badge(ok ? 'success' : 'secondary', `${ok} of ${mr.filter(r => r.model !== 'pipeline').length || 4} tested`)}</td></tr>`;
            }).join('');
            return card('Methods chosen', `${CATS[m].label}, per clinic`, `<div class="table-responsive"><table class="table table-hover mb-0 an-table">
                <thead><tr><th>Clinic</th><th>Method chosen</th><th class="text-end" title="MASE; below 1 beats repeating last week">Vs last week</th><th class="text-end" title="MAPE">Percent miss</th><th class="text-end">Tests</th></tr></thead><tbody>${body}</tbody></table></div>`,
                { bare: true, flush: true, after: glossary() });
        }
        const rows = (s.mrows || []).slice().sort((a, b) => MODEL_ORDER.indexOf(a.model) - MODEL_ORDER.indexOf(b.model));
        if (!rows.length) return card('Methods tested', '', '<p class="an-sub py-2 mb-0">No test results for this selection.</p>', { bare: true, after: glossary() });
        const err = v => !isNum(v) ? '<span class="text-muted">—</span>' : m === 'sales' ? peso(v) : num(v, 2);
        const body = rows.map(r => {
            const info = MODELS[r.model] || { name: r.model, cat: '' };
            const status = r.status === 'ok' ? badge('success', 'Tested')
                : r.status === 'insufficient_data' ? `<span title="${esc(r.message || '')}">${badge('secondary', I.mins[r.model] ? `Needs ${I.mins[r.model]} days` : 'Not enough data')}</span>`
                : `<span title="${esc(r.message || '')}">${badge('danger', 'Failed')}</span>`;
            return `<tr class="${r.is_selected ? 'is-selected' : ''}"><td><span class="text-dark">${esc(info.name)}</span>${r.is_selected ? ' ' + badge('primary', 'Chosen') : ''}
                <div class="fs-11 text-muted">${esc(info.cat)}</div></td><td class="text-end">${err(r.mae)}</td><td class="text-end an-hide-sm">${err(r.rmse)}</td>
                <td class="text-end">${isNum(r.mase) ? num(r.mase, 2) : '<span class="text-muted">—</span>'}</td>
                <td class="text-end an-hide-sm">${isNum(r.mape) ? pct(r.mape, 1) : '<span class="text-muted">—</span>'}</td><td class="text-end an-hide-sm">${num(r.n_obs)}</td><td class="text-end">${status}</td></tr>`;
        }).join('');
        const note = !rows.some(r => r.status === 'ok') ? '<p class="an-note pt-3 mb-0">No method has finished testing yet, so none is chosen.</p>' : '';
        return card('Methods tested', `${esc(branchName(s.b))}, ${m === 'inventory' ? esc(itemsOf(s.b).find(i => i.id === s.item)?.name || '') : CATS[m].label.toLowerCase()}; misses in ${unit} per day`,
            `<div class="table-responsive"><table class="table table-hover mb-0 an-table"><thead><tr><th>Method</th>${head}<th class="text-end an-hide-sm">Days</th><th class="text-end">Status</th></tr></thead>
            <tbody>${body}</tbody></table></div>${note}`, { bare: true, flush: true, after: glossary() });
    }
    function aboutSection(s) {
        const m = st.metric, sel = s && !s.combined ? s.sel : null;
        const method = s?.combined ? (s.sel ? 'Chosen per clinic (see below)' : 'None yet') : sel ? esc(MODELS[sel.model]?.name || sel.model) : 'None yet';
        const miss = sel && isNum(sel.mae) ? `About ${valFmt(m, sel.mae, m === 'sales' ? 0 : 1)}${m === 'appointments' ? ' visits' : m === 'inventory' ? ' units' : ''} a day` : '—';
        const vsWeek = sel && isNum(sel.mase) ? (Number(sel.mase) < 1 ? `${num((1 - sel.mase) * 100)}% more accurate` : 'No better') : '—';
        const facts = `${kv('Method used', method)}
            ${m !== 'inventory' || s ? kv('Typical miss in testing', miss) + kv('Against repeating last week', vsWeek) : ''}
            ${kv('Forecast covers', `${esc(shortDate(addDays(I.last, 1)))} – ${esc(shortDate(periodEnd()))}`)}
            ${kv('Records up to', esc(longDate(I.last)))}
            ${kv('Generated', esc(stamp(D.run.finished_at)))}
            ${kv('Data used', D.source === 'simulated' ? 'Demo data' : 'Clinic records')}`;
        const open = !(s && s.sel) ? ' open' : '';
        return `<details class="card an-more"${open}><summary><span><span class="an-h d-block">How this forecast was made</span>
                <span class="an-sub">Method, accuracy in testing, and how much history each clinic has</span></span></summary>
            <div class="an-more-grid">${card('This forecast', '', facts, { bare: true })}${readinessCard(s)}</div>
            <div class="an-sec-full">${modelsCard(s)}</div></details>`;
    }

    // ── Clinic comparison + stock outlook ─────────────────────
    function comparisonCard() {
        if (!I.multi || st.branch !== 'all') return '';
        const m = st.metric, P = st.period;
        if (m === 'inventory') {
            const body = I.branchIds.map(b => {
                const items = I.inventory.filter(r => r.branch_id === b).map(r => ({ ...r, use: invUse(r), attention: needsAttention(r) }));
                const att = items.filter(r => r.attention).length;
                const low = items.filter(r => isNum(r.days_of_stock)).sort((x, y) => x.days_of_stock - y.days_of_stock)[0];
                return `<tr><td class="text-dark">${esc(branchName(b))}</td><td class="text-end">${num(items.length)}</td>
                    <td class="text-end">${att ? badge('danger', att) : badge('success', '0')}</td>
                    <td>${low ? `${esc(low.item_name)} <span class="text-muted">(${plural(Math.round(low.days_of_stock), 'day')})</span>` : '—'}</td></tr>`;
            }).join('');
            return card('By clinic', `materials over the next ${P} days`, `<div class="table-responsive"><table class="table table-hover mb-0 an-table">
                <thead><tr><th>Clinic</th><th class="text-end">Materials</th><th class="text-end">Reorder soon</th><th>Shortest supply</th></tr></thead><tbody>${body}</tbody></table></div>`, { flush: true });
        }
        const body = I.branchIds.map(b => {
            const p = single(b, m, null), T = p.sel ? total(inPeriod(p)) : null, prev = total(prevPeriod(p)), ch = T === null ? null : change(T, prev);
            return `<tr><td class="text-dark">${esc(branchName(b))}</td>
                <td class="text-end">${valFmt(m, prev)}</td><td class="text-end">${T === null ? '<span class="text-muted">No forecast yet</span>' : valFmt(m, T)}</td>
                <td class="text-end">${ch === null ? '<span class="text-muted">—</span>' : arrow(ch)}</td>
                <td class="text-muted an-hide-sm">${p.sel ? esc(MODELS[p.sel.model]?.name || p.sel.model) : '—'}</td></tr>`;
        }).join('');
        return card('By clinic', `${CATS[m].label.toLowerCase()}, previous and next ${P} days`, `<div class="table-responsive"><table class="table table-hover mb-0 an-table">
            <thead><tr><th>Clinic</th><th class="text-end">Previous ${P} days</th><th class="text-end">Next ${P} days</th><th class="text-end">Change</th><th class="an-hide-sm">Method</th></tr></thead><tbody>${body}</tbody></table></div>`, { flush: true });
    }
    function stockCard(title) {
        const rows = scopedStock().sort((a, b) => (b.attention - a.attention) || ((a.days_of_stock ?? 1e9) - (b.days_of_stock ?? 1e9)));
        const sub = `${scopeLabel()}, next ${st.period} days`;
        if (!rows.length) return card(title, sub, empty('Materials need recorded use before their stock can be projected.', 200));
        const shown = st.showAllStock ? rows : rows.slice(0, 8), all = st.branch === 'all';
        const body = shown.map(r => `<tr ${all ? '' : `class="an-pick" data-fc-pick="${r.item_id}" title="Show this material’s chart"`}>
            <td><span class="text-dark">${esc(r.item_name || 'Item #' + r.item_id)}</span>${all ? `<div class="fs-11 text-muted">${esc(branchName(r.branch_id))}</div>` : ''}</td>
            <td class="text-end">${num(r.stock_on_hand)}</td><td class="text-end text-muted an-hide-sm">${num(r.min_stock)}</td>
            <td class="text-end">${r.use === null ? '—' : num(r.use, 1)}${r.basis === 'average' ? ' <span class="fs-11 text-muted" title="Recent 4-week average × open days">avg</span>' : ''}</td>
            <td class="text-end">${isNum(r.days_of_stock) ? (r.days_of_stock > 365 ? '365+' : num(r.days_of_stock)) : '—'}</td>
            <td class="text-end">${r.attention ? badge('danger', 'Reorder soon') : badge('success', 'OK')}</td></tr>`).join('');
        const more = rows.length > 8 ? `<div class="px-4 pt-2"><button type="button" class="an-link" data-fc-stockall>${st.showAllStock ? 'Show fewer' : `Show all ${rows.length}`}</button></div>` : '';
        return card(title, sub, `<div class="table-responsive"><table class="table table-hover mb-0 an-table"><thead><tr><th>Material</th><th class="text-end">In stock</th>
            <th class="text-end an-hide-sm">Minimum</th><th class="text-end">Expected use</th><th class="text-end">Days left</th><th class="text-end">Status</th></tr></thead><tbody>${body}</tbody></table></div>${more}`,
            { flush: true, after: `<div class="an-note pt-3">“Reorder soon” means the stock left after the expected use would be at or below the material’s minimum level.${all ? '' : ' Select a row to see its chart.'}</div>` });
    }

    // ── State screens ─────────────────────────────────────────
    function stateScreen() {
        const box = (icon, title, text) => `<div class="card"><div class="an-state">
            <span class="an-ico t-slate"><i class="${icon}" aria-hidden="true"></i></span><h2 class="an-h">${title}</h2><div class="an-state-text">${text}</div></div></div>`;
        if (!D && loadState === 'error') return box('feather-wifi-off', 'The forecast could not be loaded',
            'Check the connection, then try again.<div class="mt-3"><button type="button" class="dash-refresh d-inline-flex" data-fc-retry><i class="feather-refresh-cw"></i>Try again</button></div>');
        if (!D) return skeleton();
        if (D.setup) return box('feather-database', 'Forecasting isn’t set up yet', 'Run <code>database/proposed/2026-11-12_forecast_tables.sql</code> in the Supabase SQL Editor.');
        if (!D.run && (D.running || runState.active)) return box('feather-loader', 'Making the first forecast…', 'This usually takes 1–3 minutes. You can keep working meanwhile.');
        if (!D.run) return box('feather-bar-chart-2', `No ${D.source === 'simulated' ? 'demo' : ''} forecast yet`.replace('  ', ' '),
            D.isOwner ? 'Use <b>Run forecast</b> to train and test the methods on the recorded history.' : 'Forecasts are made by the clinic owner or by the scheduled nightly job.');
        if (!I.branchIds.length) return box('feather-lock', 'No clinic in scope', 'This account is not linked to a clinic. Ask the owner to assign one.');
        return null;
    }

    const skeleton = () => `<div class="row">${[1, 2, 3, 4].map(() => `<div class="col-xxl-3 col-md-6"><div class="card stretch stretch-full an-kpi"><div class="card-body">
        <span class="dash-skel" style="width:55%"></span><div class="an-kpi-value"><span class="dash-skel" style="width:40%"></span></div><span class="dash-skel mt-2" style="width:70%"></span></div></div></div>`).join('')}</div>
        <div class="card"><div class="an-state"><span class="es-spin"></span>Loading the forecast…</div></div>`;

    // ── Render ────────────────────────────────────────────────
    const emit = () => document.dispatchEvent(new CustomEvent('fc:data'));
    function render() {
        observers.splice(0).forEach(o => o.disconnect());
        charts.splice(0).forEach(c => { try { c.destroy(); } catch (e) { /* gone */ } });
        renderNotice();
        const screen = stateScreen();
        if (screen !== null) { app.innerHTML = screen; syncHeader(); emit(); return; }
        normaliseState();
        saveUi();
        syncHeader();
        const s = current(), m = st.metric;
        const cmp = comparisonCard();
        app.innerHTML = `<div class="row">${statCards(s || { actual: [], forecast: [] })}</div>
            <div class="row"><div class="col-xxl-8">${mainCard(s)}</div><div class="col-xxl-4">${insightsCard(s)}</div></div>
            ${m === 'inventory' && st.branch !== 'all' && itemsOf(st.branch).length ? `<div class="row"><div class="col-12">${stockCard('Stock outlook')}</div></div>` : ''}
            ${cmp ? `<div class="row"><div class="col-12">${cmp}</div></div>` : ''}
            ${aboutSection(s)}`;
        if (window.enhanceCustomSelects) window.enhanceCustomSelects();
        if (!pane.hidden) drawMain(s);
        emit();
    }
    function reset() { I = D ? index(D) : null; render(); }

    // Filters (the clinic filter in the page header is shared with analytics-history.js)
    document.addEventListener('change', e => {
        const id = e.target.id;
        if (id === 'fcBranch') { st.branch = e.target.value === 'all' ? 'all' : Number(e.target.value); st.item = null; st.showAllStock = false; }
        else if (id === 'fcMetric') { st.metric = e.target.value; st.showAllStock = false; }
        else if (id === 'fcPeriod') st.period = Number(e.target.value);
        else if (id === 'fcItem') st.item = Number(e.target.value);
        else return;
        if (I) render(); else if (id === 'fcBranch') saveUi();
    });
    pane.addEventListener('click', e => {
        const t = e.target.closest('[data-fc-view],[data-fc-pick],[data-fc-stockall],[data-src],[data-fc-retry]');
        if (!t) return;
        if (t.hasAttribute('data-fc-retry')) { ensure(); return; }
        if (t.dataset.src) { if (!runState.active && t.dataset.src !== source) switchSource(t.dataset.src); return; }
        if (t.dataset.fcView) st.view = t.dataset.fcView;
        if (t.dataset.fcPick) { st.item = Number(t.dataset.fcPick); pane.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        if (t.hasAttribute('data-fc-stockall')) st.showAllStock = !st.showAllStock;
        render();
    });

    // Charts are drawn when the section becomes visible, and redrawn on theme / size changes
    pane.addEventListener('fc:shown', ensure);
    new MutationObserver(() => { if (!pane.hidden && I && !stateScreen()) render(); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    let lastNarrow = window.innerWidth < 768;
    window.addEventListener('resize', () => {
        const narrow = window.innerWidth < 768;
        if (narrow !== lastNarrow && !pane.hidden && I) { lastNarrow = narrow; render(); }
    });

    // ── Loading (on demand, cached per source) ────────────────
    /** Fetch one source's payload. force = bypass the page cache (after a new run). */
    function fetchData(src, force) {
        if (!force && cache[src]) return Promise.resolve(cache[src]);
        if (!force && loading && loading.src === src) return loading.p;
        const p = fetch('api/forecast_data.php?source=' + encodeURIComponent(src), { headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(r => r.json())
            .then(d => { if (!d.success) throw new Error(d.message || 'failed'); cache[src] = d.data; return d.data; })
            .finally(() => { if (loading && loading.p === p) loading = null; });
        loading = { src, p };
        return p;
    }
    /** Show `source` (page cache or server). force = after a new run; quiet = background, no error toast. */
    function load(force, quiet) {
        const src = source;
        if (!cache[src] || force) { loadState = 'loading'; if (!pane.hidden && !quiet) render(); }
        return fetchData(src, force).then(data => {
            if (src !== source) return;                  // switched source meanwhile
            const first = !D;
            D = data; loadState = 'ok';
            reset();
            if (first) resumeRun();
        }).catch(() => {
            if (src !== source) return;
            if (!D) loadState = 'error';
            if (!quiet && window.esToast) window.esToast('Could not load the forecast. Please try again.', 'error');
            render();
        });
    }
    /** The section was opened (or Try again): load once, otherwise just (re)draw. */
    function ensure() {
        if (D && I) { if (!stateScreen()) render(); return; }
        load(false, false);
    }
    function switchSource(src) {
        source = src;
        D = null; I = null;
        try { history.replaceState(null, '', 'analytics.php' + (src === 'simulated' ? '?fsrc=simulated' : '') + location.hash); } catch (e) { /* ignore */ }
        load(false, false);
    }

    // ── Run forecast (owner only; api/forecast_run.php) ───────
    const runBtn = document.getElementById('fcRunBtn');
    const runState = { active: false, timer: null, started: 0 };
    const POLL_MS = 5000, MAX_MS = 10 * 60 * 1000;
    const post = body => fetch('api/forecast_run.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(r => r.json());
    function busy(on, since) {
        runState.active = on;
        clearInterval(runState.timer);
        if (!runBtn) return;
        runBtn.disabled = on || !!D?.setup;
        runBtn.setAttribute('aria-busy', String(on));
        if (!on) { runBtn.innerHTML = '<i class="feather-play"></i><span>Run forecast</span>'; if (I) render(); else syncHeader(); return; }
        runState.started = since || Date.now();
        const tick = () => {
            const s = Math.max(0, Math.round((Date.now() - runState.started) / 1000));
            runBtn.innerHTML = `<span class="es-spin"></span><span>Forecasting… ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}</span>`;
        };
        tick();
        runState.timer = setInterval(tick, 1000);
        if (I) render(); else syncHeader();
    }
    function poll(source, requestedAt, pollStart) {
        if (Date.now() - pollStart > MAX_MS) {
            busy(false);
            return Swal.fire({ icon: 'info', title: 'Still running', text: 'The forecast is taking longer than usual. Reload the page in a few minutes.' });
        }
        post({ action: 'status', source }).then(d => {
            const run = d.run;
            const fresh = run && Date.parse(run.started_at) >= requestedAt - 60000;
            if (!fresh || run.status === 'running') return setTimeout(() => poll(source, requestedAt, pollStart), POLL_MS);
            busy(false);
            if (run.status === 'success') {
                load(true, true).then(() => { if (window.esToast) window.esToast('Forecast updated.', 'success'); });
            } else {
                load(true, true);
                Swal.fire({ icon: 'error', title: 'Forecast failed', text: run.error || 'Unknown error. See cron/logs/forecast.log on the server.' });
            }
        }).catch(() => setTimeout(() => poll(source, requestedAt, pollStart), POLL_MS));
    }
    if (runBtn) {
        runBtn.addEventListener('click', () => {
            // Primary action LEFT, cancel RIGHT (Swal default order)
            Swal.fire({
                icon: 'question', title: 'Run the forecast now?',
                html: `This retrains and re-tests every method on the <b>${source === 'simulated' ? 'demo' : 'clinic'}</b> history for every clinic.
                       It usually takes 1–3 minutes; you can keep working meanwhile.`,
                showCancelButton: true, confirmButtonText: 'Run forecast', cancelButtonText: 'Cancel',
            }).then(r => {
                if (!r.isConfirmed) return;
                busy(true);
                post({ action: 'start', source }).then(d => {
                    if (!d.success) { busy(false); return Swal.fire({ icon: 'warning', title: 'Not started', text: d.message || 'Could not start the forecast.' }); }
                    if (window.esToast) window.esToast('Forecast started. You can keep working while it runs.', 'info');
                    const requestedAt = Date.parse(d.requested_at) || Date.now();
                    setTimeout(() => poll(source, requestedAt, Date.now()), POLL_MS);
                }).catch(() => { busy(false); Swal.fire({ icon: 'error', title: 'Connection problem', text: 'Could not reach the server. Please try again.' }); });
            });
        });
    }
    /** A run already in progress (e.g. started from another tab): resume the progress state. */
    function resumeRun() {
        if (!runBtn || runState.active || !D?.running) return;
        const since = Date.parse(D.running.started_at);
        busy(true, since);
        setTimeout(() => poll(D.source, since, Date.now()), 1000);
    }

    // ── Read-only summaries for the briefing + Materials section ──
    /** Forecast totals for the current clinic scope and period (null parts when not available). */
    function brief() {
        const base = { state: loadState, source, period: st.period };
        if (!D) return base;
        base.state = 'ok';
        if (D.setup || !D.run || !I || !I.branchIds.length) return { ...base, hasRun: false, setup: !!D.setup };
        const one = metric => {
            const s = st.branch === 'all' && I.multi ? combined(metric) : single(st.branch === 'all' ? I.branchIds[0] : st.branch, metric, null);
            const missing = s.combined ? s.missing.map(branchName) : [];
            if (!s.sel || !s.forecast.length) return { ready: false, nObs: s.nObs, need: statMin(), missing };
            const T = total(inPeriod(s)), prev = total(prevPeriod(s));
            return { ready: true, next: T, prev, ch: change(T, prev), early: isEarly(s), missing };
        };
        return { ...base, hasRun: true, last: I.last, appointments: one('appointments'), sales: one('sales') };
    }
    /** Recorded material use (last 28 days vs the 28 before) for the current clinic scope. */
    function materials() {
        if (!D || !I || D.setup || !D.run) return null;
        const ids = scopeIds(), inv = {};
        I.inventory.forEach(r => { inv[r.branch_id + '|' + r.item_id] = r; });
        const from = addDays(I.last, -27), prevFrom = addDays(I.last, -55), prevTo = addDays(I.last, -28);
        const rows = Object.keys(I.values).filter(k => { const p = k.split('|'); return p[1] === 'inventory' && ids.includes(Number(p[0])); }).map(k => {
            const [b, , it] = k.split('|');
            const act = I.values[k].filter(r => r.kind === 'actual');
            const sumIn = (lo, hi) => act.filter(r => r.value_date >= lo && r.value_date <= hi && r.is_open && isNum(r.yhat)).reduce((t, r) => t + Number(r.yhat), 0);
            const earliest = act.reduce((a, r) => (!a || r.value_date < a ? r.value_date : a), null);
            const used = sumIn(from, I.last), prev = earliest && earliest <= prevFrom ? sumIn(prevFrom, prevTo) : null;
            const row = inv[b + '|' + it];
            return {
                branch: Number(b), branchName: branchName(Number(b)), item: Number(it), name: row?.item_name || `Item #${it}`,
                used, prev, ch: prev === null ? null : change(used, prev),
                stock: row ? Number(row.stock_on_hand) : null, days: row && isNum(row.days_of_stock) ? Number(row.days_of_stock) : null,
                attention: row ? needsAttention(row) : false,
            };
        }).filter(r => r.used > 0).sort((a, b) => b.used - a.used);
        return { source, last: I.last, rows };
    }
    /** Recorded (last 4 weeks) + forecast visits for the current clinic scope (Overview teaser). */
    function preview() {
        if (!D || !I || D.setup || !D.run || !I.branchIds.length) return null;
        const s = st.branch === 'all' && I.multi ? combined('appointments') : single(st.branch === 'all' ? I.branchIds[0] : st.branch, 'appointments', null);
        const from = addDays(I.last, -27);
        return {
            source, last: I.last, period: st.period, combined: !!s.combined, ready: !!(s.sel && s.forecast.length),
            hist: s.actual.filter(a => a.d >= from).map(a => ({ d: a.d, y: a.y })),
            fc: s.sel ? inPeriod(s).map(f => ({ d: f.d, y: f.open ? f.y : null })) : [],
        };
    }
    window.ESForecast = {
        prefetch() { if (!D && !loading) load(false, true); },
        reload() { if (runState.active) return; if (D) load(true, true); else load(false, false); },
        preview,
        state: () => ({ loadState, branch: st.branch, metric: st.metric, period: st.period }),
        brief, materials,
        /** Open a metric (and material) in the Forecast section. */
        focus(metric, item) { if (CATS[metric]) st.metric = metric; if (item !== undefined) st.item = item; st.showAllStock = false; if (I) render(); },
    };

    // Start loading straight away: the briefing at the top of the page quotes the forecast.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => window.ESForecast.prefetch());
    else window.ESForecast.prefetch();
})();
