// ============================================================
//  EssenciaSmile — Treatments JS
//  File: assets/js/treatments.js
// ============================================================

// ── Helpers ────────────────────────────────────────────────
function trtSetLoading(btn, isLoading) {
    if (!btn) return;
    btn.disabled = isLoading;
    btn.innerHTML = isLoading
        ? '<span class="spinner-border spinner-border-sm me-1"></span> Saving...'
        : '<i class="feather-save me-1"></i> Save';
}

function trtShowError(msg) {
    const el = document.getElementById('trtModalError');
    if (!el) return;
    el.textContent = msg;
    el.style.display = 'block';
}

function trtHideError() {
    const el = document.getElementById('trtModalError');
    if (el) el.style.display = 'none';
}

// ── Open Add Modal ──────────────────────────────────────────
function openAddTreatmentModal() {
    document.getElementById('trtModalTitle').textContent = 'Add Treatment';
    document.getElementById('trt_id').value       = '';
    document.getElementById('trt_date').value     = new Date().toISOString().split('T')[0];
    document.getElementById('trt_tooth').value    = '';
    document.getElementById('trt_cost').value     = '';
    document.getElementById('trt_stage').value    = '';
    document.getElementById('trt_status').value   = 'pending';
    document.getElementById('trt_notes').value    = '';
    trtHideError();

    // Auto-set branch for non-owners
    const branchSel  = document.getElementById('trt_branch');
    const sessionBranch = window._sessionBranchId || '';
    const isOwner    = window._isOwner || false;

    if (!isOwner && sessionBranch) {
        branchSel.value = sessionBranch;
        loadTreatmentDropdowns();
    } else {
        branchSel.value = '';
        _resetSelect('trt_patient', '— Select Patient —');
        _resetSelect('trt_dentist', '— Select Dentist —');
        _resetSelect('trt_service', '— Select Service —');
    }

    new bootstrap.Modal(document.getElementById('treatmentModal')).show();
}

// ── Open Edit Modal ─────────────────────────────────────────
function openEditTreatmentModal(t) {
    document.getElementById('trtModalTitle').textContent = 'Edit Treatment';
    document.getElementById('trt_id').value     = t.treatment_id;
    document.getElementById('trt_date').value   = t.treatment_date   || '';
    document.getElementById('trt_tooth').value  = t.tooth_number     || '';
    document.getElementById('trt_cost').value   = t.cost             || '';
    document.getElementById('trt_stage').value  = t.current_stage    || '';
    document.getElementById('trt_status').value = t.status           || 'pending';
    document.getElementById('trt_notes').value  = t.notes            || '';
    trtHideError();

    // Load branch first, then populate dependants
    const branchSel = document.getElementById('trt_branch');
    branchSel.value = t.branch_id || '';
    loadTreatmentDropdowns(t.patient_id, t.dentist_id, t.service_id);

    new bootstrap.Modal(document.getElementById('treatmentModal')).show();
}

// ── View Details Modal ──────────────────────────────────────
function viewTreatment(t) {
    document.getElementById('tv_code').textContent    = t.treatment_code || '—';
    document.getElementById('tv_date').textContent    = t.treatment_date  || '—';
    document.getElementById('tv_patient').textContent = t.patient_name    || '—';
    document.getElementById('tv_branch').textContent  = t.branch_name     || '—';
    document.getElementById('tv_dentist').textContent = t.dentist_name    || '—';
    document.getElementById('tv_service').textContent = t.service_name    || '—';
    document.getElementById('tv_tooth').textContent   = t.tooth_number    || '—';
    document.getElementById('tv_cost').textContent    = t.cost            || '—';
    document.getElementById('tv_stage').textContent   = t.current_stage   || '—';
    document.getElementById('tv_status').textContent  = t.status          || '—';
    document.getElementById('tv_notes').textContent   = t.notes           || '—';
    new bootstrap.Modal(document.getElementById('treatmentViewModal')).show();
}

// ── Dynamic Dropdowns ───────────────────────────────────────
function loadTreatmentDropdowns(prePatient = null, preDentist = null, preService = null) {
    const branchId = document.getElementById('trt_branch').value;
    if (!branchId) {
        _resetSelect('trt_patient', '— Select Patient —');
        _resetSelect('trt_dentist', '— Select Dentist —');
        _resetSelect('trt_service', '— Select Service —');
        return;
    }

    // Load patients by branch
    fetch(`api/patients.php?branch_id=${branchId}`)
        .then(r => r.json())
        .then(data => {
            _populateSelect('trt_patient', data, 'patient_id', 'full_name', '— Select Patient —', prePatient);
        }).catch(() => _resetSelect('trt_patient', '— Select Patient —'));

    // Load dentists by branch
    fetch(`api/dentists.php?branch_id=${branchId}`)
        .then(r => r.json())
        .then(data => {
            _populateSelect('trt_dentist', data, 'dentist_id', 'full_name', '— Select Dentist —', preDentist);
        }).catch(() => _resetSelect('trt_dentist', '— Select Dentist —'));

    // Load services (not branch-specific)
    fetch(`api/services.php`)
        .then(r => r.json())
        .then(data => {
            _populateSelect('trt_service', data, 'service_id', 'service_name', '— Select Service —', preService);
            // Store prices for auto-fill
            window._trtServices = data;
        }).catch(() => _resetSelect('trt_service', '— Select Service —'));
}

function autoFillCost() {
    const serviceId = document.getElementById('trt_service').value;
    if (!serviceId || !window._trtServices) return;
    const svc = window._trtServices.find(s => String(s.service_id) === String(serviceId));
    if (svc && svc.price) {
        document.getElementById('trt_cost').value = svc.price;
    }
}

// ── Save (Add / Edit) ───────────────────────────────────────
function saveTreatment() {
    trtHideError();
    const btn = document.getElementById('trtSaveBtn');

    const id        = document.getElementById('trt_id').value.trim();
    const date      = document.getElementById('trt_date').value.trim();
    const branchId  = document.getElementById('trt_branch').value.trim();
    const patientId = document.getElementById('trt_patient').value.trim();
    const dentistId = document.getElementById('trt_dentist').value.trim();
    const serviceId = document.getElementById('trt_service').value.trim();
    const tooth     = document.getElementById('trt_tooth').value.trim();
    const cost      = document.getElementById('trt_cost').value.trim();
    const stage     = document.getElementById('trt_stage').value.trim();
    const status    = document.getElementById('trt_status').value.trim();
    const notes     = document.getElementById('trt_notes').value.trim();

    // Validation
    if (!date)      return trtShowError('Treatment date is required.');
    if (!branchId)  return trtShowError('Please select a branch.');
    if (!patientId) return trtShowError('Please select a patient.');
    if (!dentistId) return trtShowError('Please select a dentist.');
    if (!serviceId) return trtShowError('Please select a service/procedure.');
    if (!status)    return trtShowError('Please select a status.');

    const payload = {
        treatment_date: date,
        branch_id:      branchId,
        patient_id:     patientId,
        dentist_id:     dentistId,
        service_id:     serviceId,
        tooth_number:   tooth   || null,
        cost:           cost    ? parseFloat(cost) : null,
        current_stage:  stage   || null,
        status:         status,
        notes:          notes   || null,
    };

    const isEdit = id !== '';
    const url    = isEdit ? `api/treatments.php?id=${id}` : 'api/treatments.php';
    const method = isEdit ? 'PATCH' : 'POST';

    trtSetLoading(btn, true);

    fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(res => {
        trtSetLoading(btn, false);
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('treatmentModal'))?.hide();
            Swal.fire({
                icon: 'success',
                title: isEdit ? 'Treatment Updated' : 'Treatment Added',
                text: isEdit ? 'Treatment record has been updated.' : 'New treatment has been recorded.',
                timer: 1800,
                showConfirmButton: false,
            }).then(() => location.reload());
        } else {
            trtShowError(res.message || 'An error occurred. Please try again.');
        }
    })
    .catch(() => {
        trtSetLoading(btn, false);
        trtShowError('Network error. Please try again.');
    });
}

// ── Delete ──────────────────────────────────────────────────
function deleteTreatment(id, code) {
    Swal.fire({
        title: 'Delete Treatment?',
        html: `Are you sure you want to delete <strong>${code}</strong>?<br><span class="text-muted small">This action cannot be undone.</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel',
    }).then(result => {
        if (!result.isConfirmed) return;
        fetch(`api/treatments.php?id=${id}`, { method: 'DELETE' })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted',
                        text: `${code} has been deleted.`,
                        timer: 1800,
                        showConfirmButton: false,
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message || 'Could not delete treatment.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
    });
}

// ── Internal helpers ────────────────────────────────────────
function _resetSelect(id, placeholder) {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = `<option value="">${placeholder}</option>`;
}

function _populateSelect(id, data, valKey, labelKey, placeholder, preselect = null) {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = `<option value="">${placeholder}</option>`;
    (data || []).forEach(item => {
        const opt = document.createElement('option');
        opt.value       = item[valKey];
        opt.textContent = item[labelKey];
        if (preselect && String(item[valKey]) === String(preselect)) opt.selected = true;
        el.appendChild(opt);
    });
}