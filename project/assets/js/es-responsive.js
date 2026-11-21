/* =============================================================
   assets/js/es-responsive.js — staff pages, small screens
   Phone card tables: every staff list table (.rec-table, .inv-table,
   .an-table) gets .es-stack and each cell a data-label taken from its
   column header. Below 576px es-admin.css then shows each row as a card:
   first column = card title, row actions top-right, the other cells as
   "LABEL ...... value" lines. Nothing changes on wider screens.
   Re-run after es-ajax.js swaps a list in (es:updated) and when a script
   rebuilds a table body (MutationObserver on the tbody).
   Tables with their own phone layout (.ar-cards-sm) keep it.
   Also: row action menus in these tables open with Popper's fixed strategy,
   so a table that scrolls inside its card never clips them.
   ============================================================= */
(function () {
  "use strict";

  var SELECTOR = ".nxl-container table.rec-table, .nxl-container table.inv-table, .nxl-container table.an-table";

  function headerLabels(table) {
    var row = table.tHead && table.tHead.rows[table.tHead.rows.length - 1];
    if (!row) return null;
    var labels = [];
    for (var i = 0; i < row.cells.length; i++) {
      var th = row.cells[i];
      // Visible text only (sort arrows are icons; "visually-hidden" labels like "Actions" count)
      var text = (th.textContent || "").replace(/\s+/g, " ").trim();
      var span = th.colSpan || 1;
      for (var s = 0; s < span; s++) labels.push({ text: text, action: th.classList.contains("rec-col-action") });
    }
    return labels;
  }

  function isActionCell(td) {
    return !!td.querySelector(".dropdown, .rec-actions, .bil-menu-holder, [data-bs-toggle='dropdown']") &&
      !(td.textContent || "").replace(/\s+/g, "").length;
  }

  function label(table) {
    if (table.classList.contains("ar-cards-sm")) return;
    var labels = headerLabels(table);
    if (!labels || !labels.length) return;
    table.classList.add("es-stack");
    var rows = table.tBodies.length ? table.tBodies[0].rows : [];
    for (var r = 0; r < rows.length; r++) {
      var cells = rows[r].cells;
      if (cells.length === 1 && cells[0].colSpan > 1) { cells[0].classList.add("es-stack-full"); continue; }   // empty / error state
      var col = 0, first = true;
      for (var c = 0; c < cells.length; c++) {
        var td = cells[c], h = labels[col] || { text: "" };
        col += td.colSpan || 1;
        if (td.offsetParent === null && getComputedStyle(td).display === "none") continue;
        td.classList.remove("es-stack-primary", "es-stack-act", "es-stack-blank");
        if (h.action || isActionCell(td)) { td.classList.add("es-stack-act"); td.removeAttribute("data-label"); continue; }
        if (first) { td.classList.add("es-stack-primary"); td.removeAttribute("data-label"); first = false; continue; }
        if (h.text) td.setAttribute("data-label", h.text); else td.removeAttribute("data-label");
        // A cell that only says "—" (or nothing) is left out of the phone card
        td.classList.toggle("es-stack-blank", /^[\s—–-]*$/.test(td.textContent || "") && !td.querySelector("img, svg, input, button, .badge, .progress"));
      }
    }
  }

  var observed = typeof WeakSet === "function" ? new WeakSet() : null;
  function watch(table) {
    if (!observed || observed.has(table) || !window.MutationObserver || !table.tBodies.length) return;
    observed.add(table);
    var t = null;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { label(table); floatMenus(table); }, 30); })
      .observe(table.tBodies[0], { childList: true });
  }

  // Row action menus: tables scroll inside their card on tablets / laptops, and an
  // absolutely positioned menu would be clipped by that scroll box. Bootstrap 5.3
  // ignores data-bs-strategy and can't read an object from a data attribute, so each
  // table menu's Dropdown is created here, before its first click, with Popper's
  // fixed strategy (Bootstrap merges this with the element's other data-bs-* options).
  var FIXED = { popperConfig: function (cfg) { return Object.assign({}, cfg, { strategy: "fixed" }); } };
  function floatMenus(table) {
    if (!window.bootstrap || !bootstrap.Dropdown) return;
    table.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (t) {
      if (t._esFixed || bootstrap.Dropdown.getInstance(t)) return;
      try { bootstrap.Dropdown.getOrCreateInstance(t, FIXED); t._esFixed = true; } catch (e) { /* leave Bootstrap's default */ }
    });
  }

  function run(root) {
    (root || document).querySelectorAll(SELECTOR).forEach(function (table) { label(table); floatMenus(table); watch(table); });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { run(); });
  else run();
  document.addEventListener("es:updated", function () { run(); });
  window.esStackTables = run;   // for scripts that build a list table themselves
})();
