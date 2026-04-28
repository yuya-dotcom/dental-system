/* =============================================================
   EssenciaSmile Admin — User Management JS
   File: assets/js/accounts.js
   Depends on: admin.js, sweetalert2
   ============================================================= */

function toggleBranchField() {
    const role      = document.getElementById('usr_role').value;
    const branchRow = document.getElementById('usrBranchRow');
    if (branchRow) branchRow.style.display = (role === 'owner') ? 'none' : '';
}

function openAddUserModal() {
    document.getElementById('usrModalTitle').textContent    = 'Add Account';
    document.getElementById('usrModalSubtitle').textContent = 'Create a new system user.';
    document.getElementById('usr_id').value                 = '';
    document.getElementById('usr_name').value               = '';
    document.getElementById('usr_email').value              = '';
    document.getElementById('usr_username').value           = '';
    document.getElementById('usr_role').value               = 'admin';
    document.getElementById('usr_branch').value             = '';
    document.getElementById('usr_status').value             = 'active';
    document.getElementById('usrModalError').style.display  = 'none';
    document.getElementById('usrPasswordSection').style.display = '';
    document.getElementById('usr_password').value           = '';
    document.getElementById('usr_password').required        = true;
    toggleBranchField();
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function openEditUserModal(usr) {
    document.getElementById('usrModalTitle').textContent    = 'Edit Account';
    document.getElementById('usrModalSubtitle').textContent = 'Update user details below.';
    document.getElementById('usr_id').value                 = usr.user_id;
    document.getElementById('usr_name').value               = usr.full_name  || '';
    document.getElementById('usr_email').value              = usr.email      || '';
    document.getElementById('usr_username').value           = usr.username   || '';
    document.getElementById('usr_role').value               = usr.role       || 'admin';
    document.getElementById('usr_branch').value             = usr.branch_id  || '';
    document.getElementById('usr_status').value             = usr.status     || 'active';
    document.getElementById('usrModalError').style.display  = 'none';
    document.getElementById('usrPasswordSection').style.display = 'none';
    document.getElementById('usr_password').required        = false;
    toggleBranchField();
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function saveUser() {
    const id     = document.getElementById('usr_id').value;
    const errBox = document.getElementById('usrModalError');
    const btn    = document.getElementById('usrSaveBtn');
    const payload = {
        action:    id ? 'edit' : 'add',
        user_id:   id,
        full_name: document.getElementById('usr_name').value.trim(),
        email:     document.getElementById('usr_email').value.trim(),
        username:  document.getElementById('usr_username').value.trim(),
        role:      document.getElementById('usr_role').value,
        branch_id: document.getElementById('usr_branch').value,
        status:    document.getElementById('usr_status').value,
    };
    if (!id) payload.password = document.getElementById('usr_password').value;

    errBox.style.display = 'none';
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/user_crud.php', {
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

function openResetPasswordModal(userId, userName) {
    document.getElementById('reset_user_id').value          = userId;
    document.getElementById('resetPasswordFor').textContent = userName;
    document.getElementById('reset_new_password').value     = '';
    document.getElementById('resetPwError').style.display   = 'none';
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

function saveResetPassword() {
    const userId  = document.getElementById('reset_user_id').value;
    const newPass = document.getElementById('reset_new_password').value;
    const errBox  = document.getElementById('resetPwError');
    const btn     = document.getElementById('resetPwSaveBtn');

    if (!newPass || newPass.length < 6) {
        errBox.textContent   = 'Password must be at least 6 characters.';
        errBox.style.display = 'block';
        return;
    }
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('api/user_crud.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reset_password', user_id: userId, new_password: newPass })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            Swal.fire({ icon: 'success', title: 'Done!', text: d.message, timer: 1600, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            errBox.textContent   = d.message;
            errBox.style.display = 'block';
            btn.disabled         = false;
            btn.innerHTML        = '<i class="feather-key me-1"></i> Reset Password';
        }
    })
    .catch(() => {
        errBox.textContent   = 'Could not connect to server.';
        errBox.style.display = 'block';
        btn.disabled         = false;
        btn.innerHTML        = '<i class="feather-key me-1"></i> Reset Password';
    });
}

function deleteUser(id, name) {
    confirmDelete('api/user_crud.php', { user_id: id }, name, 'Account');
}