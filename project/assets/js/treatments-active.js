/* =============================================================
   EssenciaSmile — Active Treatments JS
   File: assets/js/treatments-active.js
   ============================================================= */

let _workData            = {};
let _workMaterials       = [];
let _inventoryItems      = [];
let _invItemSelectedId   = null;
let _invItemSelectedData = null;

// ── Tooth-centered procedure plan ─────────────────────────────
// A plan line = one procedure (service) on one tooth, or a general
// procedure with no tooth. Lines are PLANNED while this modal is open and
// are saved as completed treatment_procedures only when the treatment is
// finished. Choosing a tooth or procedure never changes the dental chart.
let _bookedSvcs = [];    // appointment's booked services [{service_id, service_name, base_price, requires_tooth_selection, chart_outcome}]
let _catalog    = null;  // active services (lazy) for "Other procedure"
let _plan       = [];    // [{service_id, service_name, unit_price, tooth, surfaces[], requires_tooth, chart_outcome, booked}]
let _focusTooth = null;  // tooth shown in the tooth panel
let _pickerOpen = null;  // 'tooth' | 'general' | null

// ── Materials derived from the plan (service materials × lines) ──
let _svcMats    = {};    // service_id → [{item_id, item_name, item_code, stock, quantity}]
let _matManual  = {};    // item_id → material added by hand
let _matEdited  = {};    // item_id → qty typed by hand
let _matRemoved = new Set();
let _matLocked  = false; // saved treatment_materials exist → keep them as-is

// ── Clinical findings recorded during this treatment (saved on finish)
let _findings = [];      // [{ tooth, surface, condition, notes }]

function _twEsc(v) {
  return String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}
function _peso(n) {
  return "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
const _O = () => window.Odontogram || { COND_LABEL: {}, SURF_NAME: {}, condLabel: (c) => c, badge: () => "", toothInfo: (n) => ({ name: "Tooth " + n }), surfaceDiagram: () => "" };
const _SURF_ORDER = ["B", "M", "O", "L", "D"];
const _lineKey = (l) => `${l.service_id}|${l.tooth || ""}`;
const _sortTeeth = (a, b) => parseInt(a, 10) - parseInt(b, 10);

// Surface vs whole-tooth procedures, from services.chart_outcome:
// filled/decay/healthy are surface-level; missing/crown/impacted act on the
// whole tooth. Services without an outcome fall back to the name (extraction).
// Mirrored in api/finish_treatment.php.
const _WHOLE_TOOTH_OUTCOMES = ["missing", "crown", "impacted", "impacted_ne"];
function _surfacesAllowed(l) {
  if (!l || !l.tooth) return false;
  if (l.chart_outcome) return !_WHOLE_TOOTH_OUTCOMES.includes(l.chart_outcome);
  return !/extract|exodont/i.test(l.service_name || "");
}

// Chart callbacks (treatments-active-chart.js)
window._wmIsPlanned  = (key) => _plan.some((l) => l.tooth === String(key));
window._wmOnToothFocus = function (key) {
  _focusTooth = key;
  if (_pickerOpen === "tooth") _pickerOpen = null;
  _renderToothPanel();
};

function _svcFrom(s, booked) {
  return {
    service_id:     parseInt(s.service_id, 10),
    service_name:   s.service_name || "—",
    unit_price:     parseFloat(s.base_price || 0),
    requires_tooth: !!s.requires_tooth_selection,
    chart_outcome:  s.chart_outcome || null,
    booked:         !!booked,
  };
}
function _isBooked(serviceId) {
  return _bookedSvcs.some((s) => parseInt(s.service_id, 10) === parseInt(serviceId, 10));
}

// Add or remove one procedure on a tooth ('' = general procedure)
function _toggleLine(svc, tooth) {
  const key = `${svc.service_id}|${tooth || ""}`;
  const i = _plan.findIndex((l) => _lineKey(l) === key);
  if (i > -1) _plan.splice(i, 1);
  else _plan.push(Object.assign(_svcFrom(svc, _isBooked(svc.service_id)), { tooth: tooth || "", surfaces: [] }));
  _onPlanChanged();
}

function twToggleToothProc(serviceId) {
  if (!_focusTooth) return;
  const line = _plan.find((l) => l.service_id === serviceId && l.tooth === _focusTooth);
  if (line) return twRemoveLine(_lineKey(line));
  const svc = _bookedSvcs.find((s) => parseInt(s.service_id, 10) === serviceId)
           || (_catalog || []).find((s) => parseInt(s.service_id, 10) === serviceId);
  if (svc) _toggleLine(svc, _focusTooth);
}
function twRemoveLine(key) {
  _plan = _plan.filter((l) => _lineKey(l) !== key);
  _onPlanChanged();
}

function twToggleSurface(key, surface) {
  const line = _plan.find((l) => _lineKey(l) === key);
  if (!line || !_SURF_ORDER.includes(surface) || !_surfacesAllowed(line)) return;
  const i = line.surfaces.indexOf(surface);
  if (i > -1) line.surfaces.splice(i, 1); else line.surfaces.push(surface);
  line.surfaces.sort((a, b) => _SURF_ORDER.indexOf(a) - _SURF_ORDER.indexOf(b));
  _renderToothPanel();
  _renderPlan();
}

function twFocusTooth(key) { if (window.wmFocus) window.wmFocus(key); }

function _onPlanChanged() {
  _plan.forEach((l) => { if (!_surfacesAllowed(l)) l.surfaces = []; }); // never keep stale surfaces
  _plan.sort((a, b) => (a.tooth ? parseInt(a.tooth, 10) : 99) - (b.tooth ? parseInt(b.tooth, 10) : 99) || a.service_name.localeCompare(b.service_name));
  document.getElementById("work_tooth_number").value = _plannedTeeth().join(", ");
  if (window.wmRefresh) window.wmRefresh();
  _renderToothPanel();
  _renderPlan();
  _recalcCost();
  _ensureLineMaterials().then(_rebuildMaterials);
}

function _plannedTeeth() {
  return [...new Set(_plan.filter((l) => l.tooth).map((l) => l.tooth))].sort(_sortTeeth);
}

// ── "Other procedure" picker (inline list, not a dropdown) ────
function twTogglePicker(which) {
  _pickerOpen = _pickerOpen === which ? null : which;
  const done = () => { _renderToothPanel(); _renderGeneralPicker(); };
  if (_pickerOpen && _catalog === null) {
    fetch("api/services.php?status=active")
      .then((r) => r.json())
      .then((list) => { _catalog = Array.isArray(list) ? list : []; done(); })
      .catch(() => { _catalog = []; done(); });
  }
  done();
}
function twFilterPicker(input) {
  const q = input.value.trim().toLowerCase();
  input.closest(".tw-picker").querySelectorAll(".tw-picker-item").forEach((b) => {
    b.style.display = b.dataset.name.includes(q) ? "" : "none";
  });
}
function _pickerHTML(list, onPick, emptyText) {
  if (_catalog === null) return '<div class="tw-picker"><div class="tw-picker-empty"><span class="spinner-border spinner-border-sm me-1"></span>Loading services…</div></div>';
  return `<div class="tw-picker">
      <input type="search" class="form-control form-control-sm tw-picker-search" placeholder="Search procedures…" oninput="twFilterPicker(this)" aria-label="Search procedures">
      <div class="tw-picker-list">${list.length
        ? list.map((s) => `<button type="button" class="tw-picker-item" data-name="${_twEsc(String(s.service_name).toLowerCase())}" onclick="${onPick}(${parseInt(s.service_id, 10)})">
              <span>${_twEsc(s.service_name)}</span><span class="tw-picker-price">${_peso(s.base_price)}</span></button>`).join("")
        : `<div class="tw-picker-empty">${emptyText}</div>`}</div>
    </div>`;
}
function twPickToothProc(serviceId) {
  const svc = (_catalog || []).find((s) => parseInt(s.service_id, 10) === serviceId);
  if (!svc || !_focusTooth) return;
  _pickerOpen = null;
  if (!_plan.some((l) => l.service_id === serviceId && l.tooth === _focusTooth)) _toggleLine(svc, _focusTooth);
  else _renderToothPanel();
}
function twPickGeneralProc(serviceId) {
  const svc = (_catalog || []).find((s) => parseInt(s.service_id, 10) === serviceId);
  if (!svc) return;
  _pickerOpen = null;
  if (!_plan.some((l) => l.service_id === serviceId && !l.tooth)) _toggleLine(svc, "");
  else _renderGeneralPicker();
}
function _renderGeneralPicker() {
  const el = document.getElementById("tw_general_picker");
  if (!el) return;
  if (_pickerOpen !== "general") { el.innerHTML = ""; el.style.display = "none"; return; }
  const list = (_catalog || []).filter((s) => !s.requires_tooth_selection && !_plan.some((l) => !l.tooth && l.service_id === parseInt(s.service_id, 10)));
  el.innerHTML = _pickerHTML(list, "twPickGeneralProc", "No other general procedures available.");
  el.style.display = "";
}

// ── Tooth panel (right of the chart) ──────────────────────────
function _renderToothPanel() {
  const el = document.getElementById("wm_tooth_panel");
  if (!el) return;
  const O = _O();
  if (!_focusTooth) {
    const teeth = _plannedTeeth();
    el.innerHTML = `<div class="tw-tp-empty">
        <i class="feather-mouse-pointer" aria-hidden="true"></i>
        <p class="mb-1 fw-semibold">Select a tooth on the chart</p>
        <p class="mb-0">Then choose the procedure performed on it.</p>
        ${teeth.length ? `<div class="tw-tp-jump">Planned: ${teeth.map((n) => `<button type="button" class="tw-tooth-chip" onclick="twFocusTooth('${n}')">${n}</button>`).join("")}</div>` : ""}
      </div>`;
    return;
  }
  const n = parseInt(_focusTooth, 10);
  const info  = O.toothInfo(n);
  const entry = window.wmGetEntry ? window.wmGetEntry(_focusTooth) : null;
  const recorded = entry && entry.surfaces ? _SURF_ORDER.filter((k) => entry.surfaces[k] && entry.surfaces[k].condition) : [];

  // Procedure cards: booked tooth procedures + any other procedure already planned on this tooth
  const cards = _bookedSvcs.filter((s) => s.requires_tooth_selection).map((s) => _svcFrom(s, true));
  _plan.filter((l) => l.tooth === _focusTooth && !cards.some((c) => c.service_id === l.service_id)).forEach((l) => cards.push(l));
  const lines = _plan.filter((l) => l.tooth === _focusTooth);

  const cardHTML = cards.map((c) => {
    const on = lines.some((l) => l.service_id === c.service_id);
    return `<button type="button" class="tw-proc-card${on ? " is-on" : ""}" aria-pressed="${on}" onclick="twToggleToothProc(${c.service_id})">
        <span class="tw-proc-check" aria-hidden="true"><i class="feather-${on ? "check" : "plus"}"></i></span>
        <span class="tw-proc-name">${_twEsc(c.service_name)}</span>
        <span class="tw-proc-price">${_peso(c.unit_price)}</span>
        <span class="tw-proc-tag ${c.booked ? "" : "is-added"}">${c.booked ? "Booked" : "Added"}</span>
      </button>`;
  }).join("");

  const otherList = (_catalog || []).filter((s) => s.requires_tooth_selection && !cards.some((c) => c.service_id === parseInt(s.service_id, 10)));
  const surfHTML = lines.map((l) => !_surfacesAllowed(l) ? `
      <div class="tw-surf-row">
        <div class="tw-surf-text">
          <div class="tw-surf-title">${_twEsc(l.service_name)}</div>
          <div class="tw-surf-value"><strong>Whole tooth</strong></div>
          <div class="tw-surf-hint">This procedure applies to the entire tooth — no surface selection needed.</div>
        </div>
      </div>` : `
      <div class="tw-surf-row">
        ${O.surfaceDiagram(entry, { pickable: true, picked: l.surfaces }).replace("<svg ", `<svg data-line="${_twEsc(_lineKey(l))}" `)}
        <div class="tw-surf-text">
          <div class="tw-surf-title">${_twEsc(l.service_name)}</div>
          <div class="tw-surf-value">${l.surfaces.length ? "Surfaces: <strong>" + l.surfaces.join(", ") + "</strong>" : "Whole tooth"}</div>
          <div class="tw-surf-hint">Click B / M / O / L / D on the diagram if the procedure is on specific surfaces.</div>
        </div>
      </div>`).join("");

  const toothFindings = _findings.map((f, i) => ({ f, i })).filter(({ f }) => f.tooth === _focusTooth);
  const condChips = Object.keys(O.COND_LABEL || {}).map((k) =>
    `<button type="button" class="tw-cond-chip" data-cond="${k}" onclick="twSetFindingCond(this)"><span class="odo-swatch odo-sw-${k}"></span>${_twEsc(O.COND_LABEL[k])}</button>`).join("");

  el.innerHTML = `
    <div class="tw-tp-head">
      <span class="tw-tp-num">${n}</span>
      <div class="tw-tp-title">
        <div class="tw-tp-name">${_twEsc(info.name)}</div>
        <div class="tw-tp-cond">Current: ${O.badge(entry && entry.condition)}</div>
      </div>
      <button type="button" class="btn-close tw-tp-close" aria-label="Close tooth panel" onclick="twFocusTooth(null)"></button>
    </div>
    <div class="tw-tp-current">
      ${O.surfaceDiagram(entry)}
      <div class="tw-tp-current-text">${recorded.length
        ? recorded.map((k) => `<div><b>${k}</b> ${_twEsc(O.SURF_NAME[k])} · ${O.badge(entry.surfaces[k].condition)}</div>`).join("")
        : `<div class="tw-ti-muted">${entry ? "No surface-level findings." : "No condition recorded for this tooth yet."}</div>`}
        ${entry && entry.notes ? `<div class="tw-ti-note">${_twEsc(entry.notes)}</div>` : ""}
      </div>
    </div>

    <div class="tw-tp-label">Procedure on tooth ${n}</div>
    <div class="tw-proc-grid">${cardHTML || '<div class="tw-ti-muted">No tooth procedures were booked for this appointment.</div>'}</div>
    <button type="button" class="tw-link-btn mt-2" onclick="twTogglePicker('tooth')" aria-expanded="${_pickerOpen === "tooth"}">
      <i class="feather-${_pickerOpen === "tooth" ? "chevron-up" : "plus-circle"}" aria-hidden="true"></i> Other procedure
    </button>
    ${_pickerOpen === "tooth" ? _pickerHTML(otherList, "twPickToothProc", "No other tooth procedures available.") : ""}

    ${surfHTML ? `<div class="tw-tp-label">Surfaces</div>${surfHTML}` : ""}

    <details class="tw-finding"${toothFindings.length ? " open" : ""}>
      <summary><i class="feather-edit-3" aria-hidden="true"></i> Record a clinical finding <span class="tw-optional">(optional)</span></summary>
      <p class="tw-finding-hint">Only what you actually found (e.g. impacted). Saved to the chart after you confirm on finish.</p>
      <div class="tw-cond-chips" id="tw_find_conds">${condChips}</div>
      <div class="tw-finding-row">
        <div class="tw-surf-chips" id="tw_find_surfs" role="group" aria-label="Finding surface">
          <button type="button" class="tw-surf-chip is-on" data-surf="" onclick="twSetFindingSurf(this)">Whole tooth</button>
          ${_SURF_ORDER.map((k) => `<button type="button" class="tw-surf-chip" data-surf="${k}" onclick="twSetFindingSurf(this)" title="${_twEsc(O.SURF_NAME[k])}">${k}</button>`).join("")}
        </div>
        <input type="text" id="tw_find_notes" class="form-control form-control-sm" maxlength="255" placeholder="Notes (optional)">
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addClinicalFinding()"><i class="feather-plus me-1"></i>Add</button>
      </div>
      ${toothFindings.length ? `<ul class="tw-findings-list">${toothFindings.map(({ f, i }) =>
        `<li><span>${_twEsc(_findingText(f))}</span><button type="button" class="tw-link-btn" onclick="removeClinicalFinding(${i})" aria-label="Remove finding"><i class="feather-x"></i></button></li>`).join("")}</ul>` : ""}
    </details>`;
}

// Surface clicks on the pickable diagram (mouse + keyboard)
document.addEventListener("click", (e) => {
  const p = e.target.closest("#wm_tooth_panel svg.is-pickable [data-surface]");
  if (p) twToggleSurface(p.closest("svg").dataset.line, p.dataset.surface);
});
document.addEventListener("keydown", (e) => {
  const p = e.target.closest && e.target.closest("#wm_tooth_panel svg.is-pickable [data-surface]");
  if (p && (e.key === "Enter" || e.key === " ")) { e.preventDefault(); twToggleSurface(p.closest("svg").dataset.line, p.dataset.surface); }
});

// ── Clinical findings (separate from procedures) ──────────────
function twSetFindingCond(btn) {
  btn.parentElement.querySelectorAll(".tw-cond-chip").forEach((b) => b.classList.toggle("is-on", b === btn));
}
function twSetFindingSurf(btn) {
  btn.parentElement.querySelectorAll(".tw-surf-chip").forEach((b) => b.classList.toggle("is-on", b === btn));
}
function addClinicalFinding() {
  const errBox    = document.getElementById("workModalError");
  const condition = document.querySelector("#tw_find_conds .tw-cond-chip.is-on")?.dataset.cond || "";
  const surface   = document.querySelector("#tw_find_surfs .tw-surf-chip.is-on")?.dataset.surf || "";
  const notes     = (document.getElementById("tw_find_notes")?.value || "").trim();
  errBox.style.display = "none";
  if (!_focusTooth || !condition) {
    errBox.textContent   = "Choose the condition you found on this tooth.";
    errBox.style.display = "block";
    return;
  }
  // One finding per tooth + surface — a newer entry replaces the earlier one
  _findings = _findings.filter((f) => !(f.tooth === _focusTooth && f.surface === surface));
  _findings.push({ tooth: _focusTooth, surface, condition, notes });
  _findings.sort((a, b) => _sortTeeth(a.tooth, b.tooth) || a.surface.localeCompare(b.surface));
  _renderToothPanel();
}
function removeClinicalFinding(i) {
  _findings.splice(i, 1);
  _renderToothPanel();
}
function _findingText(f) {
  const O = _O();
  const where = f.surface ? `${f.surface} · ${O.SURF_NAME[f.surface] || f.surface}` : "Whole tooth";
  return `Tooth ${f.tooth} (${where}) → ${O.COND_LABEL[f.condition] || f.condition}${f.notes ? " — " + f.notes : ""}`;
}

// ── Plan table + cost ─────────────────────────────────────────
function _renderPlan() {
  const body = document.getElementById("tw_plan_body");
  if (!body) return;
  body.innerHTML = _plan.length
    ? _plan.map((l) => `<tr>
        <td>${l.tooth ? `<button type="button" class="tw-tooth-chip" onclick="twFocusTooth('${l.tooth}')" title="Show tooth ${l.tooth}">${l.tooth}</button>` : '<span class="tw-ti-muted">General</span>'}</td>
        <td><span class="fw-semibold">${_twEsc(l.service_name)}</span>${l.booked ? "" : ' <span class="tw-proc-tag is-added">Added</span>'}</td>
        <td>${l.tooth ? (l.surfaces.length ? l.surfaces.join(", ") : '<span class="tw-ti-muted">Whole tooth</span>') : "—"}</td>
        <td class="text-end tw-num">${_peso(l.unit_price)}</td>
        <td class="text-end"><button type="button" class="tw-link-btn" onclick="twRemoveLine('${_twEsc(_lineKey(l))}')" aria-label="Remove ${_twEsc(l.service_name)}"><i class="feather-x"></i></button></td>
      </tr>`).join("")
    : '<tr><td colspan="5" class="text-center text-muted small py-3">No procedures yet — select a tooth on the chart, or add a general procedure.</td></tr>';

  // Booked tooth procedures that have no tooth yet (reminder, not a blocker)
  const missing = _bookedSvcs.filter((s) => s.requires_tooth_selection && !_plan.some((l) => l.service_id === parseInt(s.service_id, 10)));
  const note = document.getElementById("tw_plan_note");
  if (note) {
    note.innerHTML = missing.length
      ? `<i class="feather-info" aria-hidden="true"></i> Booked but not yet on a tooth: ${missing.map((s) => `<strong>${_twEsc(s.service_name)}</strong>`).join(", ")}`
      : "";
    note.style.display = missing.length ? "" : "none";
  }
}

function _calcTotal() {
  return Math.round(_plan.reduce((sum, l) => sum + (l.unit_price || 0), 0) * 100) / 100;
}
// Cost is always the sum of the configured procedure prices (read-only; the
// server recalculates it too). Discounts are applied later, in Billing.
function _recalcCost() {
  const costEl = document.getElementById("work_cost");
  if (costEl) costEl.value = _plan.length ? _calcTotal().toFixed(2) : "";
  _renderCostBreakdown();
}
function _renderCostBreakdown() {
  _renderStepSummary();
  const box  = document.getElementById("work_cost_breakdown");
  const hint = document.getElementById("work_cost_hint");
  if (!box) return;
  const total = _calcTotal();
  if (hint) hint.textContent = "Calculated from the configured price of each procedure. Discounts are applied in Billing.";
  if (!_plan.length) { box.style.display = "none"; box.innerHTML = ""; return; }
  box.innerHTML =
    `<div class="tw-cb-row"><span>Procedures (${_plan.length})</span><span>${_peso(total)}</span></div>` +
    `<div class="tw-cb-row tw-cb-total"><span>Invoice total</span><span>${_peso(total)}</span></div>`;
  box.style.display = "";
}

// ── Booked services → plan seed ───────────────────────────────
function _loadPlanServices(t) {
  const src = t.appointment_id
    ? fetch(`api/appointment_services_list.php?appointment_id=${t.appointment_id}`).then((r) => r.json()).then((d) => d.services || [])
    : Promise.resolve([]);
  return src
    .then((list) => (list.length || !t.service_id ? list
      : fetch(`api/services.php?service_id=${t.service_id}`).then((r) => r.json()).then((l) => (Array.isArray(l) ? l : []))))
    .catch(() => [])
    .then((services) => {
      _bookedSvcs = services;
      // Booked general procedures (no tooth) start on the plan; tooth procedures wait for a tooth.
      services.filter((s) => !s.requires_tooth_selection).forEach((s) => {
        _plan.push(Object.assign(_svcFrom(s, true), { tooth: "", surfaces: [] }));
      });
      // Teeth already saved on this treatment (older records) → one booked tooth procedure each
      const toothSvcs = services.filter((s) => s.requires_tooth_selection);
      const saved = String(t.tooth_number || "").split(",").map((x) => x.trim()).filter((x) => /^\d+$/.test(x) && +x >= 1 && +x <= 32);
      if (toothSvcs.length === 1 && saved.length) {
        saved.forEach((n) => _plan.push(Object.assign(_svcFrom(toothSvcs[0], true), { tooth: n, surfaces: [] })));
      }
      _onPlanChanged();
    });
}

// ── Materials (each plan line uses its service's configured materials) ──
function _ensureLineMaterials() {
  const need = [...new Set(_plan.map((l) => l.service_id))].filter((id) => !(id in _svcMats));
  return Promise.all(need.map((id) =>
    fetch(`api/service_materials.php?service_id=${id}`)
      .then((r) => r.json())
      .then((mats) => {
        _svcMats[id] = (mats || []).map((m) => {
          const inv = m.inventory || {};
          return { item_id: inv.item_id || m.item_id, item_name: inv.item_name || "—", item_code: inv.item_code || "",
                   stock: parseInt(inv.stock_quantity ?? 0), quantity: parseInt(m.quantity) || 1 };
        }).filter((m) => m.item_id);
      })
      .catch(() => { _svcMats[id] = []; })));
}
function _rebuildMaterials() {
  if (!_matLocked) {
    const merged = {};
    _plan.forEach((l) => (_svcMats[l.service_id] || []).forEach((m) => {
      if (_matRemoved.has(m.item_id)) return;
      if (!merged[m.item_id]) merged[m.item_id] = Object.assign({}, m, { qty_used: 0, base_qty: 0, lines: 0 });
      merged[m.item_id].qty_used += m.quantity;
      merged[m.item_id].base_qty += m.quantity;
      merged[m.item_id].lines    += 1;
    }));
    Object.values(_matManual).forEach((m) => { if (!merged[m.item_id]) merged[m.item_id] = Object.assign({}, m); });
    Object.keys(_matEdited).forEach((id) => { if (merged[id]) merged[id].qty_used = _matEdited[id]; });
    _workMaterials = Object.values(merged);
  }
  _showMaterialsTable();
  _scheduleStockCheck();
}
function _loadSavedMaterials(treatmentId) {
  return fetch(`api/treatment_materials.php?treatment_id=${treatmentId}`)
    .then((r) => r.json())
    .then((existing) => {
      if (!Array.isArray(existing) || !existing.length) return;
      _matLocked = true;
      _workMaterials = existing.map((m) => ({
        material_id: m.material_id,
        item_id:     m.item_id,
        item_name:   m.inventory?.item_name || "—",
        item_code:   m.inventory?.item_code || "",
        stock:       parseInt(m.inventory?.stock_quantity ?? 0),
        qty_used:    m.quantity_used,
        base_qty:    m.quantity_used,
      }));
    })
    .catch(() => {});
}

// ── Stock check (server, fresh data every time) ───────────────
// The plan's combined materials are checked against the branch's current
// stock by api/stock_check.php (floor: 1 unit must remain). The alert is
// rebuilt from each response, so it clears once stock is replenished and
// keeps only the materials that are still short. finish_treatment.php
// repeats the check before saving anything.
let _stock = { ok: true, shortages: [], seq: 0, timer: null };

function _scheduleStockCheck() {
  clearTimeout(_stock.timer);
  _stock.timer = setTimeout(_checkStock, 300);
}
function _checkStock() {
  clearTimeout(_stock.timer);
  const seq = ++_stock.seq;
  const materials = _workMaterials.map((m) => ({ item_id: m.item_id, quantity: m.qty_used }));
  if (!materials.length) { _stock.ok = true; _stock.shortages = []; _renderStockAlert(); return Promise.resolve(true); }
  return fetch("api/stock_check.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ branch_id: parseInt(document.getElementById("work_branch_id").value, 10) || 0, materials }),
  })
    .then((r) => r.json())
    .then((res) => {
      if (seq !== _stock.seq) return _stock.ok;          // a newer check is on its way
      if (!res || !res.success) throw new Error();
      // Current stock for every listed material (by the id the table uses)
      const avail = {};
      (res.items || []).concat(res.shortages || []).forEach((it) =>
        (it.source_ids || [it.item_id]).forEach((id) => { avail[id] = it.available; }));
      _workMaterials.forEach((m) => { if (m.item_id in avail) m.stock = avail[m.item_id]; });
      _stock.ok = !!res.ok;
      _stock.shortages = res.shortages || [];
      _renderStockAlert();
      renderWorkMaterials();
      return _stock.ok;
    })
    .catch(() => {
      if (seq !== _stock.seq) return _stock.ok;
      _stock.ok = false;
      _stock.shortages = [{ error: true }];
      _renderStockAlert();
      return false;
    });
}
function _renderStockAlert() {
  const box = document.getElementById("twStockAlert");
  if (!box) return;
  if (_stock.ok || !_stock.shortages.length) { box.hidden = true; box.innerHTML = ""; return; }
  if (_stock.shortages[0].error) {
    box.innerHTML = `<div class="tw-stock-title"><i class="feather-alert-triangle" aria-hidden="true"></i>Stock could not be checked. Check your connection and try again.</div>`;
  } else {
    box.innerHTML = `
      <div class="tw-stock-title"><i class="feather-alert-triangle" aria-hidden="true"></i>Not enough stock for this plan</div>
      <ul>${_stock.shortages.map((s) => s.not_in_branch
        ? `<li><strong>${_twEsc(s.item_name)}</strong> — not stocked at this branch</li>`
        : `<li><strong>${_twEsc(s.item_name)}</strong> — available ${s.available}, required ${s.required}, short ${s.shortage}</li>`).join("")}</ul>
      <div class="tw-stock-note">At least 1 unit of each material must stay in stock. Restock the item or change the plan to continue.</div>`;
  }
  box.hidden = false;
}

// ── View Details Modal ────────────────────────────────────────
function viewTreatment(t) {
  const set = (id, v) => {
    const el  = document.getElementById(id);
    const val = (v ?? "").toString().trim();
    el.textContent = val && val !== "—" ? val : "—";
    el.classList.toggle("ar-muted", !val || val === "—");
  };
  document.getElementById("tv_code_sub").textContent = t.treatment_code || "—";
  set("tv_code", t.treatment_code);
  set("tv_appointment_code", t.appointment_code);
  set("tv_date", t.treatment_date);
  set("tv_stage", t.current_stage);
  set("tv_cost", t.cost);
  set("tv_patient", t.patient_name);
  set("tv_dentist", t.dentist_name || "Not yet assigned");
  set("tv_branch", t.branch_name);
  set("tv_tooth", t.tooth_number);
  set("tv_procedure", t.procedure_notes);
  const svcs = Array.isArray(t.services) && t.services.length ? t.services : [];
  document.getElementById("tv_service").innerHTML = svcs.length
    ? svcs.map((n) => `<li>${_twEsc(n)}</li>`).join("")
    : '<li class="ar-muted">—</li>';
  document.getElementById("tv_status").innerHTML =
    `<span class="badge ${_twEsc(t.status_class || "bg-soft-secondary text-secondary")}">${_twEsc(t.status || "—")}</span>`;
  document.getElementById("tv_patient_link").innerHTML = t.patient_id
    ? `<a href="patient-records.php?id=${parseInt(t.patient_id, 10)}" class="text-primary fw-semibold">Open patient record <i class="feather-external-link"></i></a>`
    : "—";
  new bootstrap.Modal(document.getElementById("treatmentViewModal")).show();
}

// ── Open Work Modal ───────────────────────────────────────────
/* ── Step navigation: one section per step, total always in the footer ── */
let _twStep = 1;
const TW_STEPS = 4;
const TW_STOCK_GATE = 3; // Plan & Cost: moving past it needs the plan's materials in stock
function twGoStep(n) {
  if (n > _twStep && n > TW_STOCK_GATE) {
    return _ensureLineMaterials().then(() => { _rebuildMaterials(); return _checkStock(); }).then((ok) => {
      if (ok) return _twShowStep(n);
      _twShowStep(TW_STOCK_GATE);
      document.getElementById("twStockAlert").scrollIntoView({ block: "nearest" });
    });
  }
  _twShowStep(n);
}
function _twShowStep(n) {
  _twStep = Math.min(Math.max(n, 1), TW_STEPS);
  const modal = document.getElementById("workModal");
  modal.querySelectorAll(".tw-pane").forEach((p) => { p.hidden = parseInt(p.dataset.pane, 10) !== _twStep; });
  modal.querySelectorAll(".tw-step").forEach((b) => {
    const s = parseInt(b.dataset.step, 10);
    b.classList.toggle("is-active", s === _twStep);
    b.classList.toggle("is-done", s < _twStep);
    if (s === _twStep) b.setAttribute("aria-current", "step"); else b.removeAttribute("aria-current");
  });
  document.getElementById("twBackBtn").hidden = _twStep === 1;
  document.getElementById("twNextBtn").hidden = _twStep === TW_STEPS;
  document.getElementById("finishTreatmentBtn").hidden = _twStep !== TW_STEPS;
  const body = modal.querySelector(".tw-body");
  if (body) body.scrollTop = 0;
}
function _renderStepSummary() {
  const el = document.getElementById("tw_summary");
  if (!el) return;
  const n = _plan.length;
  const total = parseFloat(document.getElementById("work_cost").value) || 0;
  el.innerHTML = n
    ? `<span class="tw-sum-count"><i class="feather-list" aria-hidden="true"></i>${n} procedure${n === 1 ? "" : "s"}</span>
       <span class="tw-sum-total"><span class="tw-sum-label">Total</span>${_peso(total)}</span>`
    : '<span class="tw-sum-empty"><i class="feather-info" aria-hidden="true"></i>No procedures added yet</span>';
}

function openWorkModal(t) {
  _workData          = t;
  _workMaterials     = [];
  _bookedSvcs = []; _plan = []; _findings = []; _focusTooth = null; _pickerOpen = null;
  _svcMats = {}; _matManual = {}; _matEdited = {}; _matRemoved = new Set(); _matLocked = false;
  _stock.seq++; _stock.ok = true; _stock.shortages = []; _renderStockAlert();

  // Hidden fields
  document.getElementById("work_treatment_id").value  = t.treatment_id;
  document.getElementById("work_appointment_id").value = t.appointment_id || "";
  document.getElementById("work_patient_id").value    = t.patient_id     || "";
  document.getElementById("work_branch_id").value     = t.branch_id      || "";
  document.getElementById("work_dentist_id").value    = t.dentist_id     || "";
  document.getElementById("work_service_id").value    = t.service_id     || "";
  document.getElementById("work_patient_name").value  = t.patient_name   || "";
  document.getElementById("work_service_name").value  = t.service_name   || "";
  document.getElementById("work_dentist_name").value  = t.dentist_name   || "";
  document.getElementById("work_branch_name").value   = t.branch_name    || "";

  // Title
  document.getElementById("workModalTitle").textContent =
    ["pending", "ongoing"].includes(t.status) ? "Start Treatment" : "Update Treatment";
  // Compact subtitle: patient · treatment code · appointment code · branch (whatever this record has)
  const meta = [
    ["feather-user", t.patient_name, "tw-meta-patient"],
    ["feather-hash", t.treatment_code],
    ["feather-calendar", t.appointment_code],
    ["feather-map-pin", t.branch_name],
  ].filter((m) => m[1]);
  document.getElementById("workModalSubtitle").innerHTML = meta
    .map(([icon, text, cls]) => `<span class="tw-meta ${cls || ""}"><i class="${icon}" aria-hidden="true"></i>${_twEsc(text)}</span>`)
    .join("");
  document.getElementById("workModalError").style.display = "none";

  // Service display
  _renderServiceDisplay(t);

  // Pre-fill editable fields
  document.getElementById("work_procedure_notes").value = t.procedure_notes || "";
  document.getElementById("work_current_stage").value   = t.current_stage   || "Not set";

  // Tooth chart — always shown: procedures are chosen per tooth
  window.initWMChart(t.patient_id);
  _renderToothPanel();
  _renderGeneralPicker();
  _renderPlan();
  document.getElementById("work_cost").value = "";
  _renderCostBreakdown();

  // Materials loading state
  document.getElementById("work_materials_loading").style.display = "";
  document.getElementById("work_materials_table").style.display   = "none";

  // Dentists dropdown
  const branchId = t.branch_id || window._sessionBranchId || "";
  _loadDentistDropdown(branchId, t.dentist_id);

  // Inventory items → populate custom dropdown
  fetch(`api/inventory.php?branch_id=${branchId}`)
    .then((r) => r.json())
    .then((items) => {
      _inventoryItems = items || [];
      populateWorkItemDropdown();
    });

  // Booked services → plan, then materials (saved treatment materials win if present)
  _loadSavedMaterials(t.treatment_id).then(() => _loadPlanServices(t));

  twGoStep(1);
  new bootstrap.Modal(document.getElementById("workModal")).show();
}

// ── Inventory Item custom dropdown ────────────────────────────
function toggleInvItemCs() {
  const wrap   = document.getElementById("invItemCs");
  const isOpen = wrap.classList.contains("open");
  wrap.classList.toggle("open", !isOpen);
  if (!isOpen) {
    const search = document.getElementById("invItemCsSearch");
    search.value = "";
    filterInvItemCs("");
    search.focus();
  }
}

function filterInvItemCs(q) {
  const lower = q.toLowerCase();
  document.querySelectorAll("#invItemCsList li").forEach((li) => {
    li.style.display = li.textContent.toLowerCase().includes(lower) ? "" : "none";
  });
}

function _selectInvItem(item) {
  _invItemSelectedId   = item.item_id;
  _invItemSelectedData = item;

  // Update trigger label
  const valueEl = document.getElementById("invItemCsValue");
  valueEl.textContent = item.item_name;
  valueEl.classList.add("cs-selected");

  // Mark active in list
  document.querySelectorAll("#invItemCsList li").forEach((li) => {
    li.classList.toggle("active", parseInt(li.dataset.id) === item.item_id);
  });

  // Update stock preview
  const preview = document.getElementById("addMat_stock_preview");
  if (preview) {
    const stock = parseInt(item.stock_quantity ?? 0);
    preview.textContent = stock;
    preview.className   =
      "form-control bg-light fw-semibold " +
      (stock === 0 ? "text-danger" : stock <= 5 ? "text-warning" : "text-success");
  }

  // Close dropdown
  document.getElementById("invItemCs").classList.remove("open");
}

function populateWorkItemDropdown() {
  const ul = document.getElementById("invItemCsList");
  ul.innerHTML = "";

  if (!_inventoryItems.length) {
    ul.innerHTML = `<li class="cs-disabled">No items available</li>`;
    return;
  }

  _inventoryItems.forEach((item) => {
    const stock = parseInt(item.stock_quantity ?? 0);
    const li    = document.createElement("li");
    li.dataset.id = item.item_id;
    li.innerHTML  = `
      <span>${item.item_name}</span>
      <span class="cs-item-stock ${stock === 0 ? "text-danger" : stock <= 5 ? "text-warning" : "text-success"}">
        Stock: ${stock}
      </span>`;
    li.addEventListener("click", () => _selectInvItem(item));
    ul.appendChild(li);
  });
}

// Close custom dropdown on outside click
document.addEventListener("click", (e) => {
  if (!e.target.closest("#invItemCs")) {
    document.getElementById("invItemCs")?.classList.remove("open");
  }
});

// ── Add Material Modal ────────────────────────────────────────
function openAddWorkMaterial() {
  // Reset custom dropdown
  _invItemSelectedId   = null;
  _invItemSelectedData = null;
  const valueEl = document.getElementById("invItemCsValue");
  if (valueEl) { valueEl.textContent = " Select Item "; valueEl.classList.remove("cs-selected"); }
  document.querySelectorAll("#invItemCsList li").forEach((li) => li.classList.remove("active"));
  const search = document.getElementById("invItemCsSearch");
  if (search) { search.value = ""; filterInvItemCs(""); }
  document.getElementById("invItemCs")?.classList.remove("open");

  // Reset other fields
  document.getElementById("work_add_qty").value = "1";
  const preview = document.getElementById("addMat_stock_preview");
  if (preview) { preview.textContent = "—"; preview.className = "form-control bg-light text-muted"; }
  document.getElementById("addMat_error").style.display = "none";

  // Blur workModal, show addMaterialModal
  document.body.classList.add("add-material-open");
  new bootstrap.Modal(document.getElementById("addMaterialModal")).show();
}

function closeAddWorkMaterial() {
  bootstrap.Modal.getInstance(document.getElementById("addMaterialModal"))?.hide();
  document.body.classList.remove("add-material-open");
}

// Safety net: remove blur if modal closes via ESC or backdrop click
document.getElementById("addMaterialModal").addEventListener("hidden.bs.modal", function () {
  document.body.classList.remove("add-material-open");
});

function addWorkMaterial() {
  const qty    = parseInt(document.getElementById("work_add_qty").value) || 1;
  const itemId = _invItemSelectedId;
  const errBox = document.getElementById("addMat_error");
  errBox.style.display = "none";

  if (!itemId) {
    errBox.textContent   = "Please select an inventory item.";
    errBox.style.display = "block";
    return;
  }
  if (_workMaterials.find((m) => m.item_id === itemId)) {
    errBox.textContent   = "This item is already in the list. Adjust its quantity in the table instead.";
    errBox.style.display = "block";
    return;
  }

  const item = _invItemSelectedData;
  const mat = {
    item_id:   itemId,
    item_name: item.item_name,
    item_code: item.item_code || "",
    stock:     parseInt(item.stock_quantity ?? 0),
    qty_used:  qty,
    base_qty:  qty,
  };
  _matManual[itemId] = mat;
  _matRemoved.delete(itemId);
  _workMaterials.push(mat);
  renderWorkMaterials();
  closeAddWorkMaterial();
  _scheduleStockCheck();
}

function removeWorkMaterial(itemId) {
  if (_matManual[itemId]) delete _matManual[itemId]; else _matRemoved.add(itemId);
  delete _matEdited[itemId];
  _workMaterials = _workMaterials.filter((m) => m.item_id !== itemId);
  renderWorkMaterials();
  _scheduleStockCheck();
}

function updateWorkMaterialQty(itemId, qty) {
  const m = _workMaterials.find((m) => m.item_id === itemId);
  if (m) m.qty_used = parseInt(qty) || 1;
  if (m) _matEdited[itemId] = m.qty_used;   // kept when the plan changes
  _scheduleStockCheck();
}

function renderWorkMaterials() {
  const tbody   = document.getElementById("work_materials_body");
  const noItems = document.getElementById("work_no_materials");
  Array.from(tbody.querySelectorAll("tr:not(#work_no_materials)")).forEach((r) => r.remove());

  if (_workMaterials.length === 0) {
    noItems.style.display = "";
    return;
  }
  noItems.style.display = "none";

  _workMaterials.forEach((mat) => {
    const stockColor =
      mat.stock === 0 ? "text-danger" : mat.stock <= 5 ? "text-warning" : "text-success";

    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>
        <div class="fw-semibold small">${_twEsc(mat.item_name)}</div>
        <div class="text-muted" style="font-size:11px;">${_twEsc(mat.item_code)}</div>
      </td>
      <td><span class="${stockColor} small fw-semibold">${mat.stock}</span></td>
      <td>
        <input type="number" value="${mat.qty_used}" min="1" max="${mat.stock}"
          style="width:70px;border:1px solid #ced4da;border-radius:6px;padding:3px 8px;font-size:13px;"
          onchange="updateWorkMaterialQty(${mat.item_id}, this.value)">
        ${
          mat.lines > 1 && mat.qty_used === mat.base_qty
            ? `<div style="font-size:10px;color:#6b7280;margin-top:2px;">From ${mat.lines} procedures</div>`
            : ""
        }
      </td>
      <td class="text-end">
        <button style="width:28px;height:28px;border:1px solid #dc3545;border-radius:6px;background:#fff;color:#dc3545;cursor:pointer;"
          onclick="removeWorkMaterial(${mat.item_id})"
          onmouseover="this.style.background='#dc3545';this.style.color='#fff'"
          onmouseout="this.style.background='#fff';this.style.color='#dc3545'">
          <i class="feather-x" style="font-size:12px;"></i>
        </button>
      </td>`;
    tbody.insertBefore(tr, noItems);
  });
}

// ── Render service display as chips ───────────────────────────
function _renderServiceDisplay(t) {
  const displayEl = document.getElementById("work_service_display");
  if (!displayEl) return;

  function _buildChips(names) {
    if (!names || names.length === 0) {
      displayEl.dataset.names = "";
      displayEl.innerHTML = '<span class="tw-chip-empty">—</span>';
      return;
    }
    // Styles: .tw-chip in assets/css/treatments.css; plain names kept for the finish summary
    displayEl.dataset.names = names.join(", ");
    displayEl.innerHTML = names
      .map((name, i) => `<span class="tw-chip"><span class="tw-chip-num">${i + 1}</span>${_twEsc(name)}</span>`)
      .join("");
  }

  if (t.appointment_id) {
    _buildChips(
      t.service_name
        ? t.service_name.split(" | ").map((s) => s.replace(/^\d+\.\s*/, ""))
        : ["—"],
    );
    fetch(`api/appointment_services_list.php?appointment_id=${t.appointment_id}`)
      .then((r) => r.json())
      .then((data) => {
        const services = data.services || [];
        _buildChips(
          services.length > 0 ? services.map((s) => s.service_name) : [t.service_name || "—"],
        );
      })
      .catch(() => {});
  } else {
    _buildChips([t.service_name || "—"]);
  }
}

// ── Dentist dropdown ──────────────────────────────────────────
function _loadDentistDropdown(branchId, preDentistId) {
  fetch(`api/dentists.php?branch_id=${branchId}`)
    .then((r) => r.json())
    .then((dentists) => {
      const sel = document.getElementById("work_dentist_select");
      sel.innerHTML = '<option value=""> Select Dentist </option>';
      (dentists || []).forEach((d) => {
        const opt = document.createElement("option");
        opt.value       = d.dentist_id;
        opt.textContent = d.full_name;
        if (preDentistId && String(d.dentist_id) === String(preDentistId)) opt.selected = true;
        sel.appendChild(opt);
      });
    });
}

function _showMaterialsTable() {
  document.getElementById("work_materials_loading").style.display = "none";
  document.getElementById("work_materials_table").style.display   = "";
  renderWorkMaterials();
}

// ── Open Finish Confirm ───────────────────────────────────────
function openFinishConfirmModal() {
  const errBox         = document.getElementById("workModalError");
  const procedureNotes = document.getElementById("work_procedure_notes").value.trim();
  const cost           = parseFloat(document.getElementById("work_cost").value) || 0;
  const dentistSel     = document.getElementById("work_dentist_select");
  const dentistId      = dentistSel.value;
  const dentistName    = dentistSel.options[dentistSel.selectedIndex]?.text || "";
  errBox.style.display = "none";
  // Jump to the step that holds the problem, then show the message there
  const fail = (msg, step) => { twGoStep(step); errBox.textContent = msg; errBox.style.display = "block"; errBox.scrollIntoView({ block: "nearest" }); };

  if (!dentistId)      return fail("Please select the dentist who performed the treatment.", 1);
  if (!procedureNotes) return fail("Please describe the procedure performed.", 1);
  if (!_plan.length)   return fail("Add at least one procedure — select a tooth on the chart, or add a general procedure.", 2);
  if (cost <= 0)       return fail("The total cost is ₱0.00 — check the prices of the selected services in Service Pricing.", 3);
  // Fresh server check of the combined materials (the alert lists what is short)
  _checkStock().then((ok) => {
    if (!ok) { _twShowStep(TW_STOCK_GATE); document.getElementById("twStockAlert").scrollIntoView({ block: "nearest" }); return; }
    _openFinishConfirm(dentistId, dentistName, procedureNotes, cost);
  });
}
function _openFinishConfirm(dentistId, dentistName, procedureNotes, cost) {
  document.getElementById("work_dentist_id").value   = dentistId;
  document.getElementById("work_dentist_name").value = dentistName;

  document.getElementById("fc_patient").textContent   = document.getElementById("work_patient_name").value;
  document.getElementById("fc_procedure").textContent = procedureNotes;
  document.getElementById("fc_dentist").textContent   = dentistName;
  document.getElementById("fc_branch").textContent    = document.getElementById("work_branch_name").value;
  document.getElementById("fc_cost").textContent      = _peso(cost);

  // Procedures
  document.getElementById("fc_plan").innerHTML =
    _plan.map((l) => `<tr>
        <td>${l.tooth ? "Tooth " + _twEsc(l.tooth) : '<span class="text-muted">General</span>'}${l.surfaces.length ? ` <span class="text-muted">(${l.surfaces.join(", ")})</span>` : ""}</td>
        <td>${_twEsc(l.service_name)}${l.booked ? "" : ' <span class="tw-proc-tag is-added">Added</span>'}</td>
        <td class="text-end">${_peso(l.unit_price)}</td></tr>`).join("");

  // Dental chart updates — nothing is pre-ticked; the dentist confirms each one
  const O = _O();
  const surfOutcome = (c) => ["filled", "decay", "healthy"].includes(c);
  const condOptions = (sel) => '<option value="">No chart change</option>' +
    Object.keys(O.COND_LABEL).map((k) => `<option value="${k}"${k === sel ? " selected" : ""}>${_twEsc(O.COND_LABEL[k])}</option>`).join("");
  const chartRows = _plan.filter((l) => l.tooth).map((l, i) => {
    const where = l.surfaces.length && surfOutcome(l.chart_outcome) ? `surfaces ${l.surfaces.join(", ")}` : "whole tooth";
    return l.chart_outcome
      ? `<label class="fc-chart-row"><input type="checkbox" class="form-check-input fc-chart-line" data-line="${_twEsc(_lineKey(l))}">
           <span>Tooth <strong>${_twEsc(l.tooth)}</strong> · ${_twEsc(l.service_name)} → mark <strong>${_twEsc(O.COND_LABEL[l.chart_outcome] || l.chart_outcome)}</strong> <span class="text-muted">(${where})</span></span></label>`
      : `<div class="fc-chart-row"><span>Tooth <strong>${_twEsc(l.tooth)}</strong> · ${_twEsc(l.service_name)}</span>
           <select class="form-select form-select-sm fc-chart-pick js-custom-select" data-cs-float data-line="${_twEsc(_lineKey(l))}" aria-label="Chart outcome for tooth ${_twEsc(l.tooth)}">${condOptions("")}</select></div>`;
  });
  const findRows = _findings.map((f, i) =>
    `<label class="fc-chart-row"><input type="checkbox" class="form-check-input fc-chart-find" data-i="${i}" checked>
       <span>Finding: ${_twEsc(_findingText(f))}</span></label>`);
  document.getElementById("fc_chart").innerHTML = chartRows.length || findRows.length
    ? chartRows.join("") + findRows.join("")
    : '<span class="text-muted">No tooth procedures or findings — the dental chart will not be changed.</span>';
  if (window.enhanceCustomSelects) window.enhanceCustomSelects(); // chart-outcome pickers use the shared dropdown

  const missing = _bookedSvcs.filter((s) => s.requires_tooth_selection && !_plan.some((l) => l.service_id === parseInt(s.service_id, 10)));
  const warn = document.getElementById("fc_plan_warn");
  warn.innerHTML = missing.length ? `<i class="feather-info me-1"></i>Booked but not performed on any tooth: ${missing.map((s) => _twEsc(s.service_name)).join(", ")}.` : "";
  warn.style.display = missing.length ? "" : "none";

  const matDiv = document.getElementById("fc_materials_list");
  matDiv.innerHTML = _workMaterials.length === 0
    ? '<span class="text-muted">No materials to deduct.</span>'
    : _workMaterials.map((m) =>
        `<div class="d-flex justify-content-between border-bottom py-1"><span>${_twEsc(m.item_name)}</span><span class="fw-semibold">× ${m.qty_used}</span></div>`).join("");

  document.getElementById("finishConfirmError").style.display = "none";
  bootstrap.Modal.getInstance(document.getElementById("workModal"))?.hide();
  setTimeout(() => new bootstrap.Modal(document.getElementById("finishConfirmModal")).show(), 300);
}

function backToWorkModal() {
  bootstrap.Modal.getInstance(document.getElementById("finishConfirmModal"))?.hide();
  setTimeout(() => new bootstrap.Modal(document.getElementById("workModal")).show(), 300);
}

// Chart changes the dentist confirmed in the finish summary
function _confirmedChartChanges() {
  const code = _workData.treatment_code || "";
  const note = (extra) => [extra, code ? `Treatment ${code}` : ""].filter(Boolean).join(" — ");
  const surfOutcome = (c) => ["filled", "decay", "healthy"].includes(c);
  const changes = [];
  const fromLine = (line, condition) => {
    if (!line || !condition) return;
    if (line.surfaces.length && surfOutcome(condition)) {
      line.surfaces.forEach((s) => changes.push({ tooth_number: line.tooth, type: "surface", surface: s, condition, notes: note(line.service_name) }));
    } else {
      changes.push({ tooth_number: line.tooth, type: "tooth", surface: "", condition, notes: note(line.service_name) });
    }
  };
  const byKey = (k) => _plan.find((l) => _lineKey(l) === k);
  document.querySelectorAll("#fc_chart .fc-chart-line:checked").forEach((cb) => {
    const l = byKey(cb.dataset.line); fromLine(l, l && l.chart_outcome);
  });
  document.querySelectorAll("#fc_chart .fc-chart-pick").forEach((sel) => fromLine(byKey(sel.dataset.line), sel.value));
  document.querySelectorAll("#fc_chart .fc-chart-find:checked").forEach((cb) => {
    const f = _findings[parseInt(cb.dataset.i, 10)];
    if (f) changes.push({ tooth_number: f.tooth, type: f.surface ? "surface" : "tooth", surface: f.surface, condition: f.condition, notes: note(f.notes) });
  });
  return changes;
}

// ── Confirm & Finish ──────────────────────────────────────────
function confirmFinishTreatment() {
  const btn    = document.getElementById("confirmFinishBtn");
  const errBox = document.getElementById("finishConfirmError");
  errBox.style.display = "none";
  btn.disabled  = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

  const chartChanges = _confirmedChartChanges();   // read before the modal closes
  const payload = {
    treatment_id:    parseInt(document.getElementById("work_treatment_id").value),
    appointment_id:  parseInt(document.getElementById("work_appointment_id").value) || null,
    patient_id:      parseInt(document.getElementById("work_patient_id").value)     || null,
    branch_id:       parseInt(document.getElementById("work_branch_id").value)      || null,
    dentist_id:      parseInt(document.getElementById("work_dentist_id").value)     || null,
    service_id:      parseInt(document.getElementById("work_service_id").value)     || null,
    procedure_notes: document.getElementById("work_procedure_notes").value.trim(),
    tooth_number:    _plannedTeeth().join(", "),
    cost:            parseFloat(document.getElementById("work_cost").value) || 0,
    performed_by:    window._currentUser || "System",
    // Prices are re-read from services on the server; only the selection is sent
    procedures:      _plan.map((l) => ({ service_id: l.service_id, tooth_number: l.tooth, surfaces: _surfacesAllowed(l) ? l.surfaces.join("") : "" })),
    materials:       _workMaterials.map((m) => ({
      item_id:       m.item_id,
      quantity_used: m.qty_used,
      item_name:     m.item_name,
    })),
  };

  fetch("api/finish_treatment.php", {
    method:  "POST",
    headers: { "Content-Type": "application/json" },
    body:    JSON.stringify(payload),
  })
    .then((r) => r.json())
    .then((res) => {
      btn.disabled  = false;
      btn.innerHTML = '<i class="feather-check-circle me-1"></i> Confirm & Finish';
      if (res.success) {
        bootstrap.Modal.getInstance(document.getElementById("finishConfirmModal"))?.hide();
        _saveChartChanges(chartChanges).then((chartMsg) => {
          const warn = (chartMsg && chartMsg.warn) || (res.warnings && res.warnings.length);
          // Shown only after finish_treatment.php succeeded (styles: assets/css/es-swal.css)
          Swal.fire({
            icon:              warn ? "warning" : "success",
            iconHtml:          `<i class="feather-${warn ? "alert-circle" : "check-circle"} es-swal-glyph"></i>`,
            title:             warn ? "Treatment Completed — Please Review" : "Treatment Completed Successfully!",
            html:              `<p class="es-swal-text">The treatment record and its procedures were saved${payload.materials.length ? " and the materials were deducted from inventory" : ""}.</p>` +
                               (res.invoice_code
                                 ? `<span class="es-swal-chip"><i class="feather-file-text"></i>Invoice ${_twEsc(res.invoice_code)} · Unpaid</span>`
                                 : (res.message ? `<p class="es-swal-text">${_twEsc(res.message)}</p>` : "")) +
                               (chartMsg ? `<p class="es-swal-note"><i class="feather-${chartMsg.warn ? "alert-triangle" : "info"}"></i><span>${_twEsc(chartMsg.text)}</span></p>` : ""),
            confirmButtonText: "Done",
            timer:             warn ? undefined : 3000,
            timerProgressBar:  !warn,
            buttonsStyling:    false,
            customClass:       ES_SWAL_TONE(warn ? "warn" : "success"),
          }).then(() => location.reload());
        });
      } else {
        if (res.shortages) {           // stock changed since the last check
          errBox.style.display = "none";
          backToWorkModal();
          _checkStock().then(() => _twShowStep(TW_STOCK_GATE));
        } else {
          errBox.textContent   = res.message || "An error occurred.";
          errBox.style.display = "block";
        }
      }
    })
    .catch(() => {
      btn.disabled  = false;
      btn.innerHTML = '<i class="feather-check-circle me-1"></i> Confirm & Finish';
      errBox.textContent   = "Network error. Please try again.";
      errBox.style.display = "block";
    });
}

// ── Save confirmed changes to the patient's dental chart ─────
// Uses api/dental_chart.php upsert_batch (writes dental_chart / surfaces
// and a dental_chart_history row per change, source = treatment_record).
function _saveChartChanges(changes) {
  if (!changes.length) return Promise.resolve(null);
  return fetch("api/dental_chart.php", {
    method:  "POST",
    headers: { "Content-Type": "application/json" },
    body:    JSON.stringify({
      action:     "upsert_batch",
      patient_id: parseInt(document.getElementById("work_patient_id").value, 10),
      source:     "treatment_record",
      changes,
    }),
  })
    .then((r) => r.json())
    .then((d) => (d.success && !(d.errors || []).length)
      ? { text: `${d.saved} dental chart update(s) recorded.` }
      : { warn: true, text: "Some dental chart updates could not be saved — please record them from the patient's record." })
    .catch(() => ({ warn: true, text: "Dental chart updates were not saved (network error) — please record them from the patient's record." }));
}
