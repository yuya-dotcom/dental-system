<!-- appointmentWeb-Scheduling.php -->

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EssenciaSmile | Book an Appointment</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

  <!-- FullCalendar -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.css">

  <!-- Site Stylesheets -->
  <link rel="stylesheet" href="assets/css/webSched.css">
  <link rel="stylesheet" href="assets/css/webNavbar.css">
  <link rel="stylesheet" href="assets/css/webFooter.css">
</head>
<body>

<!-- ═══════════════════════════════════════════════
     NAVBAR
═══════════════════════════════════════════════ -->
<?php include("partials/appointmentWeb-Navbar.php")?>

<!-- Mobile Drawer -->
<div class="nav-mobile-menu" id="navMobileMenu">
  <a href="#hero">Home</a>
  <a href="#about">About</a>
  <a href="#services">Services</a>
  <a href="#contact">Contacts</a>
  <a href="portal-login.php" class="nav-mobile-cta">Create Account Now</a>
</div>


<!-- ═══════════════════════════════════════════════
     PAGE HERO
═══════════════════════════════════════════════ -->
<section class="page-hero">
  <h1>Book your dental visit<br><em>in just a few clicks</em></h1>
  <p>Exceptional dental care for a brighter, healthier smile — pick a branch, date, and time that works for you.</p>
</section>


<!-- ═══════════════════════════════════════════════
     BOOKING CARD
═══════════════════════════════════════════════ -->
<main class="container-fluid px-3 px-sm-4 px-lg-5 pb-5">
  <div class="booking-card">
    <div class="booking-grid">

      <!-- ── LEFT: Instructions + Branch ── -->
      <aside class="panel-left">
        <h4>Schedule an Appointment</h4>
        <p class="panel-subtitle">Exceptional dental care for a brighter smile today.</p>

        <p class="fw-bold small mb-2" style="color:var(--blue-dark)">For New Patients</p>

        <ul class="step-list">
          <li>
            <span class="step-num">1</span>
            <div>
              <h5>Select a Branch</h5>
              <p>Pick the clinic closest to you.</p>
            </div>
          </li>
          <li>
            <span class="step-num">2</span>
            <div>
              <h5>Choose a Day</h5>
              <p>Click any available date on the calendar.</p>
            </div>
          </li>
          <li>
            <span class="step-num">3</span>
            <div>
              <h5>Pick a Time Slot</h5>
              <p>Select a time that fits your schedule.</p>
            </div>
          </li>
          <li>
            <span class="step-num">4</span>
            <div>
              <h5>Fill Out the Form</h5>
              <p>Quick patient info for your records.</p>
            </div>
          </li>
        </ul>

        <div class="branch-section">
          <label for="appBranchFilter">
            <svg width="11" height="11" viewBox="0 0 12 12" fill="none" style="margin-right:4px;vertical-align:-1px">
              <path d="M6 1C4.3 1 3 2.4 3 4c0 2.5 3 7 3 7s3-4.5 3-7c0-1.6-1.3-3-3-3z" stroke="currentColor" stroke-width="1.2" fill="none"/>
            </svg>
            Pick a Branch
          </label>
          <div class="branch-select-wrap">
            <select id="appBranchFilter">
              <option value="">— Select a branch —</option>
            </select>
            <span class="branch-arrow">
              <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                <path d="M3 5l3.5 3.5L10 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </span>
          </div>
        </div>
      </aside>

      <!-- ── CENTER: Calendar ── -->
      <div class="panel-center">
        <p class="panel-heading">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="margin-right:6px;vertical-align:-1px">
            <rect x="1" y="2" width="12" height="11" rx="2.5" stroke="currentColor" stroke-width="1.3"/>
            <path d="M4 1v2M10 1v2M1 5.5h12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
          </svg>
          Select a date
        </p>
        <div id="calendar"></div>
      </div>

      <!-- ── RIGHT: Time Slots ── -->
      <div class="panel-right">
        <p id="selectedDate" class="panel-heading">
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="margin-right:6px;vertical-align:-1px">
            <circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.3"/>
            <path d="M7 4v3l2.5 1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
          </svg>
          Select time
        </p>
        <div id="timeSlots" class="time-slots">
          <div class="slot-empty">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" style="margin-bottom:8px;opacity:0.3">
              <circle cx="16" cy="16" r="12" stroke="#6b7a8d" stroke-width="1.5"/>
              <path d="M16 9v7l4 2.5" stroke="#6b7a8d" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <br>Select a branch and date<br>to see available times.
          </div>
        </div>
      </div>

    </div>
  </div>
</main>


<!-- ═══════════════════════════════════════════════
     BOOKING MODAL  (Bootstrap)
═══════════════════════════════════════════════ -->
<div class="modal fade" id="personalInfoModal" tabindex="-1" aria-labelledby="personalInfoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <!-- Header -->
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="personalInfoModalLabel">Complete Your Booking</h5>
          <p class="text-muted small mb-0 mt-1">Fill in your details to confirm the appointment.</p>

          <!-- Summary chips in header -->
          <div class="modal-summary-chips">
            <span class="modal-chip">
              <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><rect x="1" y="2" width="10" height="9" rx="2" stroke="rgba(255,255,255,0.7)" stroke-width="1.2"/><path d="M4 1v2M8 1v2M1 5h10" stroke="rgba(255,255,255,0.7)" stroke-width="1.2" stroke-linecap="round"/></svg>
              <span id="modalSummaryDate">—</span>
            </span>
            <span class="modal-chip">
              <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><circle cx="6" cy="6" r="4.5" stroke="rgba(255,255,255,0.7)" stroke-width="1.2"/><path d="M6 3.5V6l2 1.5" stroke="rgba(255,255,255,0.7)" stroke-width="1.2" stroke-linecap="round"/></svg>
              <span id="modalSummaryTime">—</span>
            </span>
            <span class="modal-chip">
              <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M6 1C4.3 1 3 2.4 3 4c0 2.5 3 7 3 7s3-4.5 3-7c0-1.6-1.3-3-3-3z" stroke="rgba(255,255,255,0.7)" stroke-width="1.2" fill="none"/></svg>
              <span id="modalSummaryBranch">—</span>
            </span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body pt-3 px-4">

        <!-- Error alert -->
        <div id="modalError" class="alert alert-danger small py-2 px-3 mb-3" style="display:none;border-radius:9px;"></div>

        <div class="row g-3">
          <!-- Last Name + Suffix -->
          <div class="col-8">
            <label for="modal_lastName" class="form-label">Last Name <span class="text-danger">*</span></label>
            <input type="text" id="modal_lastName" class="form-control" placeholder="e.g. Dela Cruz" autocomplete="family-name">
            <div class="text-danger small mt-1" id="err_lastName"></div>
          </div>
          <div class="col-4">
            <label for="modal_suffix" class="form-label">Suffix <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(opt.)</span></label>
            <select id="modal_suffix" class="form-select">
              <option value="">None</option>
              <option value="Jr.">Jr.</option>
              <option value="Sr.">Sr.</option>
              <option value="II">II</option>
              <option value="III">III</option>
              <option value="IV">IV</option>
            </select>
          </div>

          <!-- First + Middle Name -->
          <div class="col-6">
            <label for="modal_firstName" class="form-label">First Name <span class="text-danger">*</span></label>
            <input type="text" id="modal_firstName" class="form-control" placeholder="e.g. Juan" autocomplete="given-name">
            <div class="text-danger small mt-1" id="err_firstName"></div>
          </div>
          <div class="col-6">
            <label for="modal_middleName" class="form-label">Middle Name <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(opt.)</span></label>
            <input type="text" id="modal_middleName" class="form-control" placeholder="e.g. Santos" autocomplete="additional-name">
          </div>

          <!-- Birthdate -->
          <div class="col-12">
            <label for="modal_birthdate" class="form-label">Birthdate <span class="text-danger">*</span></label>
            <input type="date" id="modal_birthdate" class="form-control">
            <div class="text-danger small mt-1" id="err_birthdate"></div>
          </div>

          <!-- Service -->
          <div class="col-12">
            <label for="modal_service" class="form-label">Service <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(optional)</span></label>
            <select id="modal_service" class="form-select">
              <option value="">— Select a service —</option>
            </select>
          </div>

          <!-- Contact Number -->
          <div class="col-12">
            <label for="modal_contactNumber" class="form-label">Contact Number <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text">+63</span>
              <input type="tel" id="modal_contactNumber" class="form-control"
                placeholder="9XX XXX XXXX" maxlength="10" autocomplete="tel"
                oninput="this.value = this.value.replace(/\D/g,'').substring(0,10)">
            </div>
            <div class="text-danger small mt-1" id="err_contactNumber"></div>
          </div>
        </div>

        <p class="text-muted small mt-3 mb-0" style="font-size:0.75rem!important">
          Your contact number identifies your record on future visits. No account needed.
        </p>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="modalConfirmBtn" class="btn btn-primary px-4" onclick="submitBookingModal()">
          Confirm Appointment
        </button>
      </div>

    </div>
  </div>
</div>


<!-- ═══════════════════════════════════════════════
     SCRIPTS
═══════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
/* ── Navbar hamburger ──────────────────────── */
const navToggler    = document.getElementById('navToggler');
const navMobileMenu = document.getElementById('navMobileMenu');

navToggler.addEventListener('click', () => {
  const open = navToggler.classList.toggle('is-open');
  navMobileMenu.classList.toggle('is-open', open);
});

/* Close drawer when a link is tapped */
navMobileMenu.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', () => {
    navToggler.classList.remove('is-open');
    navMobileMenu.classList.remove('is-open');
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

  let selectedDate   = null;
  let selectedTime   = null;
  let selectedBranch = null;

  const calendarEl  = document.getElementById('calendar');
  const timeSlotsEl = document.getElementById('timeSlots');
  const dateLabel   = document.getElementById('selectedDate');

  /* ── Load Branches ──────────────────────── */
  fetch('api/get_branches.php')
    .then(r => r.json())
    .then(data => {
      const select = document.getElementById('appBranchFilter');
      if (!select || !data.branches) return;
      data.branches.forEach(b => {
        const opt = document.createElement('option');
        opt.value       = b.branch_id;
        opt.textContent = b.branch_name;
        select.appendChild(opt);
      });
    })
    .catch(() => console.warn('Could not load branches.'));

  /* ── Load Services ──────────────────────── */
  fetch('api/get_services.php')
    .then(r => r.json())
    .then(data => {
      const select = document.getElementById('modal_service');
      if (!select || !data.services) return;
      data.services.forEach(s => {
        const opt = document.createElement('option');
        opt.value       = s.service_id;
        opt.textContent = s.base_price
          ? `${s.service_name} — ₱${parseFloat(s.base_price).toLocaleString()}`
          : s.service_name;
        select.appendChild(opt);
      });
    })
    .catch(() => console.warn('Could not load services.'));

  /* ── FullCalendar ───────────────────────── */
  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView:        'dayGridMonth',
    headerToolbar:      { left: 'prev', center: 'title', right: 'next' },
    fixedWeekCount:     false,
    showNonCurrentDates: false,
    hiddenDays:         [0, 1],
    validRange: {
      start: (() => {
        const t = new Date();
        t.setDate(t.getDate() + 1);
        return t.toISOString().split('T')[0];
      })()
    },
    datesSet(info) {
      checkFullyBookedDays(info.startStr, info.endStr);
    },
    dateClick(info) {
      const branchSelect = document.getElementById('appBranchFilter');
      if (!branchSelect.value) {
        showWarningToast('Please select a branch first before choosing a date.');
        return;
      }
      if (info.dayEl.classList.contains('fc-day-fully-booked')) return;

      document.querySelectorAll('.fc-day-selected')
              .forEach(el => el.classList.remove('fc-day-selected'));
      info.dayEl.classList.add('fc-day-selected');

      selectedDate   = info.dateStr;
      selectedTime   = null;
      selectedBranch = branchSelect.value;

      const displayDate = new Date(info.dateStr + 'T00:00:00')
        .toLocaleDateString('en-PH', {
          weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        });

      dateLabel.innerHTML = `<svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="margin-right:6px;vertical-align:-1px"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.3"/><path d="M7 4v3l2.5 1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>${displayDate}`;

      loadTimeSlots(info.dateStr, selectedBranch);
    }
  });

  calendar.render();

  /* ── Check Fully Booked Days ────────────── */
  function checkFullyBookedDays(startStr, endStr) {
    const branchId = document.getElementById('appBranchFilter').value;
    if (!branchId) return;

    fetch(`api/get_booked_days.php?start=${startStr}&end=${endStr}&branch=${branchId}`)
      .then(r => r.json())
      .then(data => {
        if (!data.fully_booked) return;
        data.fully_booked.forEach(dateStr => {
          const dayEl = calendarEl.querySelector(`[data-date="${dateStr}"]`);
          if (dayEl) {
            dayEl.classList.add('fc-day-fully-booked');
            dayEl.setAttribute('title', 'Fully booked');
          }
        });
      })
      .catch(err => console.warn('Could not check booked days:', err));
  }

  /* ── Branch change ──────────────────────── */
  document.getElementById('appBranchFilter').addEventListener('change', function () {
    document.querySelectorAll('.fc-day-fully-booked')
            .forEach(el => el.classList.remove('fc-day-fully-booked'));

    timeSlotsEl.innerHTML = `
      <div class="slot-empty">
        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" style="margin-bottom:8px;opacity:0.3">
          <circle cx="16" cy="16" r="12" stroke="#6b7a8d" stroke-width="1.5"/>
          <path d="M16 9v7l4 2.5" stroke="#6b7a8d" stroke-width="1.5" stroke-linecap="round"/>
        </svg><br>Select a date to see available times.
      </div>`;

    dateLabel.innerHTML = `<svg width="14" height="14" viewBox="0 0 14 14" fill="none" style="margin-right:6px;vertical-align:-1px"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.3"/><path d="M7 4v3l2.5 1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>Select time`;
    selectedDate = null;
    selectedTime = null;

    document.querySelectorAll('.fc-day-selected')
            .forEach(el => el.classList.remove('fc-day-selected'));

    const view = calendar.view;
    checkFullyBookedDays(
      view.activeStart.toISOString().split('T')[0],
      view.activeEnd.toISOString().split('T')[0]
    );
  });

  /* ── Load Time Slots ────────────────────── */
  function loadTimeSlots(dateStr, branchId) {
    timeSlotsEl.innerHTML = `
      <div class="slot-loading">
        <div class="spinner-border spinner-border-sm text-primary"></div>
        Loading available times…
      </div>`;

    fetch(`api/get_timeslots.php?date=${dateStr}&branch=${branchId}`)
      .then(r => r.json())
      .then(data => {
        if (data.error) throw new Error(data.error);
        renderTimeSlots(dateStr, data.booked || []);
      })
      .catch(err => {
        console.error(err);
        timeSlotsEl.innerHTML = `
          <div class="slot-empty text-danger">
            Failed to load time slots. Please try again.
          </div>`;
      });
  }

  /* ── Generate Slots: 9:00 – 17:00 ──────── */
  function generateSlots() {
    const slots = [];
    for (let h = 9; h <= 17; h++) {
      slots.push(`${String(h).padStart(2,'0')}:00`);
      if (h < 17) slots.push(`${String(h).padStart(2,'0')}:30`);
    }
    return slots;
  }

  function formatTime(time) {
    return new Date(`1970-01-01T${time}:00`)
      .toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
  }

  /* ── Render Slots ───────────────────────── */
  function renderTimeSlots(dateStr, bookedTimes) {
    const slots     = generateSlots();
    const available = slots.filter(s => !bookedTimes.includes(s));

    timeSlotsEl.innerHTML = '';

    if (!slots.length) {
      timeSlotsEl.innerHTML = `<div class="slot-empty">No slots configured for this day.</div>`;
      return;
    }

    /* Legend */
    const legend = document.createElement('div');
    legend.className = 'slot-legend';
    legend.innerHTML = `
      <span>
        <span class="legend-dot" style="background:var(--blue-light);border:1.5px solid rgba(13,110,253,0.3)"></span>
        Available
      </span>
      <span>
        <span class="legend-dot" style="background:#f1f4f8;border:1px solid var(--border)"></span>
        Booked
      </span>`;
    timeSlotsEl.appendChild(legend);

    /* Grid */
    const grid = document.createElement('div');
    grid.className = 'slot-grid';

    slots.forEach(time => {
      const isBooked = bookedTimes.includes(time);
      const btn = document.createElement('button');
      btn.className = `slot-btn ${isBooked ? 'slot-btn-booked' : 'slot-btn-available'}`;
      btn.textContent = formatTime(time);
      btn.disabled    = isBooked;
      btn.title       = isBooked ? 'Already booked' : 'Click to select';

      if (!isBooked) {
        btn.addEventListener('click', () => {
          grid.querySelectorAll('.slot-btn-available').forEach(b => b.classList.remove('selected'));
          btn.classList.add('selected');
          selectedTime = time;
          openBookingModal(dateStr, time);
        });
      }

      grid.appendChild(btn);
    });

    timeSlotsEl.appendChild(grid);

    /* Count */
    const info = document.createElement('p');
    info.className = 'slot-count';
    info.textContent = `${available.length} of ${slots.length} slots available`;
    timeSlotsEl.appendChild(info);
  }

  /* ── Open Modal ─────────────────────────── */
  function openBookingModal(dateStr, time) {
    const displayDate = new Date(dateStr + 'T00:00:00')
      .toLocaleDateString('en-PH', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
      });

    const branchSelect = document.getElementById('appBranchFilter');
    const branchName   = branchSelect.options[branchSelect.selectedIndex]?.text || '';

    document.getElementById('modalSummaryDate').textContent   = displayDate;
    document.getElementById('modalSummaryTime').textContent   = formatTime(time);
    document.getElementById('modalSummaryBranch').textContent = branchName;

    ['lastName','suffix','firstName','middleName','birthdate','contactNumber'].forEach(id => {
      const el = document.getElementById('modal_' + id);
      if (el) { el.value = ''; el.classList.remove('is-invalid'); }
      const err = document.getElementById('err_' + id);
      if (err) err.textContent = '';
    });

    const serviceEl = document.getElementById('modal_service');
    if (serviceEl) serviceEl.selectedIndex = 0;

    const birthdateEl = document.getElementById('modal_birthdate');
    if (birthdateEl) birthdateEl.max = new Date().toISOString().split('T')[0];

    const errBox = document.getElementById('modalError');
    if (errBox) { errBox.textContent = ''; errBox.style.display = 'none'; }

    new bootstrap.Modal(document.getElementById('personalInfoModal')).show();
  }

  /* ── Submit Modal ───────────────────────── */
  window.submitBookingModal = function () {
    const lastName   = document.getElementById('modal_lastName').value.trim();
    const suffix     = document.getElementById('modal_suffix').value.trim();
    const firstName  = document.getElementById('modal_firstName').value.trim();
    const middleName = document.getElementById('modal_middleName').value.trim();
    const birthdate  = document.getElementById('modal_birthdate').value;
    const phone      = document.getElementById('modal_contactNumber').value.trim();

    ['lastName','firstName','birthdate','contactNumber'].forEach(id => {
      const el = document.getElementById('modal_' + id);
      if (el) el.classList.remove('is-invalid');
      const err = document.getElementById('err_' + id);
      if (err) err.textContent = '';
    });

    let valid = true;
    if (lastName.length < 2) {
      document.getElementById('modal_lastName').classList.add('is-invalid');
      document.getElementById('err_lastName').textContent = 'Enter your last name.';
      valid = false;
    }
    if (firstName.length < 2) {
      document.getElementById('modal_firstName').classList.add('is-invalid');
      document.getElementById('err_firstName').textContent = 'Enter your first name.';
      valid = false;
    }
    if (!birthdate) {
      document.getElementById('modal_birthdate').classList.add('is-invalid');
      document.getElementById('err_birthdate').textContent = 'Please enter your birthdate.';
      valid = false;
    } else {
      const today = new Date(), bd = new Date(birthdate);
      const age = today.getFullYear() - bd.getFullYear();
      if (bd >= today || age > 120) {
        document.getElementById('modal_birthdate').classList.add('is-invalid');
        document.getElementById('err_birthdate').textContent = 'Please enter a valid birthdate.';
        valid = false;
      }
    }
    if (!/^9\d{9}$/.test(phone)) {
      document.getElementById('modal_contactNumber').classList.add('is-invalid');
      document.getElementById('err_contactNumber').textContent = 'Enter a valid PH number starting with 9 (10 digits).';
      valid = false;
    }
    if (!valid) return;

    const btn = document.getElementById('modalConfirmBtn');
    btn.disabled  = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Confirming…`;

    fetch('api/book_appointment.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        first_name:     firstName,
        middle_name:    middleName,
        last_name:      lastName,
        suffix:         suffix,
        birthdate:      birthdate,
        contact_number: '+63' + phone,
        date:           selectedDate,
        time:           selectedTime,
        branch_id:      parseInt(document.getElementById('appBranchFilter').value),
        service_id:     document.getElementById('modal_service').value
                          ? parseInt(document.getElementById('modal_service').value)
                          : null,
      })
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        bootstrap.Modal.getInstance(document.getElementById('personalInfoModal')).hide();
        loadTimeSlots(selectedDate, selectedBranch);
        const view = calendar.view;
        checkFullyBookedDays(
          view.activeStart.toISOString().split('T')[0],
          view.activeEnd.toISOString().split('T')[0]
        );
        showSuccessToast(data.appointment_code);
      } else {
        const errBox = document.getElementById('modalError');
        errBox.textContent = data.message;
        errBox.style.display = 'block';
      }
    })
    .catch(() => {
      const errBox = document.getElementById('modalError');
      errBox.textContent = 'Something went wrong. Please try again.';
      errBox.style.display = 'block';
    })
    .finally(() => {
      btn.disabled  = false;
      btn.innerHTML = 'Confirm Appointment';
    });
  };

  /* ── SweetAlert2 toasts ─────────────────── */
  function showWarningToast(msg) {
    Swal.fire({
      icon: 'warning',
      title: 'Hold on!',
      text:  msg,
      confirmButtonText:  'Got it',
      confirmButtonColor: '#f59e0b',
    });
  }

  function showSuccessToast(code) {
    Swal.fire({
      icon:  'success',
      title: 'Appointment Confirmed!',
      html:  `Booking reference: <strong>${code}</strong><br>
              <span class="text-muted small">We'll see you soon!</span>`,
      confirmButtonText:  'Done',
      confirmButtonColor: '#0d6efd',
      allowOutsideClick:  false,
    });
  }

});
</script>

</body>
</html>