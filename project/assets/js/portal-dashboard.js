// assets/js/portal-dashboard.js
// Requires: const API defined in portal-dashboard.php before this file loads

// ── State ─────────────────────────────────────────────────────────────────────
let currentCancelId = null;
let accountData = {};
let isProfileComplete = false;

// ── Helpers ───────────────────────────────────────────────────────────────────
const _cache = {};

async function php(path, body) {
  const key = JSON.stringify(body);
  if (_cache[key]) return _cache[key];

  const data = await fetch(path, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  }).then((r) => r.json());

  _cache[key] = data;
  return data;
}

function clearCache(...actions) {
  actions.forEach((action) => {
    delete _cache[JSON.stringify({ action })];
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
async function checkAndShowQuestionnaire() {
  try {
    const data = await fetch(API, {
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
  if (typeof window.loadPortalAppointments === "function") {
    return window.loadPortalAppointments();
  }
}

function openCancelModal(id, code) {
  currentCancelId = id;
  document.getElementById("cancelApptCode").textContent = code;
  new bootstrap.Modal(document.getElementById("cancelModal")).show();
}

async function confirmCancel() {
  const data = await php(API, {
    action: "cancel_appointment",
    appointment_id: currentCancelId,
  });
  bootstrap.Modal.getInstance(document.getElementById("cancelModal")).hide();
  const code = document.getElementById("cancelApptCode")?.textContent.trim() || "";
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
    loadAppointments();
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
  }
}

// ── TREATMENTS ────────────────────────────────────────────────────────────────
async function loadTreatments() {
  const tbody = document.getElementById("treatTableBody");
  tbody.innerHTML =
    '<tr><td colspan="5" class="text-center py-4">Loading…</td></tr>';
  // Feedback state is fetched fresh every time (not cached) so it's right after a refresh or in another tab
  const [data] = await Promise.all([php(API, { action: "get_treatments" }), fbLoadStatus()]);
  const list = data.treatments || [];
  window._portalTreatments = list; // used by the Action menu (View Treatment History)
  if (!list.length) {
    tbody.innerHTML =
      '<tr><td colspan="5" class="text-center py-4" style="color:var(--text-muted);">No treatments on record.</td></tr>';
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
            ${fbActionItem(t)}
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
  } else {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Loading…</td></tr>';
  }

  try {
    const fresh = await fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: key,
    }).then((r) => r.json());

    if (!cached || JSON.stringify(fresh) !== JSON.stringify(cached)) {
      _cache[key] = fresh;
      renderBilling(fresh);
    }
  } catch (err) {
    console.error("Billing refresh failed:", err);
    // Keep showing the cached copy; only show an error if there was nothing to show.
    if (!cached) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Could not load invoices. Please try again.</td></tr>';
    }
  }
}

function renderBilling(data) {
  const tbody = document.getElementById("billingTableBody");
  const list = data.invoices || [];
  window._portalInvoices = list; // used by the Action menu (View Details)
  let totalBilled = 0, totalPaid = 0;

  if (!list.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted);">No invoices found.</td></tr>';
    document.getElementById("billTotal").textContent = peso(0);
    document.getElementById("billPaid").textContent = peso(0);
    document.getElementById("billBalance").textContent = peso(0);
    return;
  }

  tbody.innerHTML = list.map((i) => {
    totalBilled += parseFloat(i.total_amount || 0);
    totalPaid += parseFloat(i.amount_paid || 0);
    const bal = parseFloat(i.total_amount || 0) - parseFloat(i.amount_paid || 0);

    const status = (i.payment_status || "").toLowerCase();
    const hasPayment = ['paid', 'partial'].includes(status);

    // Action dropdown — same custom menu as My Appointments (.appt-action).
    // Clicks are handled in portal-dashboard.php (openInvoiceDetails /
    // printReceipt / downloadReceiptPdf), using this row's invoice_id. Receipts exist only
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
            <button type="button" class="appt-action-item" role="menuitem" data-action="bill-print" ${off}>
              <i class="fa-solid fa-print"></i>Print Receipt</button>
            <div class="appt-action-divider"></div>
            <button type="button" class="appt-action-item" role="menuitem" data-action="bill-pdf" ${off}>
              <i class="fa-solid fa-file-pdf"></i>Download PDF</button>
          </div>
        </div>`;

    return `<tr>
        <td data-label="Invoice #"><span class="bill-inv-code">${esc(i.invoice_code || "—")}</span></td>
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

// View in a new tab, or download (download keeps the patient on the portal)
function openPortalFile(f, download) {
  const url = safeFileUrl(f.file_path);
  if (!url) {
    Swal.fire({ icon: "info", title: "File not available", text: "This file could not be found. Please contact the clinic.", confirmButtonColor: "#2563eb" });
    return;
  }
  if (!download) {
    window.open(url, "_blank", "noopener");
    return;
  }
  const a = document.createElement("a");
  a.href = url;
  // Keep the real extension (e.g. ".pdf") even when file_name has none
  const fromPath = fileNameFromPath(url);
  const ext = (fromPath.match(/\.[A-Za-z0-9]{1,6}$/) || [""])[0];
  let name = String(f.file_name || "").trim() || fromPath || "file";
  if (ext && !name.toLowerCase().endsWith(ext.toLowerCase())) name += ext;
  a.download = name;
  a.rel = "noopener";
  document.body.appendChild(a);
  a.click();
  a.remove();
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
  const list = data.files || [];
  window._portalFiles = list;

  if (!list.length) {
    tbody.innerHTML =
      '<tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No files uploaded yet.</p><small>X-rays, referrals and certificates appear here once the clinic uploads them.</small></div></td></tr>';
    return;
  }

  const icons = {
    "x-ray": "fa-x-ray",
    xray: "fa-x-ray",
    referral: "fa-file-medical",
    certificate: "fa-certificate",
  };

  tbody.innerHTML = list
    .map((f, idx) => {
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
  const list = data.waivers || [];
  window._portalWaivers = list;

  if (!list.length) {
    tbody.innerHTML =
      '<tr><td colspan="5"><div class="empty-state"><i class="fa-solid fa-pen-to-square"></i><p>No waivers on file.</p><small>Consent forms prepared by the clinic will appear here for you to review and sign.</small></div></td></tr>';
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
  const docUrl = safeFileUrl(w.file_path);
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
  if (cached && cached.success) applyProfileData(cached);

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
  _cache[key] = fresh;
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
  // ── Email verification badge ──
  const isVerified = !!p.is_verified;
  const verifiedBadge = document.getElementById("emailVerifiedBadge");
  const unverifiedBadge = document.getElementById("emailUnverifiedBadge");
  const verifyBtnWrap = document.getElementById("emailVerifyBtnWrap");
  if (verifiedBadge)
    verifiedBadge.style.display = isVerified ? "inline" : "none";
  if (unverifiedBadge)
    unverifiedBadge.style.display = isVerified ? "none" : "inline";
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

  let ok = true;
  const req = (id, errId, msg) => {
    if (!document.getElementById(id)?.value.trim()) {
      showPfErr(errId, msg);
      ok = false;
    } else {
      hidePfErr(errId);
    }
  };
  req("pf_first", "err_pf_first", "First name is required.");
  req("pf_last", "err_pf_last", "Last name is required.");
  req("pf_birthdate", "err_pf_birthdate", "Birthdate is required.");

  // Guardian phone is optional, but must be a valid PH mobile number when given
  const guardianPhone = document.getElementById("pf_guardian_phone")?.value.trim() || "";
  if (guardianPhone && !/^9\d{9}$/.test(guardianPhone)) {
    showPfErr("err_pf_guardian_phone", "Enter a valid 10-digit PH mobile number starting with 9.");
    ok = false;
  } else {
    hidePfErr("err_pf_guardian_phone");
  }

  if (!ok) {
    setLoading(false);
    Swal.fire({
      icon: "warning",
      title: "Required Fields Missing",
      text: "Please fill in all required fields and correct any highlighted entries.",
      confirmButtonColor: "#2563eb",
    });
    if (typeof profGoTo === "function") profGoTo("basic");
    else document.getElementById("sec-basic").scrollIntoView({ behavior: "smooth" });
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
async function openChangePassword() {
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
    customClass: ES_SWAL_CLASS,
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
      btn.innerHTML =
        '<i class="fa-solid fa-envelope"></i> Send Verification Email';
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
  el.innerHTML =
    email || phone
      ? '<i class="fa-solid fa-circle-check"></i> Contact verification requirement satisfied — your ' +
        (email && phone ? "email and phone are" : email ? "email is" : "phone is") +
        " verified." + (email && phone ? "" : " Verifying the other method is optional.")
      : '<i class="fa-solid fa-circle-info"></i> Verify your email <strong>or</strong> your phone number before booking an appointment. Only one is required.';
  el.style.display = "flex";
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
  show("emailUnverifiedBadge", !email, "inline");
  show("emailVerifyBtnWrap", !email, "block");
  const hasPhone = !!document.getElementById("pf_contact")?.value.trim();
  show("phoneVerifiedBadge", hasPhone && phone, "inline");
  show("phoneUnverifiedBadge", hasPhone && !phone, "inline");
  show("phoneVerifyBtnWrap", hasPhone && !phone, "block");
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

async function checkContactStatus() {
  if (_contactChecking) return;
  _contactChecking = true;
  try {
    // Straight fetch: php() would cache the answer
    const res = await fetch(API, {
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
async function preloadAll() {
  await Promise.all([
    php(API, { action: "dashboard_summary" }),
    php(API, { action: "get_profile" }),
    php(API, { action: "get_treatments" }),
    php(API, { action: "get_billing" }),
    php(API, { action: "get_dental_chart" }),
    php(API, { action: "get_files" }),
    php(API, { action: "get_waivers" }),
  ]);

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
  checkAndShowQuestionnaire();
}

preloadAll();
checkContactStatus(); // silent baseline; later changes are announced
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

// ── Feedback (opened from Treatment History → Give Feedback) ──
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

// Action-menu item for a Treatment History row
function fbActionItem(t) {
  const aid = t.appointment_id;
  if (!aid || String(t.status).toLowerCase() !== "completed") return ""; // not eligible: not offered
  if (_fbSubmitted[aid]) {
    return `<button type="button" class="appt-action-item fb-done-item" role="menuitem" disabled aria-disabled="true"
              tabindex="-1" title="You already sent feedback for this appointment">
              <i class="fa-solid fa-circle-check"></i>Feedback Submitted</button>`;
  }
  return `<button type="button" class="appt-action-item" role="menuitem" data-action="trt-feedback">
            <i class="fa-solid fa-comment-medical"></i>Give Feedback</button>`;
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
    if (r.code === "already_submitted") { _fbSubmitted[appointmentId] = { category: "" }; loadTreatments(); }
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
      confirmButtonText: "Back to Treatment History",
      buttonsStyling: false,
      customClass: ES_SWAL_CLASS,
    });
    return showPage("treatments", null); // reloads the list → "Feedback Submitted"
  }
  if (["already_submitted", "not_found", "not_completed", "no_dentist"].includes(r.code)) {
    if (r.code === "already_submitted") _fbSubmitted[aid] = { category: "" };
    _fbCurrent = null;
    await fbSwal("info", "fa-comment-slash", "Feedback Unavailable", r.message);
    return showPage("treatments", null);
  }
  err.textContent = r.message || "We could not save your feedback. Please try again.";
}
