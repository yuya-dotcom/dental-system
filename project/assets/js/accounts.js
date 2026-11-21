/* =============================================================
   EssenciaSmile Admin — Accounts JS
   File: assets/js/accounts.js
   Page: accounts-records.php   API: api/user_crud.php
   Depends on: admin.js, es-ajax.js (esToast / esRefresh), es-swal.js,
               sweetalert2 v11, custom-select.js, es-records.js (+63 phone,
               delete alert)

   Each table row carries its record as JSON in data-account; the row
   actions read it with accRow(this). All record text is written with
   textContent (never innerHTML) so names can't inject markup.
   ============================================================= */

const ACC_API = 'api/user_crud.php';
const ACC_ROLE_LABEL = { owner: 'Owner', admin: 'Admin', dentist: 'Dentist' };
const ACC_USERNAME_RE = /^[A-Za-z0-9]+$/;   // letters and numbers only
const ACC_EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const ACC_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV'];
const ACC_GENDER_LABEL = { 'Male': 'Male', 'Female': 'Female', 'Non-binary': 'Non-Binary' };

let _accEditing = null;   // record being edited (null when adding)

/** The record of the table row that holds `el`. */
function accRow(el) {
    const tr = el && el.closest('tr[data-account]');
    try { return tr ? JSON.parse(tr.dataset.account) : null; } catch (e) { return null; }
}

function _accEsc(s) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(s ?? '').replace(/[&<>"']/g, c => map[c]);
}

function _accDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return isNaN(d) ? '' : d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function _accToast(msg, type) {
    if (window.esToast) return esToast(msg, type);
    Swal.fire({ icon: type === 'error' ? 'error' : 'success', title: msg, timer: 1800, showConfirmButton: false });
}

function _accBranchText(u) {
    if (u.role === 'owner') return 'All Branches';
    return u.branch_name || '';
}

/* ── Name parts ⇄ "Last, First Middle Suffix" ──────────────── */
function _splitName(full) {
    if (!full) return { last: '', first: '', middle: '', suffix: '' };
    const parts = full.trim().split(/\s+/);
    let suffix = '';
    if (parts.length > 1 && ACC_SUFFIXES.includes(parts[parts.length - 1])) suffix = parts.pop();
    const joined = parts.join(' ');
    if (joined.includes(',')) {
        const i    = joined.indexOf(',');
        const rest = joined.slice(i + 1).trim().split(/\s+/).filter(Boolean);
        return { last: joined.slice(0, i).trim(), first: rest.shift() || '', middle: rest.join(' '), suffix };
    }
    // No comma → assume "First [Middle] Last"
    const last  = parts.pop() || '';
    const first = parts.shift() || '';
    return { last, first, middle: parts.join(' '), suffix };
}

function _buildFullName() {
    const v      = id => document.getElementById(id).value.trim().replace(/\s+/g, ' ');
    const last   = v('usr_last_name');
    const first  = v('usr_first_name');
    const middle = v('usr_middle_name');
    const suffix = v('usr_suffix');
    return ((last + ', ' + first + (middle ? ' ' + middle : '') + (suffix ? ' ' + suffix : '')).trim())
        .replace(/^,\s*/, '').replace(/,\s*$/, '');
}

/* ── View Details ──────────────────────────────────────────── */
function viewUserDetails(u) {
    if (!u) return;
    const set = (id, v, muted) => {
        const n = document.getElementById(id);
        n.textContent = v || (muted || '—');
        n.classList.toggle('ar-muted', !v && !!muted);
    };
    const av = document.getElementById('uvAvatar');
    av.className   = 'rec-avatar rec-head-avatar ' + (u.tone || 'c1') + (u.status === 'inactive' ? ' is-muted' : '');
    av.textContent = u.initials || 'U';

    document.getElementById('uvName').textContent = (u.full_name || 'Account') + (u.is_self ? ' (You)' : '');
    document.getElementById('uvMeta').textContent =
        [ACC_ROLE_LABEL[u.role] || 'Unknown role', _accBranchText(u) || 'No branch'].join(' · ');

    const st = document.createElement('span');
    st.className   = 'rec-status st-' + (u.status === 'inactive' ? 'inactive' : 'active');
    st.textContent = u.status === 'inactive' ? 'Inactive' : 'Active';
    document.getElementById('uvStatus').replaceChildren(st);

    set('uvRole',     ACC_ROLE_LABEL[u.role], 'Unknown');
    set('uvBranch',   _accBranchText(u), 'No branch');
    set('uvCode',     u.user_code, 'Not set');
    set('uvFull',     u.full_name);
    set('uvGender',   ACC_GENDER_LABEL[u.gender] || u.gender, 'Not set');
    set('uvEmail',    u.email, 'Not set');
    set('uvContact',  u.contact_number ? recPhoneFormat(u.contact_number) : '', 'Not set');
    set('uvUsername', u.username, 'Not set');
    set('uvCreated',  _accDate(u.created_at));
    set('uvUpdated',  _accDate(u.updated_at));
    document.getElementById('uvDentistNote').style.display = u.role === 'dentist' ? '' : 'none';

    const modalEl = document.getElementById('userViewModal');
    const handoff = fn => () => {
        // Open the next modal once the details modal has finished closing
        modalEl.addEventListener('hidden.bs.modal', fn, { once: true });
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    };
    document.getElementById('uvEditBtn').onclick  = handoff(() => openEditUserModal(u));
    document.getElementById('uvResetBtn').onclick = handoff(() => openResetPasswordModal(u));
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

/* ── Field errors (shared by both form modals) ─────────────── */
function _accClearErrors(modalId, boxId) {
    const box = document.getElementById(boxId);
    box.style.display = 'none';
    box.textContent   = '';
    document.querySelectorAll(`#${modalId} .apt-field-err`).forEach(n => { n.textContent = ''; });
    document.querySelectorAll(`#${modalId} .is-invalid`).forEach(n => n.classList.remove('is-invalid'));
}

function _accFieldError(modalId, id, msg) {
    const err = document.querySelector(`#${modalId} [data-err-for="${id}"]`);
    if (err) err.textContent = msg;
    const input = document.getElementById(id);
    if (input) {
        input.classList.add('is-invalid');
        input.closest('.apt-field')?.querySelector('.modal-cs')?.classList.add('is-invalid');
    }
}

function _accFocusFirstError(modalId) {
    const first = document.querySelector(`#${modalId} .is-invalid`);
    if (first && first.focus && first.offsetParent !== null) first.focus();
}

/* ── Add / Edit form ───────────────────────────────────────── */
function _accRole()   { return (document.querySelector('input[name="usr_role"]:checked') || {}).value || 'admin'; }
function _accStatus() { return (document.querySelector('input[name="usr_status"]:checked') || {}).value || 'active'; }

/* ── Page access (admin / dentist) ─────────────────────────── */
/** Show only the modules the role can use; owners have no checklist (always full access). */
function _accSyncModules(role) {
    document.getElementById('usrModulesCard').style.display = role === 'owner' ? 'none' : '';
    document.querySelectorAll('#usr_modules .usr-module').forEach(c => {
        c.hidden = !(c.dataset.roles || '').split(',').includes(role);
    });
}

/** null = every module the role allows (nothing restricted); otherwise the ticked keys. */
function _accModulesValue(role) {
    if (role === 'owner') return null;
    const boxes = [...document.querySelectorAll('#usr_modules .usr-module:not([hidden])')];
    const picked = boxes.filter(c => c.checked).map(c => c.value);
    return picked.length === boxes.length ? null : picked;
}

function _accClearModulesError() {
    document.getElementById('usr_modules').classList.remove('is-invalid');
    const err = document.querySelector('#userModal [data-err-for="usr_modules"]');
    if (err) err.textContent = '';
}

/** Show / hide the branch + specialization fields for the chosen role. */
function toggleBranchField() {
    const role  = _accRole();
    const isAdd = !_accEditing;
    document.getElementById('usrBranchRow').style.display         = role === 'owner' ? 'none' : '';
    document.getElementById('usrOwnerHint').style.display         = role === 'owner' ? '' : 'none';
    document.getElementById('usrSpecializationRow').style.display = (role === 'dentist' && isAdd) ? '' : 'none';
    _accSyncModules(role);

    // Explain the link between a Dentist account and its dentist record
    let note = '';
    if (role === 'dentist' && isAdd) {
        note = 'A dentist record is created with this account, so they can be assigned to appointments.';
    } else if (role === 'dentist') {
        note = _accEditing.role === 'dentist'
            ? 'Name, branch and Active/Inactive are copied to their dentist record. Specialization and contact number are edited in Dentist Records.'
            : 'Saving adds (or re-activates) a dentist record for this account.';
    } else if (_accEditing && _accEditing.role === 'dentist') {
        note = 'Changing the role away from Dentist sets their dentist record to Inactive. Their appointment and treatment history is kept.';
    }
    document.getElementById('usrDentistNote').style.display  = note ? '' : 'none';
    document.getElementById('usrDentistNoteText').textContent = note;
}

function _accFillForm(u, isAdd) {
    _accEditing = isAdd ? null : u;
    _accClearErrors('userModal', 'usrModalError');

    document.getElementById('usrModalTitle').textContent    = isAdd ? 'Add Account' : 'Edit Account';
    document.getElementById('usrModalSubtitle').textContent = isAdd
        ? 'Create a sign-in for a staff member.'
        : 'Update the details for ' + (u.full_name || 'this account') + '.';
    document.getElementById('usrModalIcon').className = isAdd ? 'feather-user-plus' : 'feather-user';

    const n = _splitName(u.full_name || '');
    document.getElementById('usr_id').value          = isAdd ? '' : u.user_id;
    document.getElementById('usr_last_name').value   = n.last;
    document.getElementById('usr_first_name').value  = n.first;
    document.getElementById('usr_middle_name').value = n.middle;
    document.getElementById('usr_suffix').value      = n.suffix;
    document.getElementById('usr_gender').value      = ACC_GENDER_LABEL[u.gender] ? u.gender : '';
    document.getElementById('usr_username').value    = u.username || '';
    document.getElementById('usr_email').value       = u.email    || '';
    document.getElementById('usr_contact').value     = recPhoneDigits(u.contact_number);
    // Sidebar access: null / missing = every module the role allows
    const mods = Array.isArray(u.allowed_modules) ? u.allowed_modules : null;
    document.querySelectorAll('#usr_modules .usr-module').forEach(c => { c.checked = mods === null || mods.includes(c.value); });
    document.getElementById('usr_branch').value      = u.branch_id || '';
    document.getElementById('usr_specialization').value = '';
    ['usr_password', 'usr_confirm_password'].forEach(id => {
        const el = document.getElementById(id);
        el.value = ''; el.type = 'password';
    });
    document.querySelectorAll('#userModal .rec-pw-toggle i').forEach(i => { i.className = 'feather-eye'; });
    document.querySelectorAll('#userModal .usr-pw-field').forEach(c => { c.style.display = isAdd ? '' : 'none'; });
    document.getElementById('usrPwEditHint').style.display = isAdd ? 'none' : '';

    const role = ACC_ROLE_LABEL[u.role] ? u.role : 'admin';
    document.getElementById('usr_role_' + role).checked = true;
    document.getElementById('usr_st_' + (u.status === 'inactive' ? 'inactive' : 'active')).checked = true;

    // You can't demote or deactivate yourself (the API refuses it too)
    const self = !isAdd && !!u.is_self;
    document.querySelectorAll('input[name="usr_role"]').forEach(r => { r.disabled = self && !r.checked; });
    document.getElementById('usr_st_inactive').disabled = self;
    document.getElementById('usrSelfNote').style.display = self ? '' : 'none';

    toggleBranchField();
    const btn = document.getElementById('usrSaveBtn');
    btn.disabled  = false;
    btn.innerHTML = '<i class="feather-save me-1"></i> ' + (isAdd ? 'Add Account' : 'Save Changes');

    const modalEl = document.getElementById('userModal');
    modalEl.addEventListener('shown.bs.modal', () => document.getElementById('usr_first_name').focus(), { once: true });
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function openAddUserModal()   { _accFillForm({ role: 'admin', status: 'active' }, true); }
function openEditUserModal(u) { if (u) _accFillForm(u, false); }

function _accValidate(p, isNew) {
    const M = 'userModal';
    // An unchanged username / email isn't re-checked (older accounts may predate the rules)
    const keepUser  = !isNew && _accEditing && p.username === (_accEditing.username || '');
    const keepEmail = !isNew && _accEditing && p.email === (_accEditing.email || '');
    let ok = true;
    if (!document.getElementById('usr_last_name').value.trim())  { _accFieldError(M, 'usr_last_name', 'Please enter the last name.'); ok = false; }
    if (!document.getElementById('usr_first_name').value.trim()) { _accFieldError(M, 'usr_first_name', 'Please enter the first name.'); ok = false; }
    if (!p.gender)                            { _accFieldError(M, 'usr_gender', 'Please choose a gender.'); ok = false; }
    if (!p.username)                          { _accFieldError(M, 'usr_username', 'Please choose a username.'); ok = false; }
    else if (!keepUser && p.username.length < 3)           { _accFieldError(M, 'usr_username', 'Use at least 3 characters.'); ok = false; }
    else if (!keepUser && !ACC_USERNAME_RE.test(p.username)) { _accFieldError(M, 'usr_username', 'Use letters and numbers only — no spaces or special characters.'); ok = false; }
    if (!p.email)                                          { _accFieldError(M, 'usr_email', 'Please enter an email address.'); ok = false; }
    else if (!keepEmail && !ACC_EMAIL_RE.test(p.email))    { _accFieldError(M, 'usr_email', 'That email address doesn\'t look right.'); ok = false; }
    const phoneErr = recPhoneError(document.getElementById('usr_contact'), true);
    if (phoneErr)                             { _accFieldError(M, 'usr_contact', phoneErr); ok = false; }
    if (p.role !== 'owner' && !p.branch_id)   { _accFieldError(M, 'usr_branch', 'Please choose a branch.'); ok = false; }
    if (Array.isArray(p.allowed_modules) && !p.allowed_modules.length) {
        _accFieldError(M, 'usr_modules', 'Tick at least one page this account can open.');
        document.getElementById('usr_modules').classList.add('is-invalid');
        ok = false;
    }
    if (isNew) {
        const pw  = document.getElementById('usr_password').value;
        const pw2 = document.getElementById('usr_confirm_password').value;
        if (pw.length < 6)    { _accFieldError(M, 'usr_password', 'Password must be at least 6 characters.'); ok = false; }
        else if (pw !== pw2)  { _accFieldError(M, 'usr_confirm_password', 'Passwords do not match.'); ok = false; }
    }
    return ok;
}

function saveUser() {
    const id     = document.getElementById('usr_id').value;
    const isNew  = !id;
    const errBox = document.getElementById('usrModalError');
    const btn    = document.getElementById('usrSaveBtn');
    const label  = isNew ? 'Add Account' : 'Save Changes';
    const role   = _accRole();

    const payload = {
        action:    isNew ? 'add' : 'edit',
        user_id:   id,
        full_name: _buildFullName(),
        email:     document.getElementById('usr_email').value.trim(),
        username:  document.getElementById('usr_username').value.trim(),
        role:      role,
        branch_id: role === 'owner' ? '' : document.getElementById('usr_branch').value,
        contact_number: recPhoneValue(document.getElementById('usr_contact')),
        gender:    document.getElementById('usr_gender').value,
        status:    _accStatus(),
        allowed_modules: _accModulesValue(role),
    };
    if (isNew) {
        payload.password = document.getElementById('usr_password').value;
        if (role === 'dentist') payload.specialization = document.getElementById('usr_specialization').value.trim();
    }

    _accClearErrors('userModal', 'usrModalError');
    if (!_accValidate(payload, isNew)) return _accFocusFirstError('userModal');

    const reset = () => { btn.disabled = false; btn.innerHTML = '<i class="feather-save me-1"></i> ' + label; };
    const fail  = msg => {
        // Put duplicate-username / email messages next to their field
        if (/username/i.test(msg))   _accFieldError('userModal', 'usr_username', msg);
        else if (/email/i.test(msg)) _accFieldError('userModal', 'usr_email', msg);
        else { errBox.textContent = msg; errBox.style.display = 'block'; }
        reset();
        _accFocusFirstError('userModal');
    };
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    fetch(ACC_API, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) return fail(d.message || 'Could not save the account.');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).hide();
        if (d.warning) {
            // Saved, but something needs the database update first — say so plainly
            Swal.fire({ icon: 'warning', title: 'Saved — one thing still pending', text: d.warning,
                buttonsStyling: !window.ES_SWAL_TONE, customClass: window.ES_SWAL_TONE ? ES_SWAL_TONE('warn') : undefined });
        } else if (!isNew) {
            // Edit Account saved: a success alert (Add Account keeps the toast)
            Swal.fire({ icon: 'success', iconHtml: '<i class="feather-check es-swal-glyph"></i>', title: 'Account Updated',
                text: (payload.full_name || 'The account') + "'s details were saved.",
                confirmButtonText: 'OK',
                buttonsStyling: !window.ES_SWAL_TONE, customClass: window.ES_SWAL_TONE ? ES_SWAL_TONE('success') : undefined });
        } else {
            _accToast(d.message || 'Account saved.', 'success');
        }
        _esAfterAction();
    })
    .catch(() => fail('Could not connect to the server. Please try again.'));
}

/* ── Reset password ────────────────────────────────────────── */
// Another account (admin / dentist / other owner): the signed-in owner first
// re-enters THEIR OWN password (api/user_crud.php verify_owner_password →
// a 10-minute server grant for that one account), then sets the new one.
// Your own account: straight to Change Password, with your current password.
let _accResetTarget = null;

/** Owner confirms their own password. Resolves true only after the server accepts it. */
async function _accVerifyOwner(u) {
    const r = await Swal.fire({
        icon: 'question',
        iconHtml: '<i class="feather-lock es-swal-glyph"></i>',
        title: 'Confirm Your Password',
        html: '<p class="es-swal-text">For security, enter <strong>your own owner password</strong> to reset the password of <strong>'
            + _accEsc(u.full_name || 'this account') + '</strong>.</p>',
        input: 'password',
        inputPlaceholder: 'Your password',
        inputAttributes: { autocomplete: 'current-password', 'aria-label': 'Your account password', autocapitalize: 'off', autocorrect: 'off', maxlength: '200' },
        showCancelButton: true,
        confirmButtonText: '<i class="feather-unlock"></i> Verify &amp; Continue',
        cancelButtonText: 'Cancel',
        reverseButtons: false,          // primary left, Cancel right
        focusConfirm: false,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        buttonsStyling: false,
        customClass: Object.assign({}, window.ES_SWAL_CLASS || {}, {
            popup: 'es-swal-popup es-swal-popup-pw',
            input: 'es-swal-input',
            validationMessage: 'es-swal-validation',
            actions: 'es-swal-actions es-swal-actions-compact',
        }),
        preConfirm: async (pw) => {
            if (!pw) { Swal.showValidationMessage('Please enter your password.'); return false; }
            let res, j;
            try {
                res = await fetch(ACC_API, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'verify_owner_password', user_id: u.user_id, password: pw }),
                });
                j = await res.json().catch(() => ({}));
            } catch (e) {
                Swal.showValidationMessage("We couldn't reach the server. Please check your connection and try again.");
                return false;
            }
            if (res.status === 401) { location.href = 'login.php'; return false; }
            if (!j.success) {
                const inp = Swal.getInput(); if (inp) { inp.value = ''; inp.focus(); }
                Swal.showValidationMessage(j.message || 'Your password could not be verified.');
                return false;
            }
            return true;
        },
    });
    return r.isConfirmed && r.value === true;
}

// openResetPasswordModal(record) — or the old signature (id, name)
async function openResetPasswordModal(u, legacyName) {
    if (u == null) return;
    if (typeof u !== 'object') u = { user_id: u, full_name: legacyName || '' };
    const self = !!u.is_self;
    if (!self && !(await _accVerifyOwner(u))) return;

    _accResetTarget = u;
    _accClearErrors('resetPasswordModal', 'resetPwError');
    document.getElementById('reset_user_id').value          = u.user_id;
    document.getElementById('resetPasswordFor').textContent = self ? 'your own account' : (u.full_name || 'this account');
    document.getElementById('rpTitle').textContent          = self ? 'Change Password' : 'Reset Password';
    document.getElementById('rpCurrentRow').style.display  = self ? '' : 'none';
    document.getElementById('rpHint').textContent = self
        ? 'You stay signed in. Use the new password the next time you sign in.'
        : 'Share the new password with them privately. They can sign in with it right away.';
    ['reset_current_password', 'reset_new_password', 'reset_confirm_password'].forEach(id => {
        const el = document.getElementById(id);
        el.value = ''; el.type = 'password';
    });
    document.querySelectorAll('#resetPasswordModal .rec-pw-toggle i').forEach(i => { i.className = 'feather-eye'; });
    const btn = document.getElementById('resetPwSaveBtn');
    btn.disabled  = false;
    btn.innerHTML = '<i class="feather-key me-1"></i> ' + (self ? 'Change Password' : 'Reset Password');

    const modalEl = document.getElementById('resetPasswordModal');
    modalEl.addEventListener('shown.bs.modal',
        () => document.getElementById(self ? 'reset_current_password' : 'reset_new_password').focus(), { once: true });
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function togglePassword(fieldId, btn) {
    const input = document.getElementById(fieldId);
    const show  = input.type === 'password';
    input.type  = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'feather-eye-off' : 'feather-eye';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}

function saveResetPassword() {
    const M       = 'resetPasswordModal';
    const u       = _accResetTarget || {};
    const self    = !!u.is_self;
    const userId  = document.getElementById('reset_user_id').value;
    const current = document.getElementById('reset_current_password').value;
    const newPass = document.getElementById('reset_new_password').value;
    const confirm = document.getElementById('reset_confirm_password').value;
    const errBox  = document.getElementById('resetPwError');
    const btn     = document.getElementById('resetPwSaveBtn');
    const label   = self ? 'Change Password' : 'Reset Password';

    _accClearErrors(M, 'resetPwError');
    if (self && !current)         _accFieldError(M, 'reset_current_password', 'Please enter your current password.');
    if (newPass.length < 6)       _accFieldError(M, 'reset_new_password', 'Password must be at least 6 characters.');
    else if (newPass !== confirm) _accFieldError(M, 'reset_confirm_password', 'Passwords do not match.');
    if (document.querySelector(`#${M} .is-invalid`)) return _accFocusFirstError(M);

    const reset = () => { btn.disabled = false; btn.innerHTML = '<i class="feather-key me-1"></i> ' + label; };
    const fail  = (msg, field) => {
        if (field) _accFieldError(M, 'reset_' + field, msg);
        else { errBox.textContent = msg; errBox.style.display = 'block'; }
        reset();
        _accFocusFirstError(M);
    };
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    const body = { action: 'reset_password', user_id: userId, new_password: newPass };
    if (self) body.current_password = current;
    fetch(ACC_API, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    })
    .then(r => r.json())
    .then(async d => {
        if (d.code === 'NEEDS_VERIFY') {
            // The 10-minute confirmation ran out: confirm again, then retry once
            reset();
            if (await _accVerifyOwner(u)) saveResetPassword();
            return;
        }
        if (!d.success) {
            if (self && d.field === 'current_password') document.getElementById('reset_current_password').value = '';
            return fail(d.message || 'Could not change the password.', d.field);
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById(M)).hide();
        _accToast(d.message || 'Password changed.', 'success');
        _esAfterAction();
    })
    .catch(() => fail('Could not connect to the server. Please try again.'));
}

/* ── Delete ────────────────────────────────────────────────── */
// deleteUser(record) — or the old signature deleteUser(id, name)
function deleteUser(u, legacyName) {
    if (u == null) return;
    if (typeof u !== 'object') u = { user_id: u, full_name: legacyName || '' };
    const name = u.full_name || 'this account';
    const dentistNote = u.role === 'dentist'
        ? '<p class="mb-0 mt-2 small">Their dentist record is set to <strong>Inactive</strong> — appointment and treatment history is kept.</p>'
        : '';
    Swal.fire({
        icon: 'warning',
        title: 'Delete this account?',
        html: `<strong>${_accEsc(name)}</strong> will no longer be able to sign in. This can't be undone.`
            + '<p class="mb-0 mt-2 small">To block sign-in but keep the account, set it to <strong>Inactive</strong> instead.</p>'
            + dentistNote,
        showCancelButton: true,
        confirmButtonText: 'Delete Account',
        cancelButtonText: 'Keep Account',
        buttonsStyling: !window.ES_SWAL_TONE,
        customClass: window.ES_SWAL_TONE ? recDeleteClass() : undefined,
        confirmButtonColor: '#dc3545',
        focusCancel: true,
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch(ACC_API, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', user_id: u.user_id })
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                return Swal.fire({ icon: 'error', title: 'Could not delete', text: d.message || 'Delete failed.',
                    buttonsStyling: !window.ES_SWAL_CLASS, customClass: window.ES_SWAL_CLASS || undefined });
            }
            _accToast(name + ' was deleted.', 'success');
            _esAfterAction();
        })
        .catch(() => _accToast('Could not connect to the server. Please try again.', 'error'));
    });
}

/* ── Live feedback ─────────────────────────────────────────── */
// Clear a field's error as soon as it is corrected
document.addEventListener('input', e => {
    const modal = e.target.closest && e.target.closest('#userModal, #resetPasswordModal');
    if (!modal) return;
    e.target.classList.remove('is-invalid');
    const err = modal.querySelector(`[data-err-for="${e.target.id}"]`);
    if (err) err.textContent = '';
});
document.addEventListener('click', e => {
    const b = e.target.closest && e.target.closest('#usrModulesCard [data-mods]');
    if (!b) return;
    document.querySelectorAll('#usr_modules .usr-module:not([hidden])').forEach(c => { c.checked = b.dataset.mods === 'all'; });
    _accClearModulesError();
});
document.addEventListener('change', e => {
    if (e.target.name === 'usr_role') return toggleBranchField();
    if (e.target.classList && e.target.classList.contains('usr-module')) return _accClearModulesError();
    if (!['usr_branch', 'usr_gender'].includes(e.target.id) || !e.target.value) return;
    e.target.classList.remove('is-invalid');
    e.target.closest('.apt-field')?.querySelector('.modal-cs')?.classList.remove('is-invalid');
    const err = document.querySelector(`#userModal [data-err-for="${e.target.id}"]`);
    if (err) err.textContent = '';
});
