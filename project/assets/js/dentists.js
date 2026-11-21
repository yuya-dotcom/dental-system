/* =============================================================
   EssenciaSmile Admin — Dentists JS
   File: assets/js/dentists.js
   Page: dentist-records.php   API: api/dentist_crud.php
   Depends on: admin.js, es-ajax.js (esToast / esRefresh), es-swal.js,
               sweetalert2 v11, custom-select.js, es-records.js (delete alert, info icon)

   Dentists are added from Accounts (a Dentist account creates its
   record), so this page only views, edits and deletes. The name is edited
   as parts; the server joins them as "Dr. First Middle Last Suffix".

   Each table row carries its record as JSON in data-dentist; the row
   actions read it with dntRow(this). All record text is written with
   textContent (never innerHTML) so names can't inject markup.
   ============================================================= */

const DNT_API = 'api/dentist_crud.php';
const DNT_STATUS_LABEL = { active: 'Active', on_leave: 'On Leave', inactive: 'Inactive' };
const DNT_EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** The record of the table row that holds `el`. */
function dntRow(el) {
    const tr = el && el.closest('tr[data-dentist]');
    try { return tr ? JSON.parse(tr.dataset.dentist) : null; } catch (e) { return null; }
}

function _dntEsc(s) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(s ?? '').replace(/[&<>"']/g, c => map[c]);
}

function _dntDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d) ? '—' : d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function _dntToast(msg, type) {
    if (window.esToast) return esToast(msg, type);
    Swal.fire({ icon: type === 'error' ? 'error' : 'success', title: msg, timer: 1800, showConfirmButton: false });
}

function _dntStatusBadge(status) {
    const s = DNT_STATUS_LABEL[status] ? status : 'inactive';
    const b = document.createElement('span');
    b.className = 'rec-status st-' + s;
    b.textContent = DNT_STATUS_LABEL[status] || (status || '—');
    return b;
}

/* ── View Details ──────────────────────────────────────────── */
function viewDentist(dnt) {
    if (!dnt) return;
    const set = (id, v, muted) => {
        const n = document.getElementById(id);
        n.textContent = v || (muted || '—');
        n.classList.toggle('ar-muted', !v && !!muted);
    };
    const av = document.getElementById('dvAvatar');
    av.className = 'rec-avatar rec-head-avatar ' + (dnt.tone || 'c1') + (dnt.status === 'inactive' ? ' is-muted' : '');
    av.textContent = dnt.initials || 'D';

    document.getElementById('dvName').textContent = dnt.full_name || 'Dentist';
    document.getElementById('dvMeta').textContent = 'Dentist · ' + (dnt.branch_name || 'No branch');
    document.getElementById('dvStatus').replaceChildren(_dntStatusBadge(dnt.status));
    set('dvBranch',  dnt.branch_name, 'Unassigned');
    set('dvEmail',   dnt.email, 'Not set');
    set('dvFull',    dnt.full_name);
    set('dvEmail2',  dnt.email, 'Not set');
    set('dvContact', dnt.contact_number, 'Not set');
    set('dvLogin',   dnt.linked ? (dnt.username ? dnt.username + (dnt.login_status && dnt.login_status !== 'active' ? ' (login ' + dnt.login_status + ')' : '') : 'Linked to a staff account') : '', 'No staff login');
    set('dvUpcoming', dnt.upcoming == null ? '' : (dnt.upcoming ? dnt.upcoming + ' booked' : 'None'), 'Not available');
    set('dvCreated', dnt.created_at ? _dntDate(dnt.created_at) : '');
    set('dvUpdated', dnt.updated_at ? _dntDate(dnt.updated_at) : '');

    const modalEl = document.getElementById('dentistViewModal');
    document.getElementById('dvEditBtn').onclick = () => {
        // Open the edit form once the details modal has finished closing
        modalEl.addEventListener('hidden.bs.modal', () => openEditDentistModal(dnt), { once: true });
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    };
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

/* ── Add / Edit form ───────────────────────────────────────── */
function _dntClearErrors() {
    const box = document.getElementById('dntModalError');
    box.style.display = 'none';
    box.textContent = '';
    document.querySelectorAll('#dentistModal .apt-field-err').forEach(n => { n.textContent = ''; });
    document.querySelectorAll('#dentistModal .is-invalid').forEach(n => n.classList.remove('is-invalid'));
}

function _dntFieldError(id, msg) {
    const err = document.querySelector(`#dentistModal [data-err-for="${id}"]`);
    if (err) err.textContent = msg;
    const input = document.getElementById(id);
    if (input) {
        input.classList.add('is-invalid');
        const cs = input.closest('.apt-field')?.querySelector('.modal-cs');
        if (cs) cs.classList.add('is-invalid');
    }
}

function openEditDentistModal(dnt) {
    if (!dnt) return;
    _dntClearErrors();
    document.getElementById('dntModalTitle').textContent = 'Edit Dentist';
    document.getElementById('dntModalSub').textContent   = 'Update the details for ' + (dnt.full_name || 'this dentist') + '.';
    document.getElementById('dnt_id').value      = dnt.dentist_id;
    document.getElementById('dnt_first').value   = dnt.first_name  || '';
    document.getElementById('dnt_middle').value  = dnt.middle_name || '';
    document.getElementById('dnt_last').value    = dnt.last_name   || '';
    document.getElementById('dnt_suffix').value  = dnt.suffix      || '';
    document.getElementById('dnt_age').value     = dnt.age === '' || dnt.age == null ? '' : String(dnt.age);
    document.getElementById('dnt_gender').value  = ['Male', 'Female', 'Non-binary'].includes(dnt.gender) ? dnt.gender : '';
    document.getElementById('dnt_email').value   = dnt.email       || '';

    // +63 is shown in front; the field holds the 10 digits starting with 9
    const digits = recPhoneDigits(dnt.contact_number);
    document.getElementById('dnt_contact').value = digits;
    const oldTel = document.getElementById('dntOldContact');
    const legacyTel = !!dnt.contact_number && !digits;
    oldTel.style.display = legacyTel ? '' : 'none';
    oldTel.textContent   = legacyTel ? 'Saved number: ' + dnt.contact_number + ' — enter a mobile number to replace it.' : '';
    document.getElementById('dnt_branch').value  = dnt.branch_id      || '';
    const st = DNT_STATUS_LABEL[dnt.status] ? dnt.status : 'active';
    document.getElementById('dnt_st_' + st).checked = true;
    document.getElementById('dntLinkedNote').style.display = dnt.linked ? '' : 'none';
    document.getElementById('dntLinkedUser').textContent  = dnt.username ? ' (' + dnt.username + ')' : '';
    const btn = document.getElementById('dntSaveBtn');
    btn.disabled  = false;
    btn.innerHTML = '<i class="feather-save me-1"></i> Save Changes';

    const modalEl = document.getElementById('dentistModal');
    modalEl.addEventListener('shown.bs.modal', () => document.getElementById('dnt_first').focus(), { once: true });
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function _dntValidate(p) {
    let ok = true;
    if (!p.first_name)                       { _dntFieldError('dnt_first', 'Please enter the first name.'); ok = false; }
    if (!p.last_name)                        { _dntFieldError('dnt_last', 'Please enter the last name.'); ok = false; }
    if (!/^\d{1,3}$/.test(p.age))            { _dntFieldError('dnt_age', 'Please enter the age in years.'); ok = false; }
    else if (+p.age < 18 || +p.age > 100)    { _dntFieldError('dnt_age', 'Age must be between 18 and 100.'); ok = false; }
    if (!p.gender)                           { _dntFieldError('dnt_gender', 'Please choose a gender.'); ok = false; }
    if (!p.email)                            { _dntFieldError('dnt_email', 'Please enter an email address.'); ok = false; }
    else if (!DNT_EMAIL_RE.test(p.email) || p.email.length > 120) { _dntFieldError('dnt_email', "That email address doesn't look right."); ok = false; }
    const telErr = recPhoneError(document.getElementById('dnt_contact'), true);
    if (telErr)                              { _dntFieldError('dnt_contact', telErr); ok = false; }
    if (!p.branch_id)                        { _dntFieldError('dnt_branch', 'Please choose a branch.'); ok = false; }
    return ok;
}

function saveDentist(confirmed) {
    const id     = document.getElementById('dnt_id').value;
    const errBox = document.getElementById('dntModalError');
    const btn    = document.getElementById('dntSaveBtn');
    const label  = 'Save Changes';
    const v = elId => document.getElementById(elId).value.trim().replace(/\s+/g, ' ');
    const payload = {
        action:         'edit',
        dentist_id:     id,
        first_name:     v('dnt_first'),
        middle_name:    v('dnt_middle'),
        last_name:      v('dnt_last'),
        suffix:         v('dnt_suffix'),
        age:            v('dnt_age'),
        gender:         v('dnt_gender'),
        email:          v('dnt_email'),
        contact_number: recPhoneValue(document.getElementById('dnt_contact')),
        branch_id:      document.getElementById('dnt_branch').value,
        status:         (document.querySelector('input[name="dnt_status"]:checked') || {}).value || 'active',
        confirm:        confirmed === true,
    };

    _dntClearErrors();
    if (!_dntValidate(payload)) {
        const first = document.querySelector('#dentistModal .is-invalid');
        if (first && first.focus && first.offsetParent !== null) first.focus();
        return;
    }

    const reset = () => { btn.disabled = false; btn.innerHTML = '<i class="feather-save me-1"></i> ' + label; };
    const fail  = msg => { errBox.textContent = msg; errBox.style.display = 'block'; reset(); };
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    fetch(DNT_API, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        if (d.needs_confirm) {
            // Upcoming appointments: ask before taking the dentist off duty / moving them.
            // Primary action on the LEFT, going back on the right.
            reset();
            return Swal.fire({
                icon: 'warning',
                title: 'Upcoming appointments',
                text: d.message,
                showCancelButton: true,
                confirmButtonText: 'Save anyway',
                cancelButtonText: 'Go back',
                buttonsStyling: !window.ES_SWAL_TONE,
                customClass: window.ES_SWAL_TONE ? ES_SWAL_TONE('warn') : undefined,
            }).then(r => { if (r.isConfirmed) saveDentist(true); });
        }
        if (!d.success) return fail(d.message || 'Could not save the dentist.');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('dentistModal')).hide();
        if (d.warning) {
            Swal.fire({ icon: 'warning', title: 'Saved — one thing still pending', text: d.warning,
                buttonsStyling: !window.ES_SWAL_TONE, customClass: window.ES_SWAL_TONE ? ES_SWAL_TONE('warn') : undefined });
        } else {
            _dntToast(d.message || 'Dentist saved.', 'success');
        }
        _esAfterAction();
    })
    .catch(() => fail('Could not connect to the server. Please try again.'));
}

/* ── Delete ────────────────────────────────────────────────── */
// deleteDentist(record)  — or the old signature deleteDentist(id, name)
function deleteDentist(dnt, legacyName) {
    if (dnt == null) return;
    if (typeof dnt !== 'object') dnt = { dentist_id: dnt, full_name: legacyName || '' };
    const name = dnt.full_name || 'this dentist';
    const linkedNote = dnt.linked
        ? '<p class="mb-0 mt-2 small">Their staff login is <strong>not</strong> deleted — manage it in Accounts.</p>'
        : '';
    Swal.fire({
        icon: 'warning',
        title: 'Delete this dentist?',
        html: `<strong>${_dntEsc(name)}</strong> will be permanently removed.`
            + '<p class="mb-0 mt-2 small">If they have appointments or treatments on record, set them to <strong>Inactive</strong> instead to keep that history.</p>'
            + linkedNote,
        showCancelButton: true,
        confirmButtonText: 'Delete Dentist',
        cancelButtonText: 'Keep Dentist',
        buttonsStyling: !window.ES_SWAL_TONE,
        customClass: window.ES_SWAL_TONE ? recDeleteClass() : undefined,
        confirmButtonColor: '#dc3545',
        focusCancel: true,
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch(DNT_API, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', dentist_id: dnt.dentist_id })
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                return Swal.fire({ icon: 'error', title: 'Could not delete', text: d.message || 'Delete failed.',
                    buttonsStyling: !window.ES_SWAL_CLASS, customClass: window.ES_SWAL_CLASS || undefined });
            }
            _dntToast(name + ' was deleted.', 'success');
            _esAfterAction();
        })
        .catch(() => _dntToast('Could not connect to the server. Please try again.', 'error'));
    });
}

// Clear a field's error as soon as it is corrected
document.addEventListener('input', e => {
    if (!e.target.closest || !e.target.closest('#dentistModal')) return;
    e.target.classList.remove('is-invalid');
    const err = document.querySelector(`#dentistModal [data-err-for="${e.target.id}"]`);
    if (err) err.textContent = '';
});
document.addEventListener('change', e => {
    if (!['dnt_branch', 'dnt_gender'].includes(e.target.id) || !e.target.value) return;
    e.target.classList.remove('is-invalid');
    e.target.closest('.apt-field')?.querySelector('.modal-cs')?.classList.remove('is-invalid');
    const err = document.querySelector(`#dentistModal [data-err-for="${e.target.id}"]`);
    if (err) err.textContent = '';
});
// Age: digits only
document.addEventListener('input', e => {
    if (e.target.id !== 'dnt_age') return;
    const d = e.target.value.replace(/\D/g, '').slice(0, 3);
    if (e.target.value !== d) e.target.value = d;
});
