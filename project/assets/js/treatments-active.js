/* =============================================================
   EssenciaSmile — Active Treatments JS
   File: assets/js/treatments-active.js
   ============================================================= */

let _workData       = {};
let _workMaterials  = [];
let _inventoryItems = [];

// ── Tooth Chart ─────────────────────────────────────────────
let _selectedTeeth  = [];
let _basePricePerTooth = 0;

function initToothChart() {
    const upper = document.getElementById('upper_teeth');
    const lower = document.getElementById('lower_teeth');
    if (!upper || !lower) return;
    upper.innerHTML = '';
    lower.innerHTML = '';

    // Upper jaw: teeth 1-16
    for (let i = 1; i <= 16; i++) {
        upper.appendChild(_makeToothBtn(i));
    }
    // Lower jaw: teeth 32 down to 17
    for (let i = 32; i >= 17; i--) {
        lower.appendChild(_makeToothBtn(i));
    }
}

function _makeToothBtn(num) {
    const btn = document.createElement('button');
    btn.type        = 'button';
    btn.className   = 'tooth-btn';
    btn.textContent = num;
    btn.dataset.num = num;
    btn.onclick     = () => toggleTooth(num, btn);
    return btn;
}

function toggleTooth(num, btn) {
    const idx = _selectedTeeth.indexOf(num);
    if (idx === -1) {
        _selectedTeeth.push(num);
        btn.classList.add('active');
    } else {
        _selectedTeeth.splice(idx, 1);
        btn.classList.remove('active');
    }
    _selectedTeeth.sort((a, b) => a - b);
    _updateToothDisplay();
    _recalcCostFromTeeth();
}

function _updateToothDisplay() {
    const label   = document.getElementById('tooth_selected_label');
    const clearBtn = document.getElementById('tooth_clear_btn');
    const hidden  = document.getElementById('work_tooth_number');

    if (_selectedTeeth.length === 0) {
        label.textContent     = 'No teeth selected';
        clearBtn.style.display = 'none';
        hidden.value          = '';
    } else {
        label.textContent     = `Selected: ${_selectedTeeth.join(', ')}`;
        clearBtn.style.display = '';
        hidden.value          = _selectedTeeth.join(', ');
    }
}

function clearToothSelection() {
    _selectedTeeth = [];
    document.querySelectorAll('.tooth-btn.active').forEach(b => b.classList.remove('active'));
    _updateToothDisplay();
    _recalcCostFromTeeth();
}

function _recalcCostFromTeeth() {
    if (_basePricePerTooth <= 0 || _selectedTeeth.length === 0) return;
    const total = _selectedTeeth.length * _basePricePerTooth;
    document.getElementById('work_cost').value = total.toFixed(2);
    document.getElementById('work_cost_hint').textContent =
        `${_selectedTeeth.length} tooth/teeth × ₱${_basePricePerTooth.toFixed(2)} = ₱${total.toFixed(2)}`;
}

function _preSelectTeeth(toothStr) {
    if (!toothStr) return;
    const nums = toothStr.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n));
    nums.forEach(num => {
        _selectedTeeth.push(num);
        const btn = document.querySelector(`.tooth-btn[data-num="${num}"]`);
        if (btn) btn.classList.add('active');
    });
    _selectedTeeth.sort((a, b) => a - b);
    _updateToothDisplay();
}

// ── View Details ─────────────────────────────────────────────
function viewTreatment(t) {
    document.getElementById('tv_code').textContent      = t.treatment_code  || '—';
    document.getElementById('tv_date').textContent      = t.treatment_date  || '—';
    document.getElementById('tv_patient').textContent   = t.patient_name    || '—';
    document.getElementById('tv_branch').textContent    = t.branch_name     || '—';
    document.getElementById('tv_dentist').textContent   = t.dentist_name    || '—';
    document.getElementById('tv_service').textContent   = t.service_name    || '—';
    document.getElementById('tv_tooth').textContent     = t.tooth_number    || '—';
    document.getElementById('tv_procedure').textContent = t.procedure_notes || '—';
    document.getElementById('tv_cost').textContent      = t.cost            || '—';
    document.getElementById('tv_stage').textContent     = t.current_stage   || '—';
    document.getElementById('tv_status').textContent    = t.status          || '—';
    new bootstrap.Modal(document.getElementById('treatmentViewModal')).show();
}

// ── Open Work Modal ──────────────────────────────────────────
function openWorkModal(t) {
    _workData      = t;
    _workMaterials = [];

    // Set hidden fields
    document.getElementById('work_treatment_id').value   = t.treatment_id;
    document.getElementById('work_appointment_id').value = t.appointment_id || '';
    document.getElementById('work_patient_id').value     = t.patient_id     || '';
    document.getElementById('work_branch_id').value      = t.branch_id      || '';
    document.getElementById('work_dentist_id').value     = t.dentist_id     || '';
    document.getElementById('work_service_id').value     = t.service_id     || '';
    document.getElementById('work_patient_name').value   = t.patient_name   || '';
    document.getElementById('work_service_name').value   = t.service_name   || '';
    document.getElementById('work_dentist_name').value   = t.dentist_name   || '';
    document.getElementById('work_branch_name').value    = t.branch_name    || '';

    // Set title
    document.getElementById('workModalTitle').textContent =
        ['pending','ongoing'].includes(t.status) ? 'Start Treatment' : 'Update Treatment';
    document.getElementById('workModalSubtitle').textContent =
        `Patient: ${t.patient_name}`;
    document.getElementById('workModalError').style.display       = 'none';
    document.getElementById('work_add_material_row').style.display = 'none';

    // Auto-fill service (read-only)
    document.getElementById('work_service_display').value = t.service_name || '—';

    // Pre-fill editable fields from existing treatment data
    document.getElementById('work_procedure_notes').value = t.procedure_notes || '';
    document.getElementById('work_current_stage').value   = t.current_stage   || '';

    // Init tooth chart and pre-select saved teeth
    _selectedTeeth = [];
    _basePricePerTooth = 0;
    initToothChart();
    if (t.tooth_number) _preSelectTeeth(t.tooth_number);

    // Show loading for materials
    document.getElementById('work_materials_loading').style.display = '';
    document.getElementById('work_materials_table').style.display   = 'none';

    // Load dentists for branch
    const branchId = t.branch_id || window._sessionBranchId || '';
    _loadDentistDropdown(branchId, t.dentist_id);

    // Load inventory items for this branch
    fetch(`api/inventory.php?branch_id=${branchId}`)
        .then(r => r.json())
        .then(items => {
            _inventoryItems = items || [];
            populateWorkItemDropdown();
        });

    // Auto-fill cost from service base_price
    if (t.service_id) {
        fetch(`api/services.php?service_id=${t.service_id}`)
            .then(r => r.json())
            .then(services => {
                const svc = services[0];
                if (svc && svc.base_price) {
                    _basePricePerTooth = parseFloat(svc.base_price);
                    if (!t.cost || parseFloat(t.cost) === 0) {
                        // No saved cost — use base price
                        document.getElementById('work_cost').value = _basePricePerTooth.toFixed(2);
                        document.getElementById('work_cost_hint').textContent =
                            `Base price: ₱${_basePricePerTooth.toFixed(2)} per tooth — select teeth above to auto-calculate`;
                    } else {
                        document.getElementById('work_cost').value = parseFloat(t.cost).toFixed(2);
                        document.getElementById('work_cost_hint').textContent =
                            `Base price: ₱${_basePricePerTooth.toFixed(2)} per tooth`;
                    }
                }
            });
    } else {
        document.getElementById('work_cost').value = t.cost ? parseFloat(t.cost).toFixed(2) : '';
        document.getElementById('work_cost_hint').textContent = '';
    }

    // Auto-load materials from service_materials config OR existing treatment_materials
    _loadWorkMaterials(t.treatment_id, t.service_id);

    new bootstrap.Modal(document.getElementById('workModal')).show();
}

function _loadDentistDropdown(branchId, preDentistId) {
    fetch(`api/dentists.php?branch_id=${branchId}`)
        .then(r => r.json())
        .then(dentists => {
            const sel = document.getElementById('work_dentist_select');
            sel.innerHTML = '<option value="">— Select Dentist —</option>';
            (dentists || []).forEach(d => {
                const opt = document.createElement('option');
                opt.value       = d.dentist_id;
                opt.textContent = d.full_name;
                if (preDentistId && String(d.dentist_id) === String(preDentistId)) opt.selected = true;
                sel.appendChild(opt);
            });
        });
}

function _loadWorkMaterials(treatmentId, serviceId) {
    // First check if treatment already has saved materials
    fetch(`api/treatment_materials.php?treatment_id=${treatmentId}`)
        .then(r => r.json())
        .then(existing => {
            if (existing && existing.length > 0) {
                // Use already-saved materials
                _workMaterials = existing.map(m => ({
                    material_id: m.material_id,
                    item_id:     m.item_id,
                    item_name:   m.inventory?.item_name || '—',
                    item_code:   m.inventory?.item_code || '',
                    stock:       parseInt(m.inventory?.stock_quantity ?? 0),
                    qty_used:    m.quantity_used,
                }));
                _showMaterialsTable();
            } else if (serviceId) {
                // Auto-fill from service_materials config
                fetch(`api/service_materials.php?service_id=${serviceId}`)
                    .then(r => r.json())
                    .then(serviceMats => {
                        _workMaterials = (serviceMats || []).map(m => ({
                            item_id:   m.item_id,
                            item_name: m.inventory?.item_name || '—',
                            item_code: m.inventory?.item_code || '',
                            stock:     parseInt(m.inventory?.stock_quantity ?? 0),
                            qty_used:  m.quantity,  // default qty from service config
                        }));
                        _showMaterialsTable();
                    });
            } else {
                _showMaterialsTable();
            }
        });
}

function _showMaterialsTable() {
    document.getElementById('work_materials_loading').style.display = 'none';
    document.getElementById('work_materials_table').style.display   = '';
    renderWorkMaterials();
}

// ── Inventory dropdown ───────────────────────────────────────
function populateWorkItemDropdown() {
    const sel = document.getElementById('work_add_item');
    sel.innerHTML = '<option value="">— Select Item —</option>';
    _inventoryItems.forEach(item => {
        const opt         = document.createElement('option');
        opt.value         = item.item_id;
        opt.textContent   = `${item.item_name} (Stock: ${item.stock_quantity})`;
        opt.dataset.name  = item.item_name;
        opt.dataset.code  = item.item_code || '';
        opt.dataset.stock = item.stock_quantity;
        sel.appendChild(opt);
    });
}

function openAddWorkMaterial()  { document.getElementById('work_add_material_row').style.display = ''; document.getElementById('work_add_item').value = ''; document.getElementById('work_add_qty').value = '1'; }
function closeAddWorkMaterial() { document.getElementById('work_add_material_row').style.display = 'none'; }

function addWorkMaterial() {
    const sel    = document.getElementById('work_add_item');
    const qty    = parseInt(document.getElementById('work_add_qty').value) || 1;
    const itemId = parseInt(sel.value);
    if (!itemId) { Swal.fire({ icon: 'warning', title: 'Select an item', timer: 1500, showConfirmButton: false }); return; }
    if (_workMaterials.find(m => m.item_id === itemId)) { Swal.fire({ icon: 'info', title: 'Already added', text: 'Adjust its quantity in the table.', timer: 1800, showConfirmButton: false }); return; }
    const opt = sel.options[sel.selectedIndex];
    _workMaterials.push({ item_id: itemId, item_name: opt.dataset.name, item_code: opt.dataset.code, stock: parseInt(opt.dataset.stock ?? 0), qty_used: qty });
    renderWorkMaterials();
    closeAddWorkMaterial();
}

function removeWorkMaterial(itemId) { _workMaterials = _workMaterials.filter(m => m.item_id !== itemId); renderWorkMaterials(); }
function updateWorkMaterialQty(itemId, newQty) { const mat = _workMaterials.find(m => m.item_id === itemId); if (mat) mat.qty_used = parseInt(newQty) || 1; }

function renderWorkMaterials() {
    const tbody   = document.getElementById('work_materials_body');
    const noItems = document.getElementById('work_no_materials');
    Array.from(tbody.querySelectorAll('tr:not(#work_no_materials)')).forEach(r => r.remove());
    if (_workMaterials.length === 0) { noItems.style.display = ''; return; }
    noItems.style.display = 'none';
    _workMaterials.forEach(mat => {
        const stockColor = mat.stock === 0 ? 'text-danger' : mat.stock <= 5 ? 'text-warning' : 'text-success';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><div class="fw-semibold small">${mat.item_name}</div><div class="text-muted" style="font-size:11px;">${mat.item_code}</div></td>
            <td><span class="${stockColor} small fw-semibold">${mat.stock}</span></td>
            <td><input type="number" value="${mat.qty_used}" min="1" max="${mat.stock}"
                style="width:70px;border:1px solid #ced4da;border-radius:6px;padding:3px 8px;font-size:13px;"
                onchange="updateWorkMaterialQty(${mat.item_id}, this.value)"></td>
            <td class="text-end">
                <button style="width:28px;height:28px;border:1px solid #dc3545;border-radius:6px;background:#fff;color:#dc3545;cursor:pointer;"
                    onclick="removeWorkMaterial(${mat.item_id})"
                    onmouseover="this.style.background='#dc3545';this.style.color='#fff'"
                    onmouseout="this.style.background='#fff';this.style.color='#dc3545'">
                    <i class="feather-x" style="font-size:12px;"></i>
                </button>
            </td>`;
        tbody.insertBefore(tr, noItems);
    });
}

// ── Open Finish Confirm Modal ────────────────────────────────
function openFinishConfirmModal() {
    const errBox         = document.getElementById('workModalError');
    const procedureNotes = document.getElementById('work_procedure_notes').value.trim();
    const cost           = parseFloat(document.getElementById('work_cost').value) || 0;
    const dentistSel     = document.getElementById('work_dentist_select');
    const dentistId      = dentistSel.value;
    const dentistName    = dentistSel.options[dentistSel.selectedIndex]?.text || '';
    errBox.style.display = 'none';

    if (!dentistId)      { errBox.textContent = 'Please select the dentist who performed the treatment.'; errBox.style.display = 'block'; return; }
    if (!procedureNotes) { errBox.textContent = 'Please describe the procedure performed.';               errBox.style.display = 'block'; return; }
    if (cost <= 0)       { errBox.textContent = 'Please enter the treatment cost.';                       errBox.style.display = 'block'; return; }

    for (const mat of _workMaterials) {
        if (mat.qty_used > mat.stock) {
            errBox.textContent   = `Insufficient stock for "${mat.item_name}". Available: ${mat.stock}, Requested: ${mat.qty_used}.`;
            errBox.style.display = 'block';
            return;
        }
    }

    // Store dentist for submission
    document.getElementById('work_dentist_id').value   = dentistId;
    document.getElementById('work_dentist_name').value = dentistName;

    // Populate confirm modal
    document.getElementById('fc_patient').textContent   = document.getElementById('work_patient_name').value;
    document.getElementById('fc_service').textContent   = document.getElementById('work_service_display').value;
    document.getElementById('fc_procedure').textContent = procedureNotes;
    document.getElementById('fc_tooth').textContent     = document.getElementById('work_tooth_number').value || '—';
    document.getElementById('fc_dentist').textContent   = dentistName;
    document.getElementById('fc_branch').textContent    = document.getElementById('work_branch_name').value;
    document.getElementById('fc_cost').textContent      = '₱' + cost.toFixed(2);

    const matDiv = document.getElementById('fc_materials_list');
    matDiv.innerHTML = _workMaterials.length === 0
        ? '<span class="text-muted">No materials to deduct.</span>'
        : _workMaterials.map(m =>
            `<div class="d-flex justify-content-between border-bottom py-1">
                <span>${m.item_name}</span>
                <span class="fw-semibold">× ${m.qty_used}</span>
            </div>`).join('');

    document.getElementById('finishConfirmError').style.display = 'none';
    bootstrap.Modal.getInstance(document.getElementById('workModal'))?.hide();
    setTimeout(() => new bootstrap.Modal(document.getElementById('finishConfirmModal')).show(), 300);
}

function backToWorkModal() {
    bootstrap.Modal.getInstance(document.getElementById('finishConfirmModal'))?.hide();
    setTimeout(() => new bootstrap.Modal(document.getElementById('workModal')).show(), 300);
}

// ── Confirm & Finish ─────────────────────────────────────────
function confirmFinishTreatment() {
    const btn    = document.getElementById('confirmFinishBtn');
    const errBox = document.getElementById('finishConfirmError');
    errBox.style.display = 'none';
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

    const payload = {
        treatment_id:    parseInt(document.getElementById('work_treatment_id').value),
        appointment_id:  parseInt(document.getElementById('work_appointment_id').value) || null,
        patient_id:      parseInt(document.getElementById('work_patient_id').value)     || null,
        branch_id:       parseInt(document.getElementById('work_branch_id').value)      || null,
        dentist_id:      parseInt(document.getElementById('work_dentist_id').value)     || null,
        service_id:      parseInt(document.getElementById('work_service_id').value)     || null,
        procedure_notes: document.getElementById('work_procedure_notes').value.trim(),
        tooth_number:    document.getElementById('work_tooth_number').value.trim(),
        cost:            parseFloat(document.getElementById('work_cost').value) || 0,
        current_stage:   document.getElementById('work_current_stage').value.trim(),
        performed_by:    window._currentUser || 'System',
        materials:       _workMaterials.map(m => ({
            item_id:      m.item_id,
            quantity_used: m.qty_used,
            item_name:    m.item_name,
        })),
    };

    fetch('api/finish_treatment.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-check-circle me-1"></i> Confirm & Finish';
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('finishConfirmModal'))?.hide();
            Swal.fire({
                icon:  'success',
                title: 'Treatment Completed!',
                html:  `Treatment finalized successfully.<br>
                        <span class="text-muted small">Invoice <strong>${res.invoice_code || ''}</strong> created — Unpaid.</span>`,
                timer: 2500,
                showConfirmButton: false,
            }).then(() => location.reload());
        } else {
            errBox.textContent   = res.message || 'An error occurred.';
            errBox.style.display = 'block';
        }
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-check-circle me-1"></i> Confirm & Finish';
        errBox.textContent   = 'Network error. Please try again.';
        errBox.style.display = 'block';
    });
}