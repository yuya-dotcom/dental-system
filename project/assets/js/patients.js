/* ── View Details Modal ────────────────────────────────────────── */
function viewPatient(code, name, contact, birth, gender, branch, lastVisit, status) {
    document.getElementById('pd_code').textContent    = code      || '—';
    document.getElementById('pd_name').textContent    = name      || '—';
    document.getElementById('pd_contact').textContent = contact   || '—';
    document.getElementById('pd_birth').textContent   = birth     || '—';
    document.getElementById('pd_gender').textContent  = gender    || '—';
    document.getElementById('pd_branch').textContent  = branch    || '—';
    document.getElementById('pd_visit').textContent   = lastVisit || '—';
    document.getElementById('pd_status').textContent  = status    || '—';
    new bootstrap.Modal(document.getElementById('patientViewModal')).show();
}


/* ── Open Add Modal ────────────────────────────────────────────── */
function openAddPatientModal() {
    document.getElementById('patModalTitle').textContent  = 'Add Patient';
    document.getElementById('pat_id').value               = '';
    document.getElementById('pat_name').value             = '';
    document.getElementById('pat_contact').value          = '';
    document.getElementById('pat_gender').value           = '';
    document.getElementById('pat_birthdate').value        = '';
    document.getElementById('pat_branch').value           = '';
    document.getElementById('pat_last_visit').value       = '';
    document.getElementById('pat_status').value           = 'active';
    document.getElementById('patModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('patientModal')).show();
}


/* ── Open Edit Modal ───────────────────────────────────────────── */
function openEditPatientModal(pat) {
    document.getElementById('patModalTitle').textContent  = 'Edit Patient';
    document.getElementById('pat_id').value               = pat.patient_id;
    document.getElementById('pat_name').value             = pat.full_name      || '';
    document.getElementById('pat_contact').value          = pat.contact_number || '';
    document.getElementById('pat_gender').value           = pat.gender         || '';
    document.getElementById('pat_birthdate').value        = pat.birthdate      || '';
    document.getElementById('pat_branch').value           = pat.branch_id      || '';
    document.getElementById('pat_last_visit').value       = pat.last_visit     || '';
    document.getElementById('pat_status').value           = pat.status         || 'active';
    document.getElementById('patModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('patientModal')).show();
}


/* ── Save Patient (Add or Edit) ────────────────────────────────── */
function savePatient() {
    const id      = document.getElementById('pat_id').value;
    const errBox  = document.getElementById('patModalError');
    const btn     = document.getElementById('patSaveBtn');
    const payload = {
        action:         id ? 'edit' : 'add',
        patient_id:     id,
        full_name:      document.getElementById('pat_name').value.trim(),
        contact_number: document.getElementById('pat_contact').value.trim(),
        gender:         document.getElementById('pat_gender').value,
        birthdate:      document.getElementById('pat_birthdate').value,
        branch_id:      document.getElementById('pat_branch').value,
        last_visit:     document.getElementById('pat_last_visit').value,
        status:         document.getElementById('pat_status').value,
    };

    if (!payload.full_name) {
        errBox.textContent   = 'Full name is required.';
        errBox.style.display = 'block';
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/patient_crud.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Saved!', text: d.message, timer: 1600, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            errBox.textContent   = d.message;
            errBox.style.display = 'block';
            btn.disabled         = false;
            btn.innerHTML        = '<i class="feather-save me-1"></i> Save';
        }
    })
    .catch(() => {
        errBox.textContent   = 'Could not connect to server.';
        errBox.style.display = 'block';
        btn.disabled         = false;
        btn.innerHTML        = '<i class="feather-save me-1"></i> Save';
    });
}


/* ── Delete Patient ────────────────────────────────────────────── */
function deletePatient(id, name) {
    confirmDelete('api/patient_crud.php', { patient_id: id }, name, 'Patient');
}