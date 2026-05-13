// assets/js/portal-dashboard.js
// Requires: const API defined in portal-dashboard.php before this file loads

// ── State ─────────────────────────────────────────────────────────────────────
let allAppointments   = [];
let currentCancelId   = null;
let accountData       = {};
let isProfileComplete = false;

// ── Helpers ───────────────────────────────────────────────────────────────────
function php(path, body) {
    return fetch(path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    }).then(r => r.json());
}

function peso(v) {
    return '₱' + parseFloat(v || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function fmtDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function fmtTime(t) {
    if (!t) return '';
    const [h, m] = t.split(':');
    const dt = new Date();
    dt.setHours(+h, +m);
    return dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function statusBadge(s) {
    return `<span class="badge-status badge-${s || 'pending'}">${(s || 'pending').replace('_', ' ')}</span>`;
}

function payBadge(s) {
    return `<span class="badge-status badge-${s || 'unpaid'}">${s || 'unpaid'}</span>`;
}

function displayContact(val) {
    if (!val || /^__PORTAL_\d+__$/.test(val) || /^PORTAL-\d+$/.test(val)) return '—';
    return val;
}

// ── Book Appointment gate ─────────────────────────────────────────────────────
function handleBookAppt() {
    if (!isProfileComplete) {
        Swal.fire({
            icon: 'info',
            title: 'Profile Incomplete',
            html: `To book an appointment, please complete the following in <strong>My Profile</strong>:
                   <ul style="text-align:left;margin-top:.75rem;font-size:.9rem;line-height:2;">
                     <li><i class="fa-solid fa-user" style="width:18px;"></i> <strong>Basic Info</strong> — Name, Birthdate, Gender</li>
                     <li><i class="fa-solid fa-address-book" style="width:18px;"></i> <strong>Contact Info</strong> — Address &amp; City</li>
                     <li><i class="fa-solid fa-circle-question" style="width:18px;"></i> <strong>Medical Questions</strong> — Answer Yes/No questions</li>
                   </ul>`,
            confirmButtonColor: '#2563eb',
            confirmButtonText: 'Complete My Profile',
            showCancelButton: true,
            cancelButtonText: 'Later',
        }).then(r => {
            if (r.isConfirmed) showPage('profile', null);
        });
        return;
    }
    window.location.href = 'appointmentWeb-Scheduling.php';
}

function setBookBtnState(complete) {
    ['bookApptBtn', 'bookApptBtnAlt'].forEach(id => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.style.opacity       = complete ? '1' : '0.55';
        btn.style.cursor        = complete ? 'pointer' : 'not-allowed';
        btn.style.pointerEvents = 'auto';
        btn.title               = complete ? '' : 'Complete your profile first';
    });
}

// ── Navigation ────────────────────────────────────────────────────────────────
function showPage(page, linkEl) {
    document.querySelectorAll('.page-body').forEach(p => p.classList.remove('active'));
    document.getElementById('page-' + page).classList.add('active');

    document.querySelectorAll('.nav-link-item').forEach(l => l.classList.remove('active'));
    if (linkEl) {
        linkEl.classList.add('active');
    } else {
        const match = Array.from(document.querySelectorAll('.nav-link-item'))
            .find(l => l.getAttribute('onclick')?.includes(`'${page}'`));
        if (match) match.classList.add('active');
    }

    const titles = {
        dashboard:   'Dashboard',
        appointments:'My Appointments',
        treatments:  'Treatment History',
        dentalchart: 'Dental Chart',
        billing:     'Billing & Payments',
        files:       'My Files',
        waivers:     'Waivers',
        profile:     'My Profile',
    };
    document.getElementById('topbarTitle').textContent      = titles[page] || page;
    document.getElementById('topbarBreadcrumb').textContent = `My Portal › ${titles[page] || page}`;

    if (page === 'appointments') loadAppointments();
    if (page === 'treatments')   loadTreatments();
    if (page === 'dentalchart')  loadDentalChart();
    if (page === 'billing')      loadBilling();
    if (page === 'files')        loadFiles();
    if (page === 'waivers')      loadWaivers();
    if (page === 'profile')      loadProfile();
    closeSidebar();
}

function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebarOverlay').classList.add('show');
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
}

// ── DASHBOARD ─────────────────────────────────────────────────────────────────
async function loadDashboard() {
    const data = await php(API, { action: 'dashboard_summary' });
    if (!data.success) return;

    const { account, stats, upcoming, recent_bills, is_profile_complete } = data;
    accountData = account;

    const firstName = account.first_name || 'Patient';
    const lastName  = account.last_name  || '';
    const initial   = firstName.charAt(0).toUpperCase();

    document.getElementById('sidebarInitial').textContent = initial;
    document.getElementById('sidebarName').textContent    = [firstName, lastName].filter(Boolean).join(' ');
    document.getElementById('welcomeName').textContent    = firstName;

    document.getElementById('statUpcoming').textContent   = stats.upcoming   || 0;
    document.getElementById('statCompleted').textContent  = stats.completed  || 0;
    document.getElementById('statBalance').textContent    = peso(stats.balance);
    document.getElementById('statTreatments').textContent = stats.treatments || 0;

    isProfileComplete = !!is_profile_complete;
    document.getElementById('profileIncompleteAlert').style.display = is_profile_complete ? 'none' : 'flex';
    setBookBtnState(is_profile_complete);

    if (upcoming && upcoming.length > 0) {
        document.getElementById('dashApptList').innerHTML = upcoming.map(a => `
            <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.85rem;">
                <div style="width:42px;height:42px;border-radius:10px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0;">
                    ${new Date(a.appointment_date).getDate()}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:.875rem;">${a.service_name || 'Consultation'}</div>
                    <div style="font-size:.78rem;color:var(--text-muted);">${fmtDate(a.appointment_date)} · ${fmtTime(a.appointment_time)} · ${a.branch_name || '—'}</div>
                </div>
                ${statusBadge(a.status)}
            </div>
        `).join('');
    }

    if (recent_bills && recent_bills.length > 0) {
        document.getElementById('dashBillList').innerHTML = recent_bills.map(b => `
            <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="font-weight:700;font-size:.875rem;">${b.invoice_code}</div>
                    <div style="font-size:.78rem;color:var(--text-muted);">${fmtDate(b.invoice_date)}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:700;">${peso(b.total_amount)}</div>
                    ${payBadge(b.payment_status)}
                </div>
            </div>
        `).join('');
    }
}

// ── APPOINTMENTS ──────────────────────────────────────────────────────────────
async function loadAppointments() {
    const tbody = document.getElementById('apptTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">Loading…</td></tr>';
    const data = await php(API, { action: 'get_appointments' });
    allAppointments = data.appointments || [];
    renderApptTable(allAppointments);
}

function renderApptTable(list) {
    const tbody = document.getElementById('apptTableBody');
    if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">No appointments found.</td></tr>';
        return;
    }
    tbody.innerHTML = list.map(a => `
        <tr>
            <td><span style="font-weight:700;font-size:.8rem;">${a.appointment_code}</span></td>
            <td>${fmtDate(a.appointment_date)}<br><span style="font-size:.75rem;color:var(--text-muted);">${fmtTime(a.appointment_time)}</span></td>
            <td>${a.branch_name || '—'}</td>
            <td>${a.service_name || 'Consultation'}</td>
            <td>${statusBadge(a.status)}</td>
            <td>
                ${a.status === 'pending'
                    ? `<button onclick="openCancelModal(${a.appointment_id},'${a.appointment_code}')" style="background:none;border:1.5px solid #fecaca;border-radius:7px;padding:.3rem .75rem;font-size:.78rem;font-weight:700;color:var(--red);cursor:pointer;font-family:inherit;">Cancel</button>`
                    : `<button disabled title="Cannot cancel a ${a.status.replace('_',' ')} appointment" style="background:none;border:1.5px solid #e5e7eb;border-radius:7px;padding:.3rem .75rem;font-size:.78rem;font-weight:700;color:#c0c8d0;cursor:not-allowed;font-family:inherit;opacity:.6;">Cancel</button>`}
            </td>
        </tr>
    `).join('');
}

function filterAppts(status, btn) {
    document.querySelectorAll('.appt-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderApptTable(status === 'all' ? allAppointments : allAppointments.filter(a => a.status === status));
}

function openCancelModal(id, code) {
    currentCancelId = id;
    document.getElementById('cancelApptCode').textContent = code;
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}

async function confirmCancel() {
    const data = await php(API, { action: 'cancel_appointment', appointment_id: currentCancelId });
    bootstrap.Modal.getInstance(document.getElementById('cancelModal')).hide();
    if (data.success) {
        Swal.fire({ icon: 'success', title: 'Cancelled', text: 'Your appointment has been cancelled.', confirmButtonColor: '#2563eb', timer: 2000, showConfirmButton: false });
        loadAppointments();
        loadDashboard();
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Could not cancel.', confirmButtonColor: '#2563eb' });
    }
}

// ── TREATMENTS ────────────────────────────────────────────────────────────────
async function loadTreatments() {
    const tbody = document.getElementById('treatTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Loading…</td></tr>';
    const data = await php(API, { action: 'get_treatments' });
    const list = data.treatments || [];
    if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4" style="color:var(--text-muted);">No treatments on record.</td></tr>';
        return;
    }
    tbody.innerHTML = list.map(t => `
        <tr>
            <td><span style="font-weight:700;font-size:.8rem;">${t.treatment_code}</span></td>
            <td>${fmtDate(t.treatment_date)}</td>
            <td>${t.service_name || '—'}</td>
            <td>${t.tooth_number || '—'}</td>
            <td>${t.dentist_name || '—'}</td>
            <td>${statusBadge(t.status)}</td>
        </tr>
    `).join('');
}

// ── DENTAL CHART ──────────────────────────────────────────────────────────────
async function loadDentalChart() {
    const data = await php(API, { action: 'get_dental_chart' });
    const chartMap = {};
    (data.chart || []).forEach(c => { chartMap[c.tooth_number] = c; });

    const condClass = { healthy: 'healthy', cavity: 'cavity', filled: 'filled', missing: 'missing', crown: 'crown' };
    const condEmoji = { healthy: '🦷', cavity: '🟡', filled: '🔵', missing: '✖', crown: '👑' };

    function buildTooth(num) {
        const entry = chartMap[num];
        const cond  = entry?.condition?.toLowerCase() || '';
        const cls   = condClass[cond] || '';
        const emoji = condEmoji[cond] || '🦷';
        const tip   = entry
            ? `${num}: ${entry.condition || 'Healthy'}${entry.notes ? ' — ' + entry.notes : ''}`
            : `${num}: No data`;
        return `<div class="tooth-cell"><div class="tooth-icon ${cls}" title="${tip}">${emoji}</div><span>${num}</span></div>`;
    }

    const upper = [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
    const lower = [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
    document.getElementById('upperTeeth').innerHTML = upper.map(buildTooth).join('');
    document.getElementById('lowerTeeth').innerHTML = lower.map(buildTooth).join('');
}

// ── BILLING ───────────────────────────────────────────────────────────────────
async function loadBilling() {
    const tbody = document.getElementById('billingTableBody');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">Loading…</td></tr>';
    const data = await php(API, { action: 'get_billing' });
    const list = data.invoices || [];
    let totalBilled = 0, totalPaid = 0;
    if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted);">No invoices found.</td></tr>';
        return;
    }
    tbody.innerHTML = list.map(i => {
        totalBilled += parseFloat(i.total_amount || 0);
        totalPaid   += parseFloat(i.amount_paid  || 0);
        const bal    = parseFloat(i.total_amount || 0) - parseFloat(i.amount_paid || 0);
        return `<tr>
            <td><span style="font-weight:700;font-size:.8rem;">${i.invoice_code}</span></td>
            <td>${fmtDate(i.invoice_date)}</td>
            <td>${i.treatment_code || '—'}</td>
            <td>${peso(i.total_amount)}</td>
            <td>${peso(i.amount_paid)}</td>
            <td>${peso(bal)}</td>
            <td>${payBadge(i.payment_status)}</td>
        </tr>`;
    }).join('');
    document.getElementById('billTotal').textContent   = peso(totalBilled);
    document.getElementById('billPaid').textContent    = peso(totalPaid);
    document.getElementById('billBalance').textContent = peso(totalBilled - totalPaid);
}

// ── FILES ─────────────────────────────────────────────────────────────────────
async function loadFiles() {
    const grid = document.getElementById('filesGrid');
    grid.innerHTML = '<div class="col-12 text-center py-4" style="color:var(--text-muted);">Loading…</div>';
    const data = await php(API, { action: 'get_files' });
    const list = data.files || [];
    if (!list.length) {
        grid.innerHTML = '<div class="col-12"><div class="empty-state"><i class="fa-solid fa-folder-open"></i><p>No files uploaded yet.</p></div></div>';
        return;
    }
    const icons = { 'X-ray': 'fa-radiation', 'Referral': 'fa-file-medical', 'Certificate': 'fa-certificate' };
    grid.innerHTML = list.map(f => `
        <div class="col-md-6">
            <div class="file-card">
                <div class="file-icon"><i class="fa-solid ${icons[f.file_type] || 'fa-file'}"></i></div>
                <div>
                    <div class="file-name">${f.file_name || 'Document'}</div>
                    <div class="file-meta">${f.file_type} · ${fmtDate(f.uploaded_at)}</div>
                </div>
                <a href="${f.file_path}" target="_blank" class="file-download" title="Download/View">
                    <i class="fa-solid fa-arrow-down-to-line"></i>
                </a>
            </div>
        </div>
    `).join('');
}

// ── WAIVERS ───────────────────────────────────────────────────────────────────
async function loadWaivers() {
    const listEl = document.getElementById('waiversList');
    const data   = await php(API, { action: 'get_waivers' });
    const list   = data.waivers || [];
    if (!list.length) {
        listEl.innerHTML = '<div class="col-12"><div class="empty-state"><i class="fa-solid fa-pen-to-square"></i><p>No waivers on file.</p></div></div>';
        return;
    }
    listEl.innerHTML = list.map(w => `
        <div class="col-md-6">
            <div class="waiver-card d-flex align-items-center justify-content-between">
                <div>
                    <div style="font-weight:700;font-size:.875rem;">${w.waiver_type || 'General Waiver'}</div>
                    <div style="font-size:.78rem;color:var(--text-muted);">${w.signed_at ? 'Signed ' + fmtDate(w.signed_at) : 'Not yet signed'}</div>
                </div>
                <div>
                    <span class="waiver-badge ${w.status === 'Signed' ? 'waiver-signed' : 'waiver-pending'}">${w.status || 'Pending'}</span>
                    ${w.file_path ? `<a href="${w.file_path}" target="_blank" class="file-download ms-2" title="View"><i class="fa-solid fa-eye"></i></a>` : ''}
                </div>
            </div>
        </div>
    `).join('');
}

// ── PROFILE ───────────────────────────────────────────────────────────────────
async function loadProfile() {
    const data = await php(API, { action: 'get_profile' });
    const p    = data.account  || {};
    const ext  = data.profile  || {};

    document.getElementById('pf_first').value     = p.first_name  || '';
    document.getElementById('pf_last').value      = p.last_name   || '';
    document.getElementById('pf_middle').value    = p.middle_name || '';
    document.getElementById('pf_suffix').value    = p.suffix      || '';
    document.getElementById('pf_birthdate').value = p.birthdate   || '';
    document.getElementById('pf_gender').value    = p.gender      || '';
    document.getElementById('pf_contact').value   = displayContact(p.contact_number);
    document.getElementById('pf_email').value     = p.email       || '';

    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.value = (val !== null && val !== undefined) ? val : '';
    };

    setVal('pf_civil_status',               ext.civil_status);
    setVal('pf_nationality',                ext.nationality);
    setVal('pf_home_phone',                 ext.home_phone);
    setVal('pf_address',                    ext.address);
    setVal('pf_city',                       ext.city);
    setVal('pf_province',                   ext.province);
    setVal('pf_zip',                        ext.zip);
    setVal('pf_chief_complaint',            ext.chief_complaint);
    setVal('pf_referral_source',            ext.referral_source);
    setVal('pf_referred_by',               ext.referred_by);
    setVal('pf_prev_dentist',              ext.prev_dentist);
    setVal('pf_last_visit',               ext.last_dental_visit);
    setVal('pf_dental_notes',             ext.dental_notes);
    setVal('pf_physician',                ext.physician);
    setVal('pf_physician_specialty',      ext.physician_specialty);
    setVal('pf_physician_office_number',  ext.physician_office_number);
    setVal('pf_physician_office_address', ext.physician_office_address);
    setVal('pf_other_allergies',          ext.other_allergies);
    setVal('pf_allergy_reaction',         ext.allergy_reaction);
    setVal('pf_blood_pressure',           ext.blood_pressure);
    setVal('pf_blood_sugar',              ext.blood_sugar);
    setVal('pf_pulse',                    ext.pulse_rate);
    setVal('pf_emergency_name',           ext.emergency_name);
    setVal('pf_emergency_relation',       ext.emergency_relation);
    setVal('pf_emergency_phone',          ext.emergency_phone);
    setVal('pf_other_conditions',         ext.other_conditions);

    const toArr = v => Array.isArray(v) ? v : (typeof v === 'string' && v ? JSON.parse(v) : []);
    const restoreChecks = (name, raw) => {
        const list = toArr(raw);
        document.querySelectorAll(`input[name="${name}"]`).forEach(cb => { cb.checked = list.includes(cb.value); });
    };
    restoreChecks('allergies',  ext.allergies);
    restoreChecks('conditions', ext.conditions);

    ['q_good_health','q_under_treatment','q_serious_illness','q_hospitalized',
     'q_taking_meds','q_tobacco','q_dangerous_drugs','q_pregnant','q_nursing','q_birth_control'
    ].forEach(qn => {
        const val = ext[qn];
        if (val === null || val === undefined) return;
        const strVal = (val === true || val === 1) ? 'yes' : 'no';
        const el = document.querySelector(`input[name="${qn}"][value="${strVal}"]`);
        if (el) el.checked = true;
    });

    Object.entries({ fu_treatment: 'q_under_treatment', fu_illness: 'q_serious_illness', fu_hospitalized: 'q_hospitalized', fu_meds: 'q_taking_meds' })
        .forEach(([fuId, qn]) => {
            const el = document.getElementById(fuId);
            if (el) el.style.display = (ext[qn] === true || ext[qn] === 1) ? 'block' : 'none';
        });

    calcAge();
    checkGenderConditions();

    const fullName = [p.first_name, p.middle_name, p.last_name, p.suffix].filter(Boolean).join(' ') || '—';
    document.getElementById('profileFullName').textContent = fullName;
    document.getElementById('profileEmail').textContent    = p.email || '—';
    document.getElementById('profileContact').textContent  = displayContact(p.contact_number);
    document.getElementById('profileAvatar').textContent   = (p.first_name || 'P').charAt(0).toUpperCase();
    document.getElementById('profileComplete').innerHTML   = p.is_profile_complete
        ? '<span class="badge-status badge-completed">Profile Complete</span>'
        : '<span class="badge-status badge-pending">Profile Incomplete</span>';
}

// ── SAVE PROFILE ──────────────────────────────────────────────────────────────
async function saveProfile() {
    const saveButtons = document.querySelectorAll('[onclick="saveProfile()"]');
    const setLoading  = (on) => {
        saveButtons.forEach(btn => {
            btn.disabled  = on;
            btn.innerHTML = on
                ? '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Saving…'
                : '<i class="fa-solid fa-floppy-disk me-1"></i> Save Changes';
        });
    };
    setLoading(true);

    let ok = true;
    const req = (id, errId, msg) => {
        if (!document.getElementById(id)?.value.trim()) { showPfErr(errId, msg); ok = false; }
        else { hidePfErr(errId); }
    };
    req('pf_first',     'err_pf_first',     'First name is required.');
    req('pf_last',      'err_pf_last',      'Last name is required.');
    req('pf_birthdate', 'err_pf_birthdate', 'Birthdate is required.');

    if (!ok) {
        setLoading(false);
        Swal.fire({ icon: 'warning', title: 'Required Fields Missing', text: 'Please fill in all required fields.', confirmButtonColor: '#2563eb' });
        document.getElementById('sec-basic').scrollIntoView({ behavior: 'smooth' });
        return;
    }

    const gv  = id   => document.getElementById(id)?.value?.trim() || null;
    const gyn = name => document.querySelector(`input[name="${name}"]:checked`)?.value || null;
    const getChecked = name => [...document.querySelectorAll(`input[name="${name}"]:checked`)].map(cb => cb.value);

    const body = {
        action: 'update_profile',
        first_name: gv('pf_first'), last_name: gv('pf_last'), middle_name: gv('pf_middle'),
        suffix: gv('pf_suffix'), birthdate: gv('pf_birthdate'), gender: gv('pf_gender'),
        civil_status: gv('pf_civil_status'), nationality: gv('pf_nationality'),
        guardian_name: gv('pf_guardian_name'), guardian_relation: gv('pf_guardian_relation'),
        home_phone: gv('pf_home_phone'), address: gv('pf_address'), city: gv('pf_city'),
        province: gv('pf_province'), zip: gv('pf_zip'),
        prev_dentist: gv('pf_prev_dentist'), last_dental_visit: gv('pf_last_visit'), dental_notes: gv('pf_dental_notes'),
        chief_complaint: gv('pf_chief_complaint'), referral_source: gv('pf_referral_source'), referred_by: gv('pf_referred_by'),
        physician: gv('pf_physician'), physician_specialty: gv('pf_physician_specialty'),
        physician_office_number: gv('pf_physician_office_number'), physician_office_address: gv('pf_physician_office_address'),
        q_good_health: gyn('q_good_health'), q_under_treatment: gyn('q_under_treatment'),
        treatment_condition: gv('pf_treatment_condition'), q_serious_illness: gyn('q_serious_illness'),
        illness_detail: gv('pf_illness_detail'), q_hospitalized: gyn('q_hospitalized'),
        hospitalized_detail: gv('pf_hospitalized_detail'), q_taking_meds: gyn('q_taking_meds'),
        meds_detail: gv('pf_meds_detail'), q_tobacco: gyn('q_tobacco'), q_dangerous_drugs: gyn('q_dangerous_drugs'),
        q_pregnant: gyn('q_pregnant'), q_nursing: gyn('q_nursing'), q_birth_control: gyn('q_birth_control'),
        allergies: getChecked('allergies'), other_allergies: gv('pf_other_allergies'), allergy_reaction: gv('pf_allergy_reaction'),
        blood_pressure: gv('pf_blood_pressure'), blood_sugar: gv('pf_blood_sugar'), pulse_rate: gv('pf_pulse'),
        emergency_name: gv('pf_emergency_name'), emergency_relation: gv('pf_emergency_relation'), emergency_phone: gv('pf_emergency_phone'),
        conditions: getChecked('conditions'), other_conditions: gv('pf_other_conditions'),
    };

    const data = await php(API, body);
    setLoading(false);

    if (data.success) {
        const savedComplete = !!data.is_profile_complete;
        isProfileComplete   = savedComplete;

        const missingParts = [];
        if (!gv('pf_first') || !gv('pf_last') || !gv('pf_birthdate') || !gv('pf_gender'))
            missingParts.push('Basic Info (name, birthdate, gender)');
        if (!gv('pf_address') || !gv('pf_city'))
            missingParts.push('Contact Info (address & city)');
        const mqAnswered = ['q_good_health','q_tobacco','q_dangerous_drugs'].every(n => document.querySelector(`input[name="${n}"]:checked`));
        if (!mqAnswered) missingParts.push('Medical Questions (Yes/No answers)');

        Swal.fire({
            icon: savedComplete ? 'success' : 'info',
            title: savedComplete ? 'Profile Saved!' : 'Progress Saved',
            html: savedComplete
                ? 'Your health profile has been updated.'
                : `Saved! Still needed to unlock booking:<br><strong>${missingParts.join(', ')}</strong>`,
            timer: 3000,
            showConfirmButton: !savedComplete,
            confirmButtonText: 'OK',
            confirmButtonColor: '#2563eb',
        });

        const firstName = gv('pf_first') || 'Patient';
        const lastName  = gv('pf_last')  || '';
        const middle    = gv('pf_middle') || '';
        const suffix    = gv('pf_suffix') || '';
        const fullName  = [firstName, middle, lastName, suffix].filter(Boolean).join(' ');

        document.getElementById('profileFullName').textContent = fullName;
        document.getElementById('profileEmail').textContent    = document.getElementById('pf_email').value || '—';
        document.getElementById('profileContact').textContent  = displayContact(document.getElementById('pf_contact').value);
        document.getElementById('profileAvatar').textContent   = firstName.charAt(0).toUpperCase();
        document.getElementById('profileComplete').innerHTML   = savedComplete
            ? '<span class="badge-status badge-completed">Profile Complete</span>'
            : '<span class="badge-status badge-pending">Profile Incomplete</span>';
        document.getElementById('sidebarInitial').textContent = firstName.charAt(0).toUpperCase();
        document.getElementById('sidebarName').textContent    = [firstName, lastName].filter(Boolean).join(' ');
        document.getElementById('welcomeName').textContent    = firstName;
        setBookBtnState(savedComplete);
        loadDashboard();
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Update failed.', confirmButtonColor: '#2563eb' });
    }
}

// ── Profile helpers ───────────────────────────────────────────────────────────
function showPfErr(id, msg) {
    const el = document.getElementById(id);
    if (el) { el.textContent = msg; el.style.display = 'block'; }
}

function hidePfErr(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function toggleFollowUp(fuId, triggerVal, radioEl) {
    const el = document.getElementById(fuId);
    if (!el) return;
    const checked = radioEl.closest('.yn-row-wrap')?.querySelector(`input[name="${radioEl.name}"]:checked`)?.value;
    el.style.display = checked === triggerVal ? 'block' : 'none';
}

function calcAge() {
    const dob          = document.getElementById('pf_birthdate').value;
    const ageEl        = document.getElementById('pf_age');
    const guardianBlock = document.getElementById('guardianBlock');
    if (!dob) {
        if (ageEl) ageEl.value = '';
        if (guardianBlock) guardianBlock.style.display = 'none';
        return;
    }
    const today = new Date(), birth = new Date(dob);
    let age = today.getFullYear() - birth.getFullYear();
    if (today.getMonth() - birth.getMonth() < 0 ||
        (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) age--;
    if (ageEl) ageEl.value = age >= 0 ? age + ' yrs' : '';
    if (guardianBlock) guardianBlock.style.display = (age >= 0 && age < 18) ? 'block' : 'none';
}

function checkGenderConditions() {
    const gender     = document.getElementById('pf_gender').value;
    const womenBlock = document.getElementById('womenQuestionsBlock');
    if (womenBlock) womenBlock.style.display = gender === 'Female' ? 'block' : 'none';
}

function toggleSection(header) {
    header.closest('.prof-section').classList.toggle('collapsed');
}

function scrollToSection(id, linkEl) {
    event.preventDefault();
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.querySelectorAll('.prof-nav-link').forEach(l => l.classList.remove('active'));
    if (linkEl) linkEl.classList.add('active');
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
async function doLogout() {
    const conf = await Swal.fire({
        icon: 'question', title: 'Sign Out?', text: 'You will be logged out of your portal.',
        showCancelButton: true, confirmButtonText: 'Yes, sign out',
        confirmButtonColor: '#ef4444', cancelButtonText: 'Stay',
    });
    if (conf.isConfirmed) window.location.href = 'portal-logout.php';
}

// ── Smooth scroll for anchor links ───────────────────────────────────────────
document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', function (e) {
        const targetId = this.getAttribute('href').slice(1);
        if (!targetId) return;
        const target = document.getElementById(targetId);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

// ── Init ──────────────────────────────────────────────────────────────────────
loadDashboard();