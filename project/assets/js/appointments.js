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

    const labels = {
        confirmed:  { title: 'Confirm Appointment?',  text: `Mark <strong>${code}</strong> as confirmed?`,           btn: 'Yes, Confirm',   color: '#198754' },
        checked_in: { title: 'Check In Patient?',     text: `Check in patient for <strong>${code}</strong>?`,        btn: 'Yes, Check In',  color: '#f59e0b' },
        cancelled:  { title: 'Cancel Appointment?',   text: `Cancel appointment <strong>${code}</strong>?`,          btn: 'Yes, Cancel',    color: '#dc3545' },
    };
    const cfg = labels[status];
    if (!cfg) return;

    Swal.fire({
        title: cfg.title,
        html:  cfg.text,
        icon:  'question',
        showCancelButton:    true,
        confirmButtonColor:  cfg.color,
        cancelButtonColor:   '#6c757d',
        confirmButtonText:   cfg.btn,
        cancelButtonText:    'Cancel',
    }).then(result => {
        if (!result.isConfirmed) return;
        _patchStatus(appointmentId, status, code);
    });
}

function _patchStatus(appointmentId, status, code) {
    fetch(`api/appointments.php?id=${appointmentId}`, {
        method:  'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ status }),
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            Swal.fire({ icon: 'success', title: 'Updated!', text: res.message, timer: 1600, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire('Error', res.message || 'Could not update status.', 'error');
        }
    })
    .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
}

// ── Completion Modal ─────────────────────────────────────────
let _completionAppointmentId = null;
let _completionCode          = null;
let _completionBranchId      = null;

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

            // Load pre-filled service materials
            const serviceId = apt.service_id;
            if (!serviceId) {
                _renderEmptyMaterials();
                return;
            }
            return fetch(`api/service_materials.php?service_id=${serviceId}`)
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
    // Show empty message if no rows left
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

    // Check if already in table
    if (document.querySelector(`#cmpl_materials_body tr[data-item-id="${itemId}"]`)) {
        Swal.fire({ icon: 'info', title: 'Already added', text: 'This item is already in the list. Adjust its quantity instead.', timer: 2000, showConfirmButton: false });
        return;
    }

    // Hide empty message
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

    // Reset add row
    sel.value      = '';
    qtyInput.value = '1';
}

// ── Confirm Completion ───────────────────────────────────────
function confirmCompletion() {
    const errBox = document.getElementById('cmpl_error');
    const btn    = document.getElementById('cmpl_confirm_btn');
    errBox.style.display = 'none';

    // Collect all material rows
    const rows     = document.querySelectorAll('#cmpl_materials_body tr[data-item-id]');
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

    // Step 1 — Mark appointment as completed
    fetch(`api/appointments.php?id=${_completionAppointmentId}`, {
        method:  'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ status: 'completed' }),
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message);

        // Step 2 — Deduct each material from inventory + log movement
        const tasks = deductions.map(d =>
            fetch(`api/inventory.php?id=${d.item_id}`, {
                method:  'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ deduct: d.quantity }),
            })
            .then(r => r.json())
            .then(invRes => {
                if (!invRes.success) throw new Error(invRes.message);
                // Log the movement
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
        }).then(() => location.reload());
    })
    .catch(err => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-check-circle me-1"></i> Confirm & Complete';
        errBox.textContent   = err.message || 'An error occurred. Please try again.';
        errBox.style.display = 'block';
    });
}


// ── Add Appointment Modal — Mini Calendar ────────────────────
// Design mirrors appointmentWeb-Scheduling.php (webSched.css).
// Shares the same APIs: get_booked_days.php, get_timeslots.php
// ─────────────────────────────────────────────────────────────
(function () {
    'use strict';

    let _adminCal = null;   // active FullCalendar instance

    // ── Destroy calendar when modal closes ───────────────────
    const _modal = document.getElementById('appointmentModal');
    if (_modal) {
        _modal.addEventListener('hidden.bs.modal', _destroyAdminCal);
    }

    // ── Branch change → render calendar ─────────────────────
// IN appointments.js — inside the IIFE, branch change listener
const _branchSel = document.getElementById('apt_branch');
if (_branchSel) {
    _branchSel.addEventListener('change', function () {
        const branchId = this.value;
        _resetDateTime();

        if (!branchId) {
            _destroyAdminCal();
            _showPlaceholder();
            // Reset dentist on branch clear
            const sel = document.getElementById('apt_dentist');
            sel.innerHTML = '<option value=""> Select a branch first </option>';
            sel.disabled  = true;
            return;
        }

        _loadDentistsByBranch(branchId);   // ← ADD THIS LINE
        _initAdminCal(branchId);
    });
}

    // ── Build and render FullCalendar inside #adminCalendarWrap
    function _initAdminCal(branchId) {
        const wrap = document.getElementById('adminCalendarWrap');
        if (!wrap) return;

        _destroyAdminCal();

        // Clean container — padding handled by inner FC styles
        wrap.className    = 'rounded-3 border';
        wrap.style.cssText = 'padding: 0.5rem 0.25rem;';
        wrap.innerHTML    = '';

        const calEl = document.createElement('div');
        wrap.appendChild(calEl);

        _adminCal = new FullCalendar.Calendar(calEl, {
            initialView:         'dayGridMonth',
            headerToolbar:       { left: 'prev', center: 'title', right: 'next' },
            fixedWeekCount:      false,
            showNonCurrentDates: false,
            hiddenDays:          [0, 1],   // Sunday + Monday (clinic closed)
            height:              'auto',
            validRange: {
                start: (() => {
                    const t = new Date();
                    t.setDate(t.getDate() + 1);
                    return t.toISOString().split('T')[0];
                })()
            },

            datesSet(info) {
                _markFullyBooked(info.startStr, info.endStr, branchId, calEl);
            },

            dateClick(info) {
                if (info.dayEl.classList.contains('fc-day-fully-booked')) return;

                calEl.querySelectorAll('.fc-day-selected')
                     .forEach(el => el.classList.remove('fc-day-selected'));
                info.dayEl.classList.add('fc-day-selected');

                document.getElementById('apt_date').value               = info.dateStr;
                document.getElementById('apt_time').value               = '';
                document.getElementById('aptDateTimeSummary').style.display = 'none';

                _loadTimeSlots(info.dateStr, branchId);
            }
        });

        _adminCal.render();
    }

    // ── Fetch & mark fully booked days ───────────────────────
    function _markFullyBooked(startStr, endStr, branchId, calEl) {
        fetch(`api/get_booked_days.php?start=${startStr}&end=${endStr}&branch=${branchId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.fully_booked) return;
                data.fully_booked.forEach(dateStr => {
                    const dayEl = calEl.querySelector(`[data-date="${dateStr}"]`);
                    if (dayEl) {
                        dayEl.classList.add('fc-day-fully-booked');
                        dayEl.setAttribute('title', 'Fully booked');
                    }
                });
            })
            .catch(() => {});
    }

    // ── Fetch available slots for clicked date ───────────────
    function _loadTimeSlots(dateStr, branchId) {
        const wrap = document.getElementById('adminTimeSlots');
        const grid = document.getElementById('adminTimeSlotsGrid');
        wrap.style.display = '';
        grid.innerHTML = `
            <div class="slot-loading">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                Loading available times…
            </div>`;

        fetch(`api/get_timeslots.php?date=${dateStr}&branch=${branchId}`)
            .then(r => r.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                _renderSlots(dateStr, data.booked || []);
            })
            .catch(() => {
                grid.innerHTML = `
                    <div class="slot-empty text-danger">
                        <i class="feather-alert-circle me-1"></i>Failed to load time slots. Please try again.
                    </div>`;
            });
    }

    // ── Render slot buttons (web scheduling design) ──────────
    function _renderSlots(dateStr, bookedTimes) {
        const grid  = document.getElementById('adminTimeSlotsGrid');
        const slots = _genSlots();
        const available = slots.filter(s => !bookedTimes.includes(s));
        grid.innerHTML = '';

        // Legend
        const legend = document.createElement('div');
        legend.className = 'slot-legend';
        legend.innerHTML = `
            <span>
                <span class="legend-dot" style="background:#e8f0fe;border:1.5px solid rgba(13,110,253,0.3);"></span>
                Available
            </span>
            <span>
                <span class="legend-dot" style="background:#f1f4f8;border:1px solid #dee2e6;"></span>
                Booked
            </span>`;
        grid.appendChild(legend);

        // Button grid
        const btnGrid = document.createElement('div');
        btnGrid.className = 'slot-grid';

        slots.forEach(time => {
            const isBooked = bookedTimes.includes(time);
            const btn      = document.createElement('button');
            btn.type       = 'button';
            btn.className  = `slot-btn ${isBooked ? 'slot-btn-booked' : 'slot-btn-available'}`;
            btn.textContent = _fmtTime(time);
            btn.disabled   = isBooked;
            btn.title      = isBooked ? 'Already booked' : 'Click to select';

            if (!isBooked) {
                btn.addEventListener('click', () => {
                    btnGrid.querySelectorAll('.slot-btn-available.selected').forEach(b => {
                        b.classList.remove('selected');
                    });
                    btn.classList.add('selected');

                    document.getElementById('apt_time').value = time;
                    _showSummary(dateStr, time);
                });
            }

            btnGrid.appendChild(btn);
        });

        grid.appendChild(btnGrid);

        // Slot count
        const info = document.createElement('p');
        info.className   = 'slot-count';
        info.textContent = `${available.length} of ${slots.length} slots available`;
        grid.appendChild(info);
    }

    // ── Show green date + time summary bar ──────────────────
    function _showSummary(dateStr, time) {
        const display = new Date(dateStr + 'T00:00:00')
            .toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        document.getElementById('aptDateTimeText').textContent = `${display} at ${_fmtTime(time)}`;
        const el = document.getElementById('aptDateTimeSummary');
        el.style.display = 'flex';
    }

    // ── Helpers ──────────────────────────────────────────────
    function _genSlots() {
        const s = [];
        for (let h = 9; h <= 17; h++) {
            s.push(`${String(h).padStart(2, '0')}:00`);
            if (h < 17) s.push(`${String(h).padStart(2, '0')}:30`);
        }
        return s;
    }

    function _fmtTime(t) {
        return new Date(`1970-01-01T${t}:00`)
            .toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    }

    function _resetDateTime() {
        document.getElementById('apt_date').value                   = '';
        document.getElementById('apt_time').value                   = '';
        document.getElementById('adminTimeSlots').style.display     = 'none';
        document.getElementById('aptDateTimeSummary').style.display = 'none';
        document.getElementById('adminTimeSlotsGrid').innerHTML      = '';
    }

    function _showPlaceholder() {
        const wrap = document.getElementById('adminCalendarWrap');
        if (!wrap) return;
        wrap.className    = 'rounded-3 border';
        wrap.style.cssText = '';
        wrap.innerHTML    = `
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
    }

})();
// ── Filter dentists by selected branch ───────────────────
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
// ── Add / Edit Appointment ───────────────────────────────────
async function saveAppointment() {
    const errBox  = document.getElementById('modalError');
    const btn     = document.getElementById('modalSaveBtn');
    errBox.style.display = 'none';

    const id      = document.getElementById('apt_id').value.trim();
    const branch  = document.getElementById('apt_branch').value;
    const patient = document.getElementById('apt_patient').value;
    const dentist = document.getElementById('apt_dentist').value;
    const service = document.getElementById('apt_service').value;
    const date    = document.getElementById('apt_date').value;
    const time    = document.getElementById('apt_time').value;
    const status  = document.getElementById('apt_status').value;
    const payment = document.getElementById('apt_payment').value;
    const notes   = document.getElementById('apt_notes').value.trim();

    // Validation
    if (!branch)  return _showModalError('Please select a branch.');
    if (!patient) return _showModalError('Please select a patient.');
    if (!date)    return _showModalError('Please select a date.');
    if (!time)    return _showModalError('Please select a time slot.');

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    const payload = {
        branch_id:        parseInt(branch),
        patient_id:       parseInt(patient),
        dentist_id:       dentist ? parseInt(dentist)  : null,
        service_id:       service ? parseInt(service)  : null,
        appointment_date: date,
        appointment_time: time,
        status,
        payment_status:   payment,
        notes:            notes || null,
    };

    try {
        const url    = id ? `api/appointments.php?id=${id}` : 'api/appointments.php';
        const method = id ? 'PUT' : 'POST';

        const res  = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const json = await res.json();

        if (!res.ok || !json.success) throw new Error(json.message || 'Could not save appointment.');

        bootstrap.Modal.getInstance(document.getElementById('appointmentModal'))?.hide();

        Swal.fire({
            icon:  'success',
            title: id ? 'Appointment Updated!' : 'Appointment Added!',
            text:  json.message || 'The appointment has been saved successfully.',
            timer: 1800,
            showConfirmButton: false,
        }).then(() => location.reload());

    } catch (err) {
        _showModalError(err.message);
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-save me-1"></i> Save Appointment';
    }
}

function _showModalError(msg) {
    const box = document.getElementById('modalError');
    box.textContent   = msg;
    box.style.display = 'block';
}