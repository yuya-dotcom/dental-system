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
/* ── Open Edit Modal (Patients List → Action → Edit) ── */
function editPatient(p) {
    document.getElementById('patModalTitle').textContent    = 'Edit Patient';
    document.getElementById('pat_id').value                = p.patient_id;
    document.getElementById('pat_name').value              = p.full_name       || '';
    document.getElementById('pat_contact').value           = p.contact_number  || '';
    // Keep a stored value that isn't one of the current options instead of blanking it
    const genderSel = document.getElementById('pat_gender');
    if (p.gender && ![...genderSel.options].some(o => o.value === p.gender)) genderSel.add(new Option(p.gender, p.gender));
    genderSel.value = p.gender || '';
    document.getElementById('pat_birthdate').value         = p.birthdate       || '';
    document.getElementById('pat_last_visit').value        = p.last_visit      || '';
    document.getElementById('pat_branch').value            = p.branch_id       || '';
    document.getElementById('pat_status').value            = p.status          || 'active';
    document.getElementById('patModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('patientModal')).show();
}

/* ── Delete Patient ────────────────────────────────────────────── */
function deletePatient(id, name) {
    confirmDelete('api/patient_crud.php', { patient_id: id }, name, 'Patient');
}