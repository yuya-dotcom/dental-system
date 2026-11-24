/* =============================================================
   Instant page skeletons for staff navigation.
   Loaded by partials/sidebar.php (every Admin / Owner / Dentist page);
   styles in assets/css/es-skeleton.css.

   Staff pages are server-rendered: the browser cannot show the next page
   until PHP has fetched its data. So instead of leaving the old page on
   screen with only a thin bar, a click on a sidebar link (or any other link
   to a staff page) immediately swaps the header + content for a skeleton
   of the page being opened (real title and breadcrumb, then cards, stat
   tiles, table rows, charts …). The normal navigation carries on
   untouched: same URL, same server render, same scripts, caches and API
   calls. When the new page arrives it fades in over the skeleton.

   Layout fidelity
   - PAGES below describes each page's layout (first visit).
   - Each time a staff page is left, its real geometry is measured (card
     heights, rows shown, row height, column widths / kinds, header
     buttons) and kept in localStorage per page and per screen size. The
     next skeleton for that page uses those numbers, so the real content
     replaces it with little or no layout shift. Only sizes are stored,
     never text or record data.

   Safety
   - Modified clicks (new tab), downloads, target=_blank, Sign Out, links
     inside modals and links already handled by another script
     (preventDefault) are left alone.
   - A page that asks "Leave site?" (unsaved edits) never gets stuck on a
     skeleton: if the leave prompt is shown, the page is restored.
   - Esc (stops loading) and Back/Forward (bfcache) restore the page.
   ============================================================= */
(function () {
  'use strict';

  var doc = document, html = doc.documentElement;
  var STORE = 'esSk:v1:';
  var ARRIVE = 'esSk:arrive';

  // ── Page layouts ─────────────────────────────────────────────
  // t: page title, c: breadcrumb after "Home", a: header button widths (px)
  // L: layout. list → o.stats (stat tiles), o.chips (status chips),
  //    o.tabs, o.pill (count pill next to the card title), o.cols:
  //    when = date + time · who = avatar + 2 lines · two = 2 lines
  //    txt = 1 line · num = right-aligned figure · pill = status · act = ⋮
  var PAGES = {
    'dashboard.php':                { t: 'Dashboard', c: ['Dashboard'], a: [150, 170, 92], L: 'dashboard' },
    'analytics.php':                { t: 'Analytics', c: ['Analytics'], a: [92], L: 'analytics' },
    'appointments-records.php':     { t: 'Appointments', c: ['Records'], a: [150], L: 'list', o: { pill: 1, chips: 7, cols: ['when', 'who', 'txt', 'pill', 'act'] } },
    'appointments-schedule.php':    { t: 'Appointments', c: ['Schedules'], a: [160], L: 'list', o: { cols: ['when', 'who', 'txt', 'txt', 'pill', 'act'] } },
    'treatments-records.php':       { t: 'Treatments', c: ['Records'], a: [], L: 'list', o: { cols: ['when', 'who', 'txt', 'txt', 'pill', 'act'] } },
    'treatments-active.php':        { t: 'Treatments', c: ['Active'], a: [], L: 'list', o: { cols: ['when', 'who', 'two', 'pill', 'act'] } },
    'patients-list.php':            { t: 'Patients', c: ['List'], a: [], L: 'list', o: { cols: ['when', 'who', 'txt', 'pill', 'act'] } },
    'patient-records.php':          { t: 'Patient Record', c: ['Patients', 'Record'], a: [70, 90], L: 'profile' },
    'billing-records.php':          { t: 'Billing', c: ['Records'], a: [], L: 'list', o: { cols: ['when', 'who', 'num', 'num', 'num', 'pill', 'act'] } },
    'inventory-records.php':        { t: 'Inventory', c: ['Records'], a: [110, 120], L: 'list', o: { stats: 5, cols: ['who', 'txt', 'txt', 'num', 'num', 'txt', 'pill', 'txt', 'act'] } },
    'inventory-stock-movement.php': { t: 'Inventory', c: ['Inventory', 'Stock Movement'], a: [120], L: 'list', o: { cols: ['when', 'two', 'txt', 'pill', 'num', 'num', 'two', 'txt'] } },
    'inventory-transfers.php':      { t: 'Inventory', c: ['Inventory', 'Stock Transfers'], a: [130], L: 'list', o: { stats: 4, cols: ['two', 'txt', 'num', 'txt', 'pill', 'txt', 'act'] } },
    'inventory-procurement.php':    { t: 'Inventory', c: ['Inventory', 'Procurement'], a: [110, 110, 130], L: 'list', o: { stats: 2, tabs: 3, cols: ['two', 'txt', 'num', 'txt', 'txt', 'pill', 'act'] } },
    'service-records.php':          { t: 'Service Catalog', c: ['Service Catalog'], a: [120], L: 'split' },
    'service-materials.php':        { t: 'Service Catalog', c: ['Service Materials'], a: [], L: 'list', o: { cols: ['two', 'txt', 'num', 'txt', 'pill', 'act'] } },
    'service-pricing.php':          { t: 'Service Catalog', c: ['Service Pricing'], a: [120], L: 'list', o: { cols: ['two', 'txt', 'num', 'pill', 'act'] } },
    'discount-vouchers.php':        { t: 'Discount Vouchers', c: ['Discount Vouchers'], a: [130], L: 'list', o: { cols: ['two', 'txt', 'txt', 'num', 'txt', 'txt', 'pill', 'act'] } },
    'dentist-records.php':          { t: 'Dentists', c: ['Records'], a: [], L: 'list', o: { stats: 3, cols: ['who', 'txt', 'txt', 'txt', 'pill', 'act'] } },
    'branches-records.php':         { t: 'Branches', c: ['Records'], a: [120], L: 'list', o: { stats: 3, cols: ['txt', 'who', 'two', 'two', 'pill', 'act'] } },
    'accounts-records.php':         { t: 'Accounts', c: ['Records'], a: [120], L: 'list', o: { stats: 3, cols: ['who', 'txt', 'pill', 'txt', 'pill', 'act'] } },
    'feedback-records.php':         { t: 'Patient Feedback', c: ['Feedback'], a: [], L: 'feedback' },
    'audit-records.php':            { t: 'Audit Trail', c: ['Audit Trail'], a: [], L: 'list', o: { tabs: 2, cols: ['when', 'who', 'txt', 'txt', 'pill', 'act'] } },
    'notification-center.php':      { t: 'Notifications', c: ['Notifications'], a: [], L: 'feed' },
    'notification-records.php':     { t: 'Notifications', c: ['Notifications'], a: [], L: 'list', o: { stats: 4, cols: ['when', 'txt', 'who', 'txt', 'txt', 'pill', 'act'] } },
    'settings.php':                 { t: 'Settings', c: ['Settings'], a: [], L: 'settings' }
  };

  // ── Small helpers ────────────────────────────────────────────
  function px(v) { return typeof v === 'number' ? v + 'px' : v; }
  /** One shimmer block. */
  function sk(w, h, cls) { return '<span class="sk' + (cls ? ' ' + cls : '') + '" style="width:' + px(w) + ';height:' + h + 'px"></span>'; }
  /** Stable "random" width so rows look natural but never jump between renders. */
  function vw(i, min, max) { var x = Math.sin(i * 12.9898 + 4.1) * 43758.5453; x -= Math.floor(x); return Math.round(min + (max - min) * x) + '%'; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function phone() { return window.innerWidth < 768; }
  function bucket() { return phone() ? 'm' : 'd'; }
  function fileOf(path) { var f = String(path).split('/').pop(); return f || 'index.php'; }
  function visible(n) { return !!(n && n.offsetParent !== null && n.getClientRects().length); }
  function load(file) { try { return JSON.parse(localStorage.getItem(STORE + file + ':' + bucket()) || 'null'); } catch (e) { return null; } }
  function save(file, rec) { try { localStorage.setItem(STORE + file + ':' + bucket(), JSON.stringify(rec)); } catch (e) { /* storage full / blocked: skeleton uses defaults */ } }

  // Same card classes as the real pages: cards in one row share the row's height
  function card(inner, cls) { return '<div class="card' + (cls === 'mb-3' ? ' mb-3' : ' stretch stretch-full' + (cls ? ' ' + cls : '')) + '">' + inner + '</div>'; }
  function cardHead(titleW, right) {
    return '<div class="card-header"><div class="sk-hd-l">' + sk(titleW || 170, 16) + '</div><div class="sk-hd-r">' + (right || '') + '</div></div>';
  }
  function row(cols) { return '<div class="row">' + cols.join('') + '</div>'; }
  function col(cls, inner) { return '<div class="' + cls + '">' + inner + '</div>'; }
  function lines(i, a, b) { return '<div style="display:flex;flex-direction:column;gap:8px">' + sk(vw(i, a[0], a[1]), 12) + sk(vw(i + 7, b[0], b[1]), 10) + '</div>'; }

  // ── Building blocks ──────────────────────────────────────────
  var WEIGHT = { when: 1.3, who: 1.8, two: 1.5, txt: 1.1, num: 0.9, pill: 1, act: 0.55 };

  function cell(kind, i) {
    switch (kind) {
      case 'when': return '<div class="sk-cell">' + lines(i, [55, 80], [35, 55]) + '</div>';
      case 'who':  return '<div class="sk-cell">' + sk(34, 34, 'c') + lines(i, [50, 85], [30, 55]) + '</div>';
      case 'two':  return '<div class="sk-cell">' + lines(i, [55, 90], [35, 60]) + '</div>';
      case 'num':  return '<div class="sk-cell r"><div>' + sk(vw(i, 45, 75), 12) + '</div></div>';
      case 'pill': return '<div class="sk-cell">' + sk(vw(i, 55, 80), 22, 'pill') + '</div>';
      case 'act':  return '<div class="sk-cell r"><div>' + sk(30, 30, 'r8') + '</div></div>';
      default:     return '<div class="sk-cell"><div>' + sk(vw(i, 45, 85), 12) + '</div></div>';
    }
  }

  function tableRows(kinds, widths, n, rh, th, seed) {
    var h = '<table class="sk-table"><colgroup>';
    widths.forEach(function (w) { h += '<col style="width:' + (w * 100).toFixed(2) + '%">'; });
    h += '</colgroup><thead><tr' + (th ? ' style="height:' + th + 'px"' : '') + '>';
    kinds.forEach(function (k, j) {
      h += '<th>' + (k === 'act' ? '' : '<div class="sk-cell' + (k === 'num' ? ' r' : '') + '"><div>' + sk(vw(seed + j, 40, 70), 10) + '</div></div>') + '</th>';
    });
    h += '</tr></thead><tbody>';
    for (var r = 0; r < n; r++) {
      h += '<tr' + (rh ? ' style="height:' + rh + 'px"' : '') + '>';
      kinds.forEach(function (k, j) { h += '<td>' + cell(k, seed + r * 11 + j * 3) + '</td>'; });
      h += '</tr>';
    }
    return h + '</tbody></table>';
  }

  /** Phone lists are cards (assets/js/es-responsive.js). */
  function phoneRows(n, rh) {
    var h = '<div class="sk-cards">';
    for (var r = 0; r < n; r++) {
      h += '<div' + (rh ? ' style="min-height:' + rh + 'px"' : '') + '>' +
        '<div class="sk-cell">' + sk(34, 34, 'c') + lines(r, [45, 70], [30, 50]) + '</div>' +
        sk(vw(r, 50, 80), 11) + sk(vw(r + 3, 35, 60), 11) + '<div class="sk-cell">' + sk(78, 22, 'pill') + '</div></div>';
    }
    return h + '</div>';
  }

  function statTiles(n) {
    var h = '<div class="sk-stats" style="--n:' + n + '">';
    for (var i = 0; i < n; i++) h += '<div class="sk-stat">' + sk(38, 38, 'r8') + '<div style="flex:1;display:flex;flex-direction:column;gap:7px">' + sk('45%', 16) + sk(vw(i, 55, 85), 10) + '</div></div>';
    return h + '</div>';
  }

  function chips(n, base, min, max) {
    var h = '<div class="sk-row">';
    for (var i = 0; i < n; i++) h += sk(Math.round(min + (max - min) * ((Math.sin((base + i) * 7.3) + 1) / 2)), 32, 'pill');
    return h + '</div>';
  }

  function chart(h, bars) {
    var o = '<div class="sk-chart" style="--h:' + h + 'px">';
    for (var i = 0; i < bars; i++) o += '<span class="sk" style="height:' + vw(i + 2, 25, 92) + '"></span>';
    return o + '</div>';
  }

  function listRows(n, seed, right) {
    var o = '<div class="sk-list">';
    for (var i = 0; i < n; i++) {
      o += '<div><div class="sk-who">' + sk(32, 32, 'c') + lines(seed + i, [40, 75], [25, 50]) + '</div>' +
        (right === false ? '' : sk(right || 44, 14)) + '</div>';
    }
    return o + '</div>';
  }

  // ── Layouts ──────────────────────────────────────────────────
  var LAYOUTS = {
    list: function (o, cal) {
      var d = (cal && cal.L) || {};
      var kinds = d.ck && d.ck.length ? d.ck : o.cols;
      var widths = d.cw && d.cw.length === kinds.length ? d.cw : (function () {
        var sum = kinds.reduce(function (s, k) { return s + (WEIGHT[k] || 1); }, 0);
        return kinds.map(function (k) { return (WEIGHT[k] || 1) / sum; });
      })();
      var n = d.n != null ? d.n : 10;
      var stats = d.st != null ? d.st : (o.stats || 0);
      var chipN = d.ch != null ? d.ch : (o.chips || 0);
      var tabs = d.tb != null ? d.tb : (o.tabs || 0);

      var pre = '';
      if (stats) pre += statTiles(stats);
      if (tabs) { pre += '<div class="sk-tabs">'; for (var i = 0; i < tabs; i++) pre += sk(vw(i, 70, 110).replace('%', 'px'), 14); pre += '</div>'; }
      if (chipN) pre += chips(chipN, 1, 64, 104);
      pre += '<div class="sk-row">' + sk(126, 32, 'r8') + '</div>';           // Show Filters toggle

      var body = phone() ? phoneRows(Math.min(n, 6), d.rh) : tableRows(kinds, widths, Math.max(n, 1), d.rh, d.th, 3);
      var foot = '<div class="card-footer"' + (d.ft ? ' style="min-height:' + d.ft + 'px"' : '') + '>' + sk(160, 12) +
        '<div class="sk-hd-r">' + sk(32, 32, 'r8') + sk(32, 32, 'r8') + sk(32, 32, 'r8') + '</div></div>';
      var head = '<div class="card-header"' + (d.hh ? ' style="min-height:' + d.hh + 'px"' : '') + '><div class="sk-hd-l">' + sk(180, 16) +
        (o.pill ? sk(70, 22, 'pill') : '') + '</div><div class="sk-hd-r">' + sk(22, 22, 'c') + sk(22, 22, 'c') + '</div></div>';

      return row([col('col-lg-12', card(head +
        '<div class="sk-pre"' + (d.pre ? ' style="min-height:' + d.pre + 'px"' : '') + '>' + pre + '</div>' +
        '<div class="card-body p-0">' + body + '</div>' + (d.ft === 0 ? '' : foot)))]);
    },

    dashboard: function () {
      var kpi = card('<div class="card-body"><div class="sk-kpi-top">' + sk('55%', 13) + sk(38, 38, 'r8') + '</div>' +
        sk('40%', 26) + '<div class="sk-kpi-top">' + sk('45%', 10) + sk(48, 20, 'pill') + '</div>' + sk('100%', 3) + sk('35%', 10) + '</div>', 'sk-kpi');
      var legend = sk(56, 10) + sk(70, 10) + sk(64, 10);
      var kv = '<div class="sk-list">';
      for (var i = 0; i < 8; i++) kv += '<div>' + sk(vw(i, 35, 60), 12) + sk(vw(i + 2, 14, 24), 14) + '</div>';
      kv += '</div>';
      return '<div class="sk-bar">' + sk(320, 14) + sk(200, 12, 'sk-hide-sm') + '</div>' +
        row([1, 2, 3, 4].map(function () { return col('col-xxl-3 col-md-6', kpi); })) +
        row([col('col-xxl-8', card(cardHead(230, legend) + '<div class="card-body pb-2">' + chart(300, 6) + '</div>')),
             col('col-xxl-4', card(cardHead(200) + '<div class="px-4 py-3">' + sk('100%', 10, 'pill') + '</div>' + listRows(5, 1)))]) +
        row([col('col-xxl-6', card(cardHead(180) + listRows(5, 9, 60))), col('col-xxl-6', card(cardHead(200) + listRows(5, 15, 60)))]) +
        row([col('col-xxl-8', card(cardHead(170, legend) + '<div class="card-body pb-2">' + chart(300, 6) + '</div>')),
             col('col-xxl-4', card(cardHead(140) + kv))]) +
        row([col('col-xxl-8', card(cardHead(150, sk(60, 12)) + tableRows(['txt', 'txt', 'who', 'txt', 'txt', 'pill'], [.13, .12, .24, .2, .15, .16], 4, 54, 44, 21))),
             col('col-xxl-4', card(cardHead(160) + listRows(5, 30, 54)))]) +
        row([col('col-12', card(cardHead(180) + '<div class="sk-tiles">' + [1, 2, 3, 4, 5, 6].map(function (i) {
          return '<div>' + sk(38, 38, 'r8') + '<div style="flex:1;display:flex;flex-direction:column;gap:7px">' + sk('40%', 16) + sk(vw(i, 55, 85), 10) + '</div></div>';
        }).join('') + '</div>'))]);
    },

    analytics: function () {
      var kpi = card('<div class="card-body"><div class="sk-kpi-top">' + sk('55%', 13) + sk(38, 38, 'r8') + '</div>' + sk('40%', 26) + sk('70%', 10) + sk('100%', 3) + '</div>', 'sk-kpi');
      return '<div class="sk-bar">' + chips(5, 2, 92, 128) + sk(240, 12, 'sk-hide-sm') + '</div>' +
        row([1, 2, 3, 4].map(function () { return col('col-xxl-3 col-md-6', kpi); })) +
        row([col('col-xxl-8', card(cardHead(220) + '<div class="card-body pb-2">' + chart(300, 8) + '</div>')),
             col('col-xxl-4', card(cardHead(180) + listRows(6, 4, 50)))]) +
        row([col('col-xxl-6', card(cardHead(200) + '<div class="card-body pb-2">' + chart(240, 6) + '</div>')),
             col('col-xxl-6', card(cardHead(200) + '<div class="card-body pb-2">' + chart(240, 6) + '</div>'))]);
    },

    feedback: function () {
      var bars = '<div class="card-body" style="display:flex;flex-direction:column;gap:16px">';
      for (var i = 0; i < 5; i++) bars += '<div class="sk-cell">' + sk(46, 12) + '<div>' + sk(vw(i, 20, 95), 10, 'pill') + '</div>' + sk(30, 12) + '</div>';
      bars += '</div>';
      return card('<div class="card-body">' + statTiles(4) + '</div>') +
        row([col('col-xxl-4 col-lg-5', card(cardHead(170) + bars)),
             col('col-xxl-8 col-lg-7', card(cardHead(160) + tableRows(['who', 'txt', 'num', 'txt'], [.4, .25, .15, .2], 6, 58, 44, 5)))]);
    },

    feed: function () {
      var items = '<div class="sk-list">';
      for (var i = 0; i < 8; i++) {
        items += '<div><div class="sk-who">' + sk(36, 36, 'c') + lines(i, [45, 80], [55, 90]) + '</div>' + sk(54, 10) + '</div>';
      }
      items += '</div>';
      return row([col('col-lg-12', card(cardHead(190, sk(110, 32, 'r8')) +
        '<div class="sk-pre">' + chips(5, 3, 70, 110) + '</div>' + items +
        '<div class="card-footer">' + sk(150, 12) + '<div class="sk-hd-r">' + sk(32, 32, 'r8') + sk(32, 32, 'r8') + '</div></div>'))]);
    },

    settings: function () {
      var side = '<div class="sk-side">';
      for (var i = 0; i < 6; i++) side += sk('100%', 34);
      side += '</div>';
      var form = '<div class="sk-form">';
      for (var j = 0; j < 6; j++) form += '<div>' + sk(vw(j, 25, 45), 11) + sk('100%', 40, 'r8') + '</div>';
      form += '</div>';
      return row([col('col-lg-12', card(cardHead(120) +
        '<div class="sk-split"><div>' + side + '</div><div class="card-body" style="display:flex;flex-direction:column;gap:22px">' +
        sk(200, 16) + statTiles(4) + sk(170, 16) + form + '</div></div>'))]);
    },

    split: function () {
      var form = '<div class="sk-form">';
      for (var j = 0; j < 6; j++) form += '<div>' + sk(vw(j, 25, 45), 11) + sk('100%', 40, 'r8') + '</div>';
      form += '</div>';
      return row([
        col('col-lg-4', card(cardHead(100, sk(28, 28, 'r8')) + '<div class="px-4 py-3">' + sk('100%', 38, 'r8') + '</div>' + listRows(8, 6, 40))),
        col('col-lg-8', card(cardHead(220, sk(90, 32, 'r8')) + '<div class="sk-pre"><div class="sk-tabs">' + sk(80, 14) + sk(70, 14) + sk(90, 14) + sk(70, 14) + '</div></div>' +
          '<div class="card-body">' + form + '</div>'))]);
    },

    profile: function () {
      var head = card('<div class="card-body"><div class="sk-cell">' + sk(64, 64, 'c') +
        '<div style="display:flex;flex-direction:column;gap:10px">' + sk('35%', 20) + sk('22%', 12) + '<div class="sk-row">' + sk(80, 22, 'pill') + sk(110, 22, 'pill') + sk(90, 22, 'pill') + '</div></div></div></div>', 'mb-3');
      var tabs = '<div class="sk-pre"><div class="sk-tabs">';
      for (var i = 0; i < 6; i++) tabs += sk(vw(i, 60, 100).replace('%', 'px'), 14);
      tabs += '</div>' + statTiles(3) + '</div>';
      return head + card(tabs + '<div class="row g-0">' +
        col('col-md-6', '<div class="card-body">' + sk(150, 14) + '</div>' + listRows(4, 2, 60)) +
        col('col-md-6', '<div class="card-body">' + sk(170, 14) + '</div>' + listRows(4, 9, 60)) + '</div>');
    }
  };

  // ── Measuring the real page (calibration) ────────────────────
  function cardsOf(block) {
    if (block.classList.contains('card')) return [block];
    return [].slice.call(block.querySelectorAll(':scope > [class*="col"] > .card')).filter(visible);
  }

  function kindOf(td, last) {
    if (td.colSpan > 1) return null;
    if (td.querySelector('[class*="avatar"], img')) return 'who';
    if (last && td.querySelector('button, .dropdown, [class*="menu"], [class*="act"]')) return 'act';
    if (td.querySelector('.an-pill, .badge, [class*="pill"], [class*="badge"], [class*="status"]') && (td.textContent || '').trim().length < 28) return 'pill';
    var align = getComputedStyle(td).textAlign;
    if (align === 'right' || align === 'end') return 'num';
    var blocks = [].slice.call(td.querySelectorAll('div, small, span.d-block, .text-muted')).filter(function (n) { return (n.textContent || '').trim() && !n.querySelector('div'); });
    if (blocks.length >= 2) return 'two';
    return 'txt';
  }

  function measureList(mc, prev) {
    var table = [].slice.call(mc.querySelectorAll('table')).filter(function (t) {
      return visible(t) && !t.closest('.modal, details') && t.tBodies.length;
    })[0];
    if (!table) return prev || null;
    var cardEl = table.closest('.card');
    var d = {};
    if (cardEl) {
      var hd = cardEl.querySelector(':scope > .card-header');
      var ft = cardEl.querySelector(':scope > .card-footer');
      if (hd) d.hh = hd.offsetHeight;
      d.ft = ft && visible(ft) ? ft.offsetHeight : 0;
      if (hd) d.pre = Math.max(0, Math.round(table.getBoundingClientRect().top - hd.getBoundingClientRect().bottom));
      d.st = [].slice.call(cardEl.querySelectorAll('.rec-stat')).filter(visible).length;
      var q = cardEl.querySelector('.ar-quick');
      d.ch = q && visible(q) ? q.querySelectorAll('button').length : 0;
      var tabs = cardEl.querySelector('.nav-tabs');
      d.tb = tabs && visible(tabs) ? tabs.querySelectorAll('.nav-link').length : 0;
    }
    var thead = table.tHead;
    if (thead && visible(thead)) d.th = thead.offsetHeight;
    var rows = [].slice.call(table.tBodies[0].rows).filter(visible);
    var data = rows.filter(function (r) { return !(r.cells.length === 1 && r.cells[0].colSpan > 1); });
    if (!data.length) {                  // empty list: keep what we knew about its rows
      if (prev) { d.n = prev.n; d.rh = prev.rh; d.ck = prev.ck; d.cw = prev.cw; }
      return d;
    }
    d.n = Math.min(data.length, 10);
    var sample = data.slice(0, d.n);
    d.rh = Math.round(sample.reduce(function (s, r) { return s + r.offsetHeight; }, 0) / sample.length);
    if (!phone() && thead) {
      var ths = [].slice.call(thead.rows[0] ? thead.rows[0].cells : []);
      var cells = [].slice.call(data[0].cells);
      if (ths.length && ths.length === cells.length) {
        var tw = table.offsetWidth || 1;
        d.cw = ths.map(function (th) { return Math.round(th.offsetWidth / tw * 1000) / 1000; });
        d.ck = cells.map(function (td, i) { return kindOf(td, i === cells.length - 1) || 'txt'; });
      }
    } else if (prev) { d.cw = prev.cw; d.ck = prev.ck; }
    return d;
  }

  function measure() {
    if (html.classList.contains('es-sk-nav')) return;          // a skeleton is on screen
    var file = fileOf(location.pathname), spec = PAGES[file];
    if (!spec) return;
    var content = doc.querySelector('.nxl-content');
    var mc = content && content.querySelector(':scope > .main-content');
    if (!mc || !visible(mc)) return;
    try {
      var prev = load(file) || {};
      var rec = { bw: mc.clientWidth };
      var hdr = content.querySelector('.page-header-right-items-wrapper');
      if (hdr) {
        rec.hb = [].slice.call(hdr.children).filter(visible).slice(0, 4).map(function (n) { return n.offsetWidth; });
      }
      rec.bl = [].slice.call(mc.children).filter(visible).map(function (b) {
        return { h: b.offsetHeight, c: cardsOf(b).map(function (c) { return c.offsetHeight; }) };
      });
      if (spec.L === 'list') rec.L = measureList(mc, prev.L);
      save(file, rec);
    } catch (e) { /* never let measuring break the page */ }
  }

  function applyBlocks(mc, cal, width) {
    if (!cal || !cal.bl || !cal.bw || Math.abs(cal.bw - width) > 40) return;
    var blocks = [].slice.call(mc.children);
    if (blocks.length !== cal.bl.length) return;
    blocks.forEach(function (b, i) {
      var r = cal.bl[i], cs = cardsOf(b);
      if (cs.length && cs.length === r.c.length) cs.forEach(function (c, j) { c.style.minHeight = r.c[j] + 'px'; });
      else if (!cs.length && !r.c.length) b.style.minHeight = r.h + 'px';
    });
  }

  // ── Showing / removing the skeleton ──────────────────────────
  var shown = null;   // { node, active: [li, …] }

  function build(file, spec, cal) {
    var crumbs = '<ul class="breadcrumb"><li class="breadcrumb-item">Home</li>' +
      spec.c.map(function (c) { return '<li class="breadcrumb-item">' + esc(c) + '</li>'; }).join('') + '</ul>';
    var btns = (cal && cal.hb) || spec.a || [];
    var right = btns.length ? '<div class="page-header-right ms-auto"><div class="page-header-right-items"><div class="page-header-right-items-wrapper">' +
      btns.map(function (w) { return sk(w, 36, 'btn-ph'); }).join('') + '</div></div></div>' : '';
    var node = doc.createElement('div');
    node.className = 'es-sk';
    node.setAttribute('role', 'status');
    node.setAttribute('aria-busy', 'true');
    node.setAttribute('aria-label', 'Loading ' + spec.t);
    node.innerHTML = '<div class="page-header"><div class="page-header-left d-flex align-items-center">' +
      '<div class="page-header-title"><h5 class="m-b-10">' + esc(spec.t) + '</h5></div>' + crumbs + '</div>' + right + '</div>' +
      '<div class="main-content">' + LAYOUTS[spec.L](spec.o || {}, cal) + '</div>';
    return node;
  }

  function markActive(file) {
    var nav = doc.querySelector('.nxl-navigation');
    if (!nav) return [];
    var before = [].slice.call(nav.querySelectorAll('.nxl-item.active'));
    var link = [].slice.call(nav.querySelectorAll('a.nxl-link[href]')).filter(function (a) {
      try { return fileOf(new URL(a.href, location.href).pathname) === file; } catch (e) { return false; }
    })[0];
    if (!link) return [];
    before.forEach(function (li) { li.classList.remove('active'); });
    var li = link.closest('.nxl-item'), group = li && li.parentElement && li.parentElement.closest('.nxl-item');
    if (li) li.classList.add('active');
    if (group) group.classList.add('active');
    return before;
  }

  function show(file, spec) {
    var content = doc.querySelector('.nxl-content');
    if (!content) return;
    var oldMc = content.querySelector(':scope > .main-content');
    var width = oldMc ? oldMc.clientWidth : content.clientWidth;
    restore();
    var cal = load(file);
    var node = build(file, spec, cal);
    content.insertBefore(node, content.firstChild);
    html.classList.add('es-sk-nav');
    applyBlocks(node.querySelector('.main-content'), cal, width);
    shown = { node: node, active: markActive(file) };

    // Phone: close the off-canvas menu so the skeleton is visible
    var nav = doc.querySelector('.nxl-navigation');
    if (nav) nav.classList.remove('mob-navigation-active');
    [].slice.call(doc.querySelectorAll('.nxl-menu-overlay')).forEach(function (o) { o.remove(); });
    window.scrollTo(0, 0);
    try { sessionStorage.setItem(ARRIVE, String(Date.now())); } catch (e) { /* fade-in is optional */ }
  }

  function restore() {
    if (!shown) return;
    var s = shown; shown = null;
    if (s.node.parentNode) s.node.parentNode.removeChild(s.node);
    html.classList.remove('es-sk-nav');
    var nav = doc.querySelector('.nxl-navigation');
    if (nav && s.active.length) {
      [].slice.call(nav.querySelectorAll('.nxl-item.active')).forEach(function (li) { li.classList.remove('active'); });
      s.active.forEach(function (li) { li.classList.add('active'); });
    }
    try { sessionStorage.removeItem(ARRIVE); } catch (e) { /* ignore */ }
  }

  // ── Click → skeleton → the normal navigation ─────────────────
  function onClick(e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-signout-confirm') || a.hasAttribute('data-no-sk')) return;
    var inNav = !!a.closest('.nxl-navigation, .es-nav-flyout');
    if (!inNav && (!a.closest('.nxl-header, .nxl-container') || a.closest('.modal, .swal2-container'))) return;
    var url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (url.origin !== location.origin) return;
    var file = fileOf(url.pathname), spec = PAGES[file];
    if (!spec) return;
    var samePage = url.pathname === location.pathname;
    if (samePage && url.hash && url.search === location.search) return;           // in-page anchor
    if (!inNav && (samePage || /export|download|print|csv|pdf/i.test(url.search))) return;

    measure();

    // A page with unsaved edits asks "Leave site?" (beforeunload). This
    // listener is added now, so it runs after the page's own ones.
    var blocked = false;
    function onLeave(ev) {
      if (ev.defaultPrevented) { blocked = true; setTimeout(restore, 0); }
    }
    window.addEventListener('beforeunload', onLeave);
    setTimeout(function () {
      window.removeEventListener('beforeunload', onLeave);
      if (e.defaultPrevented || blocked) return;   // another script took the click, or the leave prompt showed
      show(file, spec);
    }, 0);
  }

  // Capture phase: the theme's menu script (vendors.min.js menuclick) stops
  // propagation on every submenu <li>, so a bubbling listener never sees
  // sidebar submenu clicks. Whether another script cancels the click is
  // still checked afterwards (setTimeout in onClick).
  doc.addEventListener('click', onClick, true);
  doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && shown) restore(); });
  window.addEventListener('pageshow', function (e) { if (e.persisted) restore(); });
  // Keep the measurements fresh for pages left by other means (forms, Back …)
  window.addEventListener('pagehide', measure);
  doc.addEventListener('visibilitychange', function () { if (doc.visibilityState === 'hidden') measure(); });

  window.esSkeleton = { show: function (file) { var s = PAGES[file]; if (s) show(file, s); }, restore: restore, measure: measure, pages: PAGES };
})();
