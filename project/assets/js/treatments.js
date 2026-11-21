// ============================================================
//  EssenciaSmile — Treatments JS
//  File: assets/js/treatments.js
// ============================================================


// ── View Details Modal ──────────────────────────────────────
function viewTreatment(t) {
    const set = (id, v) => {
        const el = document.getElementById(id);
        const val = (v ?? '').toString().trim();
        el.textContent = val || '—';
        el.classList.toggle('ar-muted', !val);
    };
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

    document.getElementById('tv_code_sub').textContent = t.treatment_code || '—';
    set('tv_code', t.treatment_code);
    set('tv_date', t.treatment_date && t.treatment_date !== '—' ? t.treatment_date : '');
    document.getElementById('tv_time').textContent = t.treatment_time || '';
    set('tv_branch', t.branch_name);
    set('tv_cost', t.cost && t.cost !== '—' ? t.cost : '');
    set('tv_patient', t.patient_name);
    set('tv_appointment', t.appointment_code);
    set('tv_service', t.service_name);
    set('tv_dentist', t.dentist_name);
    set('tv_stage', t.current_stage);
    set('tv_tooth', t.tooth_number);
    set('tv_notes', t.notes);

    document.getElementById('tv_patient_link').innerHTML = t.patient_id
        ? `<a href="patient-records.php?id=${parseInt(t.patient_id, 10)}" class="text-primary fw-semibold">Open patient record <i class="feather-external-link"></i></a>`
        : '—';
    document.getElementById('tv_status').innerHTML =
        `<span class="badge ${esc(t.status_class || 'bg-soft-secondary text-secondary')}">${esc(t.status || '—')}</span>`;

    // Procedure lines (tooth + service + surfaces) saved when the treatment was finished
    const procs = Array.isArray(t.procedures) ? t.procedures : [];
    document.getElementById('tv_proc_card').style.display = procs.length ? '' : 'none';
    document.getElementById('tv_procs').innerHTML = procs.map(p =>
        `<tr><td>${p.tooth ? 'Tooth ' + esc(p.tooth) : '<span class="text-muted">General</span>'}</td>` +
        `<td>${esc(p.service)}</td><td>${p.surfaces ? esc(p.surfaces.split('').join(', ')) : (p.tooth ? '<span class="text-muted">Whole tooth</span>' : '—')}</td>` +
        `<td class="text-end">${esc(p.price)}</td></tr>`).join('');

    new bootstrap.Modal(document.getElementById('treatmentViewModal')).show();
}
