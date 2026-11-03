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

    Swal.fire({
        icon:     'question',
        iconHtml: `<i class="${cfg.icon} es-swal-glyph"></i>`,
        title:    cfg.title,
        html:     `<p class="es-swal-text">${cfg.text}</p>` +
                  `<span class="es-swal-chip"><i class="feather-calendar"></i> ${_esc(code)}</span>`,
        showCancelButton:  true,
        confirmButtonText: cfg.btn,
        cancelButtonText:  'Go Back',
        reverseButtons:    true,
        focusCancel:       status === 'cancelled',
        buttonsStyling:    false,
        customClass:       ES_SWAL_TONE(cfg.tone),
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
            }).then(() => location.reload());
        } else {
            _statusError(res.message || 'Could not update the appointment status.');
        }
    })
    .catch(() => _statusError('Network error. Please check your connection and try again.'));
}

function _statusError(message) {
    Swal.fire({
        icon:     'error',
        iconHtml: '<i class="feather-alert-circle es-swal-glyph"></i>',
        title:    'Update Failed',
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
(function () {
    'use strict';

    let _adminCal = null;

    // ── Destroy calendar when modal closes ───────────────────
    const _modal = document.getElementById('appointmentModal');
    if (_modal) {
        _modal.addEventListener('hidden.bs.modal', _destroyAdminCal);
    }

    // ── Branch change → render calendar + load dentists ──────
    const _branchSel = document.getElementById('apt_branch');
    if (_branchSel) {
        _branchSel.addEventListener('change', function () {
            const branchId = this.value;
            _resetDateTime();

            if (!branchId) {
                _destroyAdminCal();
                _showPlaceholder();
                const sel = document.getElementById('apt_dentist');
                sel.innerHTML = '<option value=""> Select a branch first </option>';
                sel.disabled  = true;
                return;
            }

            _loadDentistsByBranch(branchId);
            _initAdminCal(branchId);
        });
    }

    function _initAdminCal(branchId) {
        const wrap = document.getElementById('adminCalendarWrap');
        if (!wrap) return;

        _destroyAdminCal();

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
            hiddenDays:          [0, 1],
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
                _markPresetDay(calEl);
            },

            dateClick(info) {
                if (info.dayEl.classList.contains('fc-day-fully-booked')) return;

                calEl.querySelectorAll('.fc-day-selected')
                     .forEach(el => el.classList.remove('fc-day-selected'));
                info.dayEl.classList.add('fc-day-selected');

                document.getElementById('apt_date').value               = info.dateStr;
                document.querySelector('#appointmentModal [data-err-for="apt_date"]')?.replaceChildren();
                document.getElementById('apt_time').value               = '';
                document.getElementById('aptDateTimeSummary').style.display = 'none';

                const preset   = window._aptEditPreset;
                const keepTime = preset && preset.date === info.dateStr ? preset.time : null;
                if (keepTime) {   // back on the saved day → restore the saved time
                    document.getElementById('apt_time').value = keepTime;
                    _showSummary(info.dateStr, keepTime);
                }
                _loadTimeSlots(info.dateStr, branchId, keepTime);
            }
        });

        _adminCal.render();

        // Edit mode: show the appointment's saved date + time as selected.
        const preset = window._aptEditPreset;
        if (preset && preset.date) {
            document.getElementById('apt_date').value = preset.date;
            document.getElementById('apt_time').value = preset.time;
            _adminCal.gotoDate(preset.date);
            _markPresetDay(calEl);
            _showSummary(preset.date, preset.time);
            _loadTimeSlots(preset.date, branchId, preset.time);
        }
    }

    // Highlight the edit preset day (only while it is still the chosen date).
    function _markPresetDay(calEl) {
        const preset = window._aptEditPreset;
        if (!preset || document.getElementById('apt_date').value !== preset.date) return;
        const dayEl = calEl.querySelector(`[data-date="${preset.date}"]`);
        if (dayEl) dayEl.classList.add('fc-day-selected');
    }

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

    function _loadTimeSlots(dateStr, branchId, keepTime) {
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
                _renderSlots(dateStr, data.booked || [], keepTime);
            })
            .catch(() => {
                grid.innerHTML = `
                    <div class="slot-empty text-danger">
                        <i class="feather-alert-circle me-1"></i>Failed to load time slots. Please try again.
                    </div>`;
            });
    }

    function _renderSlots(dateStr, bookedTimes, keepTime) {
        const grid  = document.getElementById('adminTimeSlotsGrid');
        const slots = _genSlots();
        // The appointment being edited occupies its own slot — don't show it as booked.
        if (keepTime) {
            bookedTimes = bookedTimes.filter(t => t !== keepTime);
            if (!slots.includes(keepTime)) { slots.push(keepTime); slots.sort(); }
        }
        const available = slots.filter(s => !bookedTimes.includes(s));
        grid.innerHTML = '';

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
            if (keepTime && time === keepTime) {
                btn.classList.add('selected');
                btn.title = 'Current booked time';
            }

            if (!isBooked) {
                btn.addEventListener('click', () => {
                    btnGrid.querySelectorAll('.slot-btn-available.selected').forEach(b => {
                        b.classList.remove('selected');
                    });
                    btn.classList.add('selected');

                    document.getElementById('apt_time').value = time;
                    document.querySelector('#appointmentModal [data-err-for="apt_time"]')?.replaceChildren();
                    _showSummary(dateStr, time);
                });
            }

            btnGrid.appendChild(btn);
        });

        grid.appendChild(btnGrid);

        const info = document.createElement('p');
        info.className   = 'slot-count';
        info.textContent = `${available.length} of ${slots.length} slots available`;
        grid.appendChild(info);
    }

    function _showSummary(dateStr, time) {
        const display = new Date(dateStr + 'T00:00:00')
            .toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        document.getElementById('aptDateTimeText').textContent = `${display} at ${_fmtTime(time)}`;
        const el = document.getElementById('aptDateTimeSummary');
        el.style.display = 'flex';
    }

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
};

window.aptRemoveService = function (id) {
    _aptServices = _aptServices.filter(s => s.id !== id);
    _aptRenderChips();
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
    const status  = document.getElementById('apt_status').value;
    const payment = document.getElementById('apt_payment').value;
    const notes   = document.getElementById('apt_notes').value.trim();

    if (!branch)  return _showModalError('Please select a branch.', 'apt_branch');
    if (!patient) return _showModalError('Please select a patient.', 'apt_patient');
    if (!date)    return _showModalError('Please select a date.', 'apt_date');
    if (!time)    return _showModalError('Please select a time slot.', 'apt_time');

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    const payload = {
        branch_id:        parseInt(branch),
        patient_id:       parseInt(patient),
        dentist_id:       dentist ? parseInt(dentist)  : null,
        service_ids:      _aptServices.map(s => s.id),  // ← multi-service array
        appointment_date: date,
        appointment_time: time,
    };
    // Status / payment / notes are only set when creating; edits keep stored values.
    if (!id) Object.assign(payload, { status, payment_status: payment, notes: notes || null });

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
            // Button stays disabled: the page reloads, so check-in can't be sent twice
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
            setTimeout(() => location.reload(), 2200);
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