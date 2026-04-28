/* ─────────────────────────────────────────────────────────────────
   patient-record.js  |  EssenciaSmile Patient Record
   ───────────────────────────────────────────────────────────────── */

const tabCache = {};

// ── Tab activation (lazy load) ────────────────────────────────────
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('shown.bs.tab', function (e) {
        const tabName = e.target.getAttribute('data-tab');
        if (!tabName || tabName === 'overview') return;
        if (tabCache[tabName]) return; // already loaded
        loadTab(tabName);
    });
});

function loadTab(tabName) {
    switch (tabName) {
        case 'appointments': loadAppointmentsTab(); break;
        case 'notes':        loadNotesTab();        break;
        case 'dental':       loadDentalChartTab();  break;
        case 'treatments':   loadTreatmentsTab();   break;
        case 'billing':      loadBillingTab();       break;
    }
}

// ── Utility ───────────────────────────────────────────────────────
function fmtDate(d) {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
}
function fmtMoney(v) {
    return '₱' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function statusBadge(s) {
    const map = {
        completed: 'bg-soft-success text-success', pending: 'bg-soft-warning text-warning',
        confirmed: 'bg-soft-info text-info',        cancelled: 'bg-soft-danger text-danger',
        ongoing: 'bg-soft-primary text-primary',    in_progress: 'bg-soft-primary text-primary',
        paid: 'bg-soft-success text-success',       unpaid: 'bg-soft-danger text-danger',
        partial: 'bg-soft-warning text-warning',
    };
    return `<span class="badge ${map[s] || 'bg-soft-secondary text-secondary'}">${s ? s.replace(/_/g,' ') : '—'}</span>`;
}

// ═══════════════════════════════════════════════════════════════════
// TAB: APPOINTMENTS
// ═══════════════════════════════════════════════════════════════════
function loadAppointmentsTab() {
    fetch(`${API_URL}?action=get_appointments&patient_id=${PATIENT_ID}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            tabCache['appointments'] = true;
            renderAppointmentsTab(data);
        });
}

function renderAppointmentsTab(data) {
    const upcoming = data.upcoming || [];
    const history  = (data.history && data.history.rows) ? data.history.rows : [];

    let upcomingHtml = '';
    if (upcoming.length === 0) {
        upcomingHtml = '<p class="text-muted small mb-0">No upcoming appointments.</p>';
    } else {
        upcomingHtml = `<div class="table-responsive"><table class="table table-hover small mb-0">
            <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Dentist</th><th>Status</th></tr></thead><tbody>` +
            upcoming.map(a => `<tr>
                <td>${fmtDate(a.appointment_date)}</td>
                <td>${a.appointment_time || '—'}</td>
                <td>${a.services?.service_name || '—'}</td>
                <td>${a.dentists?.full_name || '—'}</td>
                <td>${statusBadge(a.status)}</td>
            </tr>`).join('') +
            `</tbody></table></div>`;
    }

    let historyHtml = '';
    if (history.length === 0) {
        historyHtml = '<p class="text-muted small mb-0">No appointment history.</p>';
    } else {
        historyHtml = `<div class="table-responsive"><table class="table table-hover small mb-0">
            <thead><tr><th>Code</th><th>Date</th><th>Service</th><th>Dentist</th><th>Status</th><th>Payment</th></tr></thead><tbody>` +
            history.map(a => `<tr>
                <td class="fw-semibold">${a.appointment_code || '—'}</td>
                <td>${fmtDate(a.appointment_date)}</td>
                <td>${a.services?.service_name || '—'}</td>
                <td>${a.dentists?.full_name || '—'}</td>
                <td>${statusBadge(a.status)}</td>
                <td>${statusBadge(a.payment_status)}</td>
            </tr>`).join('') +
            `</tbody></table></div>`;
    }

    document.getElementById('appointments-tab-content').innerHTML = `
        <div class="card mb-3">
            <div class="card-header"><h6 class="card-title mb-0"><i class="feather-calendar me-2 text-info"></i>Upcoming Appointments</h6></div>
            <div class="card-body p-0 px-3 py-2">${upcomingHtml}</div>
        </div>
        <div class="card">
            <div class="card-header"><h6 class="card-title mb-0"><i class="feather-clock me-2 text-muted"></i>Appointment History</h6></div>
            <div class="card-body p-0">${historyHtml}</div>
        </div>`;
}

// ═══════════════════════════════════════════════════════════════════
// TAB: CLINICAL NOTES
// ═══════════════════════════════════════════════════════════════════
let allNotes = [];
let activeNoteId = null;

function loadNotesTab() {
    fetch(`${API_URL}?action=get_notes&patient_id=${PATIENT_ID}`)
        .then(r => r.json())
        .then(data => {
            tabCache['notes'] = true;
            allNotes = data.notes || [];
            renderNotesList();
            if (allNotes.length > 0) showNoteDetail(allNotes[0]);
        });
}

function renderNotesList() {
    const container = document.getElementById('notes-list-body');
    if (allNotes.length === 0) {
        container.innerHTML = `<div class="text-center py-4 text-muted small">
            <i class="feather-file-text mb-2 d-block" style="font-size:1.5rem;opacity:.3;"></i>No clinical notes yet.
        </div>`;
        return;
    }
    container.innerHTML = allNotes.map(n => `
        <div class="note-list-item px-3 py-2 border-bottom cursor-pointer ${n.note_id === activeNoteId ? 'note-active' : ''}"
             onclick="showNoteDetail(${JSON.stringify(n).replace(/"/g,'&quot;')})">
            <div class="d-flex justify-content-between align-items-center">
                <span class="small fw-semibold">${fmtDate(n.note_date)}</span>
                ${n.is_private ? '<span class="badge bg-soft-warning text-warning" style="font-size:10px;">Private</span>' : ''}
            </div>
            <div class="text-muted" style="font-size:11px;">${n.dentists?.full_name || n.created_by}</div>
            <div class="small text-truncate mt-1" style="max-width:220px;">${n.note_text}</div>
        </div>
    `).join('');
}

function showNoteDetail(note) {
    activeNoteId = note.note_id;
    renderNotesList(); // re-render to update active state
    const canEdit = !IS_DENTIST || true; // dentists can edit their own notes — simplify by allowing all for now

    document.getElementById('note-detail-panel').innerHTML = `
        <div class="p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h6 class="fw-bold mb-1">${fmtDate(note.note_date)}</h6>
                    <div class="text-muted small">
                        By: ${note.dentists?.full_name || note.created_by}
                        ${note.appointments ? ' · Appt: ' + note.appointments.appointment_code : ''}
                    </div>
                </div>
                <div class="d-flex gap-2">
                    ${note.is_private ? '<span class="badge bg-soft-warning text-warning">Private</span>' : ''}
                    <button class="btn btn-sm btn-outline-primary" onclick='openEditNoteModal(${JSON.stringify(note).replace(/'/g,"&#39;")})'>
                        <i class="feather-edit-2"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteNote(${note.note_id})">
                        <i class="feather-trash-2"></i>
                    </button>
                </div>
            </div>
            <div class="note-body p-3 rounded" style="background:var(--bs-light);white-space:pre-wrap;font-size:.875rem;line-height:1.7;">
${note.note_text}
            </div>
            <div class="text-muted mt-2" style="font-size:11px;">
                Added: ${new Date(note.created_at).toLocaleString('en-PH')}
                ${note.updated_at !== note.created_at ? ' · Edited: ' + new Date(note.updated_at).toLocaleString('en-PH') : ''}
            </div>
        </div>`;
}

function openAddNoteModal() {
    document.getElementById('noteModalTitle').innerHTML = '<i class="feather-file-text me-2 text-primary"></i>Add Clinical Note';
    document.getElementById('note_id_field').value    = '';
    document.getElementById('note_date_field').value  = new Date().toISOString().split('T')[0];
    document.getElementById('note_text_field').value  = '';
    document.getElementById('note_private_field').checked = false;
    new bootstrap.Modal(document.getElementById('noteModal')).show();
}

function openEditNoteModal(note) {
    document.getElementById('noteModalTitle').innerHTML = '<i class="feather-edit me-2 text-primary"></i>Edit Clinical Note';
    document.getElementById('note_id_field').value    = note.note_id;
    document.getElementById('note_date_field').value  = note.note_date;
    document.getElementById('note_text_field').value  = note.note_text;
    document.getElementById('note_private_field').checked = note.is_private;
    if (note.dentist_id) document.getElementById('note_dentist_field').value = note.dentist_id;
    new bootstrap.Modal(document.getElementById('noteModal')).show();
}

function saveNote() {
    const noteId   = document.getElementById('note_id_field').value;
    const noteText = document.getElementById('note_text_field').value.trim();
    const noteDate = document.getElementById('note_date_field').value;
    const isPrivate= document.getElementById('note_private_field').checked ? '1' : '0';
    const dentistId= document.getElementById('note_dentist_field').value;

    if (!noteText) { Swal.fire('Oops', 'Note text is required.', 'warning'); return; }

    const action = noteId ? 'update_note' : 'add_note';
    const fd = new FormData();
    fd.append('action', action);
    fd.append('patient_id', PATIENT_ID);
    fd.append('note_text', noteText);
    fd.append('note_date', noteDate);
    fd.append('is_private', isPrivate);
    if (dentistId) fd.append('dentist_id', dentistId);
    if (noteId)    fd.append('note_id', noteId);

    fetch(API_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            bootstrap.Modal.getInstance(document.getElementById('noteModal'))?.hide();
            if (data.success) {
                tabCache['notes'] = false; // invalidate cache
                loadNotesTab();
                Swal.fire({ icon:'success', title:'Saved', text: data.message, timer:1500, showConfirmButton:false });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        });
}

function deleteNote(noteId) {
    Swal.fire({
        title: 'Delete this note?',
        text: 'This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete',
    }).then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'delete_note');
        fd.append('patient_id', PATIENT_ID);
        fd.append('note_id', noteId);
        fetch(API_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    tabCache['notes'] = false;
                    document.getElementById('note-detail-panel').innerHTML = `
                        <div class="d-flex align-items-center justify-content-center h-100 text-muted" style="min-height:300px;">
                            <div class="text-center">
                                <i class="feather-file-text mb-2 d-block" style="font-size:2rem;opacity:.3;"></i>
                                <p class="small mb-0">Select a note to view details</p>
                            </div>
                        </div>`;
                    loadNotesTab();
                }
            });
    });
}

// ═══════════════════════════════════════════════════════════════════
// TAB: DENTAL CHART
// ═══════════════════════════════════════════════════════════════════
function loadDentalChartTab() {
    fetch(`${API_URL}?action=get_dental_chart&patient_id=${PATIENT_ID}`)
        .then(r => r.json())
        .then(data => {
            tabCache['dental'] = true;
            renderDentalChart(data.chart || {});
        });
}

function renderDentalChart(chartData) {
    // FDI notation: Upper right 18-11, upper left 21-28 / Lower right 48-41, lower left 31-38
    const upper = [[18,17,16,15,14,13,12,11],[21,22,23,24,25,26,27,28]];
    const lower = [[48,47,46,45,44,43,42,41],[31,32,33,34,35,36,37,38]];

    function toothBtn(num) {
        const treatments = chartData[String(num)] || [];
        const hasActive    = treatments.some(t => ['ongoing','in_progress','pending'].includes(t.status));
        const hasCompleted = treatments.some(t => t.status === 'completed');
        let cls = 'tooth-btn';
        let title = `Tooth #${num}`;
        if (hasActive)    { cls += ' tooth-active';    title += ' (Active treatment)'; }
        else if (hasCompleted) { cls += ' tooth-done'; title += ' (Treated)'; }
        return `<button class="${cls}" title="${title}" onclick="showToothDetail(${num}, event)">${num}</button>`;
    }

    function rowHtml(rows) {
        return rows.map(half => `<div class="d-flex gap-1">${half.map(toothBtn).join('')}</div>`).join(
            '<div class="tooth-divider mx-2"></div>'
        );
    }

    document.getElementById('dental-chart-content').innerHTML = `
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0"><i class="feather-smile me-2 text-primary"></i>Dental Chart (FDI Notation)</h6>
                <div class="d-flex gap-3 small text-muted">
                    <span><span class="tooth-legend tooth-active-legend"></span> Active</span>
                    <span><span class="tooth-legend tooth-done-legend"></span> Treated</span>
                    <span><span class="tooth-legend tooth-normal-legend"></span> No record</span>
                </div>
            </div>
            <div class="card-body">
                <div class="dental-chart-wrap">
                    <div class="text-center text-muted small mb-2 fw-semibold">UPPER</div>
                    <div class="d-flex justify-content-center gap-2 mb-1">${rowHtml(upper)}</div>
                    <div class="dental-midline my-2"></div>
                    <div class="d-flex justify-content-center gap-2 mt-1">${rowHtml(lower)}</div>
                    <div class="text-center text-muted small mt-2 fw-semibold">LOWER</div>
                </div>
            </div>
        </div>
        <div id="tooth-detail-card" class="card mt-3" style="display:none;">
            <div class="card-header"><h6 class="card-title mb-0" id="tooth-detail-title">Tooth Details</h6></div>
            <div class="card-body" id="tooth-detail-body"></div>
        </div>`;

    window._chartData = chartData; // store for detail clicks
}

function showToothDetail(num, event) {
    const treatments = (window._chartData || {})[String(num)] || [];
    const card = document.getElementById('tooth-detail-card');
    const title = document.getElementById('tooth-detail-title');
    const body  = document.getElementById('tooth-detail-body');

    title.innerHTML = `<i class="feather-smile me-2 text-primary"></i>Tooth #${num}`;

    if (treatments.length === 0) {
        body.innerHTML = '<p class="text-muted small mb-0">No treatment records for this tooth.</p>';
    } else {
        body.innerHTML = `<div class="table-responsive">
            <table class="table table-sm small mb-0">
                <thead><tr><th>Code</th><th>Service</th><th>Date</th><th>Stage</th><th>Status</th><th>Dentist</th></tr></thead>
                <tbody>
                ${treatments.map(t => `<tr>
                    <td class="fw-semibold">${t.treatment_code}</td>
                    <td>${t.services?.service_name || '—'}</td>
                    <td>${fmtDate(t.treatment_date)}</td>
                    <td>${t.current_stage}</td>
                    <td>${statusBadge(t.status)}</td>
                    <td>${t.dentists?.full_name || '—'}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>`;
    }

    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ═══════════════════════════════════════════════════════════════════
// TAB: TREATMENT PLANS
// ═══════════════════════════════════════════════════════════════════
function loadTreatmentsTab() {
    fetch(`${API_URL}?action=get_treatments&patient_id=${PATIENT_ID}`)
        .then(r => r.json())
        .then(data => {
            tabCache['treatments'] = true;
            renderTreatmentsTab(data.treatments || []);
        });
}

function renderTreatmentsTab(treatments) {
    const active    = treatments.filter(t => ['ongoing','in_progress','pending'].includes(t.status));
    const completed = treatments.filter(t => t.status === 'completed');
    const others    = treatments.filter(t => !['ongoing','in_progress','pending','completed'].includes(t.status));

    function trtTable(rows, emptyMsg) {
        if (rows.length === 0) return `<p class="text-muted small px-3 py-2 mb-0">${emptyMsg}</p>`;
        return `<div class="table-responsive">
            <table class="table table-hover small mb-0">
                <thead><tr><th>Code</th><th>Service</th><th>Tooth</th><th>Date</th><th>Stage</th><th>Cost</th><th>Status</th></tr></thead>
                <tbody>
                ${rows.map(t => `<tr>
                    <td class="fw-semibold">${t.treatment_code}</td>
                    <td>${t.services?.service_name || '—'}</td>
                    <td>${t.tooth_number || '—'}</td>
                    <td>${fmtDate(t.treatment_date)}</td>
                    <td>${t.current_stage}</td>
                    <td>${fmtMoney(t.cost)}</td>
                    <td>${statusBadge(t.status)}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>`;
    }

    const totalCost = treatments.reduce((s, t) => s + parseFloat(t.cost || 0), 0);

    document.getElementById('treatments-tab-content').innerHTML = `
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0"><i class="feather-activity me-2 text-warning"></i>Active / Ongoing Plans (${active.length})</h6>
            </div>
            <div class="card-body p-0">${trtTable(active, 'No active treatment plans.')}</div>
        </div>
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="card-title mb-0"><i class="feather-check-circle me-2 text-success"></i>Completed Plans (${completed.length})</h6>
            </div>
            <div class="card-body p-0">${trtTable(completed, 'No completed plans yet.')}</div>
        </div>
        ${others.length ? `<div class="card mb-3">
            <div class="card-header"><h6 class="card-title mb-0">Other Plans (${others.length})</h6></div>
            <div class="card-body p-0">${trtTable(others, '')}</div>
        </div>` : ''}
        <div class="d-flex justify-content-end">
            <div class="px-3 py-2 rounded bg-light border fw-bold">
                Total Treatment Cost: <span class="text-primary ms-2">${fmtMoney(totalCost)}</span>
            </div>
        </div>`;
}

// ═══════════════════════════════════════════════════════════════════
// TAB: BILLING
// ═══════════════════════════════════════════════════════════════════
function loadBillingTab() {
    fetch(`${API_URL}?action=get_billing&patient_id=${PATIENT_ID}`)
        .then(r => r.json())
        .then(data => {
            tabCache['billing'] = true;
            renderBillingTab(data.invoices || [], data.summary || {});
        });
}

function renderBillingTab(invoices, summary) {
    let invRows = '';
    invoices.forEach((inv, i) => {
        const payments = Array.isArray(inv.payments) ? inv.payments : [];
        const payHtml = payments.length === 0
            ? '<tr><td colspan="4" class="text-muted small py-2 ps-3">No payments recorded.</td></tr>'
            : payments.map(p => `<tr class="bg-light">
                <td class="ps-4 text-muted small" colspan="2">${fmtDate(p.payment_date)} — ${p.payment_method || '—'}</td>
                <td class="small text-success">${fmtMoney(p.amount)}</td>
                <td class="small text-muted">${p.notes || ''}</td>
              </tr>`).join('');

        invRows += `
            <tr class="cursor-pointer" onclick="togglePayments(${i})">
                <td class="fw-semibold">${inv.invoice_code}</td>
                <td>${fmtDate(inv.invoice_date)}</td>
                <td>${inv.services?.service_name || '—'}</td>
                <td>${fmtMoney(inv.total_amount)}</td>
                <td class="text-success">${fmtMoney(inv.amount_paid)}</td>
                <td class="${parseFloat(inv.balance) > 0 ? 'text-danger fw-bold' : 'text-success'}">${fmtMoney(inv.balance)}</td>
                <td>${statusBadge(inv.payment_status)}</td>
                <td><i class="feather-chevron-down" id="inv-chevron-${i}"></i></td>
            </tr>
            <tr id="inv-payments-${i}" style="display:none;">
                <td colspan="8" class="p-0">
                    <table class="table table-sm mb-0 small">${payHtml}</table>
                </td>
            </tr>`;
    });

    document.getElementById('billing-tab-content').innerHTML = `
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <div class="text-muted small mb-1">Total Billed</div>
                        <div class="fs-5 fw-bold">${fmtMoney(summary.total_billed || 0)}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <div class="text-muted small mb-1">Total Paid</div>
                        <div class="fs-5 fw-bold text-success">${fmtMoney(summary.total_paid || 0)}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body py-3">
                        <div class="text-muted small mb-1">Outstanding Balance</div>
                        <div class="fs-5 fw-bold ${parseFloat(summary.total_balance || 0) > 0 ? 'text-danger' : 'text-success'}">${fmtMoney(summary.total_balance || 0)}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h6 class="card-title mb-0"><i class="feather-file-text me-2 text-primary"></i>Invoices (click to expand payments)</h6></div>
            <div class="card-body p-0">
                ${invoices.length === 0 ? '<p class="text-muted small p-3 mb-0">No invoices found.</p>' :
                `<div class="table-responsive"><table class="table table-hover small mb-0">
                    <thead><tr><th>Code</th><th>Date</th><th>Service</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead>
                    <tbody>${invRows}</tbody>
                </table></div>`}
            </div>
        </div>`;
}

function togglePayments(index) {
    const row     = document.getElementById(`inv-payments-${index}`);
    const chevron = document.getElementById(`inv-chevron-${index}`);
    const isHidden = row.style.display === 'none';
    row.style.display = isHidden ? '' : 'none';
    chevron.className = isHidden ? 'feather-chevron-up' : 'feather-chevron-down';
}

// ═══════════════════════════════════════════════════════════════════
// PLACEHOLDERS (wire to your existing appointment modal)
// ═══════════════════════════════════════════════════════════════════
function openNewAppointmentModal() {
    // Call your existing openAddModal() here, pre-populated with PATIENT_ID
    // Example: document.getElementById('apt_patient').value = PATIENT_ID;
    // then trigger your existing modal
    alert('Wire this to your existing appointment modal and pre-select the patient.');
}

function openEditPatientModal(patientId) {
    // Wire to your existing patient edit modal/page
    alert('Wire this to your existing patient edit flow.');
}