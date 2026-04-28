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
            sel.innerHTML = '<option value="">— Select Item —</option>';
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