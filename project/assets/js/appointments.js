/* =============================================================
   EssenciaSmile — Appointments JS
   File: assets/js/appointments.js
   Depends on: admin.js, sweetalert2
   ============================================================= */

// ── Status updates (non-completed) ──────────────────────────
function updateStatus(appointmentId, status, code) {
    // Intercept "completed" — show materials modal instead
    if (status === 'completed') {
        openCompletionModal(appointmentId, code);
        return;
    }

    // EssenciaSmile Swal style (assets/css/es-swal.css); icons use the admin's Feather font
    const labels = {
        confirmed:  { title: 'Confirm Appointment?', text: 'This appointment will be marked as confirmed.',      btn: 'Yes, Confirm',  icon: 'feather-check-circle', tone: 'success' },
        checked_in: { title: 'Check In Patient?',    text: 'The patient will be checked in for this appointment.', btn: 'Yes, Check In', icon: 'feather-log-in',       tone: 'warn' },
        cancelled:  { title: 'Cancel Appointment?',  text: 'This appointment will be cancelled. This cannot be undone.', btn: 'Yes, Cancel', icon: 'feather-x-circle', tone: 'danger' },
    };
    const cfg = labels[status];
    if (!cfg) return;

    // Cancelling asks for the reason (required) — it is stored with who
    // cancelled and when, and shown in Appointment Records → Cancellation Information.
    if (status === 'cancelled') {
        Swal.fire({
            icon:     'warning',
            iconHtml: `<i class="${cfg.icon} es-swal-glyph"></i>`,
            title:    cfg.title,
            html:     `<p class="es-swal-text">${cfg.text}</p>` +
                      `<span class="es-swal-chip"><i class="feather-calendar"></i> ${_esc(code)}</span>` +
                      '<label class="es-swal-label apt-cancel-label" for="swal2-textarea">Reason for cancellation <span class="text-danger">*</span></label>',
            input:            'textarea',
            inputPlaceholder: 'e.g. Patient called to reschedule; dentist unavailable…',
            inputAttributes:  { maxlength: '500', 'aria-label': 'Reason for cancellation' },
            inputValidator:   v => {
                const t = String(v || '').trim();
                if (!t) return 'Please enter the reason for cancelling.';
                if (t.length > 500) return 'Please keep the reason under 500 characters.';
            },
            showCancelButton:  true,
            confirmButtonText: cfg.btn,
            cancelButtonText:  'Go Back',
            buttonsStyling:    false,
            customClass:       Object.assign({}, ES_SWAL_TONE(cfg.tone), { input: 'es-swal-input apt-cancel-reason' }),
        }).then(result => {
            if (!result.isConfirmed) return;
            _patchStatus(appointmentId, status, code, String(result.value || '').trim());
        });
        return;
    }

    Swal.fire({
        icon:     'question',
        iconHtml: `<i class="${cfg.icon} es-swal-glyph"></i>`,
        title:    cfg.title,
        html:     `<p class="es-swal-text">${cfg.text}</p>` +
                  `<span class="es-swal-chip"><i class="feather-calendar"></i> ${_esc(code)}</span>`,
        showCancelButton:  true,
        confirmButtonText: cfg.btn,
        cancelButtonText:  'Go Back',
        focusCancel:       status === 'cancelled',
        buttonsStyling:    false,
        customClass:       ES_SWAL_TONE(cfg.tone),
    }).then(result => {
        if (!result.isConfirmed) return;
        _patchStatus(appointmentId, status, code);
    });
}

function _patchStatus(appointmentId, status, code, reason) {
    const body = { status };
    if (status === 'cancelled') body.reason = reason || '';
    fetch(`api/appointments.php?id=${appointmentId}`, {
        method:  'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(body),
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const done = {
                confirmed:  ['Appointment Confirmed', 'feather-check-circle', 'success'],
                checked_in: ['Patient Checked In',    'feather-log-in',       'success'],
                cancelled:  ['Appointment Cancelled', 'feather-x-circle',     'danger'],
            }[status] || ['Status Updated', 'feather-check-circle', 'success'];
            Swal.fire({
                icon:     'success',
                iconHtml: `<i class="${done[1]} es-swal-glyph"></i>`,
                title:    done[0],
                html:     `<p class="es-swal-text">${_esc(res.message || 'The appointment status has been updated.')}</p>` +
                          `<span class="es-swal-chip"><i class="feather-calendar"></i> ${_esc(code)}</span>`,
                confirmButtonText: 'Okay',
                timer:             1800,
                timerProgressBar:  true,
                buttonsStyling:    false,
                customClass:       ES_SWAL_TONE(done[2]),
            }).then(() => esRefresh());
        } else {
            _statusError(res.message || 'Could not update the appointment status.');
        }
    })
    .catch(() => _statusError('Network error. Please check your connection and try again.'));
}

function _statusError(message, title) {
    Swal.fire({
        icon:     'error',
        iconHtml: '<i class="feather-alert-circle es-swal-glyph"></i>',
        title:    title || 'Update Failed',
        html:     `<p class="es-swal-text">${_esc(message)}</p>`,
        confirmButtonText: 'Okay',
        buttonsStyling:    false,
        customClass:       ES_SWAL_TONE('warn'),
    });
}

function _esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ── Completion Modal ─────────────────────────────────────────
var _completionAppointmentId = null;
var _completionCode          = null;
var _completionBranchId      = null;

function openCompletionModal(appointmentId, code) {
    _completionAppointmentId = appointmentId;
    _completionCode          = code;

    // Reset modal state
    document.getElementById('cmpl_appointment_code').textContent = code;
    document.getElementById('cmpl_materials_body').innerHTML     =
        '<tr><td colspan="4" class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading materials...</td></tr>';
    document.getElementById('cmpl_error').style.display          = 'none';
    document.getElementById('cmpl_no_materials').style.display   = 'none';

    // Reset add-item row
    document.getElementById('cmpl_add_item').value    = '';
    document.getElementById('cmpl_add_qty').value     = '1';

    // Show modal
    new bootstrap.Modal(document.getElementById('completionModal')).show();

    // Fetch appointment details to get service_id and branch_id
    fetch(`api/appointments.php?id=${appointmentId}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message);
            const apt = res.data;
            _completionBranchId = apt.branch_id;

            // Load inventory items for add-item dropdown
            _loadInventoryDropdown(apt.branch_id);

            // Load pre-filled service materials — use first service for materials lookup
            const aptServices = apt.appointment_services || [];
            const primaryServiceId = aptServices.length > 0
                ? aptServices[0].service_id
                : (apt.service_id || null);

            if (!primaryServiceId) {
                _renderEmptyMaterials();
                return;
            }
            return fetch(`api/service_materials.php?service_id=${primaryServiceId}`)
                .then(r => r.json())
                .then(materials => _renderMaterials(materials));
        })
        .catch(err => {
            document.getElementById('cmpl_materials_body').innerHTML =
                `<tr><td colspan="4" class="text-center py-3 text-danger"><i class="feather-alert-circle me-2"></i>${err.message || 'Failed to load materials.'}</td></tr>`;
        });
}

function _loadInventoryDropdown(branchId) {
    fetch(`api/inventory.php?branch_id=${branchId}`)
        .then(r => r.json())
        .then(items => {
            const sel = document.getElementById('cmpl_add_item');
            sel.innerHTML = '<option value=""> Select Item </option>';
            (items || []).forEach(item => {
                const opt = document.createElement('option');
                opt.value              = item.item_id;
                opt.textContent        = `${item.item_name} (Stock: ${item.stock_quantity})`;
                opt.dataset.name       = item.item_name;
                opt.dataset.stock      = item.stock_quantity;
                sel.appendChild(opt);
            });
        });
}

function _renderMaterials(materials) {
    const tbody = document.getElementById('cmpl_materials_body');
    if (!materials || materials.length === 0) {
        _renderEmptyMaterials();
        return;
    }
    tbody.innerHTML = '';
    materials.forEach(mat => {
        const inv   = mat.inventory || {};
        const stock = parseInt(inv.stock_quantity ?? 0);
        tbody.innerHTML += `
        <tr id="mat_row_${mat.material_id}" data-item-id="${inv.item_id}" data-material-id="${mat.material_id}">
            <td>${inv.item_name || '—'}<div class="text-muted small">${inv.item_code || ''}</div></td>
            <td><span class="badge bg-soft-${stock > 0 ? 'success text-success' : 'danger text-danger'}">${stock} in stock</span></td>
            <td style="width:100px;">
                <input type="number" class="form-control form-control-sm mat-qty"
                    value="${mat.quantity}" min="1" max="${stock}" style="max-width:80px;">
            </td>
            <td class="text-end">
                <button class="btn btn-sm btn-light-brand text-danger" onclick="removeMaterialRow(this)" title="Remove">
                    <i class="feather-x"></i>
                </button>
            </td>
        </tr>`;
    });
}

function _renderEmptyMaterials() {
    document.getElementById('cmpl_materials_body').innerHTML = '';
    document.getElementById('cmpl_no_materials').style.display = '';
}

function removeMaterialRow(btn) {
    btn.closest('tr').remove();
    if (document.getElementById('cmpl_materials_body').children.length === 0) {
        document.getElementById('cmpl_no_materials').style.display = '';
    }
}

function addMaterialRow() {
    const sel      = document.getElementById('cmpl_add_item');
    const qtyInput = document.getElementById('cmpl_add_qty');
    const itemId   = sel.value;
    const qty      = parseInt(qtyInput.value) || 1;
    const itemName = sel.options[sel.selectedIndex]?.dataset.name || '';
    const stock    = parseInt(sel.options[sel.selectedIndex]?.dataset.stock ?? 0);

    if (!itemId) {
        Swal.fire({ icon: 'warning', title: 'Select an item', text: 'Please choose an inventory item to add.', timer: 1800, showConfirmButton: false });
        return;
    }

    if (document.querySelector(`#cmpl_materials_body tr[data-item-id="${itemId}"]`)) {
        Swal.fire({ icon: 'info', title: 'Already added', text: 'This item is already in the list. Adjust its quantity instead.', timer: 2000, showConfirmButton: false });
        return;
    }

    document.getElementById('cmpl_no_materials').style.display = 'none';

    const tbody = document.getElementById('cmpl_materials_body');
    const row   = document.createElement('tr');
    row.dataset.itemId = itemId;
    row.innerHTML = `
        <td>${itemName}<div class="text-muted small">Manual addition</div></td>
        <td><span class="badge bg-soft-${stock > 0 ? 'success text-success' : 'danger text-danger'}">${stock} in stock</span></td>
        <td style="width:100px;">
            <input type="number" class="form-control form-control-sm mat-qty"
                value="${qty}" min="1" max="${stock}" style="max-width:80px;">
        </td>
        <td class="text-end">
            <button class="btn btn-sm btn-light-brand text-danger" onclick="removeMaterialRow(this)" title="Remove">
                <i class="feather-x"></i>
            </button>
        </td>`;
    tbody.appendChild(row);

    sel.value      = '';
    qtyInput.value = '1';
}

// ── Confirm Completion ───────────────────────────────────────
function confirmCompletion() {
    const errBox = document.getElementById('cmpl_error');
    const btn    = document.getElementById('cmpl_confirm_btn');
    errBox.style.display = 'none';

    const rows       = document.querySelectorAll('#cmpl_materials_body tr[data-item-id]');
    const deductions = [];

    for (const row of rows) {
        const itemId = row.dataset.itemId;
        const qty    = parseInt(row.querySelector('.mat-qty')?.value) || 0;
        const stock  = parseInt(row.querySelector('.badge')?.textContent) || 0;

        if (qty < 1) {
            errBox.textContent   = 'All quantities must be at least 1.';
            errBox.style.display = 'block';
            return;
        }
        if (qty > stock) {
            const name = row.cells[0].textContent.trim();
            errBox.textContent   = `Insufficient stock for "${name}". Available: ${stock}, Requested: ${qty}.`;
            errBox.style.display = 'block';
            return;
        }
        deductions.push({ item_id: itemId, quantity: qty });
    }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

    fetch(`api/appointments.php?id=${_completionAppointmentId}`, {
        method:  'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ status: 'completed' }),
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message);

        const tasks = deductions.map(d =>
            fetch(`api/inventory.php?id=${d.item_id}`, {
                method:  'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ deduct: d.quantity }),
            })
            .then(r => r.json())
            .then(invRes => {
                if (!invRes.success) throw new Error(invRes.message);
                return fetch('api/inventory_movements.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        item_id:         d.item_id,
                        branch_id:       _completionBranchId,
                        quantity_change: -d.quantity,
                        reason:          `Used in appointment ${_completionCode}`,
                        performed_by:    'System',
                        movement_date:   new Date().toISOString().split('T')[0],
                    }),
                });
            })
        );

        return Promise.all(tasks);
    })
    .then(() => {
        bootstrap.Modal.getInstance(document.getElementById('completionModal'))?.hide();
        Swal.fire({
            icon:  'success',
            title: 'Appointment Completed!',
            html:  deductions.length > 0
                ? `<strong>${_completionCode}</strong> marked as completed.<br><span class="text-muted small">${deductions.length} inventory item(s) deducted.</span>`
                : `<strong>${_completionCode}</strong> marked as completed.`,
            timer: 2200,
            showConfirmButton: false,
        }).then(() => esRefresh());
    })
    .catch(err => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-check-circle me-1"></i> Confirm & Complete';
        errBox.textContent   = err.message || 'An error occurred. Please try again.';
        errBox.style.display = 'block';
    });
}


// ── Add Appointment Modal — Mini Calendar ────────────────────
// ══════════════════════════════════════════════════════════════
//  ADD / EDIT APPOINTMENT — date + time from the shared availability
//  Same source of truth as the Patient Portal booking page:
//    api/get_booked_days.php  fully booked days (for the visit length)
//    api/get_timeslots.php    every start time of the day, available or not
//  Both apply controllers/branch_config.php: branch hours and open days,
//  visit length = the chosen services, no overlap with active bookings.
//  Edit passes &exclude=<id> so the appointment doesn't block its own time.
//  Branch open days come from window.APT_BOOKING_BRANCHES (same rules, PHP).
// ══════════════════════════════════════════════════════════════
(function () {
    'use strict';

    let _adminCal = null;
    let _branchId = '';
    let _calEl = null;
    let _slotReq = 0;

    const $id = (id) => document.getElementById(id);
    const _editId = () => ($id('apt_id')?.value || '').trim();
    const _svcIds = () => (typeof _aptServices !== 'undefined' ? _aptServices.map(s => s.id) : []);
    const _rules = (b) => (window.APT_BOOKING_BRANCHES || {})[String(b)] || null;
    const _qs = (extra) => {
        const p = new URLSearchParams(extra);
        const svcs = _svcIds();
        if (svcs.length) p.set('services', svcs.join(','));
        if (_editId()) p.set('exclude', _editId());
        return p.toString();
    };
    const _iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    const _tomorrow = () => { const t = new Date(); t.setDate(t.getDate() + 1); return _iso(t); };

    // ── Destroy calendar when modal closes ───────────────────
    const _modal = $id('appointmentModal');
    if (_modal) {
        _modal.addEventListener('hidden.bs.modal', _destroyAdminCal);
        // Edit loads the appointment so fast that the calendar can be built while
        // the modal is still fading in (no width yet) → size it once it's shown
        _modal.addEventListener('shown.bs.modal', () => { if (_adminCal) _adminCal.updateSize(); });
    }

    // ── Branch change → render calendar + load dentists ──────
    const _branchSel = $id('apt_branch');
    if (_branchSel) {
        _branchSel.addEventListener('change', function () {
            const branchId = this.value;
            _resetDateTime();
            if (!branchId) {
                _destroyAdminCal();
                _showPlaceholder();
                const sel = $id('apt_dentist');
                sel.innerHTML = '<option value=""> Select a branch first </option>';
                sel.disabled  = true;
                return;
            }
            _loadDentistsByBranch(branchId);
            _initAdminCal(branchId);
        });
    }
    $id('aptSlotsRefresh')?.addEventListener('click', () => window._aptRefreshSlots());

    function _isOpenDay(dateStr) {
        const r = _rules(_branchId);
        if (!r) return true;   // unknown → let the server decide
        if (!r.active) return false;
        // Date override (Branch Records → Schedule Exceptions) beats the weekly days
        const ex = (r.exceptions || {})[dateStr];
        if (ex) return !!ex.open;
        return r.days.includes(new Date(dateStr + 'T00:00:00').getDay());
    }

    function _initAdminCal(branchId) {
        const wrap = $id('adminCalendarWrap');
        if (!wrap) return;
        _destroyAdminCal();
        _branchId = String(branchId);

        wrap.className     = 'rounded-3 border apt-cal';
        wrap.style.cssText = 'padding: 0.35rem 0.25rem;';
        wrap.innerHTML     = '';
        const calEl = document.createElement('div');
        wrap.appendChild(calEl);
        _calEl = calEl;

        // Bookable from tomorrow (portal rule). An edit may keep an earlier saved day.
        const preset = window._aptEditPreset;
        let start = _tomorrow();
        if (preset && preset.date && preset.date < start) start = preset.date;

        _adminCal = new FullCalendar.Calendar(calEl, {
            initialView:         'dayGridMonth',
            headerToolbar:       { left: 'prev', center: 'title', right: 'next' },
            fixedWeekCount:      false,
            showNonCurrentDates: false,
            height:              'auto',
            validRange:          { start },
            dayCellClassNames(arg) {
                const d = _iso(arg.date);
                const isPreset = preset && preset.date === d;
                return !_isOpenDay(d) && !isPreset ? ['fc-day-closed'] : [];
            },
            dayCellDidMount(arg) {
                const d = _iso(arg.date);
                if (arg.el.classList.contains('fc-day-closed')) {
                    const ex = ((_rules(_branchId) || {}).exceptions || {})[d];
                    arg.el.setAttribute('title', 'Branch closed' + (ex && ex.reason ? ' — ' + ex.reason : ''));
                }
                if (preset && preset.date === d && d < _tomorrow()) arg.el.setAttribute('title', 'Saved date of this appointment');
            },
            datesSet(info) {
                _markFullyBooked(info.startStr.slice(0, 10), info.endStr.slice(0, 10));
                _markPresetDay();
            },
            dateClick(info) {
                const isPreset = preset && preset.date === info.dateStr;
                if (!isPreset && (info.dayEl.classList.contains('fc-day-fully-booked') || info.dayEl.classList.contains('fc-day-closed'))) return;

                calEl.querySelectorAll('.fc-day-selected').forEach(el => el.classList.remove('fc-day-selected'));
                info.dayEl.classList.add('fc-day-selected');

                $id('apt_date').value = info.dateStr;
                document.querySelector('#appointmentModal [data-err-for="apt_date"]')?.replaceChildren();
                $id('apt_time').value = '';
                $id('aptDateTimeSummary').style.display = 'none';

                const keepTime = isPreset ? preset.time : null;
                if (keepTime) {   // back on the saved day → restore the saved time
                    $id('apt_time').value = keepTime;
                    _showSummary(info.dateStr, keepTime);
                }
                _loadTimeSlots(info.dateStr, keepTime);
            }
        });
        _adminCal.render();

        // Edit mode: show the appointment's saved date + time as selected.
        if (preset && preset.date) {
            $id('apt_date').value = preset.date;
            $id('apt_time').value = preset.time;
            _adminCal.gotoDate(preset.date);
            _markPresetDay();
            _showSummary(preset.date, preset.time);
            _loadTimeSlots(preset.date, preset.time);
        }
    }

    // Highlight the edit preset day (only while it is still the chosen date).
    function _markPresetDay() {
        const preset = window._aptEditPreset;
        if (!_calEl || !preset || $id('apt_date').value !== preset.date) return;
        const dayEl = _calEl.querySelector(`[data-date="${preset.date}"]`);
        if (dayEl) dayEl.classList.add('fc-day-selected');
    }

    function _markFullyBooked(startStr, endStr) {
        if (!_calEl || !_branchId) return;
        const calEl = _calEl;
        fetch('api/get_booked_days.php?' + _qs({ start: startStr, end: endStr, branch: _branchId }), { cache: 'no-store' })
            .then(r => r.json())
            .then(data => {
                calEl.querySelectorAll('.fc-day-fully-booked').forEach(el => { el.classList.remove('fc-day-fully-booked'); el.removeAttribute('title'); });
                (data.fully_booked || []).forEach(dateStr => {
                    const dayEl = calEl.querySelector(`[data-date="${dateStr}"]`);
                    if (dayEl) { dayEl.classList.add('fc-day-fully-booked'); dayEl.setAttribute('title', 'Fully booked'); }
                });
            })
            .catch(() => {});
    }

    // quiet: background re-check (no spinner — the current list stays until the answer arrives)
    function _loadTimeSlots(dateStr, keepTime, quiet) {
        const grid = $id('adminTimeSlotsGrid');
        const req  = ++_slotReq;
        $id('aptSlotsRefresh').hidden = false;
        if (!quiet) grid.innerHTML = `
            <div class="slot-loading">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                Loading available times…
            </div>`;

        fetch('api/get_timeslots.php?' + _qs({ date: dateStr, branch: _branchId }), { cache: 'no-store' })
            .then(r => r.json())
            .then(data => {
                if (req !== _slotReq) return;   // a newer request is on its way
                if (data.error) throw new Error(data.error);
                _renderSlots(dateStr, data, keepTime);
            })
            .catch((e) => {
                if (req !== _slotReq || quiet) return;   // a failed background check keeps the list shown
                grid.innerHTML = `
                    <div class="slot-empty text-danger">
                        <i class="feather-alert-circle me-1"></i>${_esc(e && e.message ? e.message : 'Failed to load time slots.')} Please try again.
                    </div>`;
            });
    }

    const _UNAVAILABLE = {
        past:     'Appointments can be booked from tomorrow onward.',
        closed:   'The branch is closed on this day.',
        inactive: 'This branch is not taking appointments right now.',
        invalid:  'Please choose a valid date.',
    };

    function _renderSlots(dateStr, data, keepTime) {
        const grid  = $id('adminTimeSlotsGrid');
        const slots = Array.isArray(data.slots) ? data.slots : [];
        const timeEl = $id('apt_time');
        grid.innerHTML = '';

        if (data.unavailable) {
            const preset = window._aptEditPreset;
            const keeps = keepTime && preset && preset.date === dateStr;
            grid.innerHTML = `<div class="slot-empty"><i class="feather-info me-1"></i>${_UNAVAILABLE[data.unavailable] || 'No times on this day.'}` +
                (keeps ? `<br><span class="apt-slot-keep">This appointment keeps its saved time (${_fmtTime(keepTime)}). Pick another date to reschedule it.</span>` : '') +
                `</div>`;
            if (!keeps) { timeEl.value = ''; $id('aptDateTimeSummary').style.display = 'none'; }
            return;
        }

        const legend = document.createElement('div');
        legend.className = 'slot-legend';
        legend.innerHTML = `
            <span><span class="legend-dot" style="background:#e8f0fe;border:1.5px solid rgba(13,110,253,0.3);"></span>Available</span>
            <span><span class="legend-dot" style="background:#f1f4f8;border:1px solid #dee2e6;"></span>Booked / doesn't fit</span>
            <span class="slot-visit">Visit: ${_esc(data.duration_label || '')}</span>`;
        grid.appendChild(legend);

        const btnGrid = document.createElement('div');
        btnGrid.className = 'slot-grid';
        let available = 0, keptOk = false;

        slots.forEach(s => {
            const ok  = !!s.available;
            if (ok) available++;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `slot-btn ${ok ? 'slot-btn-available' : 'slot-btn-booked'}`;
            btn.textContent = s.label || _fmtTime(s.time);
            btn.disabled = !ok;
            btn.dataset.time = s.time;
            btn.title = ok ? `${s.label} – ${s.ends}` : (s.reason === 'closing' ? 'Would end after closing time' : 'Already booked');
            if (ok && keepTime && s.time === keepTime) {
                btn.classList.add('selected');
                btn.title = 'Current time of this appointment';
                keptOk = true;
            }
            if (ok) {
                btn.addEventListener('click', () => {
                    btnGrid.querySelectorAll('.slot-btn-available.selected').forEach(b => b.classList.remove('selected'));
                    btn.classList.add('selected');
                    timeEl.value = s.time;
                    document.querySelector('#appointmentModal [data-err-for="apt_time"]')?.replaceChildren();
                    _showSummary(dateStr, s.time);
                });
            }
            btnGrid.appendChild(btn);
        });
        grid.appendChild(btnGrid);

        // The chosen time no longer fits (taken meanwhile, or a longer visit now)
        if (timeEl.value && !keptOk && !slots.some(s => s.available && s.time === timeEl.value)) {
            const was = timeEl.value;
            timeEl.value = '';
            $id('aptDateTimeSummary').style.display = 'none';
            const note = document.createElement('p');
            note.className = 'apt-slot-warn';
            note.innerHTML = `<i class="feather-alert-triangle me-1"></i>${_fmtTime(was)} is no longer available for this visit. Please pick another time.`;
            grid.insertBefore(note, btnGrid);
        }

        const info = document.createElement('p');
        info.className   = 'slot-count';
        info.textContent = `${available} of ${slots.length} start times available`;
        grid.appendChild(info);
    }

    // Services changed (visit length), dentist changed, Refresh pressed, or the
    // periodic re-check → ask the server again (same API as the Patient Portal)
    window._aptRefreshSlots = function (quiet) {
        if (!_adminCal || !_branchId) return;
        const v = _adminCal.view;
        _markFullyBooked(_iso(v.activeStart), _iso(v.activeEnd));
        const date = $id('apt_date').value;
        if (date) _loadTimeSlots(date, $id('apt_time').value || null, quiet === true);
    };

    // The assigned dentist changed → availability is checked again. (Availability
    // is per branch, exactly as in the Patient Portal; there is no per-dentist
    // schedule, so the same rules apply whichever dentist is chosen.)
    $id('apt_dentist')?.addEventListener('change', () => window._aptRefreshSlots(true));

    // While the form is open, re-check every 30 s so bookings made meanwhile
    // (Patient Portal or another staff member) show up; a time that was taken
    // is unselected with a note (see _renderSlots). Paused while the tab is hidden.
    let _pollTimer = null;
    if (_modal) {
        _modal.addEventListener('shown.bs.modal', () => {
            clearInterval(_pollTimer);
            _pollTimer = setInterval(() => { if (!document.hidden) window._aptRefreshSlots(true); }, 30000);
        });
        _modal.addEventListener('hidden.bs.modal', () => { clearInterval(_pollTimer); _pollTimer = null; });
    }
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && _pollTimer) window._aptRefreshSlots(true);
    });

    function _showSummary(dateStr, time) {
        const display = new Date(dateStr + 'T00:00:00')
            .toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        $id('aptDateTimeText').textContent = `${display} at ${_fmtTime(time)}`;
        $id('aptDateTimeSummary').style.display = 'flex';
    }

    function _fmtTime(t) {
        return new Date(`1970-01-01T${String(t).slice(0, 5)}:00`)
            .toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    }

    function _slotsPlaceholder() {
        $id('adminTimeSlotsGrid').innerHTML =
            '<div class="slot-empty apt-slots-placeholder"><i class="feather-clock"></i><br>Choose a date to see the available times.</div>';
        $id('aptSlotsRefresh').hidden = true;
    }
    window._aptSlotsPlaceholder = _slotsPlaceholder;

    function _resetDateTime() {
        $id('apt_date').value = '';
        $id('apt_time').value = '';
        $id('aptDateTimeSummary').style.display = 'none';
        _slotsPlaceholder();
    }

    function _showPlaceholder() {
        const wrap = $id('adminCalendarWrap');
        if (!wrap) return;
        wrap.className     = 'rounded-3 border';
        wrap.style.cssText = '';
        wrap.innerHTML     = `
            <div class="slot-empty">
                <svg width="30" height="30" viewBox="0 0 32 32" fill="none" style="margin-bottom:6px;opacity:0.3;">
                    <rect x="3" y="5" width="26" height="22" rx="3" stroke="#6b7a8d" stroke-width="1.5"/>
                    <path d="M9 3v4M23 3v4M3 12h26" stroke="#6b7a8d" stroke-width="1.5" stroke-linecap="round"/>
                </svg><br>
                Select a branch to load available dates.
            </div>`;
    }

    function _destroyAdminCal() {
        if (_adminCal) {
            try { _adminCal.destroy(); } catch (_) {}
            _adminCal = null;
        }
        _calEl = null;
    }
})();

// ── Filter dentists by selected branch ───────────────────────
function _loadDentistsByBranch(branchId) {
    const sel = document.getElementById('apt_dentist');
    sel.innerHTML = '<option value="">Loading…</option>';
    sel.disabled  = true;

    fetch(`api/dentists.php?branch_id=${branchId}`)
        .then(r => r.json())
        .then(res => {
            const list = Array.isArray(res) ? res : (res.data || []);
            sel.innerHTML = '<option value=""> Select Dentist </option>';

            if (!list.length) {
                sel.innerHTML = '<option value="">No dentists in this branch</option>';
                return;
            }

            list.forEach(dt => {
                const opt       = document.createElement('option');
                opt.value       = dt.dentist_id;
                opt.textContent = dt.full_name;
                sel.appendChild(opt);
            });
        })
        .catch(() => {
            sel.innerHTML = '<option value="">Failed to load dentists</option>';
        })
        .finally(() => {
            sel.disabled = false;
        });
}


// ══════════════════════════════════════════════════════════════
//  ADMIN MULTI-SERVICE PICKER
//  Mirrors the portal booking modal's service picker.
//  State is module-level so saveAppointment() can read it.
// ══════════════════════════════════════════════════════════════

const APT_MAX_SERVICES = 3;
let _aptServices     = [];   // [{id, name, price}] — currently selected
let _aptAllServices  = [];   // full service catalogue (loaded once)
let _aptPickerSelId  = null; // highlighted item in dropdown (not yet added)

// Load service catalogue once on page load
(function _loadAptServices() {
    fetch('api/services.php?status=active&limit=500')
        .then(r => r.json())
        .then(res => {
            const list = Array.isArray(res) ? res : (res.data || []);
            _aptAllServices = list;
            _aptBuildDropdownList(_aptAllServices);
        })
        .catch(() => console.warn('Could not load services for appointment modal.'));
})();

function _aptBuildDropdownList(services) {
    const ul = document.getElementById('apt_svc_list');
    if (!ul) return;
    ul.innerHTML = '';
    if (!services.length) {
        ul.innerHTML = `<li class="cs-disabled" style="cursor:default;padding:.45rem .75rem;font-size:.8rem;color:#9ca3af;">No services available</li>`;
        return;
    }
    services.forEach(s => {
        const id          = parseInt(s.service_id);
        const alreadyAdded = _aptServices.find(sel => sel.id === id);
        const li          = document.createElement('li');
        li.dataset.id     = id;
        if (alreadyAdded) {
            li.className = 'cs-disabled';
        } else {
            li.addEventListener('click', () => _aptSelectFromList(id, s.service_name, s.base_price ? parseFloat(s.base_price) : null, li));
        }
        li.innerHTML = `
            <span>${s.service_name}</span>
            ${s.base_price ? `<span class="cs-item-price">₱${parseFloat(s.base_price).toLocaleString()}</span>` : ''}`;
        ul.appendChild(li);
    });
}

function _aptSelectFromList(id, name, price, li) {
    document.querySelectorAll('#apt_svc_list li').forEach(l => l.classList.remove('active'));
    li.classList.add('active');
    _aptPickerSelId = id;
    const valueEl = document.getElementById('apt_svc_value');
    valueEl.textContent = price
        ? `${name} — ₱${price.toLocaleString()}`
        : name;
    valueEl.classList.add('cs-selected');
    document.getElementById('apt_svc_select').classList.remove('open');
}

window.toggleAptSvcSelect = function () {
    const wrap   = document.getElementById('apt_svc_select');
    const isOpen = wrap.classList.contains('open');
    wrap.classList.toggle('open', !isOpen);
    if (!isOpen) {
        const search = document.getElementById('apt_svc_search');
        search.value = '';
        filterAptSvc('');
        _aptPlaceSvcDropdown();
        search.focus({ preventScroll: true });
    }
};

// Fixed to the viewport (below the field, or above when there is no room)
// so the modal's scroll area can't clip the list.
function _aptPlaceSvcDropdown() {
    const wrap = document.getElementById('apt_svc_select');
    if (!wrap || !wrap.classList.contains('open')) return;
    const dd = document.getElementById('apt_svc_dropdown');
    const r  = wrap.querySelector('.modal-cs-trigger').getBoundingClientRect();
    dd.style.position = 'fixed';
    dd.style.left  = Math.round(r.left) + 'px';
    dd.style.width = Math.round(r.width) + 'px';
    dd.style.right = 'auto';
    // --cs-gap (staff dropdown design): the list floats as a separate card below the field
    const gap = parseFloat(getComputedStyle(wrap).getPropertyValue('--cs-gap')) || 0;
    const h = dd.offsetHeight, below = window.innerHeight - r.bottom - gap - 8, above = r.top - gap - 8;
    const up = h > below && above > below;
    dd.style.top = Math.round(up ? r.top - h - gap : r.bottom + gap) + 'px';
    wrap.classList.toggle('apt-cs-above', up);
}
window.addEventListener('resize', _aptPlaceSvcDropdown);
window.addEventListener('scroll', e => {
    const dd = document.getElementById('apt_svc_dropdown');
    if (dd && e.target !== dd && !dd.contains(e.target)) _aptPlaceSvcDropdown();
}, true);

window.filterAptSvc = function (q) {
    const lower = q.toLowerCase();
    document.querySelectorAll('#apt_svc_list li').forEach(li => {
        li.style.display = li.textContent.toLowerCase().includes(lower) ? '' : 'none';
    });
};

// Close dropdown on outside click
document.addEventListener('click', e => {
    if (!e.target.closest('#apt_svc_select')) {
        document.getElementById('apt_svc_select')?.classList.remove('open');
    }
});

// Visit length follows the services → the open times are re-checked
function _aptServicesChanged() {
    if (typeof window._aptRefreshSlots === 'function') window._aptRefreshSlots();
}

window.aptAddService = function () {
    if (_aptPickerSelId === null) return;
    const id = _aptPickerSelId;

    if (_aptServices.find(s => s.id === id)) {
        _aptResetPicker();
        return;
    }
    if (_aptServices.length >= APT_MAX_SERVICES) return;

    const svc = _aptAllServices.find(s => parseInt(s.service_id) === id);
    if (!svc) return;

    _aptServices.push({
        id:    id,
        name:  svc.service_name,
        price: svc.base_price ? parseFloat(svc.base_price) : null,
    });

    _aptResetPicker();
    _aptRenderChips();
    _aptServicesChanged();
};

window.aptRemoveService = function (id) {
    _aptServices = _aptServices.filter(s => s.id !== id);
    _aptRenderChips();
    _aptServicesChanged();
};

function _aptResetPicker() {
    _aptPickerSelId = null;
    const valueEl = document.getElementById('apt_svc_value');
    if (valueEl) { valueEl.textContent = ' Add a service '; valueEl.classList.remove('cs-selected'); }
    document.querySelectorAll('#apt_svc_list li').forEach(l => l.classList.remove('active'));
    const search = document.getElementById('apt_svc_search');
    if (search) { search.value = ''; filterAptSvc(''); }
}

function _aptRenderChips() {
    const wrap   = document.getElementById('apt_svc_chips_wrap');
    const chips  = document.getElementById('apt_svc_chips');
    const capMsg = document.getElementById('apt_svc_cap_msg');
    const addBtn = document.getElementById('apt_svc_add_btn');

    if (!wrap) return;

    chips.innerHTML = '';

    if (_aptServices.length === 0) {
        wrap.style.display = 'none';
    } else {
        wrap.style.display = 'block';
        _aptServices.forEach((svc, idx) => {
            const chip = document.createElement('span');
            chip.className = 'svc-chip';
            chip.innerHTML = `
                <span class="svc-chip-num">${idx + 1}</span>
                <span class="svc-chip-name">${svc.name}</span>
                ${svc.price ? `<span class="svc-chip-price">₱${svc.price.toLocaleString()}</span>` : ''}
                <button type="button" class="svc-chip-remove" onclick="aptRemoveService(${svc.id})" title="Remove">×</button>`;
            chips.appendChild(chip);
        });
    }

    const atCap = _aptServices.length >= APT_MAX_SERVICES;
    if (capMsg) capMsg.style.display = atCap ? 'block' : 'none';
    if (addBtn) addBtn.disabled = atCap;

    // Rebuild dropdown list to grey out already-added items
    _aptBuildDropdownList(_aptAllServices);
}

// ── Reset service picker state ────────────────────────────────
function _aptResetServicePicker() {
    _aptServices    = [];
    _aptPickerSelId = null;
    _aptRenderChips();
    _aptResetPicker();
    const capMsg = document.getElementById('apt_svc_cap_msg');
    if (capMsg) capMsg.style.display = 'none';
    const addBtn = document.getElementById('apt_svc_add_btn');
    if (addBtn) addBtn.disabled = false;
}

// ── Pre-populate picker from existing appointment_services ────
function _aptLoadExistingServices(aptServices) {
    _aptResetServicePicker();
    if (!Array.isArray(aptServices)) return;
    aptServices.forEach(row => {
        const svc = row.services || {};
        const id  = parseInt(svc.service_id || row.service_id);
        if (!id) return;
        _aptServices.push({
            id:    id,
            name:  svc.service_name || '—',
            price: svc.base_price ? parseFloat(svc.base_price) : null,
        });
    });
    _aptRenderChips();
}


// ── Add / Edit Appointment ───────────────────────────────────
// ── Bootstrap 5.0 keeps focus inside an open modal; let a SweetAlert shown on
//    top of it (password prompt, save confirmation) take the keyboard.
document.addEventListener('focusin', function (e) {
    if (e.target && e.target.closest && e.target.closest('.swal2-container')) e.stopImmediatePropagation();
}, true);

// ══════════════════════════════════════════════════════════════
//  EDIT APPOINTMENT — the signed-in owner / admin re-enters THEIR password
//  api/verify_staff_password.php checks it on the server (bcrypt, like the
//  sign-in) and allows editing that one appointment for 15 minutes; the
//  save (PUT) is refused without it. Primary action left, Cancel right.
//  Resolves true only after a successful check.
// ══════════════════════════════════════════════════════════════
async function verifyAdminPassword(appointmentId) {
    const r = await Swal.fire({
        icon: 'question',
        iconHtml: '<i class="feather-lock es-swal-glyph"></i>',
        title: 'Confirm Your Password',
        html: '<p class="es-swal-text">For security, enter <strong>your own account password</strong> to edit this appointment.</p>',
        input: 'password',
        inputPlaceholder: 'Your password',
        inputAttributes: { autocomplete: 'current-password', 'aria-label': 'Your account password', autocapitalize: 'off', autocorrect: 'off', maxlength: '200' },
        showCancelButton: true,
        confirmButtonText: '<i class="feather-unlock"></i> Verify &amp; Continue',
        cancelButtonText: 'Cancel',
        reverseButtons: false,          // primary left, Cancel right
        focusConfirm: false,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        buttonsStyling: false,
        // Same look as the other admin alerts: compact, equal-width buttons and
        // a standard-height field (assets/css/es-swal.css → .es-swal-input)
        customClass: Object.assign({}, ES_SWAL_CLASS, {
            popup: 'es-swal-popup es-swal-popup-pw',
            input: 'es-swal-input',
            validationMessage: 'es-swal-validation',
            actions: 'es-swal-actions es-swal-actions-compact',
        }),
        preConfirm: async (pw) => {
            if (!pw) { Swal.showValidationMessage('Please enter your password.'); return false; }
            let res, j;
            try {
                res = await fetch('api/verify_staff_password.php', {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: pw, appointment_id: parseInt(appointmentId, 10) }),
                });
                j = await res.json().catch(() => ({}));
            } catch (e) {
                Swal.showValidationMessage("We couldn't reach the server. Please check your connection and try again.");
                return false;
            }
            if (res.status === 401) { location.href = 'login.php'; return false; }
            if (!j.success) {
                const inp = Swal.getInput(); if (inp) { inp.value = ''; inp.focus(); }
                Swal.showValidationMessage(j.message || 'Your password could not be verified.');
                return false;
            }
            return true;
        },
    });
    return r.isConfirmed && r.value === true;
}
window.verifyAdminPassword = verifyAdminPassword;

// Summary for the "Save changes?" confirmation
function _aptEditSummary(date, time) {
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const sel = (id) => {
        const el = document.getElementById(id);
        if (el && el.tagName !== 'SELECT') {   // fixed branch (hidden field): its name is in the header chip
            return (document.querySelector('#appointmentModal .apt-branch-chip')?.textContent || '').trim();
        }
        return el && el.selectedIndex >= 0 ? el.options[el.selectedIndex].text.trim() : '';
    };
    const when = new Date(date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }) +
        ' at ' + new Date('1970-01-01T' + time.slice(0, 5) + ':00').toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    const svcs = (typeof _aptServices !== 'undefined' && _aptServices.length) ? _aptServices.map(s => s.name).join(', ') : 'None selected';
    const row = (k, v) => `<div class="apt-confirm-row"><span>${k}</span><strong>${esc(v)}</strong></div>`;
    return '<p class="es-swal-text">You are about to update this appointment:</p><div class="apt-confirm-sum">' +
        row('Patient', sel('apt_patient')) + row('Branch', sel('apt_branch')) + row('Schedule', when) + row('Services', svcs) + '</div>';
}

async function saveAppointment() {
    const errBox  = document.getElementById('modalError');
    const btn     = document.getElementById('modalSaveBtn');
    errBox.style.display = 'none';
    _aptClearFieldErrors();

    const id      = document.getElementById('apt_id').value.trim();
    const branch  = document.getElementById('apt_branch').value;
    const patient = document.getElementById('apt_patient').value;
    const dentist = document.getElementById('apt_dentist').value;
    const date    = document.getElementById('apt_date').value;
    const time    = document.getElementById('apt_time').value;
    const notes   = document.getElementById('apt_notes').value.trim();
    // Branch-assigned account: the server uses the account's own branch
    const fixedBranch = !!document.querySelector('#apt_branch[data-fixed-branch]');

    if (!branch)  return _showModalError('Please select a branch.', 'apt_branch');
    if (!patient) return _showModalError('Please select a patient.', 'apt_patient');
    if (!date)    return _showModalError('Please select a date.', 'apt_date');
    if (!time)    return _showModalError('Please select a time slot.', 'apt_time');

    // Edit: confirm first (primary left, Cancel right). Cancel → back to the
    // form with every change still in place.
    if (id) {
        const c = await Swal.fire({
            icon: 'question',
            iconHtml: '<i class="feather-save"></i>',
            title: 'Save Changes?',
            html: _aptEditSummary(date, time),
            showCancelButton: true,
            confirmButtonText: '<i class="feather-save me-1"></i> Save Changes',
            cancelButtonText: 'Keep Editing',
            reverseButtons: false,
            focusCancel: true,
            buttonsStyling: false,
            customClass: ES_SWAL_CLASS,
        });
        if (!c.isConfirmed) return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    const payload = {
        patient_id:       parseInt(patient),
        service_ids:      _aptServices.map(s => s.id),  // ← multi-service array
        appointment_date: date,
        appointment_time: time,
    };
    if (!fixedBranch) payload.branch_id = parseInt(branch);   // owner only
    // Notes / dentist are only set when creating; an edit keeps the stored
    // values (the dentist is not part of the edit form). Status is always
    // Confirmed for a new appointment and payment is recorded in Billing —
    // both are decided by the server, never sent from here.
    if (!id) Object.assign(payload, { dentist_id: dentist ? parseInt(dentist) : null, notes: notes || null });

    try {
        const url    = id ? `api/appointments.php?id=${id}` : 'api/appointments.php';
        const method = id ? 'PUT' : 'POST';

        const send = () => fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        }).then(async r => ({ res: r, json: await r.json().catch(() => ({})) }));
        let { res, json } = await send();

        // Password permission expired (15 min) → ask again, then send once more
        if (id && json.code === 'REVERIFY') {
            if (!(await verifyAdminPassword(id))) throw new Error('Changes not saved: your password was not confirmed.');
            ({ res, json } = await send());
        }
        if (!res.ok || !json.success) {
            // A time that was just taken: refresh the open times right away
            if (json.code && json.code.startsWith('SLOT_') && typeof window._aptRefreshSlots === 'function') window._aptRefreshSlots();
            const e = new Error(json.message || 'Could not save appointment.');
            e.field = json.field;
            throw e;
        }

        bootstrap.Modal.getInstance(document.getElementById('appointmentModal'))?.hide();

        Swal.fire({
            icon:  'success',
            title: id ? 'Appointment Updated!' : 'Appointment Added!',
            text:  json.message || 'The appointment has been saved successfully.',
            timer: 1800,
            showConfirmButton: false,
        }).then(() => esRefresh());

    } catch (err) {
        _showModalError(err.message, err.field);
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-save me-1"></i> ' + (id ? 'Save Changes' : 'Save Appointment');
    }
}

// fieldId (optional): also shows the message under that field and scrolls to it
function _showModalError(msg, fieldId) {
    const box = document.getElementById('modalError');
    box.textContent   = msg;
    box.style.display = 'block';
    const slot = fieldId && document.querySelector(`#appointmentModal [data-err-for="${fieldId}"]`);
    if (slot) {
        slot.textContent = msg;
        const field = document.getElementById(fieldId);
        if (field && field.type !== 'hidden') field.classList.add('is-invalid');
        slot.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function _aptClearFieldErrors() {
    document.querySelectorAll('#appointmentModal [data-err-for]').forEach(el => (el.textContent = ''));
    document.querySelectorAll('#appointmentModal .is-invalid').forEach(el => el.classList.remove('is-invalid'));
}
window._aptClearFieldErrors = _aptClearFieldErrors;

// A field's error clears as soon as it is changed
document.addEventListener('change', e => {
    if (!e.target.closest || !e.target.closest('#appointmentModal')) return;
    e.target.classList.remove('is-invalid');
    const slot = document.querySelector(`#appointmentModal [data-err-for="${e.target.id}"]`);
    if (slot) slot.textContent = '';
});

// ══════════════════════════════════════════════════════════════
//  CHECK-IN PATIENT  (enhanced: reads medical profile + vitals)
// ══════════════════════════════════════════════════════════════

const CI_HIGH_RISK = [
    'hypertension',
    'diabetes (type 1)',
    'diabetes (type 2)',
    'heart disease',
    'asthma',
];

function openCheckInModal(appointmentId, patientId, aptCode, patientName) {
    document.getElementById('ci_apt_code').textContent     = aptCode;
    document.getElementById('ci_patient_name').textContent = patientName || '';
    document.getElementById('ci_appointment_id').value     = appointmentId;
    document.getElementById('ci_patient_id').value         = patientId;
    document.getElementById('ci_error').style.display      = 'none';

    document.getElementById('ci_risk_banner').style.display    = 'none';
    document.getElementById('ci_medical_summary').style.display = 'none';
    document.getElementById('ci_medical_loading').style.display = 'block';
    document.getElementById('ci_meds_row').style.display        = 'none';

    ['ci_blood_pressure', 'ci_pulse_rate', 'ci_temperature', 'ci_notes']
        .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });

    const checkedByEl = document.getElementById('ci_checked_by');
    if (checkedByEl) checkedByEl.classList.remove('is-invalid');
    if (checkedByEl) checkedByEl.value = (typeof CURRENT_USER_NAME !== 'undefined' && CURRENT_USER_NAME) ? CURRENT_USER_NAME : '';

    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('ci_checked_at').value = now.toISOString().slice(0, 16);

    const btn = document.getElementById('ci_submit_btn');
    btn.disabled  = false;
    btn.innerHTML = '<i class="feather-user-check me-1"></i> Confirm Check-In';

    new bootstrap.Modal(document.getElementById('checkInModal')).show();

    fetch('api/checkin_appointment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_patient_medical', patient_id: patientId }),
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('ci_medical_loading').style.display = 'none';
        _renderCheckInRisk(data.success ? data.profile : null);
    })
    .catch(() => {
        document.getElementById('ci_medical_loading').style.display = 'none';
        _renderCheckInRisk(null);
    });
}

function _renderCheckInRisk(profile) {
    const banner  = document.getElementById('ci_risk_banner');
    const summary = document.getElementById('ci_medical_summary');
    const condEl  = document.getElementById('ci_conditions');
    const algEl   = document.getElementById('ci_allergies');
    const medsRow = document.getElementById('ci_meds_row');
    const medsEl  = document.getElementById('ci_meds');

    if (!profile) {
        banner.className = 'ci-risk ci-risk--neutral';
        document.getElementById('ci_risk_icon').className = 'feather-info ci-risk-icon';
        document.getElementById('ci_risk_title').textContent = 'No medical profile found.';
        document.getElementById('ci_risk_detail').textContent = 'The patient has not completed their medical history yet.';
        banner.style.removeProperty('display');
        return;
    }

    const parseArr = v => {
        if (Array.isArray(v)) return v;
        try { return JSON.parse(v || '[]'); } catch { return []; }
    };

    const conditions = parseArr(profile.conditions);
    const otherCond  = (profile.other_conditions || '').trim();
    if (otherCond) conditions.push(otherCond);

    const allergies  = parseArr(profile.allergies);
    const otherAlg   = (profile.other_allergies || '').trim();
    if (otherAlg) allergies.push(otherAlg);

    const takingMeds = profile.q_taking_meds === true || profile.q_taking_meds === 'yes';
    const medsDetail = (profile.meds_detail || '').trim();

    const triggeredConditions = conditions.filter(c => CI_HIGH_RISK.includes(c.toLowerCase()));
    const isHighRisk = triggeredConditions.length > 0;

    if (isHighRisk) {
        banner.className = 'ci-risk ci-risk--danger';
        document.getElementById('ci_risk_icon').className = 'feather-alert-triangle ci-risk-icon';
        document.getElementById('ci_risk_title').textContent = 'Vital signs required before treatment.';
        document.getElementById('ci_risk_detail').innerHTML =
            'Flagged condition(s): <strong>' + _esc(triggeredConditions.join(', ')) + '</strong>';
    } else {
        banner.className = 'ci-risk ci-risk--ok';
        document.getElementById('ci_risk_icon').className = 'feather-check-circle ci-risk-icon';
        document.getElementById('ci_risk_title').textContent = 'Low risk patient. Vital signs optional.';
        document.getElementById('ci_risk_detail').textContent = 'No high-risk medical conditions on record.';
    }
    banner.style.removeProperty('display');

    condEl.innerHTML = conditions.length
        ? conditions.map(c =>
            `<span class="ci-chip ci-chip--cond${CI_HIGH_RISK.includes(String(c).toLowerCase()) ? ' is-flagged' : ''}">${_esc(c)}</span>`
          ).join('')
        : '<span class="ci-none">None recorded</span>';

    algEl.innerHTML = allergies.length
        ? allergies.map(a =>
            `<span class="ci-chip ci-chip--allergy">${_esc(a)}</span>`
          ).join('')
        : '<span class="ci-none">None recorded</span>';

    if (takingMeds && medsDetail) {
        medsEl.textContent    = medsDetail;
        medsRow.style.display = 'block';
    } else {
        medsRow.style.display = 'none';
    }

    summary.style.display = 'block';

    if (profile.blood_pressure) document.getElementById('ci_blood_pressure').value = profile.blood_pressure;
    if (profile.pulse_rate)     document.getElementById('ci_pulse_rate').value     = profile.pulse_rate;
}

async function submitCheckIn() {
    const appointmentId = document.getElementById('ci_appointment_id').value;
    const patientId     = document.getElementById('ci_patient_id').value;
    const checkedBy     = document.getElementById('ci_checked_by').value.trim();
    const errEl         = document.getElementById('ci_error');

    errEl.style.display = 'none';

    const checkedByEl = document.getElementById('ci_checked_by');
    checkedByEl.classList.toggle('is-invalid', !checkedBy);
    if (!checkedBy) {
        errEl.textContent   = 'Your staff name could not be loaded for "Checked By". Please sign out and sign in again.';
        errEl.style.display = 'block';
        checkedByEl.addEventListener('input', () => checkedByEl.classList.remove('is-invalid'), { once: true });
        checkedByEl.focus();
        return;
    }

    const btn = document.getElementById('ci_submit_btn');
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Checking In…';

    const payload = {
        action:         'submit_checkin',
        appointment_id: parseInt(appointmentId),
        patient_id:     parseInt(patientId),
        checked_by:     checkedBy,
        checked_at:     document.getElementById('ci_checked_at').value || new Date().toISOString(),
        blood_pressure: document.getElementById('ci_blood_pressure').value.trim() || null,
        pulse_rate:     document.getElementById('ci_pulse_rate').value     || null,
        temperature:    document.getElementById('ci_temperature').value    || null,
        notes:          document.getElementById('ci_notes').value.trim()   || null,
    };

    try {
        const res  = await fetch('api/checkin_appointment.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const data = await res.json();

        if (data.success) {
            // Button stays disabled until the list refresh (esRefresh restores the modal), so check-in can't be sent twice
            bootstrap.Modal.getInstance(document.getElementById('checkInModal')).hide();
            Swal.fire({
                icon:     'success',
                iconHtml: '<i class="feather-user-check es-swal-glyph"></i>',
                title:    'Patient Checked In',
                html:     '<p class="es-swal-text">Vital signs saved and appointment status updated.</p>',
                timer:             2200,
                timerProgressBar:  true,
                showConfirmButton: false,
                customClass:       ES_SWAL_TONE('success'),
            });
            setTimeout(() => esRefresh(), 2200);
        } else {
            btn.disabled  = false;
            btn.innerHTML = '<i class="feather-user-check me-1"></i> Confirm Check-In';
            errEl.textContent   = data.message || 'Check-in failed. Please try again.';
            errEl.style.display = 'block';
        }
    } catch (e) {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-user-check me-1"></i> Confirm Check-In';
        errEl.textContent   = 'Network error. Please try again.';
        errEl.style.display = 'block';
    }
}