/* =============================================================
   EssenciaSmile Admin — Branches JS
   File: assets/js/branches.js
   Depends on: admin.js, sweetalert2
   ============================================================= */

const BRN_DEFAULT_DAYS = [1, 2, 3, 4, 5, 6]; // Mon–Sat

// Tick the day buttons for a list of weekday numbers (0 = Sun … 6 = Sat)
function setBranchDays(days) {
    const list = Array.isArray(days) && days.length ? days.map(Number) : BRN_DEFAULT_DAYS;
    document.querySelectorAll('.brn-day').forEach(cb => {
        cb.checked = list.includes(Number(cb.value));
    });
}

function getBranchDays() {
    return Array.from(document.querySelectorAll('.brn-day:checked'))
        .map(cb => Number(cb.value))
        .sort((a, b) => a - b);
}

function openAddBranchModal() {
    document.getElementById('brnModalTitle').textContent   = 'Add Branch';
    document.getElementById('brn_id').value                = '';
    document.getElementById('brn_name').value              = '';
    document.getElementById('brn_address').value           = '';
    document.getElementById('brn_contact').value           = '';
    document.getElementById('brn_open').value              = '09:00';
    document.getElementById('brn_close').value             = '17:00';
    document.getElementById('brn_status').value            = 'active';
    setBranchDays(BRN_DEFAULT_DAYS);
    document.getElementById('brnModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('branchModal')).show();
}

function openEditBranchModal(brn) {
    document.getElementById('brnModalTitle').textContent   = 'Edit Branch';
    document.getElementById('brn_id').value                = brn.branch_id;
    document.getElementById('brn_name').value              = brn.branch_name    || '';
    document.getElementById('brn_address').value           = brn.address        || '';
    document.getElementById('brn_contact').value           = brn.contact_number || '';
    document.getElementById('brn_open').value              = brn.open_time      ? brn.open_time.substring(0,5)  : '';
    document.getElementById('brn_close').value             = brn.close_time     ? brn.close_time.substring(0,5) : '';
    document.getElementById('brn_status').value            = brn.status         || 'active';
    setBranchDays(brn.operating_days);
    document.getElementById('brnModalError').style.display = 'none';
    new bootstrap.Modal(document.getElementById('branchModal')).show();
}

function viewBranch(brn) {
    document.getElementById('bv_code').textContent    = brn.branch_code    || '—';
    document.getElementById('bv_name').textContent    = brn.branch_name    || '—';
    document.getElementById('bv_address').textContent = brn.address        || '—';
    document.getElementById('bv_contact').textContent = brn.contact_number || '—';
    const fmt = t => t ? new Date('1970-01-01T' + t).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : '—';
    document.getElementById('bv_days').textContent    = brn.days_label     || '—';
    document.getElementById('bv_hours').textContent   = fmt(brn.open_time) + ' – ' + fmt(brn.close_time);
    document.getElementById('bv_status').textContent  = brn.status ? brn.status.charAt(0).toUpperCase() + brn.status.slice(1) : '—';
    new bootstrap.Modal(document.getElementById('branchViewModal')).show();
}

function saveBranch() {
    const id     = document.getElementById('brn_id').value;
    const errBox = document.getElementById('brnModalError');
    const btn    = document.getElementById('brnSaveBtn');
    const payload = {
        action:         id ? 'edit' : 'add',
        branch_id:      id,
        branch_name:    document.getElementById('brn_name').value.trim(),
        address:        document.getElementById('brn_address').value.trim(),
        contact_number: document.getElementById('brn_contact').value.trim(),
        open_time:      document.getElementById('brn_open').value,
        close_time:     document.getElementById('brn_close').value,
        operating_days: getBranchDays(),
        status:         document.getElementById('brn_status').value,
    };

    errBox.style.display = 'none';
    if (!payload.operating_days.length) {
        errBox.textContent   = 'Please select at least one operating day.';
        errBox.style.display = 'block';
        return;
    }
    if (!payload.open_time || !payload.close_time || payload.close_time <= payload.open_time) {
        errBox.textContent   = 'Close time must be later than open time.';
        errBox.style.display = 'block';
        return;
    }
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/branch_crud.php', {
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

function deleteBranch(id, name) {
    confirmDelete('api/branch_crud.php', { branch_id: id }, name, 'Branch');
}