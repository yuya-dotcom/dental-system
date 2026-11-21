/* =============================================================
   EssenciaSmile Admin — Branches JS
   File: assets/js/branches.js
   Page: branches-records.php   API: api/branch_crud.php
   Depends on: admin.js, es-ajax.js (esToast / esRefresh), es-swal.js,
               sweetalert2 v11, custom-select.js

   Each table row carries its record as JSON in data-branch; the row
   actions read it with brnRow(this). Record text is written with
   textContent or escaped, never injected as raw HTML.
   ============================================================= */

const BRN_API          = 'api/branch_crud.php';
// Province → City / Municipality → Barangay (assets/js/ph-address.js, same data as the Patient Portal)
const brnAddr = PhAddress.attach({
    province: document.getElementById('brn_province'),
    city:     document.getElementById('brn_city'),
    barangay: document.getElementById('brn_barangay'),
});
const BRN_DEFAULT_DAYS = [1, 2, 3, 4, 5, 6]; // Mon–Sat
const BRN_WEEK         = [1, 2, 3, 4, 5, 6, 0]; // Monday first
const BRN_DAY_SHORT    = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const BX_DAY_NAMES     = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** The record of the table row that holds `el`. */
function brnRow(el) {
    const tr = el && el.closest('tr[data-branch]');
    try { return tr ? JSON.parse(tr.dataset.branch) : null; } catch (e) { return null; }
}

function _bxEsc(s) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(s ?? '').replace(/[&<>"']/g, c => map[c]);
}

function _brnToast(msg, type) {
    if (window.esToast) return esToast(msg, type);
    Swal.fire({ icon: type === 'error' ? 'error' : 'success', title: msg, timer: 1800, showConfirmButton: false });
}

/** "09:00:00" → "9:00 AM" */
function _brnTime(t) {
    if (!t) return '';
    const [h, m] = String(t).split(':').map(Number);
    if (isNaN(h)) return '';
    return ((h % 12) || 12) + ':' + String(m || 0).padStart(2, '0') + ' ' + (h < 12 ? 'AM' : 'PM');
}

function _brnDate(iso, opts) {
    if (!iso) return '—';
    const d = new Date(/^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso + 'T00:00:00' : iso);
    return isNaN(d) ? '—' : d.toLocaleDateString('en-PH', opts || { year: 'numeric', month: 'short', day: 'numeric' });
}

/** Same rules as formatOperatingDays() in branch_controller.php: [1..6] → "Mon–Sat". */
function _brnDaysLabel(days) {
    const set = new Set((days || []).map(Number));
    if (!set.size) return '—';
    if (set.size === 7) return 'Every day';
    const parts = [];
    let run = [];
    const flush = () => {
        if (!run.length) return;
        parts.push(run.length >= 3 ? BRN_DAY_SHORT[run[0]] + '–' + BRN_DAY_SHORT[run[run.length - 1]] : run.map(d => BRN_DAY_SHORT[d]).join(', '));
        run = [];
    };
    BRN_WEEK.forEach(d => { if (set.has(d)) run.push(d); else flush(); });
    flush();
    return parts.join(', ');
}

/* ── Day toggles ───────────────────────────────────────────── */
// Tick the day buttons for a list of weekday numbers (0 = Sun … 6 = Sat)
function setBranchDays(days) {
    const list = Array.isArray(days) && days.length ? days.map(Number) : BRN_DEFAULT_DAYS;
    document.querySelectorAll('.brn-day').forEach(cb => { cb.checked = list.includes(Number(cb.value)); });
    _brnPreview();
}

function getBranchDays() {
    return Array.from(document.querySelectorAll('.brn-day:checked'))
        .map(cb => Number(cb.value))
        .sort((a, b) => a - b);
}

/** Live one-line summary under the schedule fields. */
function _brnPreview() {
    const box = document.getElementById('brnSchedPreview');
    if (!box) return;
    const open  = document.getElementById('brn_open').value;
    const close = document.getElementById('brn_close').value;
    const days  = getBranchDays();
    let text, bad = false;
    if (!days.length)                     { text = 'Choose at least one open day.'; bad = true; }
    else if (!open || !close)             { text = 'Set the opening and closing time.'; bad = true; }
    else if (close <= open)               { text = 'Closing time must be later than opening time.'; bad = true; }
    else {
        const [oh, om] = open.split(':').map(Number), [ch, cm] = close.split(':').map(Number);
        const mins = (ch * 60 + cm) - (oh * 60 + om);
        const hrs  = Math.floor(mins / 60), rest = mins % 60;
        text = `Open ${_brnDaysLabel(days)}, ${_brnTime(open)} – ${_brnTime(close)}`
             + ` · ${hrs ? hrs + ' hr' + (hrs === 1 ? '' : 's') : ''}${hrs && rest ? ' ' : ''}${rest ? rest + ' min' : ''} a day`;
    }
    box.classList.toggle('is-bad', bad);
    box.querySelector('i').className = bad ? 'feather-alert-circle' : 'feather-check-circle';
    box.querySelector('span').textContent = text;
}

/* ── View Details ──────────────────────────────────────────── */
function viewBranch(brn) {
    if (!brn) return;
    const set = (id, v, muted) => {
        const n = document.getElementById(id);
        n.textContent = v || (muted || '—');
        n.classList.toggle('ar-muted', !v && !!muted);
    };
    document.getElementById('bvName').textContent  = brn.branch_name || 'Branch';
    document.getElementById('bvCode').textContent  = brn.branch_code || '—';
    document.getElementById('bvToday').textContent = brn.today_label || '';

    const st = document.createElement('span');
    st.className = 'rec-status st-' + (brn.status === 'active' ? 'active' : 'inactive');
    st.textContent = brn.status === 'active' ? 'Active' : 'Inactive';
    document.getElementById('bvStatus').replaceChildren(st);

    set('bvDays',    brn.days_label);
    set('bvHours',   (brn.open_time && brn.close_time) ? _brnTime(brn.open_time) + ' – ' + _brnTime(brn.close_time) : '', 'Not set');
    set('bvAddress', brn.address, 'Not set');
    set('bvContact', brn.contact_number ? recPhoneFormat(brn.contact_number) : '', 'Not set');
    set('bvCode2',   brn.branch_code);
    set('bvCreated', brn.created_at ? _brnDate(brn.created_at) : '');
    set('bvUpdated', brn.updated_at ? _brnDate(brn.updated_at) : '');
    set('bvUpcoming', brn.upcoming == null ? '' : (brn.upcoming ? brn.upcoming + ' booked' : 'None'), 'Not available');

    // Weekly schedule, Monday first, today marked
    const days  = (brn.operating_days || []).map(Number);
    const hours = (brn.open_time && brn.close_time) ? _brnTime(brn.open_time) + ' – ' + _brnTime(brn.close_time) : 'Open';
    const week  = document.getElementById('bvWeek');
    week.replaceChildren(...BRN_WEEK.map(d => {
        const li = document.createElement('li');
        if (window.BRN_TODAY && Number(BRN_TODAY.dow) === d) li.classList.add('is-today');
        const name = document.createElement('span'); name.className = 'rec-week-day'; name.textContent = BX_DAY_NAMES[d];
        const val  = document.createElement('span');
        if (days.includes(d)) val.textContent = hours; else { val.textContent = 'Closed'; val.className = 'is-closed'; }
        li.append(name, val);
        return li;
    }));

    // Upcoming exceptions
    const exc = document.getElementById('bvExc');
    if (!brn.exc_ready) {
        exc.innerHTML = '<li class="rec-empty-line">Schedule exceptions aren\'t set up yet.</li>';
    } else if (!brn.exceptions || !brn.exceptions.length) {
        exc.innerHTML = '<li class="rec-empty-line">None coming up — the weekly schedule applies to every date.</li>';
    } else {
        exc.innerHTML = brn.exceptions.map(x => `
            <li>
                <span><span class="ar-strong">${_bxEsc(_bxDateLabel(x.date))}</span>
                    ${x.reason ? `<span class="rec-exc-reason">${_bxEsc(x.reason)}</span>` : ''}</span>
                <span class="rec-status ${x.is_open ? 'st-active' : 'st-inactive'}">${x.is_open ? 'Open' : 'Closed'}</span>
            </li>`).join('')
            + (brn.exc_count > brn.exceptions.length ? `<li class="rec-empty-line">and ${brn.exc_count - brn.exceptions.length} more…</li>` : '');
    }

    const modalEl = document.getElementById('branchViewModal');
    const then = fn => {
        modalEl.addEventListener('hidden.bs.modal', fn, { once: true });
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    };
    document.getElementById('bvEditBtn').onclick = () => then(() => openEditBranchModal(brn));
    document.getElementById('bvExcBtn').onclick  = () => then(() => openBranchExceptions(brn));
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

/* ── Add / Edit form ───────────────────────────────────────── */
function _brnClearErrors() {
    const box = document.getElementById('brnModalError');
    box.style.display = 'none';
    box.textContent = '';
    document.querySelectorAll('#branchModal .apt-field-err').forEach(n => { n.textContent = ''; });
    document.querySelectorAll('#branchModal .is-invalid').forEach(n => n.classList.remove('is-invalid'));
}

function _brnFieldError(id, msg) {
    const err = document.querySelector(`#branchModal [data-err-for="${id}"]`);
    if (err) err.textContent = msg;
    const input = document.getElementById(id);
    if (input) input.classList.add('is-invalid');
}

function _brnFillForm(brn, isAdd) {
    _brnClearErrors();
    document.getElementById('brnModalTitle').textContent = isAdd ? 'Add Branch' : 'Edit Branch';
    document.getElementById('brnModalSub').textContent   = isAdd
        ? 'A branch code is assigned automatically.'
        : (brn.branch_code ? brn.branch_code + ' · ' : '') + 'Update the details for ' + (brn.branch_name || 'this branch') + '.';
    document.getElementById('brn_id').value      = isAdd ? '' : brn.branch_id;
    document.getElementById('brn_name').value    = brn.branch_name    || '';
    document.getElementById('brn_street').value  = brn.street   || '';
    document.getElementById('brn_zip').value     = brn.zip_code || '';
    brnAddr.set(brn.province || '', brn.city || '', brn.barangay || '');
    // Older branches only have the one-line address: show it so it can be re-entered in parts
    const oldAddr = document.getElementById('brnOldAddress');
    const legacyAddr = !isAdd && brn.address && !brn.province && !brn.street;
    oldAddr.style.display = legacyAddr ? '' : 'none';
    oldAddr.textContent   = legacyAddr ? 'Saved address: ' + brn.address + ' — fill in the fields above to replace it.' : '';
    // Contact: +63 is shown in front; the field holds the 10 digits starting with 9
    const digits = recPhoneDigits(brn.contact_number);
    document.getElementById('brn_contact').value = digits;
    const oldTel = document.getElementById('brnOldContact');
    const legacyTel = !isAdd && brn.contact_number && !digits;
    oldTel.style.display = legacyTel ? '' : 'none';
    oldTel.textContent   = legacyTel ? 'Saved number: ' + brn.contact_number + ' — enter a mobile number to replace it.' : '';
    _brnSetTime('brn_open',  brn.open_time  ? String(brn.open_time).substring(0, 5)  : (isAdd ? '09:00' : ''));
    _brnSetTime('brn_close', brn.close_time ? String(brn.close_time).substring(0, 5) : (isAdd ? '17:00' : ''));
    document.getElementById(brn.status === 'inactive' ? 'brn_st_inactive' : 'brn_st_active').checked = true;
    setBranchDays(isAdd ? BRN_DEFAULT_DAYS : brn.operating_days);
    const btn = document.getElementById('brnSaveBtn');
    btn.disabled  = false;
    btn.innerHTML = '<i class="feather-save me-1"></i> ' + (isAdd ? 'Add Branch' : 'Save Changes');

    const modalEl = document.getElementById('branchModal');
    modalEl.addEventListener('shown.bs.modal', () => document.getElementById('brn_name').focus(), { once: true });
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

/** Choose a time in an Opens / Closes dropdown (adds it when it isn't on the half-hour list). */
function _brnSetTime(id, hhmm) {
    const sel = document.getElementById(id);
    if (hhmm && ![...sel.options].some(o => o.value === hhmm)) {
        const o = document.createElement('option');
        o.value = hhmm;
        o.textContent = _brnTime(hhmm);
        sel.appendChild(o);
        if (sel._csRebuild) sel._csRebuild();
    }
    sel.value = hhmm || '';
}

function openAddBranchModal() { _brnFillForm({ status: 'active' }, true); }

function openEditBranchModal(brn) { if (brn) _brnFillForm(brn, false); }

function _brnValidate(p) {
    let ok = true;
    if (!p.branch_name)                 { _brnFieldError('brn_name', 'Please enter the branch name.'); ok = false; }
    else if (p.branch_name.length > 100){ _brnFieldError('brn_name', 'Name is too long (100 characters max).'); ok = false; }
    if (!p.street)                      { _brnFieldError('brn_street', 'Please enter the building / house no. and street.'); ok = false; }
    if (!p.province)                    { _brnFieldError('brn_province', 'Please choose the province.'); ok = false; }
    if (!p.city)                        { _brnFieldError('brn_city', 'Please choose the city / municipality.'); ok = false; }
    if (!p.barangay)                    { _brnFieldError('brn_barangay', 'Please choose the barangay.'); ok = false; }
    if (!/^\d{4}$/.test(p.zip_code))     { _brnFieldError('brn_zip', 'Enter the 4-digit ZIP code.'); ok = false; }
    const telErr = recPhoneError(document.getElementById('brn_contact'), true);
    if (telErr)                         { _brnFieldError('brn_contact', telErr); ok = false; }
    if (!p.open_time)                   { _brnFieldError('brn_open', 'Set the opening time.'); ok = false; }
    if (!p.close_time)                  { _brnFieldError('brn_close', 'Set the closing time.'); ok = false; }
    else if (p.open_time && p.close_time <= p.open_time) { _brnFieldError('brn_close', 'Must be later than the opening time.'); ok = false; }
    if (!p.operating_days.length) {
        _brnFieldError('brn_days', 'Choose at least one open day.');
        document.getElementById('brn_days').classList.add('is-invalid');
        ok = false;
    }
    return ok;
}

function saveBranch(confirmed) {
    const id     = document.getElementById('brn_id').value;
    const errBox = document.getElementById('brnModalError');
    const btn    = document.getElementById('brnSaveBtn');
    const label  = id ? 'Save Changes' : 'Add Branch';
    const payload = {
        action:         id ? 'edit' : 'add',
        branch_id:      id,
        branch_name:    document.getElementById('brn_name').value.trim().replace(/\s+/g, ' '),
        street:         document.getElementById('brn_street').value.trim().replace(/\s+/g, ' '),
        province:       brnAddr.get().province,
        city:           brnAddr.get().city,
        barangay:       brnAddr.get().barangay,
        zip_code:       document.getElementById('brn_zip').value.trim(),
        contact_number: recPhoneValue(document.getElementById('brn_contact')),
        open_time:      document.getElementById('brn_open').value,
        close_time:     document.getElementById('brn_close').value,
        operating_days: getBranchDays(),
        status:         (document.querySelector('input[name="brn_status"]:checked') || {}).value || 'active',
        confirm:        confirmed === true,
    };

    _brnClearErrors();
    if (!_brnValidate(payload)) {
        const first = document.querySelector('#branchModal .is-invalid');
        if (first && first.focus && first.offsetParent !== null) first.focus();
        return;
    }

    const reset = () => { btn.disabled = false; btn.innerHTML = '<i class="feather-save me-1"></i> ' + label; };
    const fail  = msg => { errBox.textContent = msg; errBox.style.display = 'block'; reset(); };
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    fetch(BRN_API, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        if (d.needs_confirm) {
            // Upcoming appointments at a branch being made Inactive — primary action LEFT
            reset();
            return Swal.fire({
                icon: 'warning',
                title: 'Patients are still booked here',
                text: d.message,
                showCancelButton: true,
                confirmButtonText: 'Make Inactive anyway',
                cancelButtonText: 'Go back',
                buttonsStyling: !window.ES_SWAL_TONE,
                customClass: window.ES_SWAL_TONE ? ES_SWAL_TONE('warn') : undefined,
            }).then(r => { if (r.isConfirmed) saveBranch(true); });
        }
        if (!d.success) return fail(d.message || 'Could not save the branch.');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('branchModal')).hide();
        _brnToast((payload.branch_name || 'Branch') + (id ? ' was updated.' : ' was added.'), 'success');
        _esAfterAction();
    })
    .catch(() => fail('Could not connect to the server. Please try again.'));
}

/* ── Delete ────────────────────────────────────────────────── */
// deleteBranch(record)  — or the old signature deleteBranch(id, name)
function deleteBranch(brn, legacyName) {
    if (brn == null) return;
    if (typeof brn !== 'object') brn = { branch_id: brn, branch_name: legacyName || '' };
    const name = brn.branch_name || 'this branch';
    Swal.fire({
        icon: 'warning',
        title: 'Delete this branch?',
        html: `<strong>${_bxEsc(name)}</strong> will be permanently removed.`
            + '<p class="mb-0 mt-2 small">A branch that still has patients, appointments or staff can\'t be deleted — set it to <strong>Inactive</strong> instead.</p>',
        showCancelButton: true,
        confirmButtonText: 'Delete Branch',
        cancelButtonText: 'Keep Branch',
        buttonsStyling: !window.ES_SWAL_TONE,
        customClass: window.ES_SWAL_TONE ? recDeleteClass() : undefined,
        confirmButtonColor: '#dc3545',
        focusCancel: true,
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch(BRN_API, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', branch_id: brn.branch_id })
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                return Swal.fire({ icon: 'error', title: 'Could not delete', text: d.message || 'Delete failed.',
                    buttonsStyling: !window.ES_SWAL_CLASS, customClass: window.ES_SWAL_CLASS || undefined });
            }
            _brnToast(name + ' was deleted.', 'success');
            _esAfterAction();
        })
        .catch(() => _brnToast('Could not connect to the server. Please try again.', 'error'));
    });
}

/* ── Form interactions ─────────────────────────────────────── */
document.addEventListener('click', e => {
    const preset = e.target.closest && e.target.closest('#branchModal .rec-presets button[data-days]');
    if (preset) {
        setBranchDays(preset.dataset.days.split(',').map(Number));
        document.getElementById('brn_days').classList.remove('is-invalid');
        const err = document.querySelector('#branchModal [data-err-for="brn_days"]');
        if (err) err.textContent = '';
    }
});
document.addEventListener('input', e => {
    if (!e.target.closest || !e.target.closest('#branchModal')) return;
    if (['brn_open', 'brn_close'].includes(e.target.id) || e.target.classList.contains('brn-day')) _brnPreview();
    if (e.target.classList.contains('brn-day')) {
        document.getElementById('brn_days').classList.remove('is-invalid');
        const err = document.querySelector('#branchModal [data-err-for="brn_days"]');
        if (err) err.textContent = '';
        return;
    }
    e.target.classList.remove('is-invalid');
    const err = document.querySelector(`#branchModal [data-err-for="${e.target.id}"]`);
    if (err) err.textContent = '';
});
document.addEventListener('change', e => {
    if (e.target.closest && e.target.closest('#branchModal') && (['brn_open', 'brn_close'].includes(e.target.id) || e.target.classList.contains('brn-day'))) _brnPreview();
});

/* ── Schedule Exceptions ──────────────────────────────────────
   One date that differs from the usual weekly schedule: open on a
   normally closed day, or closed on a normally open one. Booking
   (portal + admin) and the predictive analytics follow these.
   API: api/branch_crud.php  list_exceptions | save_exception | delete_exception
   ──────────────────────────────────────────────────────────── */

let _bxUsualDays = BRN_DEFAULT_DAYS;
let _bxToday     = '';
let _bxChanged   = false;   // refresh the list page when the modal closes

function _bxDateLabel(iso) {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
}

function _bxError(msg) {
    const box = document.getElementById('bxError');
    box.textContent   = msg || '';
    box.style.display = msg ? 'block' : 'none';
}

function _bxPost(body) {
    return fetch(BRN_API, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    }).then(r => r.json());
}

function _bxResetForm() {
    document.getElementById('bx_date').value   = '';
    document.getElementById('bx_reason').value = '';
    document.querySelectorAll('input[name="bx_open"]').forEach(r => { r.checked = false; });
    document.getElementById('bxDateHint').innerHTML = '&nbsp;';
}

function openBranchExceptions(brn) {
    if (!brn) return;
    document.getElementById('bx_branch_id').value       = brn.branch_id;
    document.getElementById('bxBranchName').textContent = brn.branch_name || '';
    document.getElementById('bxUsual').textContent      = brn.days_label || '—';
    _bxResetForm();
    _bxError('');
    _bxChanged = false;
    _bxLoad();

    const el = document.getElementById('branchExcModal');
    el.addEventListener('hidden.bs.modal', () => { if (_bxChanged) _esAfterAction(); }, { once: true });
    bootstrap.Modal.getOrCreateInstance(el).show();
}

function _bxLoad() {
    const list = document.getElementById('bxList');
    const save = document.getElementById('bxSaveBtn');
    list.innerHTML = '<div class="p-3 small text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
    _bxPost({ action: 'list_exceptions', branch_id: document.getElementById('bx_branch_id').value })
        .then(d => {
            if (!d.success) {
                list.innerHTML = `<div class="p-3 small ${d.setup ? 'text-warning' : 'text-danger'}">${_bxEsc(d.message)}</div>`;
                save.disabled = !!d.setup;
                return;
            }
            save.disabled = false;
            _bxUsualDays  = (d.operating_days || BRN_DEFAULT_DAYS).map(Number);
            _bxToday      = d.today || '';
            _bxRender(d.exceptions || []);
        })
        .catch(() => { list.innerHTML = '<div class="p-3 small text-danger">Could not connect to server.</div>'; });
}

function _bxRender(rows) {
    const list = document.getElementById('bxList');
    if (!rows.length) {
        list.innerHTML = '<div class="p-4 small text-muted text-center"><i class="feather-calendar d-block mb-2 fs-4 text-primary"></i>No exceptions — the usual weekly schedule applies to every date.</div>';
        return;
    }
    const upcoming = rows.filter(r => r.exception_date >= _bxToday);
    const past     = rows.filter(r => r.exception_date <  _bxToday).reverse();   // most recent first
    const row = (r, isPast) => `
        <tr class="${isPast ? 'text-muted' : ''}">
            <td class="ps-3 text-nowrap fw-semibold">${_bxEsc(_bxDateLabel(r.exception_date))}</td>
            <td><span class="rec-status ${r.is_open ? 'st-active' : 'st-inactive'}">${r.is_open ? 'Open' : 'Closed'}</span></td>
            <td class="small">${r.reason ? _bxEsc(r.reason) : '<span class="text-muted">—</span>'}</td>
            <td class="text-end pe-3">
                <a href="javascript:void(0);" class="avatar-text avatar-sm ms-auto text-danger bx-del" title="Remove" aria-label="Remove ${_bxEsc(_bxDateLabel(r.exception_date))}"
                   data-id="${Number(r.exception_id)}" data-label="${_bxEsc(_bxDateLabel(r.exception_date))}">
                    <i class="feather-trash-2"></i>
                </a>
            </td>
        </tr>`;
    const section = (title, items, isPast) => items.length
        ? `<tr class="bx-group"><td colspan="4" class="ps-3 py-2">${title}</td></tr>` + items.map(r => row(r, isPast)).join('')
        : '';
    list.innerHTML = `
        <div style="max-height:300px;overflow:auto;">
            <table class="table table-sm table-hover align-middle mb-0">
                <tbody>${section('Upcoming', upcoming, false)}${section('Past 90 days', past, true)}</tbody>
            </table>
        </div>`;
}

document.addEventListener('click', e => {
    const del = e.target.closest && e.target.closest('#bxList .bx-del');
    if (del) deleteBranchException(Number(del.dataset.id), del.dataset.label);
});

// Picking a date pre-selects the opposite of the usual schedule (the only change that makes sense)
document.addEventListener('change', e => {
    if (e.target.id !== 'bx_date') return;
    const hint = document.getElementById('bxDateHint');
    const iso  = e.target.value;
    if (!iso) { hint.innerHTML = '&nbsp;'; return; }
    const dow       = new Date(iso + 'T00:00:00').getDay();
    const usualOpen = _bxUsualDays.includes(dow);
    hint.textContent = `Usually ${usualOpen ? 'open' : 'closed'} on ${BX_DAY_NAMES[dow]}s.`;
    document.getElementById(usualOpen ? 'bx_open_0' : 'bx_open_1').checked = true;
});

function saveBranchException(confirmed) {
    const btn    = document.getElementById('bxSaveBtn');
    const picked = document.querySelector('input[name="bx_open"]:checked');
    const payload = {
        action:         'save_exception',
        branch_id:      document.getElementById('bx_branch_id').value,
        exception_date: document.getElementById('bx_date').value,
        is_open:        picked ? picked.value === '1' : null,
        reason:         document.getElementById('bx_reason').value.trim(),
        confirm:        confirmed === true,
    };
    _bxError('');
    if (!payload.exception_date) return _bxError('Please choose a date.');
    if (payload.is_open === null) return _bxError('Please choose Open or Closed.');
    if (!payload.reason) { document.getElementById('bx_reason').focus(); return _bxError('Please enter the reason for this exception.'); }

    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
    const reset = () => { btn.disabled = false; btn.innerHTML = '<i class="feather-save me-1"></i> Save Exception'; };

    _bxPost(payload)
        .then(d => {
            reset();
            if (d.needs_confirm) {
                // Primary action on the LEFT, going back on the right
                return Swal.fire({
                    icon: 'warning',
                    title: 'Appointments on this day',
                    text: d.message,
                    showCancelButton: true,
                    confirmButtonText: 'Close the day anyway',
                    cancelButtonText: 'Go back',
                    buttonsStyling: !window.ES_SWAL_TONE,
                    customClass: window.ES_SWAL_TONE ? ES_SWAL_TONE('danger') : undefined,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                }).then(r => { if (r.isConfirmed) saveBranchException(true); });
            }
            if (!d.success) return _bxError(d.message);
            _bxChanged = true;
            _bxResetForm();
            _brnToast(d.message || 'Exception saved.', 'success');
            _bxLoad();
        })
        .catch(() => { reset(); _bxError('Could not connect to server.'); });
}

function deleteBranchException(id, label) {
    Swal.fire({
        icon: 'warning',
        title: 'Remove exception?',
        html: `<strong>${_bxEsc(label)}</strong> will follow the usual weekly schedule again.`,
        showCancelButton: true,
        confirmButtonText: 'Yes, remove',
        cancelButtonText: 'Cancel',
        buttonsStyling: !window.ES_SWAL_TONE,
        customClass: window.ES_SWAL_TONE ? recDeleteClass() : undefined,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
    }).then(r => {
        if (!r.isConfirmed) return;
        _bxPost({ action: 'delete_exception', exception_id: id })
            .then(d => {
                if (!d.success) return Swal.fire({ icon: 'error', title: 'Error', text: d.message || 'Could not remove it.',
                    buttonsStyling: !window.ES_SWAL_CLASS, customClass: window.ES_SWAL_CLASS || undefined });
                _bxChanged = true;
                _brnToast(d.message || 'Removed.', 'success');
                _bxLoad();
            })
            .catch(() => _brnToast('Could not connect to server.', 'error'));
    });
}

// Dropdowns (province / city / barangay / times): a choice clears that field's error
document.addEventListener('change', e => {
    if (!e.target.closest || !e.target.closest('#branchModal') || e.target.tagName !== 'SELECT' || !e.target.value) return;
    e.target.classList.remove('is-invalid');
    e.target.closest('.apt-field')?.querySelector('.modal-cs')?.classList.remove('is-invalid');
    const err = document.querySelector(`#branchModal [data-err-for="${e.target.id}"]`);
    if (err) err.textContent = '';
});
// ZIP code: digits only
document.addEventListener('input', e => {
    if (e.target.id !== 'brn_zip') return;
    const d = e.target.value.replace(/\D/g, '').slice(0, 4);
    if (e.target.value !== d) e.target.value = d;
});
