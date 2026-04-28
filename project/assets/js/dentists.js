/* =============================================================
   EssenciaSmile Admin — Dentists JS
   File: assets/js/dentists.js
   Depends on: admin.js, sweetalert2
   ============================================================= */

function openAddDentistModal() {
    document.getElementById('dntModalTitle').textContent   = 'Add Dentist';
    document.getElementById('dnt_id').value                = '';
    document.getElementById('dnt_name').value              = '';
    document.getElementById('dnt_spec').value              = '';
    document.getElementById('dnt_contact').value           = '';
    document.getElementById('dnt_branch').value            = '';
    document.getElementById('dnt_status').value            = 'active';
    document.getElementById('dntModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('dentistModal')).show();
}

function openEditDentistModal(dnt) {
    document.getElementById('dntModalTitle').textContent   = 'Edit Dentist';
    document.getElementById('dnt_id').value                = dnt.dentist_id;
    document.getElementById('dnt_name').value              = dnt.full_name      || '';
    document.getElementById('dnt_spec').value              = dnt.specialization || '';
    document.getElementById('dnt_contact').value           = dnt.contact_number || '';
    document.getElementById('dnt_branch').value            = dnt.branch_id      || '';
    document.getElementById('dnt_status').value            = dnt.status         || 'active';
    document.getElementById('dntModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('dentistModal')).show();
}

function viewDentist(dnt) {
    document.getElementById('dv_name').textContent    = dnt.full_name      || '—';
    document.getElementById('dv_spec').textContent    = dnt.specialization || '—';
    document.getElementById('dv_contact').textContent = dnt.contact_number || '—';
    document.getElementById('dv_branch').textContent  = dnt.branch_name    || '—';
    document.getElementById('dv_status').textContent  = dnt.status ? dnt.status.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase()) : '—';
    new bootstrap.Modal(document.getElementById('dentistViewModal')).show();
}

function saveDentist() {
    const id     = document.getElementById('dnt_id').value;
    const errBox = document.getElementById('dntModalError');
    const btn    = document.getElementById('dntSaveBtn');
    const payload = {
        action:         id ? 'edit' : 'add',
        dentist_id:     id,
        full_name:      document.getElementById('dnt_name').value.trim(),
        specialization: document.getElementById('dnt_spec').value.trim(),
        contact_number: document.getElementById('dnt_contact').value.trim(),
        branch_id:      document.getElementById('dnt_branch').value,
        status:         document.getElementById('dnt_status').value,
    };

    errBox.style.display = 'none';
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/dentist_crud.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
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

function deleteDentist(id, name) {
    confirmDelete('api/dentist_crud.php', { dentist_id: id }, name, 'Dentist');
}