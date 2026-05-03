// =============================================================
//  EssenciaSmile – fullCalendar.js
//  File: assets/js/fullCalendar.js
// =============================================================

document.addEventListener('DOMContentLoaded', function () {

  // ── State ────────────────────────────────────────────────────
  let selectedDate   = null;
  let selectedTime   = null;
  let selectedBranch = null;

  const calendarEl  = document.getElementById('calendar');
  const timeSlotsEl = document.getElementById('timeSlots');
  const dateLabel   = document.getElementById('selectedDate');

  // ── Load Branches into branch dropdown ───────────────────────
  fetch('api/get_branches.php')
    .then(res => res.json())
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

  // ── Load Services into modal dropdown ────────────────────────
  fetch('api/get_services.php')
    .then(res => res.json())
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

  // Total slots per day — used to detect fully booked days
  // Weekdays: 9:00–17:00 = 17 slots (every 30 min including 9:00 and 17:00)
  // We check this per-date since Saturday may differ
  function getTotalSlots(dateStr) {
    const day = new Date(dateStr + 'T00:00:00').getDay();
    return generateSlots(dateStr).length;
  }

  // ── FullCalendar Setup ────────────────────────────────────────
  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    headerToolbar: { left: 'prev', center: 'title', right: 'next' },
    fixedWeekCount: false,
    showNonCurrentDates: false,
    hiddenDays: [0, 1], // hide Sunday and Monday (clinic closed)
    validRange: {
      start: (() => {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        return tomorrow.toISOString().split('T')[0];
      })()
    },

    // When the calendar month changes, fetch which dates are fully booked
    datesSet(info) {
      checkFullyBookedDays(info.startStr, info.endStr);
    },

    dateClick(info) {
      // Require branch first
      const branchSelect = document.getElementById('appBranchFilter');
      if (!branchSelect.value) {
        showWarningToast('Please select a branch first before choosing a date.');
        return;
      }

      // Don't allow clicking fully-booked dates
      if (info.dayEl.classList.contains('fc-day-fully-booked')) return;

      // Highlight selected day
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

      dateLabel.textContent = displayDate;
      dateLabel.classList.remove('text-muted');

      loadTimeSlots(info.dateStr, selectedBranch);
    }
  });

  calendar.render();

  // ── Check Fully Booked Days ───────────────────────────────────
  // Fetches booked counts for every visible date and greys out full days
  function checkFullyBookedDays(startStr, endStr) {
    const branchId = document.getElementById('appBranchFilter').value;
    if (!branchId) return; // need branch to check

    fetch(`api/get_booked_days.php?start=${startStr}&end=${endStr}&branch=${branchId}`)
      .then(res => res.json())
      .then(data => {
        if (!data.fully_booked) return;

        data.fully_booked.forEach(dateStr => {
          // Find the calendar day cell and mark it
          const dayEl = calendarEl.querySelector(`[data-date="${dateStr}"]`);
          if (dayEl) {
            dayEl.classList.add('fc-day-fully-booked');
            dayEl.setAttribute('title', 'Fully booked');
          }
        });
      })
      .catch(err => console.warn('Could not check booked days:', err));
  }

  // Re-check when branch changes
  document.getElementById('appBranchFilter').addEventListener('change', function () {
    // Clear old markings
    document.querySelectorAll('.fc-day-fully-booked')
            .forEach(el => el.classList.remove('fc-day-fully-booked'));

    // Reset time slots panel
    timeSlotsEl.innerHTML = '';
    dateLabel.textContent = 'Select time';
    dateLabel.classList.add('text-muted');
    selectedDate = null;
    selectedTime = null;

    // Re-check for new branch
    const view = calendar.view;
    checkFullyBookedDays(view.activeStart.toISOString().split('T')[0],
                         view.activeEnd.toISOString().split('T')[0]);
  });


  // ── Load Time Slots ───────────────────────────────────────────
  function loadTimeSlots(dateStr, branchId) {
    timeSlotsEl.innerHTML = `
      <div class="text-muted small py-2 d-flex align-items-center gap-2">
        <span class="spinner-border spinner-border-sm"></span> Loading available times...
      </div>`;

    fetch(`api/get_timeslots.php?date=${dateStr}&branch=${branchId}`)
      .then(res => res.json())
      .then(data => {
        if (data.error) throw new Error(data.error);
        renderTimeSlots(dateStr, data.booked || []);
      })
      .catch(err => {
        console.error(err);
        timeSlotsEl.innerHTML = `
          <div class="text-danger small py-2">
            <i class="feather-alert-circle me-1"></i>Failed to load time slots. Please try again.
          </div>`;
      });
  }

  // ── Generate Slots: 9:00 AM – 5:00 PM ────────────────────────
  function generateSlots(dateStr) {
    // Clinic hours: 9:00 AM to 5:00 PM, every 30 minutes
    // Both weekdays and Saturday use the same hours
    const slots = [];
    for (let h = 9; h <= 17; h++) {
      slots.push(`${String(h).padStart(2,'0')}:00`);
      if (h < 17) slots.push(`${String(h).padStart(2,'0')}:30`);
    }
    return slots; // 9:00, 9:30, 10:00 ... 16:30, 17:00 → 17 slots
  }

  function formatTime(time) {
    return new Date(`1970-01-01T${time}:00`)
      .toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
  }

  // ── Render Slots ──────────────────────────────────────────────
  function renderTimeSlots(dateStr, bookedTimes) {
    const slots     = generateSlots(dateStr);
    const available = slots.filter(s => !bookedTimes.includes(s));

    timeSlotsEl.innerHTML = '';

    if (!slots.length) {
      timeSlotsEl.innerHTML = `<div class="text-muted small py-2">No slots configured for this day.</div>`;
      return;
    }

    // Legend
    const legend = document.createElement('div');
    legend.className = 'd-flex gap-3 mb-2';
    legend.innerHTML = `
      <span class="d-flex align-items-center gap-1 small">
        <span style="width:12px;height:12px;border-radius:3px;background:#0d6efd;display:inline-block;"></span> Available
      </span>
      <span class="d-flex align-items-center gap-1 small text-muted">
        <span style="width:12px;height:12px;border-radius:3px;background:#e9ecef;border:1px solid #dee2e6;display:inline-block;"></span> Booked
      </span>`;
    timeSlotsEl.appendChild(legend);

    const grid = document.createElement('div');
    grid.className = 'd-flex flex-wrap gap-2';

    slots.forEach(time => {
      const isBooked = bookedTimes.includes(time);
      const btn = document.createElement('button');
      btn.className = 'btn ' + (isBooked ? 'btn-outline-secondary' : 'btn-outline-primary');
      btn.textContent = formatTime(time);
      btn.disabled = isBooked;
      btn.title = isBooked ? 'Already booked' : 'Click to select this time';
      btn.style.cssText = 'min-width:110px; padding:10px 14px; font-size:0.875rem; font-weight:500;';

      if (!isBooked) {
        btn.addEventListener('click', () => {
          grid.querySelectorAll('.btn').forEach(b => {
            b.classList.remove('btn-primary');
            if (!b.disabled) b.classList.add('btn-outline-primary');
          });
          btn.classList.remove('btn-outline-primary');
          btn.classList.add('btn-primary');

          selectedTime = time;
          openBookingModal(dateStr, time);
        });
      }

      grid.appendChild(btn);
    });

    timeSlotsEl.appendChild(grid);

    // Remaining count
    const info = document.createElement('p');
    info.className = 'small text-muted mt-2 mb-0';
    info.textContent = `${available.length} of ${slots.length} slots available`;
    timeSlotsEl.appendChild(info);
  }


  // ── Open Modal ────────────────────────────────────────────────
  function openBookingModal(dateStr, time) {
    const displayDate = new Date(dateStr + 'T00:00:00')
      .toLocaleDateString('en-PH', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
      });

    const branchSelect = document.getElementById('appBranchFilter');
    const branchName   = branchSelect.options[branchSelect.selectedIndex]?.text || '';

    // Fill summary
    document.getElementById('modalSummaryDate').textContent   = displayDate;
    document.getElementById('modalSummaryTime').textContent   = formatTime(time);
    document.getElementById('modalSummaryBranch').textContent = branchName;

    // Reset form
    // Reset all form fields
    ['lastName','suffix','firstName','middleName','birthdate','contactNumber'].forEach(id => {
      const el = document.getElementById('modal_' + id);
      if (el) { el.value = ''; el.classList.remove('is-invalid'); }
      const err = document.getElementById('err_' + id);
      if (err) err.textContent = '';
    });

    // Reset service dropdown back to first option
    const serviceEl = document.getElementById('modal_service');
    if (serviceEl) serviceEl.selectedIndex = 0;

    // Set birthdate max to today (can't be born in the future)
    const birthdateEl = document.getElementById('modal_birthdate');
    if (birthdateEl) birthdateEl.max = new Date().toISOString().split('T')[0];

    // Hide error alert
    const errBox = document.getElementById('modalError');
    if (errBox) { errBox.textContent = ''; errBox.style.display = 'none'; }

    // Open
    const modal = new bootstrap.Modal(document.getElementById('personalInfoModal'));
    modal.show();
  }


  // ── Submit Modal ──────────────────────────────────────────────
  window.submitBookingModal = function () {
    const lastName   = document.getElementById('modal_lastName').value.trim();
    const suffix     = document.getElementById('modal_suffix').value.trim();
    const firstName  = document.getElementById('modal_firstName').value.trim();
    const middleName = document.getElementById('modal_middleName').value.trim();
    const birthdate  = document.getElementById('modal_birthdate').value;
    const phone      = document.getElementById('modal_contactNumber').value.trim();

    // Clear previous errors
    ['lastName','firstName','birthdate','contactNumber'].forEach(id => {
      const el = document.getElementById('modal_' + id);
      if (el) el.classList.remove('is-invalid');
      const err = document.getElementById('err_' + id);
      if (err) err.textContent = '';
    });

    // Validate required fields
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
      // Must be at least 1 year old, not in the future
      const today = new Date();
      const bd    = new Date(birthdate);
      const age   = today.getFullYear() - bd.getFullYear();
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

    // Button loading state
    const btn = document.getElementById('modalConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>Confirming...`;

    fetch('api/book_appointment.php', {
      method: 'POST',
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
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        // Close modal
        bootstrap.Modal.getInstance(document.getElementById('personalInfoModal')).hide();

        // Refresh slots to show newly booked slot
        loadTimeSlots(selectedDate, selectedBranch);

        // Re-check if day is now fully booked
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
      btn.disabled = false;
      btn.innerHTML = 'Confirm Appointment';
    });
  };


  // ── Warning Alert (SweetAlert2) ──────────────────────────────
  function showWarningToast(msg) {
    Swal.fire({
      icon:              'warning',
      title:             'Hold on!',
      text:              msg,
      confirmButtonText: 'Got it',
      confirmButtonColor: '#f59e0b',
    });
  }

  // ── Success Alert (SweetAlert2) ───────────────────────────────
  function showSuccessToast(code) {
    Swal.fire({
      icon:  'success',
      title: 'Appointment Confirmed!',
      html:  `Booking reference: <strong>${code}</strong><br>
              <span class="text-muted small">We'll see you soon!</span>`,
      confirmButtonText:  'Done',
      confirmButtonColor: '#1a7a5e',
      allowOutsideClick: false,
    });
  }

});