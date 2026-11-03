/* =============================================================
   EssenciaSmile — Billing JS
   File: assets/js/billing.js
   ============================================================= */

let _currentInvoiceId = null;
let _currentInvoiceCode = null;
let _currentBalance = 0;
let _currentTotal = 0;

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

  fetch(`api/billing.php?id=${invoiceId}`)
    .then((r) => r.json())
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
      _loadInvoiceDiscounts(inv, lineSum);
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

// Invoice view: charge, total and vouchers
function _loadInvoiceDiscounts(inv, lineSum) {
  const box = document.getElementById("bv_discount");
  if (!box) return;
  box.className = "ar-card-value ar-muted";
  box.textContent = "None recorded";

  _fetchInvoiceDiscounts(inv.invoice_id)
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
  document.getElementById("pm_total").textContent = _formatCost(total);
  document.getElementById("pm_paid").textContent = _formatCost(paid);
  document.getElementById("pm_balance").textContent = _formatCost(balance);
  document.getElementById("pm_max_balance").value = balance;
  document.getElementById("pm_amount_hint").textContent = `Max payable: ${_formatCost(balance)}`;
  const amt = document.getElementById("pm_amount");
  if ((parseFloat(amt.value) || 0) > balance) amt.value = balance ? balance.toFixed(2) : "";
}
function _loadPaymentDiscounts() {
  const card = document.getElementById("pm_discount_card");
  _fetchInvoiceDiscounts(_currentInvoiceId)
    .then((res) => {
      // Hidden before the discounts SQL is run, and for roles that can't apply vouchers
      if (!res.success || !res.can_edit) { card.style.display = "none"; return; }
      card.style.display = "";
      document.getElementById("pm_subtotal").textContent = _formatCost(res.subtotal);
      _setPaymentAmounts(res.total, res.paid);
      _renderInvoiceDiscounts("pm", res);
    })
    .catch(() => { card.style.display = "none"; });
}

// After a voucher change on Add Payment, refresh its amounts
function _afterDiscountChange() {
  _billingChanged = true;
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
    reverseButtons: true,
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
    if (_billingChanged) location.reload();
  });
  // Add Payment cancelled after a discount change: refresh the list then
  document.getElementById("addPaymentModal")?.addEventListener("hidden.bs.modal", () => {
    if (_billingChanged && !document.querySelector(".modal.show")) location.reload();
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
  document.getElementById("pm_method").value = "";
  // Today in local time (toISOString is UTC: before 8 AM in PH it would give yesterday)
  const now = new Date();
  document.getElementById("pm_date").value =
    `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
  document.getElementById("pm_recorded_by").value = window._currentUser || "";
  document.getElementById("pm_notes").value = "";
  document.getElementById("pmModalError").style.display = "none";
  document.getElementById("pm_discount_card").style.display = "none";
  _setPaymentAmounts(tot, Math.max(0, tot - bal));

  bootstrap.Modal.getOrCreateInstance(document.getElementById("addPaymentModal")).show();
  _loadPaymentDiscounts(); // vouchers + up-to-date amounts
}

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
  const amount = parseFloat(document.getElementById("pm_amount").value) || 0;
  const balance =
    parseFloat(document.getElementById("pm_max_balance").value) || 0;
  errBox.style.display = "none";

  if (amount <= 0) {
    errBox.textContent = "Please enter a valid amount.";
    errBox.style.display = "block";
    return;
  }
  if (amount > balance) {
    errBox.textContent = `Amount exceeds balance of ${_formatCost(balance)}.`;
    errBox.style.display = "block";
    return;
  }
  if (!document.getElementById("pm_method").value) {
    errBox.textContent = "Please select the payment method.";
    errBox.style.display = "block";
    return;
  }
  if (!document.getElementById("pm_date").value) {
    errBox.textContent = "Please select the payment date.";
    errBox.style.display = "block";
    return;
  }

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
      if (res.success) {
        bootstrap.Modal.getInstance(
          document.getElementById("addPaymentModal"),
        )?.hide();
        _billingChanged = false; // reloading right after the alert anyway
        _esToast("Payment Recorded",
          `Payment of <strong>${_formatCost(amount)}</strong> recorded.<br>` +
          `Balance: <strong>${_formatCost(res.new_balance)}</strong> · Status: <strong>${_bvEsc(_capitalize(res.new_status))}</strong>`)
          .then(() => location.reload());
      } else {
        errBox.textContent = res.message || "Failed to record payment.";
        errBox.style.display = "block";
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="feather-check me-1"></i> Record Payment';
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

function deleteBilling(id, code) {
  Swal.fire({
    title: "Delete Invoice?",
    html: `Delete invoice <strong>${code}</strong>? This cannot be undone.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#dc3545",
    cancelButtonColor: "#6c757d",
    confirmButtonText: "Yes, Delete",
  }).then((result) => {
    if (!result.isConfirmed) return;
    fetch(`api/billing.php?id=${id}`, { method: "DELETE" })
      .then((r) => r.json())
      .then((res) => {
        if (res.success) {
          Swal.fire({
            icon: "success",
            title: "Deleted!",
            timer: 1500,
            showConfirmButton: false,
          }).then(() => location.reload());
        } else {
          Swal.fire(
            "Error",
            res.message || "Could not delete invoice.",
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
