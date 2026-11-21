/* =============================================================
   EssenciaSmile — Billing JS
   File: assets/js/billing.js
   ============================================================= */

let _currentInvoiceId = null;
let _currentInvoiceCode = null;
let _currentBalance = 0;
let _currentTotal = 0;

// ── One request per invoice (api/billing.php?id=) ────────────────
// The response carries the invoice, its services, procedure lines, payments
// AND the voucher block ('discounts', same shape as api/invoice_discounts.php),
// so View Details and Add Payment need a single request between them.
// The last response is kept briefly so "Add Payment" right after "View
// Details" reuses it instead of asking again; anything that changes the
// invoice (payment, voucher, removal) forgets it. The server re-checks the
// real balance on every payment, so a reused view can't over-record.
const BV_REUSE_MS = 30000;
let _bvLoaded = null; // { id, res, at }
function _bvForget() { _bvLoaded = null; }
function _fetchInvoiceView(invoiceId, allowReuse) {
  const id = String(invoiceId);
  if (allowReuse && _bvLoaded && _bvLoaded.id === id && Date.now() - _bvLoaded.at < BV_REUSE_MS) {
    return Promise.resolve(_bvLoaded.res);
  }
  return fetch(`api/billing.php?id=${encodeURIComponent(invoiceId)}`)
    .then((r) => r.json())
    .then((res) => {
      if (res && res.success) _bvLoaded = { id, res, at: Date.now() };
      return res;
    });
}

function openBillingDetails(invoiceId) {
  _currentInvoiceId = invoiceId;
  [
    "bv_code", "bv_invoice_no", "bv_patient", "bv_patient_code", "bv_contact",
    "bv_branch", "bv_treatment", "bv_appointment", "bv_schedule", "bv_dentist",
    "bv_tooth", "bv_procedure", "bv_date", "bv_updated", "bv_total", "bv_total_line",
    "bv_subtotal", "bv_paid", "bv_balance", "bv_status",
  ].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.textContent = "—";
  });
  const receiptBtn = document.getElementById("bv_receipt_btn");
  if (receiptBtn) receiptBtn.style.display = "none";
  document.getElementById("bv_payments_loading").style.display = "";
  document.getElementById("bv_payments_wrap").style.display = "none";
  // getOrCreateInstance: calling this again on the open view just refreshes it
  bootstrap.Modal.getOrCreateInstance(document.getElementById("billingViewModal")).show();

  _fetchInvoiceView(invoiceId, false) // the view always shows the current invoice
    .then((res) => {
      if (!res.success) throw new Error(res.message);
      const inv = res.data;
      _currentBalance = parseFloat(inv.balance ?? 0);
      _currentTotal = parseFloat(inv.total_amount ?? 0);
      _currentInvoiceCode = inv.invoice_code;

      const esc = (v) => String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
      const set = (id, v) => {
        const el = document.getElementById(id);
        if (!el) return;
        const val = (v ?? "").toString().trim();
        el.textContent = val || "—";
        el.classList.toggle("ar-muted", !val);
      };
      const fmtDate = (d) => d
        ? new Date(String(d).length === 10 ? d + "T00:00:00" : d)
            .toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })
        : "";
      const fmtTime = (t) => t
        ? new Date("1970-01-01T" + t).toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" })
        : "";
      const trt = inv.treatments || {};
      const apt = inv.appointments || {};

      document.getElementById("bv_code").textContent = inv.invoice_code || "—";
      set("bv_invoice_no", inv.invoice_code);
      set("bv_date", fmtDate(inv.invoice_date));
      set("bv_updated", inv.updated_at
        ? new Date(inv.updated_at).toLocaleString("en-PH", { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" })
        : "");
      set("bv_branch", inv.branches?.branch_name);
      set("bv_patient", inv.patients?.full_name);
      set("bv_patient_code", inv.patients?.patient_code);
      set("bv_contact", inv.patients?.contact_number);
      set("bv_appointment", apt.appointment_code);
      set("bv_schedule", [fmtDate(apt.appointment_date), fmtTime(apt.appointment_time)].filter(Boolean).join(" · "));
      set("bv_treatment", trt.treatment_code);
      set("bv_dentist", trt.dentists?.full_name);
      set("bv_tooth", trt.tooth_number);

      const serviceNames = res.service_names || [];
      const procedureEl = document.getElementById("bv_procedure");
      procedureEl.classList.toggle("ar-muted", serviceNames.length === 0);
      procedureEl.innerHTML = serviceNames.length
        ? serviceNames.map((name, i) => `<div>${serviceNames.length > 1 ? i + 1 + ". " : ""}${esc(name)}</div>`).join("")
        : "—";

      // Itemized procedure lines; any difference to the stored total is shown as
      // an adjustment (the invoice total itself is never recalculated here).
      const lines = res.lines || [];
      document.getElementById("bv_lines_row").style.display = lines.length ? "" : "none";
      document.getElementById("bv_lines").innerHTML = lines.map((l) =>
        `<div class="d-flex justify-content-between gap-2"><span>${l.tooth_number ? "Tooth " + esc(l.tooth_number) + " · " : ""}${esc(l.services?.service_name || "—")}${l.surfaces ? ` (${esc(l.surfaces.split("").join(", "))})` : ""}</span><span class="text-nowrap">${_formatCost(l.unit_price)}</span></div>`).join("");
      const lineSum = Math.round(lines.reduce((s, l) => s + parseFloat(l.unit_price || 0), 0) * 100) / 100;
      const adjust  = Math.round((parseFloat(inv.total_amount || 0) - lineSum) * 100) / 100;
      document.getElementById("bv_adjust_row").style.display = lines.length && adjust !== 0 ? "" : "none";
      document.getElementById("bv_adjust").textContent = (adjust > 0 ? "+" : "−") + _formatCost(Math.abs(adjust));

      // Amounts are shown exactly as stored on the invoice (no recalculation).
      // Until discounts load, the treatment charge is the invoice total.
      set("bv_subtotal", _formatCost(inv.total_amount));
      set("bv_total_line", _formatCost(inv.total_amount));
      document.getElementById("bv_total").textContent = _formatCost(inv.total_amount);
      document.getElementById("bv_paid").textContent = _formatCost(inv.amount_paid);
      const balEl = document.getElementById("bv_balance");
      balEl.textContent = _formatCost(inv.balance);
      balEl.classList.toggle("text-danger", parseFloat(inv.balance || 0) > 0);
      balEl.classList.toggle("text-success", parseFloat(inv.balance || 0) <= 0);
      document.getElementById("bv_status").innerHTML = _paymentBadge(inv.payment_status);

      if (receiptBtn && ["paid", "partial"].includes(inv.payment_status)) {
        receiptBtn.href = `receipt.php?invoice_id=${encodeURIComponent(inv.invoice_id)}`;
        receiptBtn.style.display = "";
      }

      const addBtn = document.getElementById("bv_add_payment_btn");
      if (addBtn)
        addBtn.style.display = inv.payment_status === "paid" ? "none" : "";

      _renderPaymentHistory(res.payments || []);
      _loadInvoiceDiscounts(inv, lineSum, res.discounts);
    })
    .catch((err) => {
      document.getElementById("bv_payments_loading").style.display = "none";
      Swal.fire("Error", err.message || "Failed to load invoice.", "error");
    });
}

/* ── Discount vouchers (api/invoice_discounts.php) ─────────────
   Several vouchers per invoice; each is calculated on the subtotal.
   The server validates everything and re-totals the invoice. */
let _billingChanged = false; // reload the list when the invoice view closes
let _billingHandoff = false; // invoice view closed only to open Add Payment

// System alert look (assets/css/es-swal.css + assets/js/es-swal.js)
function _esToast(title, text) {
  return Swal.fire({
    icon: "success",
    iconHtml: '<i class="feather-check-circle es-swal-glyph"></i>',
    title,
    html: `<p class="es-swal-text">${text}</p>`,
    confirmButtonText: "Okay",
    timer: 1800,
    timerProgressBar: true,
    buttonsStyling: false,
    customClass: ES_SWAL_TONE("success"),
  });
}
function _esError(title, text) {
  return Swal.fire({
    icon: "error",
    iconHtml: '<i class="feather-alert-circle es-swal-glyph"></i>',
    title,
    html: `<p class="es-swal-text">${_bvEsc(text)}</p>`,
    confirmButtonText: "Okay",
    buttonsStyling: false,
    customClass: ES_SWAL_TONE("warn"),
  });
}

// ── Voucher picker helpers ─────────────────────────────────────
const _voucherCache = {}; // prefix → { available: [...], total } from api/invoice_discounts.php

// "₱100 off" / "10% off" (whole amounts without decimals)
function _voucherOff(v) {
  const n = +v.discount_value;
  const num = Number.isInteger(n) ? String(n) : n.toFixed(2);
  return v.discount_type === "percentage" ? `${num}% off` : `₱${Number(num).toLocaleString("en-PH", { maximumFractionDigits: 2 })} off`;
}
// "New Patient Voucher" → "New Patient"; long names are cut (the preview shows the full name)
function _shortVoucherName(name) {
  const s = String(name || "").replace(/\s+voucher$/i, "").trim();
  return s.length > 22 ? s.slice(0, 21).trimEnd() + "…" : s;
}

// Details of the selected voucher under the picker (hidden when nothing is selected)
function _renderVoucherPreview(prefix) {
  const box = document.getElementById(`${prefix}_voucher_preview`);
  const sel = document.getElementById(`${prefix}_voucher_select`);
  if (!box || !sel) return;
  const cache = _voucherCache[prefix] || { available: [], total: 0 };
  const v = cache.available.find((x) => x.voucher_code === sel.value);
  if (!v) { box.hidden = true; box.innerHTML = ""; return; }
  const esc = _bvEsc;
  const fmtDate = (d) => new Date(d + "T00:00:00").toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" });
  const rows = [
    ["Type", v.discount_type === "percentage" ? `${esc(+v.discount_value)}% of the charge` : `Fixed ${_formatCost(v.discount_value)}`],
    ["Discount", `<strong class="bv-vp-amt">−${_formatCost(v.preview_amount)}</strong>`],
    ["New total", `<strong>${_formatCost(Math.max(0, cache.total - v.preview_amount))}</strong>`],
  ];
  if (v.min_bill_amount) rows.push(["Min. bill", _formatCost(v.min_bill_amount)]);
  if (v.expiration_date) rows.push(["Valid until", esc(fmtDate(v.expiration_date))]);
  if (v.usage_limit) rows.push(["Usage limit", `${esc(v.usage_limit)} use${v.usage_limit === 1 ? "" : "s"}`]);
  box.innerHTML =
    `<div class="bv-vp-head"><span class="bv-discount-code">${esc(v.voucher_code)}</span><span class="bv-vp-name">${esc(v.voucher_name || "")}</span></div>` +
    `<dl class="bv-vp-rows">${rows.map(([k, val]) => `<div><dt>${k}</dt><dd>${val}</dd></div>`).join("")}</dl>`;
  box.hidden = false;
}

// Applied-voucher list (+ picker on Add Payment). prefix: "bv" (invoice view,
// read-only — no picker, no remove buttons) or "pm" (Add Payment)
function _renderInvoiceDiscounts(prefix, res) {
  const el = (k) => document.getElementById(`${prefix}_${k}`);
  const esc = _bvEsc;
  const editable = prefix === "pm" && res.can_edit;
  const box = el("discount"), apply = el("voucher_apply");
  if (apply) { el("voucher_msg").textContent = ""; apply.style.display = "none"; }

  if (res.applied.length) {
    box.className = "ar-card-value bv-discounts";
    box.innerHTML = res.applied.map((d) => `
      <div class="bv-discount">
        <span class="bv-discount-name"><span class="bv-discount-code">${esc(d.voucher_code)}</span>
          ${esc(d.voucher_name || "")} <span class="text-muted">(${d.discount_type === "percentage" ? esc(+d.discount_value) + "%" : _formatCost(d.discount_value)})</span></span>
        <span class="bv-discount-amt">−${_formatCost(d.discount_amount)}</span>
        ${editable ? `<button type="button" class="bv-discount-remove" onclick="removeInvoiceVoucher(${+d.invoice_discount_id}, '${esc(d.voucher_code)}', '${prefix}')" aria-label="Remove ${esc(d.voucher_code)}"><i class="feather-x"></i></button>` : ""}
      </div>`).join("");
  } else {
    box.className = "ar-card-value ar-muted";
    box.textContent = prefix === "pm" ? "None" : "None recorded";
  }

  // Picker: Add Payment only, for owner/admin while something is still owed
  if (!editable || res.total <= res.paid) return;
  const sel = el("voucher_select");
  _voucherCache[prefix] = { available: res.available, total: res.total };
  // Short label: CODE · Name · ₱100 off / 10% off (amount details go in the preview)
  sel.innerHTML = '<option value="">' + (res.available.length ? "Choose a voucher" : "No vouchers available for this invoice") + "</option>" +
    res.available.map((v) => `<option value="${esc(v.voucher_code)}">${esc(v.voucher_code)} · ${esc(_shortVoucherName(v.voucher_name))} · ${_voucherOff(v)}</option>`).join("");
  sel.value = "";
  _renderVoucherPreview(prefix);
  el("voucher_btn").disabled = !res.available.length;
  apply.style.display = "";
}

function _fetchInvoiceDiscounts(invoiceId) {
  return fetch(`api/invoice_discounts.php?invoice_id=${encodeURIComponent(invoiceId)}`).then((r) => r.json());
}

// Invoice view: charge, total and vouchers. `pre` = the voucher block that
// came with the invoice (api/billing.php); without it, ask the voucher API.
function _loadInvoiceDiscounts(inv, lineSum, pre) {
  const box = document.getElementById("bv_discount");
  if (!box) return;
  box.className = "ar-card-value ar-muted";
  box.textContent = "None recorded";

  (pre ? Promise.resolve(Object.assign({ success: true }, pre)) : _fetchInvoiceDiscounts(inv.invoice_id))
    .then((res) => {
      if (!res.success) return; // discounts not set up yet: keep "None recorded"
      document.getElementById("bv_subtotal").textContent = _formatCost(res.subtotal);
      document.getElementById("bv_total_line").textContent = _formatCost(res.total);
      // Itemized lines vs. the charge before discounts
      const adjust = Math.round((res.subtotal - lineSum) * 100) / 100;
      document.getElementById("bv_adjust_row").style.display = lineSum && adjust !== 0 ? "" : "none";
      _renderInvoiceDiscounts("bv", res);
    })
    .catch(() => {});
}

// Add Payment: vouchers + the amounts they change (total, balance, max payable)
function _setPaymentAmounts(total, paid) {
  const balance = Math.max(0, Math.round((total - paid) * 100) / 100);
  _currentBalance = balance;
  _pmWiz.total = +total || 0;
  _pmWiz.paid = +paid || 0;
  document.getElementById("pm_total").textContent = _formatCost(total);
  document.getElementById("pm_paid").textContent = _formatCost(paid);
  document.getElementById("pm_balance").textContent = _formatCost(balance);
  document.getElementById("pm_max_balance").value = balance;
  document.getElementById("pm_amount_hint").textContent = `Max payable: ${_formatCost(balance)}`;
  const amt = document.getElementById("pm_amount");
  if ((parseFloat(amt.value) || 0) > balance) amt.value = balance ? balance.toFixed(2) : "";
}
// `pre`: optional promise of the voucher block from api/billing.php (null →
// ask api/invoice_discounts.php, e.g. after a voucher was applied/removed)
function _loadPaymentDiscounts(pre) {
  const card = document.getElementById("pm_discount_card");
  const none = document.getElementById("pm_discount_unavailable");
  const invoiceId = _currentInvoiceId;
  const unavailable = () => { card.style.display = "none"; if (none) none.hidden = false; _pmWiz.disc = null; };
  const loading = document.getElementById("pm_discount_loading");
  _pmWiz.discLoading = true;
  // First load: say so (the voucher card appears once its data is here)
  if (loading && card.style.display === "none") loading.hidden = false;
  const src = pre
    ? pre.then((d) => (d ? Object.assign({ success: true }, d) : _fetchInvoiceDiscounts(invoiceId)))
    : _fetchInvoiceDiscounts(invoiceId);
  return src
    .then((res) => {
      if (invoiceId !== _currentInvoiceId) return;
      // Not available before the discounts SQL is run, and for roles that can't apply vouchers
      if (!res.success || !res.can_edit) { unavailable(); return; }
      card.style.display = "";
      if (none) none.hidden = true;
      _pmWiz.disc = { subtotal: +res.subtotal || 0, total: +res.total || 0, paid: +res.paid || 0 };
      document.getElementById("pm_subtotal").textContent = _formatCost(res.subtotal);
      document.getElementById("pm_adjusted").textContent = _formatCost(res.total);
      _setPaymentAmounts(res.total, res.paid);
      _renderInvoiceDiscounts("pm", res);
    })
    .catch(() => { if (invoiceId === _currentInvoiceId) unavailable(); })
    .finally(() => {
      if (invoiceId !== _currentInvoiceId) return;
      _pmWiz.discLoading = false;
      if (loading) loading.hidden = true;
      // Clear a "still loading" message once there is something to look at
      const box = document.getElementById("pmModalError");
      if (box && /still loading/.test(box.textContent)) box.style.display = "none";
    });
}

// After a voucher change on Add Payment, refresh its amounts
function _afterDiscountChange() {
  _billingChanged = true;
  _bvForget();
  _loadPaymentDiscounts();
}

function applyInvoiceVoucher(prefix = "pm") {
  const code = document.getElementById(`${prefix}_voucher_select`).value;
  const msg  = document.getElementById(`${prefix}_voucher_msg`);
  const btn  = document.getElementById(`${prefix}_voucher_btn`);
  if (!code) { msg.textContent = "Choose a voucher first."; return; }
  btn.disabled = true;
  msg.textContent = "";
  fetch("api/invoice_discounts.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ invoice_id: _currentInvoiceId, voucher_code: code }),
  })
    .then((r) => r.json())
    .then((res) => {
      btn.disabled = false;
      if (!res.success) { msg.textContent = res.message; return; }
      _esToast("Voucher Applied", `${_bvEsc(res.message)}<br>New balance: <strong>${_formatCost(res.balance)}</strong>`);
      _afterDiscountChange();
    })
    .catch(() => { btn.disabled = false; msg.textContent = "Could not apply the voucher. Please try again."; });
}

function removeInvoiceVoucher(id, code, prefix = "pm") {
  Swal.fire({
    icon: "question",
    iconHtml: '<i class="feather-tag es-swal-glyph"></i>',
    title: "Remove Voucher?",
    html: '<p class="es-swal-text">The invoice total will go back up by this discount.</p>' +
          `<span class="es-swal-chip"><i class="feather-tag"></i> ${_bvEsc(code)}</span>`,
    showCancelButton: true,
    confirmButtonText: "Yes, Remove",
    cancelButtonText: "Go Back",
    focusCancel: true,
    buttonsStyling: false,
    customClass: ES_SWAL_TONE("danger"),
  }).then((r) => {
    if (!r.isConfirmed) return;
    fetch(`api/invoice_discounts.php?id=${id}`, { method: "DELETE" })
      .then((res) => res.json())
      .then((res) => {
        if (!res.success) { _esError("Remove Failed", res.message || "Could not remove the voucher."); return; }
        _afterDiscountChange();
      })
      .catch(() => _esError("Remove Failed", "Network error. Please check your connection and try again."));
  });
}

// Row Action menus: the bundled Bootstrap 5.0.2 (vendors.min.js) ignores
// data-bs-strategy, so the menu stayed inside .bil-table-wrapper
// (overflow-x:auto ⇒ overflow-y:auto) and was clipped — with only a few rows
// it had to be scrolled into view. While open, the menu is moved to <body>
// (Bootstrap keeps its own reference to it, so clicks/close still work);
// Popper anchors it to the button and flips it upward near the viewport bottom.
// The holder keeps the .dropdown class so the theme's
// ".dropdown .dropdown-menu .dropdown-item" look (font, padding, icons) applies.
let _bilMenuHolder = null;
document.addEventListener("show.bs.dropdown", (e) => {
  const toggle = e.target;
  if (!toggle.closest || !toggle.closest(".bil-table-wrapper")) return;
  const menu = toggle.parentNode.querySelector(".dropdown-menu");
  if (!menu) return;
  if (!_bilMenuHolder) {
    _bilMenuHolder = document.createElement("div");
    _bilMenuHolder.className = "dropdown bil-menu-holder";
    document.body.appendChild(_bilMenuHolder);
  }
  menu._bilHome = toggle.parentNode;
  _bilMenuHolder.appendChild(menu);
});
document.addEventListener("hidden.bs.dropdown", (e) => {
  const home = e.target.parentNode;
  const menu = _bilMenuHolder && [..._bilMenuHolder.children].find((m) => m._bilHome === home);
  if (menu) home.appendChild(menu);
});

document.addEventListener("DOMContentLoaded", () => {
  // Voucher preview follows the selection (Add Payment picker)
  document.getElementById("pm_voucher_select")?.addEventListener("change", () => {
    document.getElementById("pm_voucher_msg").textContent = "";
    _renderVoucherPreview("pm");
  });
  document.getElementById("billingViewModal")?.addEventListener("hidden.bs.modal", () => {
    if (_billingHandoff) { _billingHandoff = false; return; }
    if (_billingChanged) { _billingChanged = false; esRefresh(); }
  });
  // Add Payment cancelled after a discount change: refresh the list then
  document.getElementById("addPaymentModal")?.addEventListener("hidden.bs.modal", () => {
    if (_billingChanged && !document.querySelector(".modal.show")) { _billingChanged = false; esRefresh(); }
  });
});

function _renderPaymentHistory(payments) {
  document.getElementById("bv_payments_loading").style.display = "none";
  document.getElementById("bv_payments_wrap").style.display = "";
  const tbody = document.getElementById("bv_payments_body");
  const noItems = document.getElementById("bv_no_payments");
  tbody.innerHTML = "";

  if (!payments || payments.length === 0) {
    noItems.style.display = "";
    return;
  }
  noItems.style.display = "none";

  const isOwner = window._isOwner || false;
  payments.forEach((p) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
            <td class="small">${_bvEsc(p.payment_date || "—")}</td>
            <td class="small fw-semibold text-success text-end">${_formatCost(p.amount)}</td>
            <td class="small">${p.payment_method ? _bvEsc(_capitalize(p.payment_method.replace(/_/g, " "))) : "—"}</td>
            <td class="small">${_bvEsc(p.recorded_by || "System")}</td>
            <td class="small text-muted">${_bvEsc(p.notes || "—")}</td>
            ${
              isOwner
                ? `<td class="text-end">
                <button style="width:24px;height:24px;border:1px solid #dc3545;border-radius:4px;background:#fff;color:#dc3545;cursor:pointer;font-size:11px;padding-left:3px;padding-top:3px;"
                    onclick="deletePayment(${p.payment_id})"
                    onmouseover="this.style.background='#dc3545';this.style.color='#fff'"
                    onmouseout="this.style.background='#fff';this.style.color='#dc3545'">
                    <i class="feather-x"></i>
                </button>
            </td>`
                : "<td></td>"
            }`;
    tbody.appendChild(tr);
  });
}

function openAddPaymentFromView() {
  _billingHandoff = true; // going to Add Payment: don't reload the list yet
  bootstrap.Modal.getInstance(
    document.getElementById("billingViewModal"),
  )?.hide();
  setTimeout(
    () =>
      openAddPaymentModal(
        _currentInvoiceId,
        _currentInvoiceCode,
        _currentBalance,
        _currentTotal,
      ),
    300,
  );
}

function openAddPaymentModal(invoiceId, invoiceCode, balance, total) {
  _currentInvoiceId = invoiceId;
  _currentInvoiceCode = invoiceCode;
  const bal = parseFloat(balance) || 0;
  const tot = parseFloat(total) || bal;

  document.getElementById("pm_invoice_id").value = invoiceId;
  document.getElementById("pm_invoice_code").textContent = invoiceCode || "—";
  document.getElementById("pm_amount").value = "";
  _pmResetMethod();
  // Today in local time (toISOString is UTC: before 8 AM in PH it would give yesterday)
  const now = new Date();
  document.getElementById("pm_date").value =
    `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
  document.getElementById("pm_recorded_by").value = window._currentUser || "";
  document.getElementById("pm_notes").value = "";
  document.getElementById("pmModalError").style.display = "none";
  document.getElementById("pm_discount_card").style.display = "none";
  document.getElementById("pm_discount_unavailable").hidden = true;
  _pmWizReset(invoiceCode);
  _setPaymentAmounts(tot, Math.max(0, tot - bal));

  bootstrap.Modal.getOrCreateInstance(document.getElementById("addPaymentModal")).show();
  _pmLoadAll(invoiceId, true);
}

// Step 1 details + Step 2 vouchers from ONE api/billing.php response
// (reused from View Details when it was just loaded).
function _pmLoadAll(invoiceId, allowReuse) {
  const view = _fetchInvoiceView(invoiceId, allowReuse);
  _pmLoadInvoice(invoiceId, view);                                     // Step 1 details
  _loadPaymentDiscounts(view.then((res) => (res && res.success ? res.discounts : null))); // vouchers + up-to-date amounts
}

// ── Payment method cards (Add Payment) ───────────────────────
// UI only for now. The four category cards and their options only decide the
// hidden #pm_method value that Record Payment has always sent
// (cash / gcash / credit_card / debit_card / bank_transfer); nothing else here
// is saved, sent, verified or processed. No payment gateway is involved.
// Maya and GrabPay can be picked but not recorded yet: there is no stored
// method value for them until e-wallet processing is added.
const PM_UNSUPPORTED_EWALLET = { maya: "Maya", grabpay: "GrabPay" };

function _pmChecked(name) {
  const r = document.querySelector(`#addPaymentModal input[name="${name}"]:checked`);
  return r ? r.value : "";
}

/** The stored method value for the current selection ('' when incomplete). */
function _pmMethodValue() {
  const cat = _pmChecked("pm_cat");
  if (cat === "cash") return "cash";
  if (cat === "bank") return "bank_transfer";
  if (cat === "card") return _pmChecked("pm_card");            // credit_card / debit_card
  if (cat === "ewallet") return _pmChecked("pm_ewallet") === "gcash" ? "gcash" : "";
  return "";
}

/** Message for an incomplete selection, or '' when Record Payment may go ahead. */
function _pmMethodError() {
  const cat = _pmChecked("pm_cat");
  if (!cat) return "Please select the payment method.";
  if (cat === "ewallet") {
    const ew = _pmChecked("pm_ewallet");
    if (!ew) return "Please select the e-wallet provider.";
    if (PM_UNSUPPORTED_EWALLET[ew]) return `${PM_UNSUPPORTED_EWALLET[ew]} payments can't be recorded yet. Choose GCash, or another payment method.`;
  }
  if (cat === "card" && !_pmChecked("pm_card")) return "Please select Credit Card or Debit Card.";
  return document.getElementById("pm_method").value ? "" : "Please select the payment method.";
}

function _pmSyncMethod() {
  const modal = document.getElementById("addPaymentModal");
  if (!modal) return;
  const cat = _pmChecked("pm_cat");
  modal.querySelectorAll("[data-pm-panel]").forEach((el) => { el.hidden = el.dataset.pmPanel !== cat; });
  const reveal = { ewallet: !!_pmChecked("pm_ewallet"), card: !!_pmChecked("pm_card") };
  modal.querySelectorAll("[data-pm-reveal]").forEach((el) => { el.hidden = !reveal[el.dataset.pmReveal]; });
  modal.querySelectorAll(".pm-cat, .pm-opt").forEach((l) => {
    const r = l.querySelector("input[type=radio]");
    l.classList.toggle("is-selected", !!(r && r.checked));
  });
  document.getElementById("pm_method").value = _pmMethodValue();

  const note = document.getElementById("pm_method_note");
  const ew = cat === "ewallet" ? _pmChecked("pm_ewallet") : "";
  if (PM_UNSUPPORTED_EWALLET[ew]) {
    note.innerHTML = `<i class="feather-info"></i> Recording ${PM_UNSUPPORTED_EWALLET[ew]} payments will be available once e-wallet processing is added.`;
    note.hidden = false;
  } else {
    note.hidden = true;
    note.textContent = "";
  }
  // Clear a "select the method" error once the selection is complete
  const errBox = document.getElementById("pmModalError");
  if (errBox && errBox.style.display !== "none" && !_pmMethodError() && /method|provider|Credit Card|recorded yet/.test(errBox.textContent)) {
    errBox.style.display = "none";
  }
}

function _pmResetMethod() {
  const modal = document.getElementById("addPaymentModal");
  if (!modal) return;
  modal.querySelectorAll('input[name="pm_cat"], input[name="pm_ewallet"], input[name="pm_card"]').forEach((r) => { r.checked = false; });
  ["pm_ew_ref", "pm_card_ref", "pm_bank_ref", "pm_bank_sender", "pm_bank_date", "pm_bank_name"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.value = "";
  });
  if (window.refreshCustomSelects) window.refreshCustomSelects();
  _pmSyncMethod();
}

document.addEventListener("change", (e) => {
  if (e.target && e.target.matches && e.target.matches('#addPaymentModal input[name="pm_cat"], #addPaymentModal input[name="pm_ewallet"], #addPaymentModal input[name="pm_card"]')) {
    // A new category starts with its own options unselected
    if (e.target.name === "pm_cat") {
      document.querySelectorAll('#addPaymentModal input[name="pm_ewallet"], #addPaymentModal input[name="pm_card"]').forEach((r) => { r.checked = false; });
    }
    _pmSyncMethod();
  }
});

// ══════════════════════════════════════════════════════════════
//  ADD PAYMENT WIZARD — 1 Invoice Details · 2 Adjustments · 3 Payment
//  Information · 4 Review & Confirm. Only steers the existing pieces:
//  vouchers (applyInvoiceVoucher / _loadPaymentDiscounts), amounts
//  (_setPaymentAmounts), method cards (_pmMethodError) and savePayment().
//  Values stay in their fields while moving Back and forward.
// ══════════════════════════════════════════════════════════════
const PM_STEPS = 4;
const _pmWiz = { step: 1, reached: 1, inv: null, disc: null, discLoading: false, invLoading: false, total: 0, paid: 0, submitting: false };

function _pmShowError(msg) {
  const box = document.getElementById("pmModalError");
  box.textContent = msg;
  box.style.display = "block";
  const main = document.querySelector("#addPaymentModal .pm-wiz-main");
  if (main) main.scrollTop = 0;
}

function _pmWizReset(code) {
  Object.assign(_pmWiz, { step: 1, reached: 1, inv: null, disc: null, total: 0, paid: 0, submitting: false });
  document.getElementById("pm_s1_code").textContent = code || "—";
  ["pm_s1_patient", "pm_s1_branch", "pm_s1_date", "pm_s1_status", "pm_subtotal", "pm_adjusted"].forEach((id) => {
    document.getElementById(id).textContent = "—";
  });
  _pmSetBusy(false);
  _pmGo(1, true);
}

// Step 1: the invoice itself (patient, branch, date, status), read-only
function _pmLoadInvoice(invoiceId, view) {
  const loading = document.getElementById("pm_s1_loading");
  loading.hidden = false;
  loading.innerHTML = '<span class="spinner-border spinner-border-sm text-primary"></span> Loading the invoice…';
  _pmWiz.invLoading = true;
  return (view || _fetchInvoiceView(invoiceId, false))
    .then((res) => {
      if (invoiceId !== _currentInvoiceId) return;
      if (!res.success) throw new Error(res.message || "Could not load the invoice.");
      const inv = res.data;
      _pmWiz.inv = inv;
      const fmt = (d) => d ? new Date(String(d).length === 10 ? d + "T00:00:00" : d)
        .toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" }) : "—";
      document.getElementById("pm_s1_code").textContent = inv.invoice_code || "—";
      document.getElementById("pm_s1_patient").textContent = inv.patients?.full_name || "—";
      document.getElementById("pm_s1_branch").textContent = inv.branches?.branch_name || "—";
      document.getElementById("pm_s1_date").textContent = fmt(inv.invoice_date);
      document.getElementById("pm_s1_status").innerHTML = _paymentBadge(inv.payment_status);
      // Voucher totals (Step 2) are the fresher source once they have loaded
      if (!_pmWiz.disc) _setPaymentAmounts(+inv.total_amount || 0, +inv.amount_paid || 0);
      loading.hidden = true;
    })
    .catch((e) => {
      if (invoiceId !== _currentInvoiceId) return;
      loading.innerHTML = `<i class="feather-alert-circle text-danger"></i> ${_bvEsc(e.message || "Could not load the invoice.")} <button type="button" class="pm-retry" onclick="_pmLoadAll(_currentInvoiceId, false)">Retry</button>`;
    })
    .finally(() => { if (invoiceId === _currentInvoiceId) _pmWiz.invLoading = false; });
}

// '' when the step may be left forward, else what to fix
function _pmStepError(n) {
  if (n === 1) {
    if (_pmWiz.invLoading || !_pmWiz.inv) return _pmWiz.invLoading ? "The invoice is still loading. Please wait a moment." : "The invoice could not be loaded. Use Retry, or close and try again.";
    if (_currentBalance <= 0) return "This invoice has no outstanding balance, so there is nothing to pay.";
    return "";
  }
  if (n === 2) {
    if (_pmWiz.discLoading) return "The discount vouchers are still loading. Please wait a moment.";
    const btn = document.getElementById("pm_voucher_btn");
    if (btn && btn.disabled && document.getElementById("pm_voucher_select")?.value) return "The voucher is still being applied. Please wait a moment.";
    const sel = document.getElementById("pm_voucher_select");
    const apply = document.getElementById("pm_voucher_apply");
    if (sel && sel.value && apply && apply.style.display !== "none" && document.getElementById("pm_discount_card").style.display !== "none")
      return `You chose voucher ${sel.value} but haven't applied it. Click Apply, or clear the selection.`;
    return "";
  }
  if (n === 3) {
    const raw = document.getElementById("pm_amount").value;
    const amount = parseFloat(raw) || 0;
    const balance = parseFloat(document.getElementById("pm_max_balance").value) || 0;
    if (!raw || amount <= 0) return "Please enter the amount to pay.";
    if (!/^\d+(\.\d{1,2})?$/.test(String(raw).trim())) return "Enter the amount in pesos, with at most two decimal places.";
    if (amount > balance) return `The amount exceeds the outstanding balance of ${_formatCost(balance)}.`;
    if (!document.getElementById("pm_date").value) return "Please select the payment date.";
    return _pmMethodError();
  }
  return "";
}

function _pmMethodLabel() {
  const m = document.getElementById("pm_method").value;
  const label = { cash: "Cash", gcash: "GCash (E-Wallet)", credit_card: "Credit Card", debit_card: "Debit Card", bank_transfer: "Bank Transfer" }[m] || "—";
  const bank = document.getElementById("pm_bank_name")?.value;
  return m === "bank_transfer" && bank ? `${label} · ${bank}` : label;
}

// Reference number of the chosen method (display only — not saved yet)
function _pmReference() {
  const cat = _pmChecked("pm_cat");
  const id = { ewallet: "pm_ew_ref", card: "pm_card_ref", bank: "pm_bank_ref" }[cat];
  if (!id) return null;   // Cash: not applicable
  return (document.getElementById(id)?.value || "").trim();
}

function _pmFillReview() {
  const inv = _pmWiz.inv || {};
  const set = (id, v) => { document.getElementById(id).textContent = v; };
  const amount = Math.round((parseFloat(document.getElementById("pm_amount").value) || 0) * 100) / 100;
  const adjusted = _pmWiz.total;
  const original = _pmWiz.disc ? _pmWiz.disc.subtotal : adjusted;
  const discount = Math.max(0, Math.round((original - adjusted) * 100) / 100);
  const balance = Math.max(0, Math.round((adjusted - _pmWiz.paid) * 100) / 100);
  const remaining = Math.max(0, Math.round((balance - amount) * 100) / 100);

  set("pm_rv_code", inv.invoice_code || _currentInvoiceCode || "—");
  set("pm_rv_patient", inv.patients?.full_name || "—");
  set("pm_rv_branch", inv.branches?.branch_name || "—");
  const d = document.getElementById("pm_date").value;
  set("pm_rv_date", d ? new Date(d + "T00:00:00").toLocaleDateString("en-PH", { weekday: "short", month: "short", day: "numeric", year: "numeric" }) : "—");
  set("pm_rv_method", _pmMethodLabel());
  const ref = _pmReference();
  const refEl = document.getElementById("pm_rv_ref");
  refEl.innerHTML = ref === null ? '<span class="ar-muted">Not applicable</span>'
    : ref ? `${_bvEsc(ref)}<span class="pm-rv-note">For reference only, not saved yet</span>`
    : '<span class="ar-muted">None entered</span>';
  const notes = document.getElementById("pm_notes").value.trim();
  set("pm_rv_notes", notes || "—");
  set("pm_rv_original", _formatCost(original));
  set("pm_rv_discount", discount ? "−" + _formatCost(discount) : "None");
  set("pm_rv_adjusted", _formatCost(adjusted));
  set("pm_rv_paid", _formatCost(_pmWiz.paid));
  set("pm_rv_amount", _formatCost(amount));
  const remEl = document.getElementById("pm_rv_remaining");
  remEl.textContent = _formatCost(remaining);
  remEl.className = "ar-card-value fw-bold " + (remaining > 0 ? "text-danger" : "text-success");
  document.getElementById("pm_rv_status").innerHTML = remaining > 0
    ? `<i class="feather-info"></i> This is a <strong>partial payment</strong>. The invoice will be marked <strong>Partially Paid</strong> with ${_formatCost(remaining)} still due.`
    : `<i class="feather-check-circle"></i> This payment settles the invoice. It will be marked <strong>Paid</strong>.`;
}

function _pmRender() {
  const modal = document.getElementById("addPaymentModal");
  modal.querySelectorAll(".pm-pane").forEach((el) => { el.hidden = +el.dataset.pane !== _pmWiz.step; });
  modal.querySelectorAll(".pm-step").forEach((li) => {
    const n = +li.dataset.step;
    li.classList.toggle("is-active", n === _pmWiz.step);
    li.classList.toggle("is-done", n < _pmWiz.step || (n < _pmWiz.reached && n !== _pmWiz.step));
    const b = li.querySelector(".pm-step-btn");
    b.disabled = _pmWiz.submitting || n > _pmWiz.reached;
    if (n === _pmWiz.step) b.setAttribute("aria-current", "step"); else b.removeAttribute("aria-current");
  });
  const last = _pmWiz.step === PM_STEPS;
  document.getElementById("pmBackBtn").hidden = _pmWiz.step === 1;
  document.getElementById("pmNextBtn").hidden = last;
  document.getElementById("pmSaveBtn").hidden = !last;
  if (last) _pmFillReview();
}

/** Show step n. Going forward checks every step on the way. */
function _pmGo(n, force) {
  n = Math.max(1, Math.min(PM_STEPS, n));
  if (!force && n > _pmWiz.step) {
    for (let k = _pmWiz.step; k < n; k++) {
      const err = _pmStepError(k);
      if (err) {
        if (k !== _pmWiz.step) { _pmWiz.step = k; _pmRender(); }
        _pmShowError(err);
        return false;
      }
    }
  }
  document.getElementById("pmModalError").style.display = "none";
  _pmWiz.step = n;
  _pmWiz.reached = Math.max(_pmWiz.reached, n);
  _pmRender();
  const main = document.querySelector("#addPaymentModal .pm-wiz-main");
  if (main) main.scrollTop = 0;
  if (!force) {
    // Focus the step heading so keyboard / screen-reader users land in it
    const h = document.querySelector(`#addPaymentModal .pm-pane[data-pane="${n}"] .pm-pane-title`);
    if (h) { h.setAttribute("tabindex", "-1"); h.focus({ preventScroll: true }); }
  }
  return true;
}

// While saving: no navigation, no closing (the request decides what happens)
function _pmSetBusy(on) {
  const modal = document.getElementById("addPaymentModal");
  if (!modal) return;
  ["pmBackBtn", "pmNextBtn"].forEach((id) => { const b = document.getElementById(id); if (b) b.disabled = on; });
  modal.querySelectorAll('[data-bs-dismiss="modal"]').forEach((b) => { b.disabled = on; });
  modal.querySelectorAll(".pm-step-btn").forEach((b) => { if (on) b.disabled = true; });
  if (!on && document.getElementById("pmNextBtn")) _pmRender();
}

document.addEventListener("click", (e) => {
  const t = e.target.closest && e.target.closest("#addPaymentModal [data-goto], #pmNextBtn, #pmBackBtn");
  if (!t || _pmWiz.submitting) return;
  if (t.id === "pmNextBtn") _pmGo(_pmWiz.step + 1);
  else if (t.id === "pmBackBtn") _pmGo(_pmWiz.step - 1);
  else _pmGo(+t.getAttribute("data-goto"));
});
// Enter in a Step 3 field moves on (instead of doing nothing)
document.addEventListener("keydown", (e) => {
  if (e.key !== "Enter" || !e.target.closest || !e.target.closest('#addPaymentModal .pm-pane[data-pane="3"]')) return;
  if (e.target.matches("input[type=text], input[type=number]")) { e.preventDefault(); _pmGo(4); }
});
// The amount field: clear an amount error as soon as it is fixed
document.addEventListener("input", (e) => {
  if (e.target && e.target.id === "pm_amount" && _pmWiz.step === 3) {
    const box = document.getElementById("pmModalError");
    if (box.style.display !== "none" && /amount/i.test(box.textContent) && !/amount/i.test(_pmStepError(3))) box.style.display = "none";
  }
});
// Don't close the modal (Escape / backdrop) while the payment is being saved
document.addEventListener("hide.bs.modal", (e) => {
  if (e.target && e.target.id === "addPaymentModal" && _pmWiz.submitting) e.preventDefault();
});

function setPaymentAmount(type) {
  const balance =
    parseFloat(document.getElementById("pm_max_balance").value) || 0;
  if (type === "full")
    document.getElementById("pm_amount").value = balance.toFixed(2);
  if (type === "half")
    document.getElementById("pm_amount").value = (balance / 2).toFixed(2);
}

function savePayment() {
  const errBox = document.getElementById("pmModalError");
  const btn = document.getElementById("pmSaveBtn");
  if (_pmWiz.submitting) return;   // one request per click, however many clicks
  const amount = parseFloat(document.getElementById("pm_amount").value) || 0;
  errBox.style.display = "none";

  // Same checks as before (Steps 1–3), in case anything changed since
  const err = _pmStepError(1) || _pmStepError(3);
  if (err) {
    _pmGo(_pmStepError(1) ? 1 : 3, true);
    _pmShowError(err);
    return;
  }

  _pmWiz.submitting = true;
  _pmSetBusy(true);
  btn.disabled = true;
  btn.innerHTML =
    '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

  fetch("api/payments.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      invoice_id: parseInt(document.getElementById("pm_invoice_id").value),
      amount,
      payment_method: document.getElementById("pm_method").value || null,
      payment_date: document.getElementById("pm_date").value,
      recorded_by:
        window._currentUser ||
        document.getElementById("pm_recorded_by").value ||
        null,
      notes: document.getElementById("pm_notes").value || null,
    }),
  })
    .then((r) => r.json())
    .then((res) => {
      btn.disabled = false;
      btn.innerHTML = '<i class="feather-check me-1"></i> Record Payment';
      _pmWiz.submitting = false;
      _pmSetBusy(false);
      if (res.success) {
        bootstrap.Modal.getInstance(
          document.getElementById("addPaymentModal"),
        )?.hide();
        _billingChanged = false; // the list refreshes right after the alert anyway
        _bvForget();
        _esToast("Payment Recorded",
          `Payment of <strong>${_formatCost(amount)}</strong> recorded.<br>` +
          `Balance: <strong>${_formatCost(res.new_balance)}</strong> · Status: <strong>${_bvEsc(_capitalize(res.new_status))}</strong>`)
          .then(() => esRefresh());
      } else {
        errBox.textContent = res.message || "Failed to record payment.";
        errBox.style.display = "block";
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="feather-check me-1"></i> Record Payment';
      _pmWiz.submitting = false;
      _pmSetBusy(false);
      errBox.textContent = "Network error. Please try again.";
      errBox.style.display = "block";
    });
}

function deletePayment(paymentId) {
  Swal.fire({
    title: "Remove Payment?",
    text: "This will reverse the payment and update the invoice balance.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#dc3545",
    cancelButtonColor: "#6c757d",
    confirmButtonText: "Yes, Remove",
  }).then((result) => {
    if (!result.isConfirmed) return;
    fetch(`api/payments.php?id=${paymentId}`, { method: "DELETE" })
      .then((r) => r.json())
      .then((res) => {
        if (res.success) {
          _bvForget();
          Swal.fire({
            icon: "success",
            title: "Removed!",
            timer: 1500,
            showConfirmButton: false,
          }).then(() => openBillingDetails(_currentInvoiceId));
        } else {
          Swal.fire(
            "Error",
            res.message || "Could not remove payment.",
            "error",
          );
        }
      })
      .catch(() => Swal.fire("Error", "Network error.", "error"));
  });
}

function _formatCost(amount) {
  if (amount === null || amount === undefined || amount === "") return "—";
  return (
    "₱" +
    parseFloat(amount).toLocaleString("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}
function _capitalize(str) {
  return str ? str.charAt(0).toUpperCase() + str.slice(1) : "—";
}
function _paymentBadge(status) {
  const map = {
    paid: "bg-soft-success text-success",
    partial: "bg-soft-warning text-warning",
    unpaid: "bg-soft-danger text-danger",
  };
  return `<span class="badge ${map[status] || "bg-soft-secondary text-secondary"}">${_capitalize(status || "unpaid")}</span>`;
}

function _bvEsc(v) {
  return String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}


// ══════════════════════════════════════════════════════════════
//  RECEIPTS (Billing Records → Action → Print Receipt / Download PDF)
//  Both reuse the existing receipt.php / generate-receipt-pdf.php, which
//  render the one receipt template and check branch access on the server.
// ══════════════════════════════════════════════════════════════

// ── Print Receipt → preview modal ────────────────────────────
// receipt.php?embed=1 (receipt only, no toolbar) in a same-site iframe, the
// same embed mode the Patient Portal's receipt modal uses. Print prints the
// frame only, with the receipt's own print layout.
// The modal markup comes after this script in billing-records.php, so set up
// once the document has been parsed.
function _initReceiptPreview() {
  const modalEl = document.getElementById("receiptPreviewModal");
  if (!modalEl || modalEl.dataset.rcpReady) return;
  modalEl.dataset.rcpReady = "1";
  const frame = document.getElementById("rcpFrame");
  const loading = document.getElementById("rcpLoading");
  const printBtn = document.getElementById("rcpPrintBtn");
  let current = 0;

  function fit() {
    try {
      const d = frame.contentDocument;
      if (d && d.body) {
        const cs = d.defaultView.getComputedStyle(d.body);
        const h = d.body.getBoundingClientRect().height + parseFloat(cs.marginTop || 0) + parseFloat(cs.marginBottom || 0);
        frame.style.height = Math.max(320, Math.ceil(h) + 2) + "px";
      }
    } catch (e) { /* keep the CSS height */ }
  }

  frame.addEventListener("load", function () {
    if (!current || !frame.getAttribute("src")) return;
    loading.style.display = "none";
    frame.classList.add("ready");
    let ok = false;
    try { ok = frame.contentDocument.title !== "Receipt unavailable"; } catch (e) { ok = false; }
    printBtn.disabled = !ok;
    fit();
    try {
      const f = frame.contentDocument.fonts;
      if (f && f.ready) f.ready.then(fit, function () {});
    } catch (e) {}
  });

  window.openReceiptPreview = function (invoiceId, invoiceCode) {
    const id = parseInt(invoiceId, 10);
    if (!id) return;
    current = id;
    document.getElementById("rcp_code").textContent = invoiceCode || "—";
    printBtn.disabled = true;
    loading.style.display = "";
    frame.classList.remove("ready");
    frame.style.height = "";
    frame.setAttribute("src", "receipt.php?invoice_id=" + id + "&embed=1");
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  };

  window.addEventListener("resize", function () {
    if (modalEl.classList.contains("show") && frame.classList.contains("ready")) fit();
  });
  modalEl.addEventListener("hidden.bs.modal", function () {
    current = 0;
    frame.removeAttribute("src");   // the next receipt never flashes this one
    frame.classList.remove("ready");
  });
  printBtn.addEventListener("click", function () {
    try {
      frame.contentWindow.focus();
      frame.contentWindow.print();
    } catch (e) {
      console.error("Receipt print failed:", e);
    }
  });

  document.addEventListener("click", function (e) {
    const a = e.target.closest && e.target.closest("a[data-receipt-modal]");
    if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;   // modifier click: the link's own new tab
    e.preventDefault();
    openReceiptPreview(a.getAttribute("data-receipt-modal"), a.getAttribute("data-invoice-code"));
  });
}
if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", _initReceiptPreview);
else _initReceiptPreview();

// ── Download PDF → loading state in the menu item ─────────────
// Same generate-receipt-pdf.php as before, fetched so the app can show
// progress. The spinner ("Preparing Download…") appears only when the PDF
// takes longer than PDF_SPINNER_DELAY, so fast downloads don't flash it. The
// item ignores further clicks while its download is running, and is always
// restored, on success or failure. Saving starts only once the PDF has
// actually been received.
const PDF_SPINNER_DELAY = 350;   // ms
const _pdfBusy = new Set();

function _pdfReason(body) {
  const m = String(body || "").match(/<p[^>]*>([\s\S]*?)<\/p>/i);   // receipt_fail() puts the reason in a <p>
  return m ? m[1].replace(/<[^>]+>/g, "").trim() : "";
}

async function downloadReceiptPdf(link) {
  const id = parseInt(link.getAttribute("data-receipt-pdf"), 10);
  if (!id || _pdfBusy.has(id)) return;
  _pdfBusy.add(id);
  const code = link.getAttribute("data-invoice-code") || "";
  const label = link.querySelector(".rc-pdf-label");
  const icon = link.querySelector("i");
  const original = { label: label ? label.textContent : "", icon: icon ? icon.className : "" };
  link.classList.add("rc-pdf-pending");
  link.setAttribute("aria-disabled", "true");
  const timer = setTimeout(() => {
    link.classList.add("rc-pdf-busy");
    link.setAttribute("aria-busy", "true");
    if (icon) icon.className = "spinner-border spinner-border-sm rc-pdf-spin";
    if (label) label.textContent = "Preparing Download…";
  }, PDF_SPINNER_DELAY);

  const restore = () => {
    clearTimeout(timer);
    link.classList.remove("rc-pdf-busy", "rc-pdf-pending");
    link.removeAttribute("aria-busy");
    link.removeAttribute("aria-disabled");
    if (icon) icon.className = original.icon;
    if (label) label.textContent = original.label;
    _pdfBusy.delete(id);
  };
  const closeMenu = () => {
    // While open, the row menu lives in .bil-menu-holder (see above); its row
    // is remembered as menu._bilHome.
    const menu = link.closest(".dropdown-menu");
    const home = (menu && menu._bilHome) || link.closest(".dropdown");
    const toggle = home && home.querySelector('[data-bs-toggle="dropdown"]');
    if (toggle && window.bootstrap) bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
  };

  try {
    const res = await fetch("generate-receipt-pdf.php?invoice_id=" + id, { credentials: "same-origin" });
    if (res.status === 401) { location.href = "login.php"; return; }
    const type = res.headers.get("Content-Type") || "";
    if (!res.ok || type.indexOf("application/pdf") === -1) {
      const body = await res.text().catch(() => "");
      throw Object.assign(new Error(_pdfReason(body) || "The receipt PDF could not be generated. Please try again."), { fromServer: true });
    }
    const blob = await res.blob();   // the whole file has arrived
    const safe = String(code || id).replace(/[^A-Za-z0-9_-]/g, "");
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "Receipt-" + (safe || id) + ".pdf";
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 10000);
    restore();
    closeMenu();
    if (window.esToast) esToast("Receipt PDF downloaded.", "success");
  } catch (err) {
    restore();
    closeMenu();
    console.error("Receipt PDF failed:", err);
    const msg = err && err.fromServer ? err.message : "Could not reach the server. Please check your connection and try again.";
    if (window.Swal) {
      Swal.fire({
        icon: "error",
        iconHtml: '<i class="feather-alert-triangle es-swal-glyph"></i>',
        title: "Download Failed",
        html: `<p class="es-swal-text">${_bvEsc(msg)}</p>`,
        confirmButtonText: "Okay",
        buttonsStyling: false,
        customClass: ES_SWAL_TONE("danger"),
      });
    } else if (window.esToast) {
      esToast(msg, "error");
    }
  }
}

// Capture phase: runs before Bootstrap's document-level handler, so the menu
// stays open and the item's progress stays visible until the PDF is ready.
document.addEventListener("click", function (e) {
  const a = e.target.closest && e.target.closest("a[data-receipt-pdf]");
  if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
  e.preventDefault();
  e.stopPropagation();
  downloadReceiptPdf(a);
}, true);
