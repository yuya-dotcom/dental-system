// assets/js/portal-dashboard.js
// Requires: const API defined in portal-dashboard.php before this file loads

// ── State ─────────────────────────────────────────────────────────────────────
let currentCancelId = null;
let accountData = {};
let isProfileComplete = false;

// ── Helpers ───────────────────────────────────────────────────────────────────
const _cache = {};
const _cacheTime = {}; // key → when it was fetched (ms)
// Sections that show cached data first re-check the server in the background,
// at most once per this window. Anything the patient changes clears the cache
// (clearCache), so their own edits always show up immediately.
const REVALIDATE_MS = 30 * 1000;

function cacheSet(key, data) {
  _cache[key] = data;
  _cacheTime[key] = Date.now();
}

function cacheIsFresh(key) {
  return !!_cache[key] && Date.now() - (_cacheTime[key] || 0) < REVALIDATE_MS;
}

// Requests already on their way, by cache key: opening a section while its
// background prefetch is still loading waits for that same request.
const _inflight = {};

async function php(path, body) {
  const key = JSON.stringify(body);
  if (_cache[key]) return _cache[key];
  if (_inflight[key]) return _inflight[key];

  const req = fetch(path, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  })
    .then((r) => r.json())
    .then((data) => {
      cacheSet(key, data);
      return data;
    })
    .finally(() => delete _inflight[key]);
  _inflight[key] = req;
  return req;
}

function clearCache(...actions) {
  actions.forEach((action) => {
    const key = JSON.stringify({ action });
    delete _cache[key];
    delete _cacheTime[key];
  });
}

function peso(v) {
  return (
    "₱" +
    parseFloat(v || 0).toLocaleString("en-PH", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

function fmtDate(d) {
  if (!d) return "—";
  return new Date(d).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

function fmtTime(t) {
  if (!t) return "";
  const [h, m] = t.split(":");
  const dt = new Date();
  dt.setHours(+h, +m);
  return dt.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
}

function statusBadge(s) {
  return `<span class="badge-status badge-${s || "pending"}">${(s || "pending").replace("_", " ")}</span>`;
}

function payBadge(s) {
  return `<span class="badge-status badge-${s || "unpaid"}">${s || "unpaid"}</span>`;
}

// ── Medical Questionnaire — first-login modal ─────────────────────────────────
async function checkAndShowQuestionnaire(prefetched) {
  try {
    // prefetched: the bootstrap's answer (same request, same payload)
    const data = prefetched && prefetched.success ? prefetched : await fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "check_questionnaire" }),
    }).then((r) => r.json());

    if (!data.has_questionnaire) {
      _showQuestionnaireModal();
    }
  } catch (e) {
    // Non-blocking — if the check fails, skip silently
  }
}

function _showQuestionnaireModal() {
  const modal = document.getElementById("questionnaireModal");
  if (!modal) return;
  // Reset all radios
  modal
    .querySelectorAll("input[type=radio]")
    .forEach((r) => (r.checked = false));
  modal
    .querySelectorAll(".mq-fu-field")
    .forEach((el) => (el.style.display = "none"));
  modal.querySelectorAll(".mq-input").forEach((el) => (el.value = ""));
  modal.querySelectorAll(".is-missing").forEach((el) => el.classList.remove("is-missing"));
  document.getElementById("mqError").style.display = "none";
  // "For Women Only" questions follow the gender PHP put on the modal
  const gender = (modal.dataset.gender || "").toLowerCase();
  document.getElementById("mqWomenBlock").style.display = gender === "female" ? "block" : "none";

  new bootstrap.Modal(modal, { backdrop: "static", keyboard: false }).show();
}

// Toggle follow-up text field inside the questionnaire modal
function mqToggleFu(fuId, triggerVal, radioEl) {
  const el = document.getElementById(fuId);
  if (!el) return;
  const wrap = radioEl.closest(".mq-yn-row");
  const checked = wrap?.querySelector(
    `input[name="${radioEl.name}"]:checked`,
  )?.value;
  el.style.display = checked === triggerVal ? "block" : "none";
}

async function submitQuestionnaire() {
  const errEl = document.getElementById("mqError");
  errEl.style.display = "none";

  const gyn = (name) =>
    document.querySelector(`#questionnaireModal input[name="${name}"]:checked`)
      ?.value || null;
  const gv = (id) => document.getElementById(id)?.value?.trim() || null;

  // Validate all required Y/N questions
  const required = [
    "mq_good_health",
    "mq_under_treatment",
    "mq_serious_illness",
    "mq_hospitalized",
    "mq_taking_meds",
    "mq_tobacco",
    "mq_dangerous_drugs",
  ];
  for (const name of required) {
    if (!gyn(name)) {
      errEl.textContent = "Please answer all questions before continuing.";
      errEl.style.display = "block";
      // Scroll to first unanswered
      const firstUnanswered = document.querySelector(
        `#questionnaireModal input[name="${name}"]`,
      );
      if (firstUnanswered) {
        const row = firstUnanswered.closest(".mq-yn-row");
        row?.classList.add("is-missing"); // styled until answered
        row?.scrollIntoView({ behavior: "smooth", block: "center" });
      }
      return;
    }
  }

  const btn = document.getElementById("mqSubmitBtn");
  btn.disabled = true;
  btn.innerHTML =
    '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

  const body = {
    action: "submit_questionnaire",
    q_good_health: gyn("mq_good_health"),
    q_under_treatment: gyn("mq_under_treatment"),
    treatment_condition: gv("mq_treatment_condition"),
    q_serious_illness: gyn("mq_serious_illness"),
    illness_detail: gv("mq_illness_detail"),
    q_hospitalized: gyn("mq_hospitalized"),
    hospitalized_detail: gv("mq_hospitalized_detail"),
    q_taking_meds: gyn("mq_taking_meds"),
    meds_detail: gv("mq_meds_detail"),
    q_tobacco: gyn("mq_tobacco"),
    q_dangerous_drugs: gyn("mq_dangerous_drugs"),
    q_pregnant: gyn("mq_pregnant"),
    q_nursing: gyn("mq_nursing"),
    q_birth_control: gyn("mq_birth_control"),
  };

  try {
    const res = await fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    }).then((r) => r.json());

    if (res.success) {
      bootstrap.Modal.getInstance(
        document.getElementById("questionnaireModal"),
      )?.hide();

      // Update local state
      window._hasQuestionnaire = true;
      clearCache("get_profile");
      if (res.is_profile_complete) window._profileComplete = true;
      if (typeof window.renderProfileSummary === "function") window.renderProfileSummary();
      if (res.is_profile_complete) {
        isProfileComplete = true;
        setBookBtnState(true);
        document.getElementById("profileIncompleteAlert").style.display =
          "none";
      }

      // Alert 1 — success confirmation (auto-closes after 2s)
      await Swal.fire({
        icon: "success",
        iconHtml: '<i class="fa-solid fa-clipboard-check es-swal-glyph"></i>',
        title: "Questionnaire Complete",
        html: '<p class="es-swal-text">Thank you! Your health history has been saved securely for your dental team.</p>',
        confirmButtonText: "Continue",
        timer: 2000,
        timerProgressBar: true,
        buttonsStyling: false,
        customClass: ES_SWAL_CLASS,
      });

      // Alert 2 — reminder about Allergies & Medical Conditions
      clearCache("get_profile", "dashboard_summary");
      loadDashboard();

      Swal.fire({
        icon: "info",
        iconHtml: '<i class="fa-solid fa-notes-medical es-swal-glyph"></i>',
        title: "One More Thing",
        html: `<p class="es-swal-text">Do you have any allergies or medical conditions?
                 Adding them to your profile helps our dentists plan the safest care for you.</p>
               <ul class="es-swal-list">
                 <li><i class="fa-solid fa-hand-dots"></i> My Profile → <strong>Allergies</strong></li>
                 <li><i class="fa-solid fa-heart-pulse"></i> My Profile → <strong>Medical Conditions</strong></li>
               </ul>`,
        confirmButtonText: '<i class="fa-solid fa-user-pen"></i> Go to My Profile',
        showCancelButton: true,
        cancelButtonText: "Maybe Later",
        buttonsStyling: false,
        customClass: ES_SWAL_CLASS,
      }).then((r) => {
        if (r.isConfirmed) showPage("profile", null);
      });
    } else {
      errEl.textContent = res.message || "Failed to save. Please try again.";
      errEl.style.display = "block";
    }
  } catch (e) {
    errEl.textContent = "Network error. Please try again.";
    errEl.style.display = "block";
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Submit & Continue';
  }
}

// ── Book Appointment gate ─────────────────────────────────────────────────────
function handleBookAppt() {
  // Booking needs a verified email OR phone (also enforced by the booking API)
  if (!CONTACT_OK) {
    toggleContactGate(true);
    return;
  }
  if (!isProfileComplete) {
    // Determine what's still missing to show a specific message
    Swal.fire({
      icon: "info",
      title: "Profile Incomplete",
      html: `To book an appointment, please complete the following:
             <ul style="text-align:left;margin-top:.75rem;font-size:.9rem;line-height:2;">
               <li><i class="fa-solid fa-user" style="width:18px;"></i> <strong>Basic Info</strong> — Name, Birthdate, Gender</li>
               <li><i class="fa-solid fa-address-book" style="width:18px;"></i> <strong>Contact Info</strong> — Address &amp; City</li>
               <li><i class="fa-solid fa-clipboard-question" style="width:18px;"></i> <strong>Health Questionnaire</strong> — Complete on first login</li>
             </ul>`,
      confirmButtonColor: "#2563eb",
      confirmButtonText: "Complete My Profile",
      showCancelButton: true,
      cancelButtonText: "Later",
    }).then((r) => {
      if (r.isConfirmed) showPage("profile", null);
    });
    return;
  }
  window.location.href = "portal-web-scheduling.php";
}

function setBookBtnState(complete) {
  ["bookApptBtn"].forEach((id) => {
    const btn = document.getElementById(id);
    if (!btn) return;
    btn.style.opacity = complete ? "1" : "0.55";
    btn.style.cursor = complete ? "pointer" : "not-allowed";
    btn.style.pointerEvents = "auto";
    btn.title = complete ? "" : "Complete your profile first";
  });
}

// ── Navigation ────────────────────────────────────────────────────────────────
function showPage(page, linkEl) {
  document
    .querySelectorAll(".page-body")
    .forEach((p) => p.classList.remove("active"));
  document.getElementById("page-" + page).classList.add("active");

  document.querySelectorAll(".nav-link-item").forEach((l) => {
    l.classList.remove("active");
    l.removeAttribute("aria-current");
  });
  const activeLink = linkEl ||
    Array.from(document.querySelectorAll(".nav-link-item")).find(
      (l) => l.getAttribute("onclick")?.includes(`'${page}'`),
    );
  if (activeLink) {
    activeLink.classList.add("active");
    activeLink.setAttribute("aria-current", "page");
  }

  const titles = {
    dashboard: "Dashboard",
    appointments: "My Appointments",
    treatments: "Treatment History",
    dentalchart: "Dental Chart",
    billing: "Billing & Payments",
    files: "My Files",
    waivers: "Waivers",
    profile: "My Profile",
    feedback: "Give Feedback",
  };
  document.getElementById("topbarTitle").textContent = titles[page] || page;
  document.getElementById("topbarBreadcrumb").textContent =
    `My Portal › ${titles[page] || page}`;

  if (page === "appointments") loadAppointments();
  if (page === "treatments") loadTreatments();
  if (page === "dentalchart") loadDentalChart();
  if (page === "billing") loadBilling();
  if (page === "files") loadFiles();
  if (page === "waivers") loadWaivers();
  if (page === "profile") loadProfile();
  closeSidebar();
  // A new section starts at its top (otherwise phones keep the old scroll position)
  window.scrollTo({ top: 0, behavior: "auto" });
}

function openSidebar() {
  document.getElementById("sidebar").classList.add("open");
  document.getElementById("sidebarOverlay").classList.add("show");
  document.body.classList.add("sidebar-open");
  document.querySelector(".mob-nav-toggle")?.setAttribute("aria-expanded", "true");
  document.querySelector(".sidebar-close")?.focus();
}

function closeSidebar() {
  const sb = document.getElementById("sidebar");
  const wasOpen = sb.classList.contains("open");
  sb.classList.remove("open");
  document.getElementById("sidebarOverlay").classList.remove("show");
  document.body.classList.remove("sidebar-open");
  const toggle = document.querySelector(".mob-nav-toggle");
  if (toggle) {
    toggle.setAttribute("aria-expanded", "false");
    if (wasOpen && sb.contains(document.activeElement)) toggle.focus();
  }
}

// Escape closes the mobile menu
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape" && document.getElementById("sidebar")?.classList.contains("open")) closeSidebar();
});

// ── DASHBOARD ─────────────────────────────────────────────────────────────────
async function loadDashboard() {
  const data = await php(API, { action: "dashboard_summary" });
  if (!data.success) return;

  const { account, stats, recent_bills, is_profile_complete } = data;
  accountData = account;

  const firstName = account.first_name || "Patient";
  const lastName = account.last_name || "";
  const initials = (firstName.charAt(0) + (lastName.charAt(0) || "")).toUpperCase();

  document.getElementById("sidebarInitial").textContent = initials || "—";
  document.getElementById("sidebarName").textContent = [firstName, lastName]
    .filter(Boolean)
    .join(" ");
  document.getElementById("welcomeName").textContent = firstName;

  document.getElementById("statUpcoming").textContent = stats.upcoming || 0;
  document.getElementById("statCompleted").textContent = stats.completed || 0;
  document.getElementById("statBalance").textContent = peso(stats.balance);
  document.getElementById("statTreatments").textContent = stats.treatments || 0;

  isProfileComplete = !!is_profile_complete;
  document.getElementById("profileIncompleteAlert").style.display =
    is_profile_complete ? "none" : "flex";
  setBookBtnState(is_profile_complete);

  // "Upcoming" list on the dashboard is drawn by renderDashAppts()
  // in portal-dashboard.php (from api/portal_get_appointments.php).

  if (recent_bills && recent_bills.length > 0) {
    document.getElementById("dashBillList").innerHTML = recent_bills
      .map(
        (b) => `
        <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
          <div>
            <div style="font-weight:700;font-size:.875rem;">${b.invoice_code}</div>
            <div style="font-size:.78rem;color:var(--text-muted);">${fmtDate(b.invoice_date)}</div>
          </div>
          <div style="text-align:right;">
            <div style="font-weight:700;">${peso(b.total_amount)}</div>
            ${payBadge(b.payment_status)}
          </div>
        </div>
      `,
      )
      .join("");
  }
}

// ── APPOINTMENTS ──────────────────────────────────────────────────────────────
// The My Appointments table (with the Action menu) is rendered only by the
// inline script in portal-dashboard.php (loadPortalAppointments). This just
// hands off to it, so the old table with the plain Cancel button is never drawn.
function loadAppointments() {
  // Feedback state is re-checked each time (it can change in another tab). The
  // table draws with the last known state (preloaded) and redraws only if the
  // fresh state differs; the server enforces the one-per-appointment rule anyway.
  const before = JSON.stringify(_fbSubmitted);
  fbLoadStatus().then(() => {
    if (JSON.stringify(_fbSubmitted) !== before) fbRefreshAppointments();
  });
  if (typeof window.loadPortalAppointments === "function") {
    return window.loadPortalAppointments();
  }
}

// Cancel flow: 1) reason (required) → 2) second confirmation → API.
// "Go Back" on step 2 returns to step 1 with the reason kept; closing either
// dialog leaves the appointment unchanged. The server re-checks ownership,
// status (pending / confirmed only) and the reason.
const CANCEL_REASON_MAX = 500;

async function openCancelModal(id, code) {
  currentCancelId = id;
  let reason = "";
  // No appointment number in these dialogs (the id is only used internally)
  const REASON_MSG = "Please provide a reason for cancelling your appointment before continuing.";

  for (;;) {
    const step1 = await Swal.fire({
      icon: "warning",
      iconHtml: '<i class="fa-solid fa-calendar-xmark es-swal-glyph"></i>',
      title: "Cancel Appointment",
      html:
        '<p class="es-swal-text">Please tell us why you are cancelling this appointment.</p>' +
        '<div class="es-pw-form">' +
        '<label class="es-pw-label" for="cancelReason">Reason for cancellation</label>' +
        `<textarea id="cancelReason" class="es-pw-input es-cancel-reason" rows="3" maxlength="${CANCEL_REASON_MAX}" ` +
        'placeholder="e.g. Schedule conflict, feeling unwell…" aria-describedby="cancelReasonErr">' +
        escHtml(reason) + "</textarea>" +
        '<div class="es-pw-err" id="cancelReasonErr" role="alert"></div>' +
        "</div>",
      showCancelButton: true,
      confirmButtonText: 'Continue <i class="fa-solid fa-arrow-right" style="margin:0 0 0 6px"></i>',
      cancelButtonText: "Keep Appointment",
      // Primary action (Continue) on the left, Keep Appointment on the right
      focusConfirm: false,
      buttonsStyling: false,
      // Same compact pair as the confirmation step, so both dialogs' buttons match
      customClass: Object.assign(ES_SWAL_TONE("danger"), { actions: "es-swal-actions es-swal-actions-compact" }),
      didOpen: () => {
        const ta = document.getElementById("cancelReason");
        ta.focus();
        ta.addEventListener("input", () => {
          ta.classList.remove("is-invalid");
          document.getElementById("cancelReasonErr").textContent = "";
          Swal.resetValidationMessage();
        });
      },
      preConfirm: () => {
        const ta = document.getElementById("cancelReason");
        const v = ta.value.replace(/\s+/g, " ").trim();
        if (!v) {   // empty or spaces only
          ta.classList.add("is-invalid");
          Swal.showValidationMessage(REASON_MSG);
          ta.focus();
          return false;
        }
        return v;
      },
    });
    if (!step1.isConfirmed) return; // kept — nothing changed
    reason = step1.value;

    const step2 = await Swal.fire({
      icon: "warning",
      iconHtml: '<i class="fa-solid fa-triangle-exclamation es-swal-glyph"></i>',
      title: "Confirm Cancellation",
      html:
        '<p class="es-swal-text">Are you sure you want to cancel this appointment? This can\'t be undone.</p>' +
        `<p class="es-swal-note"><i class="fa-solid fa-quote-left"></i><span>${escHtml(reason)}</span></p>`,
      showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-calendar-xmark"></i> Confirm Cancellation',
      cancelButtonText: '<i class="fa-solid fa-arrow-left"></i> Go Back',
      // Primary action (Confirm Cancellation) on the left, Go Back on the right
      buttonsStyling: false,
      // Compact pair: Confirm Cancellation no longer wraps taller than Go Back
      customClass: Object.assign(ES_SWAL_TONE("danger"), { actions: "es-swal-actions es-swal-actions-compact" }),
    });
    if (step2.isConfirmed) break;
    if (step2.dismiss !== Swal.DismissReason.cancel) return; // closed / Esc — nothing changed
    // "Go Back" → show the reason step again
  }

  Swal.fire({
    title: "Cancelling…",
    html: '<p class="es-swal-text">Please wait a moment.</p>',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    customClass: ES_SWAL_CLASS,
    didOpen: () => Swal.showLoading(),
  });
  return confirmCancel(code, reason);
}

async function confirmCancel(code, reason) {
  // Not through php(): its response cache would replay an earlier result
  let data;
  try {
    const res = await fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "cancel_appointment", appointment_id: currentCancelId, reason: reason }),
    });
    data = await res.json();
  } catch (e) {
    data = { success: false, message: "We couldn't reach the server. Please check your connection and try again." };
  }
  if (data.success) {
    Swal.fire({
      icon: "success",
      iconHtml: '<i class="fa-solid fa-calendar-xmark es-swal-glyph"></i>',
      title: "Appointment Cancelled",
      html:
        '<p class="es-swal-text">Your appointment has been cancelled.</p>' +
        (code ? `<span class="es-swal-chip"><i class="fa-solid fa-hashtag"></i> ${escHtml(code)}</span>` : "") +
        '<p class="es-swal-note"><i class="fa-solid fa-circle-info"></i><span>Need a new schedule? Use <strong>Book Appointment</strong> anytime.</span></p>',
      confirmButtonText: "Got It",
      timer: 3500,
      timerProgressBar: true,
      buttonsStyling: false,
      customClass: ES_SWAL_TONE("danger"),
    });
    clearCache("dashboard_summary");
    window.loadPortalAppointments?.({ force: true }); // the cancelled row must update now
    loadDashboard();
  } else {
    Swal.fire({
      icon: "error",
      iconHtml: '<i class="fa-solid fa-circle-exclamation es-swal-glyph"></i>',
      title: "Couldn't Cancel",
      html: `<p class="es-swal-text">${escHtml(data.message || "We could not cancel this appointment. Please try again.")}</p>`,
      confirmButtonText: "Okay",
      buttonsStyling: false,
      customClass: ES_SWAL_TONE("warn"),
    });
    window.loadPortalAppointments?.({ force: true }); // show the current status
  }
}

// ── TREATMENTS ────────────────────────────────────────────────────────────────
async function loadTreatments() {
  const tbody = document.getElementById("treatTableBody");
  const key = JSON.stringify({ action: "get_treatments" });
  if (!_cache[key]) {
    tbody.innerHTML =
      '<tr><td colspan="5" class="text-center py-4">Loading…</td></tr>';
  }
  const data = await php(API, { action: "get_treatments" });
  renderTreatments(data.treatments || []);
}

// Status words as shown in the badges ("checked_in" → "Checked In")
function statusWord(s) {
  return String(s || "").replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function renderTreatments(all) {
  const tbody = document.getElementById("treatTableBody");
  window._portalTreatments = all; // used by the Action menu (View Treatment History)
  const F = window.PortalFilters;
  if (F) {
    F.setOptions("treat", "procedure", F.distinct(all, (t) => t.service_name));
    F.setOptions("treat", "dentist", F.distinct(all, (t) => t.dentist_name));
    F.setOptions("treat", "status", F.distinct(all, (t) => String(t.status || "").toLowerCase()).map((s) => [s, statusWord(s)]));
  }
  const v = F ? F.values("treat") : {};
  const list = all.filter((t) =>
    (!F || F.inRange(t.treatment_date, v.from, v.to)) &&
    (!v.procedure || (t.service_name || "") === v.procedure) &&
    (!v.dentist || (t.dentist_name || "") === v.dentist) &&
    (!v.status || String(t.status || "").toLowerCase() === v.status));
  if (F) F.result("treat", list.length, all.length, "treatment");
  if (!all.length) {
    tbody.innerHTML =
      '<tr><td colspan="5" class="text-center py-4" style="color:var(--text-muted);">No treatments on record.</td></tr>';
    return;
  }
  if (!list.length) {
    tbody.innerHTML = F.emptyRow("treat", 5, "treatments");
    return;
  }
  tbody.innerHTML = list
    .map((t) => {
      // Action dropdown — same custom menu as My Appointments (.appt-action).
      // Clicks are handled in portal-dashboard.php (openTreatmentDetails /
      // viewMedicalRecord), looked up by treatment_code.
      const esc = (v) =>
        String(v ?? "").replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
      const actionCell = `
        <div class="appt-action trt-action" data-trt-code="${esc(t.treatment_code)}">
          <button type="button" class="appt-action-btn" aria-haspopup="menu" aria-expanded="false"
                  aria-label="Actions for ${esc(t.treatment_code)}"><i class="fa-solid fa-ellipsis-vertical"></i></button>
          <div class="appt-action-menu" role="menu">
            <button type="button" class="appt-action-item" role="menuitem" data-action="trt-view">
              <i class="fa-solid fa-tooth"></i>View Treatment History</button>
            <div class="appt-action-divider"></div>
            <button type="button" class="appt-action-item" role="menuitem" data-action="trt-medical"
              ${t.appointment_id ? "" : 'disabled aria-disabled="true" title="No visit linked to this treatment"'}>
              <i class="fa-solid fa-file-waveform"></i>Medical Record</button>
          </div>
        </div>`;

      return `
        <tr>
          <td data-label="Date">${fmtDate(t.treatment_date)}</td>
          <td data-label="Procedure">${t.service_name || "—"}</td>
          <td data-label="Dentist">${t.dentist_name || "—"}</td>
          <td data-label="Status">${statusBadge(t.status)}</td>
          <td class="pt-actions" style="text-align:center;">${actionCell}</td>
        </tr>`;
    })
    .join("");
}

// ── Medical Record data: loaded once per visit, kept for a few minutes ─────────
// prefetchMedicalRecord() is called when the treatment row's ⋮ menu opens
// (portal-dashboard.php), so the record is usually ready by the time
// "Medical Record" is clicked and the modal opens without a spinner.
const _mrCache = {}; // appointment_id → { promise, data, time }
const MR_CACHE_MS = 5 * 60 * 1000; // re-check with the clinic after 5 minutes

function fetchMedicalRecord(appointmentId) {
  const key = String(appointmentId);
  const hit = _mrCache[key];
  if (hit && Date.now() - hit.time < MR_CACHE_MS) return hit.promise;

  const entry = { data: null, time: Date.now() };
  entry.promise = fetch(API, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      action: "get_medical_record",
      appointment_id: appointmentId,
    }),
  })
    .then((r) => r.json())
    .then((res) => {
      if (res && res.success) entry.data = res;
      else delete _mrCache[key]; // don't keep errors — try again next time
      return res;
    })
    .catch((err) => {
      delete _mrCache[key];
      throw err;
    });
  _mrCache[key] = entry;
  return entry.promise;
}

function prefetchMedicalRecord(appointmentId) {
  if (!appointmentId) return;
  fetchMedicalRecord(appointmentId).catch(() => {}); // errors show when opened
}
window.prefetchMedicalRecord = prefetchMedicalRecord;

async function viewMedicalRecord(appointmentId, treatCode) {
  // Already loaded (prefetched or opened before)? Then skip the spinner.
  const hit = _mrCache[String(appointmentId)];
  const ready = !!(hit && hit.data && Date.now() - hit.time < MR_CACHE_MS);

  // ── Reset modal state ────────────────────────────────────────────
  document.getElementById("mrLoading").style.display = ready ? "none" : "block";
  document.getElementById("mrError").style.display = "none";
  document.getElementById("mrContent").style.display = "none";
  document.getElementById("mrModalTitle").textContent = "Medical Record";
  document.getElementById("mrModalSubtitle").textContent = treatCode
    ? `Treatment ${treatCode}`
    : "Visit details";

  // ── Get the record (instant when cached) ─────────────────────────
  let res;
  if (ready) {
    res = hit.data;
  } else {
    bootstrap.Modal.getOrCreateInstance(document.getElementById("medicalRecordModal")).show();
    try {
      res = await fetchMedicalRecord(appointmentId);
    } catch (e) {
      _mrShowError("Network error. Please try again.");
      return;
    }
  }

  if (!res || !res.success) {
    _mrShowError(res?.message || "Could not load medical record.");
    return;
  }

  const { appointment: apt, vitals, profile } = res;

  // ── Helpers ──────────────────────────────────────────────────────
  const set = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val && val.toString().trim() ? val : "—";
  };

  const fmtDT = (dateStr, timeStr) => {
    const d = dateStr
      ? new Date(dateStr + "T00:00:00").toLocaleDateString("en-PH", {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric",
      })
      : "—";
    const t = timeStr
      ? (() => {
        const dt = new Date();
        const [h, m] = timeStr.split(":");
        dt.setHours(+h, +m);
        return dt.toLocaleTimeString("en-US", {
          hour: "numeric",
          minute: "2-digit",
        });
      })()
      : "";
    return t ? `${d} · ${t}` : d;
  };

  const fmtCheckedAt = (val) => {
    if (!val) return "—";
    try {
      return new Date(val).toLocaleString("en-PH", {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
      });
    } catch {
      return val;
    }
  };

  const renderTags = (containerId, items, colorClass) => {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (!items || items.length === 0) {
      el.innerHTML = '<span class="mr-tag-empty">None recorded</span>';
    } else {
      el.innerHTML = items
        .map((i) => `<span class="mr-tag ${colorClass}">${i}</span>`)
        .join("");
    }
  };

  // ── SECTION 1: Appointment Info ──────────────────────────────────
  set("mr_apt_code", apt.appointment_code);
  set("mr_apt_datetime", fmtDT(apt.appointment_date, apt.appointment_time));
  set("mr_apt_branch", apt.branches?.branch_name);
  set("mr_apt_service", apt.all_service_names?.length ? apt.all_service_names.join(", ") : apt.services?.service_name);
  set("mr_apt_notes", apt.notes || "No notes provided.");

  // Status badge
  const statusLabel =
    apt.status === "checked_in"
      ? "Checked In"
      : apt.status
        ? apt.status.charAt(0).toUpperCase() + apt.status.slice(1)
        : "—";
  const statusEl = document.getElementById("mr_apt_status");
  if (statusEl)
    statusEl.innerHTML = apt.status
      ? `<span class="badge-status badge-${apt.status}">${statusLabel}</span>`
      : "—";

  // Payment badge
  const payLabel = apt.payment_status
    ? apt.payment_status.charAt(0).toUpperCase() + apt.payment_status.slice(1)
    : "—";
  const payEl = document.getElementById("mr_apt_payment");
  if (payEl)
    payEl.innerHTML = apt.payment_status
      ? `<span class="badge-status badge-${apt.payment_status}">${payLabel}</span>`
      : "—";

  // ── SECTION 2: Vital Signs ──────────────────────────────────────
  const noVitals = document.getElementById("mr_no_vitals");
  const vitalsData = document.getElementById("mr_vitals_data");

  if (!vitals) {
    if (noVitals) noVitals.style.display = "block";
    if (vitalsData) vitalsData.style.display = "none";
  } else {
    if (noVitals) noVitals.style.display = "none";
    if (vitalsData) vitalsData.style.display = "block";

    set("mr_blood_pressure", vitals.blood_pressure);
    set(
      "mr_pulse_rate",
      vitals.pulse_rate ? vitals.pulse_rate.toString() : null,
    );
    set(
      "mr_temperature",
      vitals.temperature ? vitals.temperature.toString() : null,
    );
    set("mr_checked_by", vitals.checked_by);
    set("mr_checked_at", fmtCheckedAt(vitals.checked_at));
    set("mr_vital_notes", vitals.notes);
  }

  // ── SECTION 3: Medical Profile ───────────────────────────────────
  renderTags("mr_allergies", profile?.allergies || [], "mr-tag-red");
  renderTags("mr_conditions", profile?.conditions || [], "mr-tag-blue");
  set("mr_other_allergies", profile?.other_allergies);
  set("mr_allergy_reaction", profile?.allergy_reaction);
  set("mr_other_conditions", profile?.other_conditions);

  // ── Reveal content ───────────────────────────────────────────────
  document.getElementById("mrLoading").style.display = "none";
  document.getElementById("mrContent").style.display = "block";

  // Update subtitle with appointment code
  document.getElementById("mrModalSubtitle").textContent =
    `Reference No. ${apt.appointment_code || "—"}` +
    (treatCode ? `  ·  ${treatCode}` : "");

  // Cached record: open the modal now, already filled (no spinner).
  // (Fetched record: the modal is already open — this does nothing.)
  bootstrap.Modal.getOrCreateInstance(document.getElementById("medicalRecordModal")).show();
}

function _mrShowError(msg) {
  document.getElementById("mrLoading").style.display = "none";
  document.getElementById("mrContent").style.display = "none";
  document.getElementById("mrErrorText").textContent = msg;
  document.getElementById("mrError").style.display = "block";
}

// ── BILLING ───────────────────────────────────────────────────────────────────
// Shows the cached invoices instantly (preloaded with the other pages), then
// asks the server for fresh data in the background. If anything changed —
// e.g. the clinic recorded a payment — the table updates quietly.
async function loadBilling() {
  const tbody = document.getElementById("billingTableBody");
  const key = JSON.stringify({ action: "get_billing" });
  const cached = _cache[key];

  if (cached) {
    renderBilling(cached);
    if (cacheIsFresh(key)) return; // just loaded — no need to ask again yet
  } else {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Loading…</td></tr>';
  }

  try {
    const fresh = await fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: key,
    }).then((r) => r.json());

    const changed = !cached || JSON.stringify(fresh) !== JSON.stringify(cached);
    cacheSet(key, fresh);
    if (changed) renderBilling(fresh);
  } catch (err) {
    console.error("Billing refresh failed:", err);
    // Keep showing the cached copy; only show an error if there was nothing to show.
    if (!cached) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Could not load invoices. Please try again.</td></tr>';
    }
  }
}

// Amount still payable on an invoice, in centavos (integers, so sums are exact).
// Same rule as the server (portal_invoice_outstanding in portal_loaders.php):
// only unpaid / partially paid invoices, total − paid, never below zero.
function portalInvoiceDueCents(inv) {
  const status = String((inv && inv.payment_status) || "").toLowerCase() || "unpaid";
  if (status !== "unpaid" && status !== "partial") return 0;
  const cents = Math.round(parseFloat(inv.total_amount || 0) * 100) - Math.round(parseFloat(inv.amount_paid || 0) * 100);
  return cents > 0 ? cents : 0;
}

function renderBilling(data) {
  const tbody = document.getElementById("billingTableBody");
  const all = data.invoices || [];
  window._portalInvoices = all; // used by the Action menu (View Details) and Make a Payment
  window._portalBilling = data;
  let totalBilled = 0, totalPaid = 0;
  // The summary cards always cover every invoice; the filters only narrow the table
  all.forEach((i) => {
    totalBilled += parseFloat(i.total_amount || 0);
    totalPaid += parseFloat(i.amount_paid || 0);
  });
  if (typeof window.portalEwalletRefresh === "function") window.portalEwalletRefresh();

  const F = window.PortalFilters;
  const v = F ? F.values("bill") : {};
  const list = all.filter((i) =>
    (!v.status || (String(i.payment_status || "").toLowerCase() || "unpaid") === v.status) &&
    (!F || F.inRange(i.invoice_date, v.from, v.to)));
  if (F) F.result("bill", list.length, all.length, "invoice");

  if (!all.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">No invoices found.</td></tr>';
    document.getElementById("billTotal").textContent = peso(0);
    document.getElementById("billPaid").textContent = peso(0);
    document.getElementById("billBalance").textContent = peso(0);
    return;
  }

  tbody.innerHTML = !list.length ? F.emptyRow("bill", 6, "invoices") : list.map((i) => {
    const bal = parseFloat(i.total_amount || 0) - parseFloat(i.amount_paid || 0);

    const status = (i.payment_status || "").toLowerCase();
    const hasPayment = ['paid', 'partial'].includes(status);

    // Action dropdown — same custom menu as My Appointments (.appt-action).
    // Clicks are handled in portal-dashboard.php (openInvoiceDetails /
    // openReceiptModal / downloadReceiptPdf), using this row's invoice_id. Receipts exist only
    // once a payment is recorded, so both options are greyed out until then.
    const invId = i.invoice_id;
    if (!invId) console.error("The server did not return an 'invoice_id' for invoice:", i);
    const canReceipt = hasPayment && !!invId;
    const esc = (v) =>
      String(v ?? "").replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    const off = canReceipt ? "" : 'disabled aria-disabled="true" title="Available once a payment is recorded"';
    const actionButtons = `
        <div class="appt-action bill-action" data-invoice-id="${esc(invId)}" data-invoice-code="${esc(i.invoice_code)}">
          <button type="button" class="appt-action-btn" aria-haspopup="menu" aria-expanded="false"
                  aria-label="Receipt options for the ${esc(fmtDate(i.invoice_date))} invoice"><i class="fa-solid fa-ellipsis-vertical"></i></button>
          <div class="appt-action-menu" role="menu">
            <button type="button" class="appt-action-item" role="menuitem" data-action="bill-view">
              <i class="fa-solid fa-eye"></i>View Details</button>
            <div class="appt-action-divider"></div>
            <button type="button" class="appt-action-item" role="menuitem" data-action="bill-receipt" ${off}>
              <i class="fa-solid fa-receipt"></i>View Receipt</button>
            <div class="appt-action-divider"></div>
            <button type="button" class="appt-action-item" role="menuitem" data-action="bill-pdf" ${off}>
              <i class="fa-solid fa-file-pdf"></i>Download PDF</button>
          </div>
        </div>`;

    // Column order: Date · Total · Paid · Balance · Status · Action
    // (the invoice number is shown in View Details and on the receipt)
    return `<tr>
        <td data-label="Date">${fmtDate(i.invoice_date)}</td>
        <td data-label="Total">${peso(i.total_amount)}</td>
        <td data-label="Paid">${peso(i.amount_paid)}</td>
        <td data-label="Balance">${peso(bal)}</td>
        <td data-label="Status">${payBadge(i.payment_status)}</td>
        <td class="pt-actions" data-label="Action">${actionButtons}</td>
      </tr>`;
  }).join("");

  document.getElementById("billTotal").textContent = peso(totalBilled);
  document.getElementById("billPaid").textContent = peso(totalPaid);
  document.getElementById("billBalance").textContent = peso(totalBilled - totalPaid);
}

// ── FILES & WAIVERS: shared helpers ─────────────────────────────────────────
function escHtml(v) {
  return String(v ?? "")
    .replace(/&/g, "&amp;").replace(/"/g, "&quot;")
    .replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

// Only allow web links or paths on this site (never "javascript:" etc.)
function safeFileUrl(path) {
  const p = String(path || "").trim();
  if (!p) return "";
  if (/^https?:\/\//i.test(p)) return p;
  if (/^[a-z][a-z0-9+.-]*:/i.test(p)) return "";
  return p.replace(/^\/+/, "");
}

function fileNameFromPath(path) {
  const p = String(path || "").split("?")[0];
  return p ? decodeURIComponent(p.split("/").pop()) : "";
}

// View in a new tab, or download (download keeps the patient on the portal).
// Files are served by api/portal_patient_file.php, which checks the file is this
// patient's (the uploads folder itself is not reachable from the web). The check
// runs first so a missing file shows a portal message instead of an error page.
async function openPortalFile(f, download) {
  const id = parseInt(f && f.file_id, 10);
  const url = id ? "api/portal_patient_file.php?file_id=" + id : "";
  let res = { success: false, message: "This file could not be found. Please contact the clinic." };
  if (url) {
    try {
      res = await fetch(url + "&check=1", { cache: "no-store", credentials: "same-origin" }).then((r) => r.json());
    } catch (e) {
      res = { success: false, message: "We couldn't reach the server. Please check your connection and try again." };
    }
  }
  if (!res || !res.success) {
    Swal.fire({
      icon: "info",
      iconHtml: '<i class="fa-solid fa-file-circle-exclamation es-swal-glyph"></i>',
      title: "File Unavailable",
      html: `<p class="es-swal-text">${escHtml((res && res.message) || "This file could not be opened. Please try again.")}</p>`,
      confirmButtonText: "Okay",
      buttonsStyling: false,
      customClass: ES_SWAL_TONE("warn"),
    });
    return;
  }
  if (download) {
    // Sent as an attachment by the server, so the portal page stays open
    const a = document.createElement("a");
    a.href = url + "&download=1";
    a.rel = "noopener";
    document.body.appendChild(a);
    a.click();
    a.remove();
    return;
  }
  // (no "noopener" feature: with it window.open() always returns null, so a
  // blocked pop-up couldn't be told apart; opener is cleared instead)
  const win = window.open(url, "_blank");
  if (win) win.opener = null;
  else window.location.href = url; // pop-up blocked → open here
}

// Waiver "View Document": served by api/portal_waiver_file.php, which checks
// that the waiver is this patient's and that its file is on this server. The
// check runs first so a missing file shows a portal message (not a host error
// page); the document then opens in a new tab.
async function viewWaiverDocument(waiverId) {
  const id = parseInt(waiverId, 10);
  if (!id) return;
  const url = "api/portal_waiver_file.php?waiver_id=" + id;
  let res;
  try {
    res = await fetch(url + "&check=1", { cache: "no-store", credentials: "same-origin" }).then((r) => r.json());
  } catch (e) {
    res = { success: false, message: "We couldn't reach the server. Please check your connection and try again." };
  }
  if (!res || !res.success) {
    Swal.fire({
      icon: "info",
      iconHtml: '<i class="fa-solid fa-file-circle-exclamation es-swal-glyph"></i>',
      title: "Document Unavailable",
      html: `<p class="es-swal-text">${escHtml((res && res.message) || "This document could not be opened. Please try again.")}</p>`,
      confirmButtonText: "Okay",
      buttonsStyling: false,
      customClass: ES_SWAL_TONE("warn"),
    });
    return;
  }
  // (no "noopener" feature: with it window.open() always returns null, so a
  // blocked pop-up couldn't be told apart; opener is cleared instead)
  const win = window.open(url, "_blank");
  if (win) win.opener = null;
  else window.location.href = url; // pop-up blocked → open here
}

function actionMenu(wrapClass, dataAttrs, items) {
  return `
        <div class="appt-action ${wrapClass}" ${dataAttrs}>
          <button type="button" class="appt-action-btn" aria-haspopup="menu" aria-expanded="false"
                  aria-label="Actions"><i class="fa-solid fa-ellipsis-vertical"></i></button>
          <div class="appt-action-menu" role="menu">${items}</div>
        </div>`;
}

// ── FILES ─────────────────────────────────────────────────────────────────────
// Action menu clicks are handled in portal-dashboard.php (openPortalFile).
async function loadFiles() {
  const tbody = document.getElementById("filesTableBody");
  const data = await php(API, { action: "get_files" });
  window._portalFiles = data.files || [];
  renderFiles();
}

function renderFiles() {
  const tbody = document.getElementById("filesTableBody");
  const all = window._portalFiles || [];
  const F = window.PortalFilters;
  if (F) F.setOptions("files", "type", F.distinct(all, (f) => f.file_type));
  const v = F ? F.values("files") : {};
  // data-file-index must stay the row's place in _portalFiles (the menu looks it up)
  const rows = all.map((f, idx) => ({ f, idx })).filter(({ f }) =>
    (!v.type || String(f.file_type || "").trim() === v.type) &&
    (!F || F.has(f.file_name || fileNameFromPath(f.file_path), v.name)) &&
    (!F || F.inRange(f.uploaded_at, v.from, v.to)));
  if (F) F.result("files", rows.length, all.length, "file");

  if (!all.length) {
    tbody.innerHTML =
      '<tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No files uploaded yet.</p><small>X-rays, referrals and certificates appear here once the clinic uploads them.</small></div></td></tr>';
    return;
  }
  if (!rows.length) {
    tbody.innerHTML = F.emptyRow("files", 5, "files");
    return;
  }

  const icons = {
    "x-ray": "fa-x-ray",
    xray: "fa-x-ray",
    referral: "fa-file-medical",
    certificate: "fa-certificate",
  };

  tbody.innerHTML = rows
    .map(({ f, idx }) => {
      const name = f.file_name || fileNameFromPath(f.file_path) || "Document";
      const icon = icons[String(f.file_type || "").toLowerCase()] || "fa-file-lines";
      const hasUrl = !!safeFileUrl(f.file_path);
      const off = hasUrl ? "" : 'disabled aria-disabled="true" title="File not available"';
      const menu = actionMenu("file-action", `data-file-index="${idx}"`, `
            <button type="button" class="appt-action-item" role="menuitem" data-action="file-view" ${off}>
              <i class="fa-solid fa-eye"></i>View</button>
            <div class="appt-action-divider"></div>
            <button type="button" class="appt-action-item" role="menuitem" data-action="file-download" ${off}>
              <i class="fa-solid fa-download"></i>Download</button>`);
      return `<tr>
          <td data-label="File"><span class="pf-file"><i class="fa-solid ${icon}"></i><span>${escHtml(name)}</span></span></td>
          <td data-label="Type">${escHtml(f.file_type || "—")}</td>
          <td data-label="Uploaded By">${escHtml(f.uploaded_by || "—")}</td>
          <td data-label="Date">${fmtDate(f.uploaded_at)}</td>
          <td class="pt-actions" data-label="Action">${menu}</td>
        </tr>`;
    })
    .join("");
}

// ── WAIVERS ───────────────────────────────────────────────────────────────────
// Action menu clicks are handled in portal-dashboard.php (View / signWaiver).
async function loadWaivers() {
  const tbody = document.getElementById("waiversTableBody");
  const data = await php(API, { action: "get_waivers" });
  window._portalWaivers = data.waivers || [];
  renderWaivers();
}

function renderWaivers() {
  const tbody = document.getElementById("waiversTableBody");
  const all = window._portalWaivers || [];
  const F = window.PortalFilters;
  if (F) F.setOptions("waivers", "type", F.distinct(all, (w) => w.waiver_type || "General Waiver"));
  const v = F ? F.values("waivers") : {};
  const list = all.filter((w) => {
    const signed = String(w.status || "").toLowerCase() === "signed";
    return (!v.status || (v.status === "signed") === signed) &&
      (!v.type || String(w.waiver_type || "General Waiver").trim() === v.type);
  });
  if (F) F.result("waivers", list.length, all.length, "waiver");

  if (!all.length) {
    tbody.innerHTML =
      '<tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-pen-to-square"></i><p>No waivers on file.</p><small>Consent forms prepared by the clinic will appear here for you to review and sign.</small></div></td></tr>';
    return;
  }
  if (!list.length) {
    tbody.innerHTML = F.emptyRow("waivers", 5, "waivers");
    return;
  }

  tbody.innerHTML = list
    .map((w) => {
      const signed = String(w.status || "").toLowerCase() === "signed";
      const docName = fileNameFromPath(w.file_path) || "—";
      const hasUrl = !!safeFileUrl(w.file_path);
      const badge = `<span class="waiver-badge ${signed ? "waiver-signed" : "waiver-pending"}">${signed ? "Signed" : "Pending"}</span>`;
      const signedCell = signed
        ? `${escHtml(w.signed_by || "—")}<div class="pf-sub">${w.signed_at ? fmtDate(w.signed_at) : ""}</div>`
        : '<span style="color:var(--text-muted);">Not yet signed</span>';
      const viewOff = hasUrl ? "" : 'disabled aria-disabled="true" title="Document not available"';
      const signOff = signed ? 'disabled aria-disabled="true" title="Already signed"' : "";
      const menu = actionMenu("waiver-action", `data-waiver-id="${escHtml(w.waiver_id)}"`, `
            <button type="button" class="appt-action-item" role="menuitem" data-action="waiver-view" ${viewOff}>
              <i class="fa-solid fa-eye"></i>View Document</button>
            <div class="appt-action-divider"></div>
            <button type="button" class="appt-action-item" role="menuitem" data-action="waiver-sign" ${signOff}>
              <i class="fa-solid fa-signature"></i>Sign Waiver</button>`);
      return `<tr>
          <td data-label="Waiver"><strong>${escHtml(w.waiver_type || "General Waiver")}</strong></td>
          <td data-label="Document"><span class="pf-doc">${escHtml(docName)}</span></td>
          <td data-label="Status">${badge}</td>
          <td data-label="Signed">${signedCell}</td>
          <td class="pt-actions" data-label="Action">${menu}</td>
        </tr>`;
    })
    .join("");
}

async function signWaiver(waiverId, waiverType) {
  const w = (window._portalWaivers || []).find((x) => String(x.waiver_id) === String(waiverId)) || {};
  // Same signed-in, ownership-checked endpoint as View Document
  const docUrl = safeFileUrl(w.file_path) ? "api/portal_waiver_file.php?waiver_id=" + encodeURIComponent(waiverId) : "";
  const suggested = [accountData?.first_name, accountData?.last_name].filter(Boolean).join(" ");
  const { isConfirmed, value } = await Swal.fire({
    title: "Sign Waiver",
    html:
      `<p style="margin:0 0 .35rem;font-size:.95rem;font-weight:600;">${escHtml(waiverType || "Waiver")}</p>` +
      (docUrl
        ? `<p style="margin:0 0 .6rem;font-size:.84rem;"><a href="${escHtml(docUrl)}" target="_blank" rel="noopener" style="color:#2563eb;font-weight:600;">` +
          `<i class="fa-solid fa-up-right-from-square me-1"></i>Open the document</a> and read it before signing.</p>`
        : "") +
      `<input id="signerName" class="swal2-input" maxlength="100" placeholder="Your full name" value="${escHtml(suggested)}" style="margin:.4rem auto .8rem;">` +
      `<label for="signerConsent" class="waiver-consent">` +
      `<input type="checkbox" id="signerConsent">` +
      `<span>I have read and understood this document, and I agree to its terms. ` +
      `I understand that typing my full name above serves as my electronic signature.</span>` +
      `</label>`,
    confirmButtonText: "Sign",
    confirmButtonColor: "#2563eb",
    showCancelButton: true,
    focusConfirm: false,
    didOpen: () => {
      // Sign stays disabled until the consent box is ticked
      const box = document.getElementById("signerConsent");
      const btn = Swal.getConfirmButton();
      btn.disabled = true;
      box.addEventListener("change", () => {
        btn.disabled = !box.checked;
        Swal.resetValidationMessage();
      });
    },
    preConfirm: () => {
      const name = document.getElementById("signerName").value.trim();
      if (!name) {
        Swal.showValidationMessage("Please enter your full name.");
        return false;
      }
      if (!document.getElementById("signerConsent").checked) {
        Swal.showValidationMessage("Please tick the box to confirm your consent.");
        return false;
      }
      return name;
    },
  });
  if (!isConfirmed) return;

  let data;
  try {
    // Direct fetch (not php()) so the result is never served from the cache
    data = await fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "sign_waiver", waiver_id: waiverId, signed_by: value, consent: true }),
    }).then((r) => r.json());
  } catch (e) {
    data = { success: false, message: "Could not reach the server. Please try again." };
  }

  if (data.success) {
    clearCache("get_waivers"); // show the new "Signed" status right away
    Swal.fire({ icon: "success", title: "Waiver Signed!", timer: 1400, showConfirmButton: false });
    loadWaivers();
  } else {
    Swal.fire({ icon: "error", title: "Could not sign", text: data.message, confirmButtonColor: "#2563eb" });
    clearCache("get_waivers");
    loadWaivers();
  }
}

// ── PROFILE ───────────────────────────────────────────────────────────────────
// Shows the preloaded profile instantly, then refreshes it from the server in the
// background. A refresh never overwrites the form while the patient is editing.
async function loadProfile() {
  const key = JSON.stringify({ action: "get_profile" });
  const cached = _cache[key];
  if (cached && cached.success) {
    applyProfileData(cached);
    if (cacheIsFresh(key)) return; // just loaded — no need to ask again yet
  }

  let fresh;
  try {
    fresh = await fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: key,
    }).then((r) => r.json());
  } catch (e) {
    return; // keep showing the cached copy
  }
  if (!fresh || !fresh.success) return;
  const changed = !cached || JSON.stringify(fresh) !== JSON.stringify(cached);
  cacheSet(key, fresh);
  if (changed && !isProfileEditing()) applyProfileData(fresh);
}

function isProfileEditing() {
  const ep = document.getElementById("profileEditPanel");
  return !!ep && ep.style.display !== "none";
}

function applyProfileData(data) {
  const p = data.account || {};
  const ext = data.profile || {};

  document.getElementById("pf_first").value = p.first_name || "";
  document.getElementById("pf_last").value = p.last_name || "";
  document.getElementById("pf_middle").value = p.middle_name || "";
  // Saved names, so an untouched hyphenated name on file still validates
  window._pfStoredNames = { first: p.first_name || "", middle: p.middle_name || "", last: p.last_name || "" };
  document.getElementById("pf_suffix").value = p.suffix || "";
  document.getElementById("pf_birthdate").value = p.birthdate || "";
  // Keep a stored value that isn't one of the current options instead of blanking it
  const genderSel = document.getElementById("pf_gender");
  if (p.gender && ![...genderSel.options].some((o) => o.value === p.gender))
    genderSel.add(new Option(p.gender, p.gender));
  genderSel.value = p.gender || "";

  const rawContact = p.contact_number || "";
  const isPlaceholder =
    /^__PORTAL_\d+__$/.test(rawContact) || /^PORTAL-\d+$/.test(rawContact);
  document.getElementById("pf_contact").value =
    isPlaceholder || !rawContact ? "" : rawContact.replace(/^\+63/, "");
  document.getElementById("pf_email").value = p.email || "";
  window._phoneVerified = !!p.phone_verified;
  syncContactLock();

  const setVal = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.value = val !== null && val !== undefined ? val : "";
  };

  setVal("pf_civil_status", ext.civil_status);
  // Keeps legacy values that aren't in the standard list (nationalities.js)
  if (typeof setNationalityValue === "function")
    setNationalityValue(document.getElementById("pf_nationality"), ext.nationality);
  else setVal("pf_nationality", ext.nationality);
  setVal("pf_guardian_name", ext.guardian_name);
  setVal("pf_guardian_relation", ext.guardian_relation);
  setVal("pf_guardian_phone", (ext.guardian_phone || "").replace(/^\+63/, ""));
  setVal("pf_guardian_address", ext.guardian_address);
  setVal("pf_home_phone", ext.home_phone);
  setVal("pf_address", ext.address);
  setVal("pf_barangay", ext.barangay);
  setVal("pf_city", ext.city);
  setVal("pf_province", ext.province);
  setVal("pf_zip", ext.zip);
  // Show the saved province / city / barangay in the Edit form dropdowns
  if (typeof window.restorePfAddress === "function") {
    window.restorePfAddress(ext.province, ext.city, ext.barangay);
  }
  setVal("pf_chief_complaint", ext.chief_complaint);
  setVal("pf_referral_source", ext.referral_source);
  setVal("pf_referred_by", ext.referred_by);
  setVal("pf_prev_dentist", ext.prev_dentist);
  setVal("pf_last_visit", ext.last_dental_visit);
  setVal("pf_dental_notes", ext.dental_notes);
  setVal("pf_other_allergies", ext.other_allergies);
  setVal("pf_allergy_reaction", ext.allergy_reaction);
  setVal("pf_other_conditions", ext.other_conditions);

  const toArr = (v) =>
    Array.isArray(v) ? v : typeof v === "string" && v ? JSON.parse(v) : [];
  const restoreChecks = (name, raw) => {
    const list = toArr(raw);
    document.querySelectorAll(`input[name="${name}"]`).forEach((cb) => {
      cb.checked = list.includes(cb.value);
    });
  };
  restoreChecks("allergies", ext.allergies);
  restoreChecks("conditions", ext.conditions);

  calcAge();

  // Completion status comes from the server (computeProfileComplete in
  // api/portal_auth.php); the summary card shows it with a step checklist.
  window._profileComplete = !!p.is_profile_complete;
  if (typeof data.has_questionnaire === "boolean") window._hasQuestionnaire = data.has_questionnaire;

  window._authMethod = data.auth_method || "password"; // "google" shows the Google sign-in chip
  // From the stored credential (portal_has_local_password), not the sign-in method:
  // false only for Google-only accounts. Missing (older cached copy) = allowed;
  // the server refuses change_password for those accounts anyway.
  window._hasPassword = p.has_password !== false;
  syncPasswordControls();
  // ── Email verification: Verified pill, or the in-field Verify Email button + (i) icon ──
  const isVerified = !!p.is_verified;
  const verifiedBadge = document.getElementById("emailVerifiedBadge");
  const verifyBtnWrap = document.getElementById("emailVerifyBtnWrap");
  if (verifiedBadge)
    verifiedBadge.style.display = isVerified ? "inline" : "none";
  if (verifyBtnWrap)
    verifyBtnWrap.style.display = isVerified ? "none" : "block";
  window._emailVerified = isVerified;
  renderContactRuleNote();

  if (typeof window._populateViewPanel === "function") window._populateViewPanel();
  if (typeof window.profMarkClean === "function") window.profMarkClean();
}

// ── SAVE PROFILE ──────────────────────────────────────────────────────────────
async function saveProfile() {
  const saveButtons = document.querySelectorAll('[onclick="saveProfile()"]');
  const setLoading = (on) => {
    saveButtons.forEach((btn) => {
      btn.disabled = on;
      btn.innerHTML = on
        ? '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Saving…'
        : '<i class="fa-solid fa-floppy-disk me-1"></i> Save Changes';
    });
  };
  setLoading(true);

  // Same rules as registration: assets/js/portal-validation.js
  // (server: controllers/portal_validation.php). Each check: [errId, section, message].
  const PV = window.PortalValidation;
  const v = (id) => document.getElementById(id)?.value.trim() || "";
  const guardianPhone = v("pf_guardian_phone");
  const stored = window._pfStoredNames || {};
  const checks = [
    ["err_pf_first", "basic", PV.profileName(v("pf_first"), "First name", true, stored.first)],
    ["err_pf_middle", "basic", PV.profileName(v("pf_middle"), "Middle name", false, stored.middle)],
    ["err_pf_last", "basic", PV.profileName(v("pf_last"), "Last name", true, stored.last)],
    ["err_pf_birthdate", "basic", PV.birthdate(v("pf_birthdate"))],
    // Guardian phone is optional, but must be a valid PH mobile number when given
    ["err_pf_guardian_phone", "basic", guardianPhone && PV.mobile(guardianPhone, false)],
    ["err_pf_contact", "contact", PV.mobile(v("pf_contact"), false)],
    ["err_pf_home_phone", "contact", PV.homePhone(v("pf_home_phone"))],
    ["err_pf_zip", "contact", PV.zip(v("pf_zip"), false)],
    ["err_pf_referred_by", "consult", PV.name(v("pf_referred_by"), "Referred by", false, true)],
    ["err_pf_prev_dentist", "dental", PV.name(v("pf_prev_dentist"), "Previous dentist", false, true)],
  ];
  let firstBad = null;
  checks.forEach(([errId, sec, msg]) => {
    if (msg) {
      showPfErr(errId, msg);
      firstBad = firstBad || sec;
    } else {
      hidePfErr(errId);
    }
  });

  if (firstBad) {
    setLoading(false);
    Swal.fire({
      icon: "warning",
      iconHtml: '<i class="fa-solid fa-circle-exclamation es-swal-glyph"></i>',
      title: "Please Check Your Details",
      html: '<p class="es-swal-text">Some fields are missing or not in the right format. Please correct the highlighted entries.</p>',
      confirmButtonText: "Okay",
      buttonsStyling: false,
      customClass: ES_SWAL_TONE("warn"),
    });
    if (typeof profGoTo === "function") profGoTo(firstBad);
    else document.getElementById("sec-" + firstBad)?.scrollIntoView({ behavior: "smooth" });
    return false; // stay in edit mode so nothing typed is lost
  }

  const gv = (id) => document.getElementById(id)?.value?.trim() || null;
  const getChecked = (name) =>
    [...document.querySelectorAll(`input[name="${name}"]:checked`)].map(
      (cb) => cb.value,
    );

  const body = {
    action: "update_profile",
    first_name: gv("pf_first"),
    last_name: gv("pf_last"),
    middle_name: gv("pf_middle"),
    suffix: gv("pf_suffix"),
    birthdate: gv("pf_birthdate"),
    gender: gv("pf_gender"),
    civil_status: gv("pf_civil_status"),
    nationality: gv("pf_nationality"),
    guardian_name: gv("pf_guardian_name"),
    guardian_relation: gv("pf_guardian_relation"),
    guardian_phone: guardianPhone ? "+63" + guardianPhone : null,
    guardian_address: gv("pf_guardian_address"),
    home_phone: gv("pf_home_phone"),
    address: gv("pf_address"),
    contact_number: gv("pf_contact")
      ? "+63" + gv("pf_contact").replace(/[^0-9]/g, "")
      : null,
    city: gv("pf_city"),
    province: gv("pf_province"),
    barangay: gv("pf_barangay"),
    zip: gv("pf_zip"),
    prev_dentist: gv("pf_prev_dentist"),
    last_dental_visit: gv("pf_last_visit"),
    dental_notes: gv("pf_dental_notes"),
    chief_complaint: gv("pf_chief_complaint"),
    referral_source: gv("pf_referral_source"),
    referred_by: gv("pf_referred_by"),
    allergies: getChecked("allergies"),
    other_allergies: gv("pf_other_allergies"),
    allergy_reaction: gv("pf_allergy_reaction"),
    conditions: getChecked("conditions"),
    other_conditions: gv("pf_other_conditions"),
  };

  const data = await php(API, body);
  setLoading(false);

  if (data.success) {
    const savedComplete = !!data.is_profile_complete;
    isProfileComplete = savedComplete;
    window._profileComplete = savedComplete;

    const missingParts = [];
    if (
      !gv("pf_first") ||
      !gv("pf_last") ||
      !gv("pf_birthdate") ||
      !gv("pf_gender")
    )
      missingParts.push("Basic Info (name, birthdate, gender)");
    if (!gv("pf_address") || !gv("pf_city"))
      missingParts.push("Contact Info (address & city)");
    if (!savedComplete && missingParts.length === 0)
      missingParts.push("Health Questionnaire (appears on first login)");

    // Reached only after the server confirmed the save (data.success)
    Swal.fire({
      icon: "success",
      iconHtml: '<i class="fa-solid fa-user-check es-swal-glyph"></i>',
      title: "Profile Updated Successfully",
      html: savedComplete
        ? '<p class="es-swal-text">Your changes have been saved.</p>'
        : '<p class="es-swal-text">Your changes have been saved. To unlock appointment booking, please also complete:</p>' +
          '<ul class="es-swal-list">' +
          missingParts.map((m) => `<li><i class="fa-solid fa-circle-exclamation"></i> ${m}</li>`).join("") +
          "</ul>",
      timer: savedComplete ? 2500 : undefined,
      timerProgressBar: savedComplete,
      showConfirmButton: !savedComplete,
      confirmButtonText: "Got It",
      buttonsStyling: false,
      customClass: ES_SWAL_CLASS,
    });

    const firstName = gv("pf_first") || "Patient";
    const lastName = gv("pf_last") || "";

    document.getElementById("sidebarInitial").textContent = firstName
      .charAt(0)
      .toUpperCase();
    document.getElementById("sidebarName").textContent = [firstName, lastName]
      .filter(Boolean)
      .join(" ");
    document.getElementById("welcomeName").textContent = firstName;
    setBookBtnState(savedComplete);
    clearCache("get_profile", "dashboard_summary");
    loadDashboard();
    return true;
  } else {
    // A server-side field check failed: show it beside the field as well
    const fieldErr = {
      first_name: ["err_pf_first", "basic"], middle_name: ["err_pf_middle", "basic"], last_name: ["err_pf_last", "basic"],
      birthdate: ["err_pf_birthdate", "basic"], contact_number: ["err_pf_contact", "contact"],
      home_phone: ["err_pf_home_phone", "contact"], zip: ["err_pf_zip", "contact"],
      referred_by: ["err_pf_referred_by", "consult"], prev_dentist: ["err_pf_prev_dentist", "dental"],
    }[data.field];
    if ((data.code === "INVALID_FIELD" || data.code === "PHONE_LOCKED") && fieldErr) {
      showPfErr(fieldErr[0], data.message);
      if (typeof profGoTo === "function") profGoTo(fieldErr[1]);
    }
    Swal.fire({
      icon: "error",
      iconHtml: '<i class="fa-solid fa-circle-exclamation es-swal-glyph"></i>',
      title: "Couldn't Save Profile",
      html:
        `<p class="es-swal-text">${escHtml(data.message || "We could not save your profile details. Please try again.")}</p>` +
        '<p class="es-swal-note"><i class="fa-solid fa-circle-info"></i><span>Your changes are still on the form — nothing was lost.</span></p>',
      confirmButtonText: "Okay",
      buttonsStyling: false,
      customClass: ES_SWAL_TONE("warn"),
    });
    return false; // keep the patient's edits on screen
  }
}

// ── Profile helpers ───────────────────────────────────────────────────────────
function showPfErr(id, msg) {
  const el = document.getElementById(id);
  if (el) {
    el.textContent = msg;
    el.style.display = "block";
  }
}

function hidePfErr(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = "none";
}

function calcAge() {
  const dob = document.getElementById("pf_birthdate").value;
  const ageEl = document.getElementById("pf_age");
  const guardianBlock = document.getElementById("guardianBlock");
  if (!dob) {
    if (ageEl) ageEl.value = "";
    if (guardianBlock) guardianBlock.style.display = "none";
    return;
  }
  const today = new Date(),
    birth = new Date(dob);
  let age = today.getFullYear() - birth.getFullYear();
  if (
    today.getMonth() - birth.getMonth() < 0 ||
    (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())
  )
    age--;
  if (ageEl) ageEl.value = age >= 0 ? age + " yrs" : "";
  if (guardianBlock)
    guardianBlock.style.display = age >= 0 && age < 18 ? "block" : "none";
}

// Accordion sections on My Profile. The height animates via CSS grid rows;
// content is clipped only while closed or animating so the address
// dropdowns inside open sections are never cut off.
function toggleSection(header, forceOpen) {
  const sec = header.closest(".prof-section");
  if (!sec) return;
  const open = typeof forceOpen === "boolean" ? forceOpen : sec.classList.contains("collapsed");
  if (open === !sec.classList.contains("collapsed")) return;
  const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (!reduce) {
    sec.classList.add("animating");
    clearTimeout(sec._animT);
    sec._animT = setTimeout(() => sec.classList.remove("animating"), 320);
  }
  sec.classList.toggle("collapsed", !open);
  header.setAttribute("aria-expanded", open ? "true" : "false");
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
// Shared confirmation design: assets/js/signout-confirm.js (same popup as the admin side)
async function doLogout() {
  await window.essenciaSignOut({
    onConfirm: () => { window.location.href = "portal-logout.php"; },
  });
}

// ── CHANGE PASSWORD ───────────────────────────────────────────────────────────
// Google-only accounts (no password): the button is disabled and an info icon
// explains why. api/portal_auth.php change_password enforces the same rule.
function syncPasswordControls() {
  const noPassword = window._hasPassword === false;
  const btn = document.getElementById("profPwBtn");
  const tip = document.getElementById("profPwTip");
  if (btn) {
    btn.disabled = noPassword;
    btn.setAttribute("aria-disabled", noPassword ? "true" : "false");
    if (noPassword) btn.setAttribute("aria-describedby", "profPwTip");
    else btn.removeAttribute("aria-describedby");
  }
  if (tip) {
    tip.hidden = !noPassword;
    if (!noPassword) window.hideInfoTip?.(tip);
  }
}

async function openChangePassword() {
  if (window._hasPassword === false) return; // Google-only account: nothing to change
  const field = (id, label, auto) => `
    <label class="es-pw-label" for="${id}">${label}</label>
    <div class="es-pw-wrap">
      <input type="password" id="${id}" class="es-pw-input" autocomplete="${auto}">
      <button type="button" class="es-pw-eye" data-target="${id}" aria-label="Show password">
        <i class="fa-solid fa-eye"></i></button>
    </div>
    <div class="es-pw-err" id="${id}_err"></div>`;
  const googleNote =
    window._authMethod === "google"
      ? `<p class="es-swal-note"><i class="fa-solid fa-circle-info"></i><span>You signed in with Google.
           This changes the password used for email sign-in, so you'll need your current one.</span></p>`
      : "";

  const result = await Swal.fire({
    icon: "info",
    iconHtml: '<i class="fa-solid fa-key es-swal-glyph"></i>',
    title: "Change Password",
    html: `<p class="es-swal-text">Enter your current password, then choose a new one with at least 8 characters.</p>
           ${googleNote}
           <div class="es-pw-form">
             ${field("cp_current", "Current Password", "current-password")}
             ${field("cp_new", "New Password", "new-password")}
             ${field("cp_confirm", "Confirm New Password", "new-password")}
           </div>`,
    confirmButtonText: '<i class="fa-solid fa-check"></i> Update Password',
    cancelButtonText: "Cancel",
    showCancelButton: true,
    showLoaderOnConfirm: true,
    allowOutsideClick: () => !Swal.isLoading(),
    buttonsStyling: false,
    // Compact pair: Update Password matches Cancel in size
    customClass: Object.assign({}, ES_SWAL_CLASS, { actions: "es-swal-actions es-swal-actions-compact" }),
    didOpen: (popup) => {
      popup.querySelectorAll(".es-pw-eye").forEach((btn) =>
        btn.addEventListener("click", () => {
          const input = document.getElementById(btn.dataset.target);
          const show = input.type === "password";
          input.type = show ? "text" : "password";
          btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
          btn.firstElementChild.className = "fa-solid " + (show ? "fa-eye-slash" : "fa-eye");
        }),
      );
      popup.querySelectorAll(".es-pw-input").forEach((input) =>
        input.addEventListener("keydown", (e) => {
          if (e.key === "Enter") Swal.clickConfirm();
        }),
      );
      document.getElementById("cp_current").focus();
    },
    preConfirm: async () => {
      const val = (id) => document.getElementById(id).value;
      const clearErrs = () => {
        Swal.resetValidationMessage();
        ["cp_current", "cp_new", "cp_confirm"].forEach((id) => {
          document.getElementById(id + "_err").textContent = "";
          document.getElementById(id).classList.remove("is-invalid");
        });
      };
      // Shows msg under the matching field (current/new/confirm), else as a general message
      const setErr = (key, msg) => {
        clearErrs();
        const id = { current: "cp_current", new: "cp_new", confirm: "cp_confirm" }[key];
        if (id) {
          document.getElementById(id + "_err").textContent = msg;
          document.getElementById(id).classList.add("is-invalid");
          document.getElementById(id).focus();
        } else {
          Swal.showValidationMessage(msg);
        }
        return false;
      };
      clearErrs();
      const current = val("cp_current"), next = val("cp_new"), confirm = val("cp_confirm");
      if (!current) return setErr("current", "Please enter your current password.");
      if (next.length < 8) return setErr("new", "New password must be at least 8 characters.");
      if (next !== confirm) return setErr("confirm", "Passwords do not match. Please re-enter your new password.");
      if (next === current) return setErr("new", "Your new password must be different from your current password.");

      try {
        const res = await fetch(API, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action: "change_password",
            current_password: current,
            new_password: next,
            confirm_password: confirm,
          }),
        }).then((r) => r.json());
        if (res.code === "NO_PASSWORD") {
          // Server says this account has no password (e.g. stale page): lock the form
          window._hasPassword = false;
          syncPasswordControls();
          return setErr(null, res.message);
        }
        if (!res.success) return setErr(res.field || null, res.message || "We could not update your password. Please try again.");
        return true;
      } catch (e) {
        return setErr(null, "Could not reach the server. Please try again.");
      }
    },
  });

  if (result.isConfirmed) {
    Swal.fire({
      icon: "success",
      iconHtml: '<i class="fa-solid fa-shield-halved es-swal-glyph"></i>',
      title: "Password Updated",
      html: '<p class="es-swal-text">Your password has been changed. Use your new password the next time you sign in.</p>',
      confirmButtonText: "Got It",
      buttonsStyling: false,
      customClass: ES_SWAL_CLASS,
    });
  }
}

// ── SEND VERIFICATION EMAIL ───────────────────────────────────────────────────
async function sendVerificationEmail(clickedBtn) {
  // The clicked button (My Profile or the booking modal) shows the spinner
  const btn = clickedBtn || document.querySelector('[onclick="sendVerificationEmail(this)"]');
  const btnHtml = btn ? btn.innerHTML : "";
  if (btn) {
    btn.disabled = true;
    btn.innerHTML =
      '<span class="spinner-border spinner-border-sm me-1"></span> Sending…';
  }

  try {
    const res = await fetch("controllers/verify_email_controller.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "action=send_verification",
    }).then((r) => r.json());

    if (res.success) {
      watchContactStatus(); // the link is opened elsewhere → pick the result up live
      // Recipient = the account email shown in My Profile, lightly masked (jo•••@gmail.com)
      const email = (document.getElementById("pf_email")?.value || "").trim();
      const at = email.lastIndexOf("@");
      const masked =
        at > 0
          ? email.slice(0, Math.min(2, at)) + "•••" + email.slice(at)
          : "your registered email address";
      Swal.fire({
        icon: "info",
        iconHtml:
          '<span class="es-swal-glyph-wrap"><i class="fa-solid fa-envelope es-swal-glyph"></i>' +
          '<i class="fa-solid fa-circle-check es-swal-badge"></i></span>',
        title: "Verification Email Sent!",
        html: `<p class="es-swal-text">We've sent a verification link to your registered email address:</p>
               <span class="es-swal-chip"><i class="fa-solid fa-at"></i> ${escHtml(masked)}</span>
               <p class="es-swal-note"><i class="fa-solid fa-circle-info"></i>
                 <span>Open the link to verify your email. Check your inbox and spam/junk folder.
                 The link expires in 24 hours and can be used only once.</span></p>
               ${CONTACT_OK ? "" : `<p class="es-swal-text" style="margin-top:.75rem;">
                 Once it's verified, this page updates automatically.
                 Verifying either your email <strong>or</strong> your phone is enough to book appointments.</p>`}`,
        confirmButtonText: "Got It",
        buttonsStyling: false,
        customClass: ES_SWAL_CLASS,
      });
    } else {
      Swal.fire({
        icon: "error",
        title: "Failed to Send",
        text: res.message || "Something went wrong. Please try again.",
        confirmButtonColor: "#0d9488",
      });
    }
  } catch (e) {
    Swal.fire({
      icon: "error",
      title: "Network Error",
      text: "Could not reach the server. Please try again.",
      confirmButtonColor: "#0d9488",
    });
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = btnHtml;
    }
  }
}

// ── Contact-verification rule (verified email OR phone) ──────────────────────
// Registration-incomplete modal over the dashboard. focus:false lets the
// email / phone verification Swals that open on top of it take keyboard input.
function toggleContactGate(show) {
  const el = document.getElementById("contactGateModal");
  if (!el || typeof bootstrap === "undefined") return;
  const modal = bootstrap.Modal.getOrCreateInstance(el, { backdrop: "static", keyboard: false, focus: false });
  show ? modal.show() : modal.hide();
}

// My Profile → Contact Info: one line telling the patient where they stand.
function renderContactRuleNote() {
  const el = document.getElementById("contactRuleNote");
  if (!el) return;
  const email = !!window._emailVerified;
  const phone = !!window._phoneVerified;
  el.className = "contact-rule-note" + (email || phone ? " is-ok" : "");
  // The text sits in one <span>: the note is a flex row, so loose text and
  // <strong> would otherwise become separate columns
  el.innerHTML =
    email || phone
      ? '<i class="fa-solid fa-circle-check"></i><span>Contact verification requirement satisfied — your ' +
        (email && phone ? "email and phone are" : email ? "email is" : "phone is") +
        " verified." + (email && phone ? "" : " Verifying the other method is optional.") + "</span>"
      : '<i class="fa-solid fa-circle-info"></i><span>Verify your email <strong>or</strong> your phone number before booking an appointment. Only one is required.</span>';
  el.style.display = "flex";
}

// A verified mobile number can't be typed over in My Profile: it is changed
// with "Change Number", which confirms the new number by SMS first (the server
// refuses a plain save of a different number: PHONE_LOCKED).
function syncContactLock() {
  const field = document.getElementById("pf_contact");
  if (!field) return;
  const locked = !!window._phoneVerified && !!field.value.trim();
  field.readOnly = locked;
  field.classList.toggle("pf-readonly", locked);
  // Locked: the explanation moves into the info icon beside the label (tooltip)
  // and Change Number sits inside the field; unlocked: the format hint shows.
  field.setAttribute("aria-describedby", locked ? "pfContactTip" : "pfContactHint");
  field.closest(".pf-phone")?.classList.toggle("has-action", locked);
  const hint = document.getElementById("pfContactHint");
  if (hint) hint.hidden = locked;
  // Edit form: the (i) icon is always shown; its message follows the status
  setInfoTip(document.getElementById("pfContactTip"), locked
    ? "Verified numbers can't be edited here. Use Change Number to confirm a new one by SMS."
    : "Verify phone number.", "About your contact number: ");
  const inFieldBtn = document.getElementById("pfContactChangeWrap");
  if (inFieldBtn) inFieldBtn.hidden = !locked;
  syncVerifyTips();
}

// Sets an (i) icon's message (data-tip + accessible name), also when its
// tooltip is already showing. Bootstrap reads the title only once otherwise.
function setInfoTip(el, text, labelPrefix) {
  if (!el) return;
  if (el.dataset.tip === text) return;
  el.dataset.tip = text;
  el.setAttribute("aria-label", (labelPrefix || "") + text);
  const t = typeof bootstrap !== "undefined" && bootstrap.Tooltip ? bootstrap.Tooltip.getInstance(el) : null;
  if (t) t.setContent({ ".tooltip-inner": text });
}

// My Profile (view): (i) icons beside Email Address / Contact Number while
// either is unverified. Each says which one is unverified — and both when
// both are. Uses the stored flags (patient_accounts.is_verified /
// phone_verified), never the email domain or the way the patient signed in.
function syncVerifyTips() {
  const emailTip = document.getElementById("vfEmailTip");
  const phoneTip = document.getElementById("vfPhoneTip");
  if (!emailTip || !phoneTip) return;
  const emailOk = !!window._emailVerified;
  const hasPhone = !!document.getElementById("pf_contact")?.value.trim();
  const phoneOk = hasPhone && !!window._phoneVerified;
  const phoneWhat = hasPhone
    ? "your contact number"
    : "you haven't added a contact number yet";
  let emailMsg = "", phoneMsg = "";
  if (!emailOk && !phoneOk) {
    emailMsg = hasPhone
      ? "Both your email address and your contact number are still unverified. Use Verify Email here, and Verify Number beside your contact number."
      : "Your email address is still unverified, and " + phoneWhat + ". Use Verify Email to get a confirmation link.";
    phoneMsg = hasPhone
      ? "Both your contact number and your email address are still unverified. Use Verify Number to confirm it by SMS, and Verify Email beside your email."
      : "No contact number yet, and your email address is still unverified too. Add a number with Edit Profile, then verify it by SMS.";
  } else if (!emailOk) {
    emailMsg = "Your email address is still unverified. Use Verify Email to get a confirmation link.";
  } else if (!phoneOk) {
    phoneMsg = hasPhone
      ? "Your contact number is still unverified. Use Verify Number to confirm it by SMS."
      : "No contact number yet. Add one with Edit Profile, then verify it by SMS.";
  }
  [[emailTip, emailMsg, "Email address: "], [phoneTip, phoneMsg, "Contact number: "]].forEach(([el, msg, prefix]) => {
    if (msg) setInfoTip(el, msg, prefix);
    el.hidden = !msg;
    if (!msg) window.hideInfoTip?.(el);
  });
}

// ── Live contact verification status ─────────────────────────────────────────
// Updates every verification indicator on the page (My Profile badges, rule
// note, booking modal, booking unlock) so a verification done in another tab
// (email link) or via OTP shows without a reload. Returns which methods flipped
// from unverified to verified.
function applyContactStatus(email, phone) {
  const prevEmail = !!window._emailVerified;
  const prevPhone = !!window._phoneVerified;
  window._emailVerified = !!email;
  window._phoneVerified = !!phone;

  const show = (id, on, mode) => {
    const el = document.getElementById(id);
    if (el) el.style.display = on ? mode : "none";
  };
  show("emailVerifiedBadge", email, "inline");
  show("emailVerifyBtnWrap", !email, "block");
  const hasPhone = !!document.getElementById("pf_contact")?.value.trim();
  show("phoneVerifiedBadge", hasPhone && phone, "inline");
  show("phoneVerifyBtnWrap", hasPhone && !phone, "block");
  syncContactLock();
  renderContactRuleNote();

  // Booking modal: status pills + hide the verify button once done
  [["email", email], ["phone", phone]].forEach(([key, ok]) => {
    const pill = document.querySelector(`[data-cg-status="${key}"]`);
    if (pill) {
      pill.classList.toggle("is-ok", !!ok);
      pill.textContent = ok ? "Verified" : "Not Verified";
    }
    const btn = document.querySelector(`[data-cg-btn="${key}"]`);
    if (btn) btn.style.display = ok ? "none" : "";
  });

  if (email || phone) CONTACT_OK = true;

  const changed = { email: !!email && !prevEmail, phone: !!phone && !prevPhone };
  // Cached profile/dashboard data still holds the old flags
  if (changed.email || changed.phone) clearCache("get_profile", "dashboard_summary");
  return changed;
}

const CONTACT_POLL_MS = 5000;
const CONTACT_POLL_WINDOW_MS = 15 * 60 * 1000; // keep watching this long after "Send Verification Email"
let _contactPollUntil = 0;
let _contactPollTimer = null;
let _contactChecking = false;
let _contactKnown = false; // first result only initialises, it never announces

async function checkContactStatus(prefetched) {
  if (_contactChecking) return;
  _contactChecking = true;
  try {
    // Straight fetch: php() would cache the answer. prefetched = the bootstrap's
    // fresh contact_status (used once, for the silent baseline).
    const res = prefetched || await fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "contact_status" }),
    }).then((r) => r.json());
    if (!res.success) return;

    const wasBlocked = !CONTACT_OK;
    const changed = applyContactStatus(res.email, res.phone);
    const announce = _contactKnown;
    _contactKnown = true;
    if (!announce || !(changed.email || changed.phone)) return;

    const what = changed.email ? "Email" : "Phone";
    toggleContactGate(false);
    Swal.fire({
      icon: "success",
      iconHtml: '<i class="fa-solid fa-circle-check es-swal-glyph"></i>',
      title: `${what} Verified!`,
      html:
        `<p class="es-swal-text">Your ${what.toLowerCase()} ${what === "Email" ? "address" : "number"} has been verified.` +
        (wasBlocked ? " You can now book an appointment." : "") + "</p>",
      confirmButtonText: wasBlocked ? '<i class="fa-solid fa-calendar-plus"></i> Book Appointment' : "Got It",
      showCancelButton: wasBlocked,
      cancelButtonText: "Later",
      buttonsStyling: false,
      customClass: ES_SWAL_TONE("success"),
    }).then((r) => {
      if (wasBlocked && r.isConfirmed) handleBookAppt();
    });
  } catch (e) {
    // Network hiccup: the next check will catch up
  } finally {
    _contactChecking = false;
  }
}

// Poll only while a verification is pending and the tab is visible
function scheduleContactPoll() {
  clearTimeout(_contactPollTimer);
  const allDone = window._emailVerified && window._phoneVerified;
  if (document.hidden || allDone || Date.now() > _contactPollUntil) return;
  _contactPollTimer = setTimeout(async () => {
    await checkContactStatus();
    scheduleContactPoll();
  }, CONTACT_POLL_MS);
}

function watchContactStatus() {
  _contactPollUntil = Date.now() + CONTACT_POLL_WINDOW_MS;
  scheduleContactPoll();
}

// Coming back to this tab (e.g. after opening the email link elsewhere) → check now
document.addEventListener("visibilitychange", async () => {
  if (document.hidden) return clearTimeout(_contactPollTimer);
  await checkContactStatus();
  scheduleContactPoll();
});
document.getElementById("contactGateModal")?.addEventListener("shown.bs.modal", watchContactStatus);

// ── Init ──────────────────────────────────────────────────────────────────────
// The first screen comes from a few 'bootstrap' requests sent side by side,
// each asking for some sections. Hosts without parallel curl (InfinityFree)
// run every query of a request one after another, so one big request took
// ~12 s there; split, the slowest group decides. Each part is stored under the
// same cache key as its single action, so the section loaders work unchanged.
// The other sections are prefetched in the background once the first screen
// is drawn. A group that fails falls back to the individual calls.
const BOOT_GROUPS = [
  ["dashboard_summary", "contact_status", "check_questionnaire", "feedback_status"],
  ["get_profile"],
  ["appointments"],
];
const BOOT_SECTIONS = ["dashboard_summary", "get_profile"];   // needed before the dashboard draws
const LATER_SECTIONS = ["get_treatments", "get_billing", "get_dental_chart", "get_files", "get_waivers"];
window.portalBootData = null; // read by the appointments script in portal-dashboard.php

async function preloadAll() {
  const parts = await Promise.all(BOOT_GROUPS.map((sections) =>
    fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "bootstrap", sections }),
    })
      .then((r) => r.json())
      .catch(() => null),
  ));
  const ok = parts.filter((p) => p && p.success);
  const boot = ok.length ? Object.assign({}, ...ok) : null;

  if (boot && boot.success) {
    window.portalBootData = boot;
    // Silent baseline first: learning the verification state clears the profile /
    // summary caches (applyContactStatus), and the copies below come from the same
    // request, so they already match it. (Runs synchronously with a prefetched value.)
    if (boot.contact_status?.success) checkContactStatus(boot.contact_status);
    else checkContactStatus();
    BOOT_SECTIONS.forEach((a) => {
      if (boot[a] && boot[a].success !== false) cacheSet(JSON.stringify({ action: a }), boot[a]);
    });
    if (boot.feedback_status?.success) _fbSubmitted = boot.feedback_status.submitted || {};
  } else {
    checkContactStatus(); // silent baseline; later changes are announced
  }
  // Anything bootstrap could not provide is fetched on its own
  await Promise.all(BOOT_SECTIONS.map((a) => php(API, { action: a })));

  // get_profile recomputes is_profile_complete live from the DB.
  // Use it to patch the dashboard_summary cache so loadDashboard()
  // always reflects the true state even if the RPC returns a stale value.
  const profileData = _cache[JSON.stringify({ action: "get_profile" })];
  const dashData = _cache[JSON.stringify({ action: "dashboard_summary" })];
  if (profileData?.account && dashData) {
    dashData.is_profile_complete = profileData.account.is_profile_complete;
  }

  loadDashboard();

  // Check if first login — show questionnaire if not yet answered
  checkAndShowQuestionnaire(boot && boot.check_questionnaire);

  // Warm the other tabs in the background (not awaited). Opening one of them
  // meanwhile reuses the request already on its way (php() in-flight map).
  LATER_SECTIONS.forEach((a) => php(API, { action: a }).catch(() => {}));
}

// Awaited by the appointments script in portal-dashboard.php (it uses the
// bootstrap's appointment list instead of a separate request)
window.portalBoot = preloadAll();
// ── Deep link: portal-dashboard.php#appointments opens that section ──────────
// Used by the booking confirmation's "View My Appointments" button.
window.addEventListener("load", () => {
  const page = location.hash.slice(1);
  const allowed = ["appointments", "treatments", "dentalchart", "billing", "files", "waivers", "profile"];
  if (allowed.includes(page) && document.getElementById("page-" + page)) showPage(page, null);

  // ?verify_contact=1 — back from the scheduling page or "Refresh status":
  // reopen the verification modal, or continue to booking once verified.
  const params = new URLSearchParams(location.search);
  if (params.has("verify_contact")) {
    history.replaceState(null, "", location.pathname + location.hash);
    if (!CONTACT_OK) toggleContactGate(true);
    else Swal.fire({
      icon: "success",
      iconHtml: '<i class="fa-solid fa-circle-check es-swal-glyph"></i>',
      title: "Contact Verified",
      html: '<p class="es-swal-text">Your contact verification requirement is satisfied. You can now book an appointment.</p>',
      confirmButtonText: '<i class="fa-solid fa-calendar-plus"></i> Book Appointment',
      showCancelButton: true,
      cancelButtonText: "Later",
      buttonsStyling: false,
      customClass: ES_SWAL_CLASS,
    }).then((r) => { if (r.isConfirmed) handleBookAppt(); });
  }
});

// ── Feedback (opened from My Appointments → Action → Give Feedback) ──
// api/portal_feedback.php checks ownership, completion, the one-per-appointment
// rule and resolves the dentist; this code only mirrors that for the UI.
const FB_API = "api/portal_feedback.php";
const FB_RATING_WORDS = ["", "Poor", "Fair", "Good", "Very good", "Excellent"];
let _fbSubmitted = {};   // appointment_id → { category, created_at }
let _fbCurrent = null;   // appointment the open form is for
let _fbSending = false;

async function fbLoadStatus() {
  try {
    const r = await fetch(`${FB_API}?action=status`, { cache: "no-store" }).then((x) => x.json());
    if (r.success) _fbSubmitted = r.submitted || {};
  } catch (e) { /* keep last known state; the server still enforces the rule */ }
}

// Action-menu item for a My Appointments row (rendered in portal-dashboard.php).
// Only COMPLETED appointments can be reviewed; pending / confirmed / checked-in /
// cancelled / no-show rows show it greyed out. One feedback per appointment:
// once sent it shows "Feedback Submitted". api/portal_feedback.php enforces the
// same rules (and that the visit's treatment is completed) on the server.
function fbActionItem(a) {
  const aid = a && a.appointment_id;
  if (!aid) return "";
  const divider = '<div class="appt-action-divider"></div>';
  if (String(a.status).toLowerCase() !== "completed") {
    return divider + `<button type="button" class="appt-action-item" role="menuitem" data-action="appt-feedback"
              disabled aria-disabled="true" title="Feedback opens once this appointment is completed">
              <i class="fa-solid fa-comment-medical"></i>Give Feedback</button>`;
  }
  if (_fbSubmitted[aid]) {
    return divider + `<button type="button" class="appt-action-item fb-done-item" role="menuitem" disabled aria-disabled="true"
              tabindex="-1" title="You already sent feedback for this appointment">
              <i class="fa-solid fa-circle-check"></i>Feedback Submitted</button>`;
  }
  return divider + `<button type="button" class="appt-action-item" role="menuitem" data-action="appt-feedback">
            <i class="fa-solid fa-comment-medical"></i>Give Feedback</button>`;
}

// Redraws the My Appointments table with the current feedback state
// (portal-dashboard.php exposes portalRenderAppointments).
function fbRefreshAppointments() {
  if (typeof window.portalRenderAppointments === "function") window.portalRenderAppointments();
}

function fbEsc(v) {
  return String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

function fbSwal(icon, glyph, title, text) {
  return Swal.fire({
    icon, iconHtml: `<i class="fa-solid ${glyph} es-swal-glyph"></i>`, title,
    html: `<p class="es-swal-text">${fbEsc(text)}</p>`,
    confirmButtonText: "OK", buttonsStyling: false, customClass: ES_SWAL_CLASS,
  });
}

async function openFeedback(appointmentId) {
  if (_fbSubmitted[appointmentId]) return; // already sent: the form never opens
  let r;
  try {
    r = await fetch(`${FB_API}?action=context&appointment_id=${encodeURIComponent(appointmentId)}`, { cache: "no-store" })
      .then((x) => x.json());
  } catch (e) {
    return fbSwal("error", "fa-wifi", "Connection Problem", "We couldn't reach the clinic server. Please check your connection and try again.");
  }
  if (!r.success) {
    if (r.code === "already_submitted") { _fbSubmitted[appointmentId] = { category: "" }; fbRefreshAppointments(); }
    return fbSwal("info", "fa-comment-slash", "Feedback Unavailable", r.message || "Feedback isn't available for this appointment.");
  }

  _fbCurrent = r.appointment;
  const a = r.appointment;
  const date = a.appointment_date
    ? new Date(a.appointment_date + "T00:00:00").toLocaleDateString("en-PH", { month: "long", day: "numeric", year: "numeric" })
    : "—";
  document.getElementById("fbApt").innerHTML = `
    <span class="fb-apt-icon" aria-hidden="true"><i class="fa-solid fa-calendar-check"></i></span>
    <span class="fb-apt-text">
      <strong>${fbEsc(a.appointment_code || "Appointment")}</strong>
      <span>${fbEsc(date)}${a.services ? " · " + fbEsc(a.services) : ""}${a.branch_name ? " · " + fbEsc(a.branch_name) : ""}</span>
    </span>`;

  // Reset the form
  const form = document.getElementById("fbForm");
  form.reset();
  // The server only returns success when a dentist is recorded for the treatment
  document.getElementById("fbDentistName").textContent = r.dentist ? r.dentist.full_name : "—";
  document.getElementById("fbErr").textContent = "";
  fbRatingChanged();
  fbCountChars();
  showPage("feedback", null);
}

function fbRatingChanged() {
  const val = +(document.querySelector('input[name="fb_rating"]:checked')?.value || 0);
  document.querySelectorAll("#fbStars .fb-star").forEach((s, i) => s.classList.toggle("on", i < val));
  document.getElementById("fbStarLabel").textContent = val ? `${val} / 5 · ${FB_RATING_WORDS[val]}` : "Tap a star to rate";
}

function fbCountChars() {
  const len = document.getElementById("fb_message").value.length;
  document.getElementById("fbCount").textContent = `${len} / 1000`;
}

async function submitFeedback(e) {
  e.preventDefault();
  if (_fbSending || !_fbCurrent) return;
  const err = document.getElementById("fbErr");
  const rating = +(document.querySelector('input[name="fb_rating"]:checked')?.value || 0);
  const message = document.getElementById("fb_message").value.trim();
  if (!rating) { err.textContent = "Please give a rating from 1 to 5 stars."; return; }
  err.textContent = "";

  const aid = _fbCurrent.appointment_id;
  const btn = document.getElementById("fbSubmit");
  _fbSending = true;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending…';
  let r;
  try {
    r = await fetch(FB_API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "submit", appointment_id: aid, rating, message }),
    }).then((x) => x.json());
  } catch (ex) {
    r = { success: false, code: "network", message: "We couldn't reach the clinic server. Your feedback was not sent — please try again." };
  }
  _fbSending = false;
  btn.disabled = false;
  btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Feedback';

  if (r.success) {
    _fbSubmitted[aid] = { category: r.category, created_at: r.created_at };
    _fbCurrent = null;
    await Swal.fire({
      icon: "success",
      iconHtml: '<i class="fa-solid fa-comment-medical es-swal-glyph"></i>',
      title: "Thank You for Your Feedback",
      html: '<p class="es-swal-text">Your feedback has been sent to the clinic. It helps us improve your care.</p>',
      confirmButtonText: "Back to My Appointments",
      buttonsStyling: false,
      customClass: ES_SWAL_CLASS,
    });
    showPage("appointments", null);
    return fbRefreshAppointments(); // row now shows "Feedback Submitted"
  }
  if (["already_submitted", "not_found", "not_completed", "no_dentist"].includes(r.code)) {
    if (r.code === "already_submitted") _fbSubmitted[aid] = { category: "" };
    _fbCurrent = null;
    await fbSwal("info", "fa-comment-slash", "Feedback Unavailable", r.message);
    showPage("appointments", null);
    return fbRefreshAppointments();
  }
  err.textContent = r.message || "We could not save your feedback. Please try again.";
}


// ── Info tooltips (.info-tip) ────────────────────────────────────────────────
// Small (i) buttons with the message in data-tip. Uses Bootstrap's Tooltip
// (already loaded with bootstrap.bundle) in manual mode, rendered into <body>
// so it is never clipped by collapsible sections or cards.
//   • Mouse / trackpad: shows on hover.
//   • Touch: tap toggles it; tapping anywhere else closes it.
//   • Keyboard: shows on focus; Escape closes it.
(function () {
  if (typeof bootstrap === "undefined" || !bootstrap.Tooltip) return;
  const fine = window.matchMedia("(hover: hover) and (pointer: fine)");
  let current = null;
  let kbOpened = null;    // the tip opened by keyboard focus (stays while focused)
  let lastPointer = 0;    // a tap/click also focuses the button — that is not keyboard
  document.addEventListener("pointerdown", () => { lastPointer = Date.now(); }, true);
  document.addEventListener("touchstart", () => { lastPointer = Date.now(); }, { capture: true, passive: true });

  function tipFor(el) {
    return bootstrap.Tooltip.getOrCreateInstance(el, {
      title: el.dataset.tip || "",
      trigger: "manual",
      container: "body",
      placement: el.dataset.tipPlacement || "top",
      customClass: "info-tooltip",
      fallbackPlacements: ["bottom", "right", "left"],
    });
  }
  function show(el) {
    if (el.hidden) return;
    if (current && current !== el) hide(current);
    tipFor(el).show();
    current = el;
  }
  function hide(el) {
    const t = el && bootstrap.Tooltip.getInstance(el);
    if (t) t.hide();
    if (current === el) current = null;
    if (kbOpened === el) kbOpened = null;
  }
  window.hideInfoTip = hide;

  document.addEventListener("mouseover", (e) => {
    if (!fine.matches) return;
    const el = e.target.closest && e.target.closest(".info-tip");
    if (el && el !== current) show(el);
  });
  document.addEventListener("mouseout", (e) => {
    if (!fine.matches) return;
    const el = e.target.closest && e.target.closest(".info-tip");
    if (el && !el.contains(e.relatedTarget) && kbOpened !== el) hide(el);
  });
  document.addEventListener("focusin", (e) => {
    const el = e.target.closest && e.target.closest(".info-tip");
    // Keyboard focus only — a tap/click also focuses, and the click below handles taps
    if (!el || Date.now() - lastPointer < 800) return;
    show(el);
    kbOpened = el;
  });
  document.addEventListener("focusout", (e) => {
    const el = e.target.closest && e.target.closest(".info-tip");
    if (el && kbOpened === el && !(fine.matches && el.matches(":hover"))) hide(el);
  });
  document.addEventListener("click", (e) => {
    const el = e.target.closest && e.target.closest(".info-tip");
    if (el) {
      e.preventDefault();
      if (!fine.matches) current === el ? hide(el) : show(el);
      return;
    }
    if (current) hide(current);
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && current) hide(current);
  });
  // A tooltip left open on a page that was switched away from
  window.addEventListener("scroll", () => { if (current && !fine.matches) hide(current); }, { passive: true, capture: true });
})();

// ── List filters (assets/js/portal-filters.js) ────────────────────────────────
(function () {
  if (!window.PortalFilters) return;
  PortalFilters.bind("treat", { onChange: () => renderTreatments(window._portalTreatments || []) });
  PortalFilters.bind("bill", { onChange: () => renderBilling(window._portalBilling || { invoices: [] }) });
  PortalFilters.bind("files", { onChange: renderFiles });
  PortalFilters.bind("waivers", { onChange: renderWaivers });
})();
