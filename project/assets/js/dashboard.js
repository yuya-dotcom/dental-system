/* =============================================================
   assets/js/dashboard.js — fills dashboard.php from api/dashboard_stats.php
   Loading: skeletons (initial) / spinner overlay (refresh).
   Empty:   "no data" messages instead of zero-height charts.
   Error:   the affected cards show "Couldn't load" + Retry; a failed
            section never blanks the others.
   Look:    the Analytics design language (assets/css/analytics.css):
            pills, icon chips, dark tooltips, clinic colours.
   ============================================================= */
(function () {
  'use strict';
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  const num = (n) => Number(n || 0).toLocaleString('en-PH');
  const pct = (a, b) => (b > 0 ? Math.round((a / b) * 100) : 0);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const charts = {};
  let ctl = null;

  // Which value slots depend on which query (for per-section error states)
  const SECTION_SLOTS = {
    appointments: ['today.main', 'today.sub', 'today.foot', 'apt.'],
    patients: ['patients.', 'pat.'],
    invoices: ['sales.'],
    payments: ['revenue.', 'rev.'],
    inventory: ['stock.', 'inv.'],
    treatments: ['trt.'],
  };

  function set(key, text, html) {
    $$('[data-v="' + key + '"]').forEach((n) => { if (html) n.innerHTML = text; else n.textContent = text; });
  }
  function bar(key, value) { $$('[data-v="' + key + '"]').forEach((n) => { n.style.width = Math.max(0, Math.min(100, value)) + '%'; }); }
  function errorSlot() { return '<span class="dash-error" title="This figure could not be loaded"><i class="feather-alert-circle"></i>—</span>'; }
  function markError(section) {
    (SECTION_SLOTS[section] || []).forEach((prefix) => {
      $$('[data-v]').filter((n) => n.dataset.v === prefix || (prefix.endsWith('.') && n.dataset.v.startsWith(prefix)))
        .forEach((n) => { if (n.classList.contains('progress-bar')) n.style.width = '0%'; else n.innerHTML = errorSlot(); });
    });
  }
  function chartState(id, html) {
    const box = document.getElementById(id);
    if (charts[id]) { charts[id].destroy(); delete charts[id]; }
    box.innerHTML = '<div class="dash-chart-state">' + html + '</div>';
  }
  const retryBtn = '<button type="button" class="dash-refresh mt-1" data-dash-retry><i class="feather-refresh-cw"></i>Retry</button>';

  function cssVar(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
  function isDark() { return document.documentElement.classList.contains('app-skin-dark'); }

  function baseChart(type, height) {
    const ink = cssVar('--an-muted') || '#6b7a90', grid = cssVar('--an-line-soft') || '#eef2f7';
    return {
      chart: { type, height: window.innerWidth < 576 ? Math.min(height, 250) : height, toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit',
        animations: { enabled: true, easing: 'easeout', speed: 350, animateGradually: { enabled: false } }, parentHeightOffset: 0 },
      grid: { borderColor: grid, strokeDashArray: 3, padding: { left: 8, right: 12, top: -6 } },
      dataLabels: { enabled: false },
      legend: { show: false },                       // HTML legend in the card header
      xaxis: { labels: { style: { colors: ink, fontSize: '12px' }, rotate: 0, hideOverlappingLabels: true }, axisBorder: { show: false }, axisTicks: { show: false },
        tooltip: { enabled: false }, crosshairs: type === 'bar' ? { show: false } : {} },
      yaxis: { labels: { style: { colors: ink, fontSize: '12px' } } },
      states: { active: { filter: { type: 'none' } }, hover: { filter: { type: 'darken', value: 0.92 } } },
      tooltip: { shared: true, intersect: false, followCursor: type === 'bar', theme: 'dark' },
    };
  }
  /** Whole-number axis for small counts (avoids repeated "1" labels). */
  function intAxis(opts, values) {
    const max = Math.max(0, ...values);
    if (max <= 6) Object.assign(opts.yaxis, { max: Math.max(1, Math.ceil(max)), tickAmount: Math.max(1, Math.ceil(max)), forceNiceScale: false });
  }
  let last = null;                                   // last payload, for redraws on theme change

  function tableHtml(headers, rows) {
    return '<table class="table table-sm mb-0"><thead><tr>' + headers.map((h) => '<th>' + esc(h) + '</th>').join('') +
      '</tr></thead><tbody>' + rows.map((r) => '<tr>' + r.map((c) => '<td>' + esc(c) + '</td>').join('') + '</tr>').join('') + '</tbody></table>';
  }

  function renderAppointmentsChart(t, failed) {
    const id = 'chartAppointments';
    if (failed) { chartState(id, '<span class="dash-error"><i class="feather-alert-circle"></i>Couldn\'t load appointment trends.</span>' + retryBtn); $('#tableAppointments').innerHTML = ''; return; }
    $('#tableAppointments').innerHTML = tableHtml(['Month', 'Total', 'Completed', 'Cancelled'], t.labels.map((l, i) => [l, t.appointments[i], t.completed[i], t.cancelled[i]]));
    if (!t.appointments.some((v) => v > 0)) { chartState(id, '<i class="feather-bar-chart-2 fs-3"></i>No appointments in the last 6 months.'); return; }
    if (!window.ApexCharts) { chartState(id, 'Chart unavailable — use “Show as table”.'); return; }
    const opts = Object.assign(baseChart('bar', 280), {
      series: [{ name: 'Total', data: t.appointments }, { name: 'Completed', data: t.completed }, { name: 'Cancelled', data: t.cancelled }],
      colors: [cssVar('--dash-c1'), cssVar('--dash-c2'), cssVar('--dash-c3')],
      plotOptions: { bar: { columnWidth: '58%', borderRadius: 4, borderRadiusApplication: 'end' } },
      stroke: { show: true, width: 3, colors: ['transparent'] },   // gap between adjacent bars
    });
    opts.xaxis.categories = t.labels.map((l) => l.slice(0, 3));
    opts.yaxis.labels.formatter = (v) => Math.round(v);
    opts.yaxis.forceNiceScale = true; opts.yaxis.min = 0;
    intAxis(opts, t.appointments);
    draw(id, opts);
  }

  function renderRevenueChart(t, failed) {
    const id = 'chartRevenue';
    if (failed) { chartState(id, '<span class="dash-error"><i class="feather-alert-circle"></i>Couldn\'t load revenue.</span>' + retryBtn); $('#tableRevenue').innerHTML = ''; return; }
    $('#tableRevenue').innerHTML = tableHtml(['Month', 'Payments received'], t.labels.map((l, i) => [l, peso(t.revenue[i])]));
    if (!t.revenue.some((v) => v > 0)) { chartState(id, '<i class="feather-trending-up fs-3"></i>No payments recorded in the last 6 months.'); return; }
    if (!window.ApexCharts) { chartState(id, 'Chart unavailable — use “Show as table”.'); return; }
    const opts = Object.assign(baseChart('area', 280), {
      series: [{ name: 'Payments received', data: t.revenue }],
      colors: [cssVar('--an-hist')],
      stroke: { curve: 'smooth', width: 2.5 },
      markers: { size: 4, strokeWidth: 2, strokeColors: cssVar('--an-surface') || '#fff', hover: { size: 6 } },
      fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.25, opacityTo: 0, stops: [0, 95] } },
    });
    opts.xaxis.categories = t.labels.map((l) => l.slice(0, 3));
    opts.yaxis.labels.formatter = (v) => { const a = Math.abs(v); return '₱' + (a >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : a >= 1e3 ? (v / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k' : Math.round(v)); };
    opts.yaxis.min = 0; opts.yaxis.tickAmount = 5; opts.yaxis.forceNiceScale = true;
    opts.tooltip.y = { formatter: (v) => peso(v) };
    draw(id, opts);
  }

  function draw(id, opts) {
    const box = document.getElementById(id);
    if (charts[id]) { try { charts[id].destroy(); } catch (e) { /* gone */ } delete charts[id]; }
    box.innerHTML = '';
    charts[id] = new ApexCharts(box, opts);
    charts[id].render();
  }

  const STATUS_BADGE = { confirmed: 'an-pill info', completed: 'an-pill up', cancelled: 'an-pill down',
    checked_in: 'an-pill info', no_show: 'an-pill noshow', pending: 'an-pill warn' };
  const label = (s) => String(s || '—').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

  function renderToday(list, failed, branchScoped) {
    const body = $('#todayBody');
    if (failed) { body.innerHTML = '<tr><td colspan="6" class="text-center py-4"><span class="dash-error"><i class="feather-alert-circle"></i>Couldn\'t load today\'s appointments.</span> ' + retryBtn + '</td></tr>'; return; }
    if (!list.length) { body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><i class="feather-calendar me-2"></i>No appointments scheduled for today' + (branchScoped ? ' in this branch' : '') + '.</td></tr>'; return; }
    body.innerHTML = list.map((a) => '<tr>' +
      '<td><span class="fw-semibold">' + esc(a.code || '—') + '</span></td>' +
      '<td>' + esc(a.time || '—') + '</td>' +
      '<td><a href="patient-records.php?id=' + (a.patient_id | 0) + '" class="text-dark text-decoration-none fw-semibold">' + esc(a.patient || '—') + '</a></td>' +
      '<td>' + esc(a.services || '—') + '</td>' +
      '<td>' + esc(a.branch || '—') + '</td>' +
      '<td><span class="' + (STATUS_BADGE[a.status] || STATUS_BADGE.pending) + '">' + esc(label(a.status)) + '</span></td></tr>').join('');
  }

  function renderAlerts(list, failed, inv) {
    const box = $('#stockAlerts');
    if (failed) { box.innerHTML = '<div class="px-4 py-3"><span class="dash-error"><i class="feather-alert-circle"></i>Couldn\'t load inventory.</span> ' + retryBtn + '</div>'; return; }
    if (!inv.total) { box.innerHTML = '<div class="px-4 py-3 text-muted fs-12">No active inventory items yet.</div>'; return; }
    if (!list.length) { box.innerHTML = '<div class="dash-alert-row"><span class="who"><span class="an-ico sm t-green"><i class="feather-check"></i></span><span class="text-muted">All items are above their minimum stock.</span></span></div>'; return; }
    box.innerHTML = list.map((a) => '<a class="dash-alert-row" href="inventory-stock-movement.php?item=' + (a.item_id | 0) + '">' +
      '<span class="who"><span class="an-ico sm ' + (a.level === 'out' ? 't-red' : 't-amber') + '"><i class="feather-package"></i></span>' +
      '<span class="text-truncate"><span class="fw-semibold text-dark">' + esc(a.name) + '</span>' +
      '<span class="d-block fs-11 text-muted">' + esc(a.code) + (a.branch ? ', ' + esc(a.branch) : '') + '</span></span></span>' +
      '<span class="' + (a.level === 'out' ? 'an-pill down' : 'an-pill warn') + '">' +
      (a.level === 'out' ? 'Out' : a.qty + ' / min ' + a.min) + '</span></a>').join('');
  }

  /** Horizontal bar list (Top Services / Appointments by Branch). */
  function barList(id, list, failed, opts) {
    const box = document.getElementById(id);
    if (!box) return;
    if (failed) { box.innerHTML = '<div class="px-4 py-3"><span class="dash-error"><i class="feather-alert-circle"></i>Couldn&#39;t load ' + opts.what + '.</span> ' + retryBtn + '</div>'; return; }
    if (opts.note) { box.innerHTML = '<div class="px-4 py-3 text-muted fs-12">' + opts.note + '</div>'; return; }
    if (!list.length || !list.some((x) => x.count > 0)) { box.innerHTML = '<div class="px-4 py-3 text-muted fs-12">' + opts.empty + '</div>'; return; }
    const max = Math.max(...list.map((x) => x.count), 1), total = list.reduce((t, x) => t + x.count, 0);
    box.innerHTML = '<ul class="an-barlist">' + list.map((x) => {
      const color = opts.color ? opts.color(x.name) : '--an-hist';
      return '<li><div class="row1"><span class="d-inline-flex align-items-center gap-2">' + (opts.color ? '<span class="an-dot" style="background:var(' + color + ')"></span>' : '') + esc(x.name) + '</span>' +
        '<span><span class="n">' + num(x.count) + '</span> <span class="fs-12 text-muted">' + opts.unit + (x.count === 1 ? '' : 's') + (total ? ', ' + pct(x.count, total) + '%' : '') + '</span></span></div>' +
        '<span class="an-track"><i style="width:' + Math.round((x.count / max) * 100) + '%;background:var(' + color + ')"></i></span></li>';
    }).join('') + '</ul>';
  }

  /** Thin stacked bar from [cssVar, value] parts (empty parts skipped). */
  function prop(parts) {
    const t = parts.reduce((a, p) => a + (p[1] || 0), 0);
    return t ? parts.filter((p) => p[1] > 0).map((p) => '<i style="width:' + (p[1] / t * 100) + '%;background:var(' + p[0] + ')" title="' + num(p[1]) + '"></i>').join('') : '';
  }
  /** Same clinic colours as Analytics: by branch_id order. */
  const BRANCHES = (window.ES_DASH && window.ES_DASH.branches) || {};
  const branchIds = Object.keys(BRANCHES).map(Number).sort((a, b) => a - b);
  function branchColor(name) {
    const id = branchIds.find((b) => BRANCHES[b] === name);
    return id === undefined ? '--an-hist' : '--an-c' + ((branchIds.indexOf(id) % 3) + 1);
  }

  function render(d) {
    last = d;
    const err = d.errors || {};
    const A = d.appointments, P = d.patients, S = d.sales, R = d.revenue, I = d.inventory, T = d.treatments;
    const scoped = d.meta.branch_id > 0;

    // Context line
    set('meta.today', d.meta.today || '');
    set('meta.scope', scoped ? (d.meta.branch_name || 'My Branch') : 'All Branches');

    // Headline cards
    set('today.main', num(A.today)); set('today.sub', num(A.upcoming) + ' upcoming');
    bar('today.bar', pct(A.completed, A.total)); set('today.foot', num(A.total) + ' appointments all time · ' + pct(A.completed, A.total) + '% completed');
    set('patients.main', num(P.total)); set('patients.sub', '+' + num(P.new_month));
    bar('patients.bar', pct(P.new_month, P.total)); set('patients.foot', pct(P.new_month, P.total) + '% of all patients joined in ' + d.meta.month_label);
    set('revenue.main', peso(R.month)); set('revenue.sub', peso(R.week));
    bar('revenue.bar', pct(R.week, R.month)); set('revenue.foot', peso(R.today) + ' collected today');
    set('stock.main', num(I.low + I.out)); set('stock.sub', num(I.low) + ' low · ' + num(I.out) + ' out');
    bar('stock.bar', pct(I.low + I.out, I.total)); set('stock.foot', 'of ' + num(I.total) + ' active items');

    // Appointment overview
    ['total', 'pending', 'confirmed', 'completed', 'cancelled'].forEach((k) => set('apt.' + k, num(A[k])));
    set('apt.rate', pct(A.completed, A.total) + '%'); bar('apt.ratebar', pct(A.completed, A.total));
    set('apt.prop', prop([['--an-c2', A.completed], ['--an-c3', A.confirmed], ['--an-warn', A.pending], ['--an-hist', A.checked_in], ['--an-bad', A.cancelled], ['--an-other', A.no_show]]), true);

    // Sales & revenue
    set('rev.today', peso(R.today)); set('rev.week', peso(R.week)); set('rev.month', peso(R.month)); set('rev.count', num(R.payments_month));
    set('meta.week', '(' + d.meta.week_label + ')');
    set('sales.billed', peso(S.billed)); set('sales.collected', peso(S.collected)); set('sales.outstanding', peso(S.outstanding));
    set('sales.split', num(S.paid) + ' / ' + num(S.partial) + ' / ' + num(S.unpaid));
    set('sales.rate', pct(S.collected, S.billed) + '%'); bar('sales.ratebar', pct(S.collected, S.billed));

    // Inventory, patients, treatments
    set('inv.total', num(I.total)); set('inv.low', num(I.low)); set('inv.out', num(I.out));
    set('inv.prop', prop([['--an-good', I.in_stock], ['--an-warn', I.low], ['--an-bad', I.out]]), true);
    set('pat.total', num(P.total)); set('pat.month', num(P.new_month)); set('pat.week', num(P.new_week)); set('pat.today', num(P.new_today));
    set('trt.active', num(T.active)); set('trt.completed', num(T.completed));
    set('todaybadge', num(A.today) + ' appointment' + (A.today === 1 ? '' : 's'));

    Object.keys(err).forEach(markError);
    renderAppointmentsChart(d.trend, !!err.appointments);
    renderRevenueChart(d.trend, !!err.payments);
    renderToday(d.today_list, !!err.today, scoped);
    renderAlerts(d.stock_alerts, !!err.inventory, I);
    barList('topServices', d.top_services || [], !!err.appointments,
      { what: 'services', unit: 'booking', empty: 'No booking data yet.' });
    barList('byBranch', d.by_branch || [], !!(err.appointments || err.branches),
      { what: 'branches', unit: 'appointment', color: branchColor, empty: 'No appointments yet.',
        note: scoped ? 'Select <b>All Branches</b> to compare branches.' : '' });

    const ge = $('#dashGlobalError');
    if (Object.keys(err).length) { ge.textContent = 'Some figures could not be loaded and are marked with —. Use Refresh to try again.'; ge.classList.remove('d-none'); }
    else ge.classList.add('d-none');
    $('#dashUpdated').textContent = '– Updated ' + d.meta.generated;
  }

  function failAll(message) {
    Object.keys(SECTION_SLOTS).forEach(markError);
    renderAppointmentsChart(null, true); renderRevenueChart(null, true); renderToday([], true); renderAlerts([], true, {});
    barList('topServices', [], true, { what: 'services' }); barList('byBranch', [], true, { what: 'branches' });
    set('todaybadge', '—');
    const ge = $('#dashGlobalError');
    ge.textContent = message; ge.classList.remove('d-none');
    $('#dashUpdated').textContent = '';
  }

  function load() {
    const sel = $('#dashBranch');
    const branch = sel ? sel.value : '0';
    if (ctl) ctl.abort();
    ctl = new AbortController();
    const btn = $('#dashRefresh');
    btn.disabled = true; btn.classList.add('es-btn-busy');
    $('#dashUpdated').textContent = '– Loading…';
    return fetch('api/dashboard_stats.php' + (branch && branch !== '0' ? '?branch=' + encodeURIComponent(branch) : ''), { credentials: 'same-origin', cache: 'no-store', signal: ctl.signal })
      .then((r) => {
        if (r.status === 401) { location.href = 'login.php'; throw new Error('auth'); }
        return r.json().catch(() => ({ success: false, message: 'Unexpected server response.' })).then((j) => ({ ok: r.ok, j }));
      })
      .then(({ ok, j }) => {
        if (!ok || !j.success) throw new Error(j.message || 'Could not load the dashboard.');
        render(j.data);
        // Header branch = the branch the SERVER scoped the figures to
        const m = (j.data && j.data.meta) || {};
        const bn = $('#dashBranchName');
        if (bn) bn.textContent = m.branch_id ? (m.branch_name || 'My Branch') : 'All Branches';
      })
      .catch((e) => { if (e.name !== 'AbortError' && e.message !== 'auth') failAll((e.message || 'Could not load the dashboard.') + ' Please check your connection and press Refresh.'); })
      .finally(() => { btn.disabled = false; btn.classList.remove('es-btn-busy'); });
  }

  document.addEventListener('click', (e) => { if (e.target.closest('[data-dash-retry]')) load(); });
  // Theme switch: chart colours come from CSS variables
  new MutationObserver(() => { if (last) { const err = last.errors || {}; renderAppointmentsChart(last.trend, !!err.appointments); renderRevenueChart(last.trend, !!err.payments); } })
    .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
  document.addEventListener('DOMContentLoaded', () => {
    $('#dashRefresh').addEventListener('click', load);
    const sel = $('#dashBranch');
    if (sel) sel.addEventListener('change', () => {
      const u = new URL(location.href);
      if (sel.value && sel.value !== '0') u.searchParams.set('branch', sel.value); else u.searchParams.delete('branch');
      history.replaceState(null, '', u);
      load();
    });
    load();
  });
})();
