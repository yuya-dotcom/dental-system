/* =============================================================
   EssenciaSmile — Dental Chart Modal JS
   File: assets/js/treatments-chart.js
   Used by: treatments-records.php
   ============================================================= */

// ── State ─────────────────────────────────────────────────────
const DC = {
    patientId    : 0,
    patientName  : '',
    treatmentTooth: '',    // tooth(s) from the treatment row
    activeTooth  : null,  // currently selected tooth number (string)
    activeSurface: null,  // currently selected surface key (B/M/O/L/D)
    chartData    : {},    // { tooth_number: { condition, notes, surfaces: { B: {condition,notes}, … } } }
    pendingChanges: [],   // array of change objects to batch-save
    modified     : new Set(), // set of tooth_numbers with unsaved changes
};

// ── Condition colour map ───────────────────────────────────────
const DC_COND = {
    healthy    : { fill: '#ffffff', stroke: '#86efac', text: '#64748b' },
    filled     : { fill: '#3b82f6', stroke: '#2563eb', text: '#ffffff' },
    decay      : { fill: '#ef4444', stroke: '#dc2626', text: '#ffffff' },
    impacted   : { fill: '#a855f7', stroke: '#9333ea', text: '#ffffff' },
    missing    : { fill: '#9ca3af', stroke: '#6b7280', text: '#ffffff' },
    crown      : { fill: '#eab308', stroke: '#ca8a04', text: '#1e293b' },
    impacted_ne: { fill: '#22c55e', stroke: '#16a34a', text: '#ffffff' },
    none       : { fill: '#f8fafc', stroke: '#e2e8f0', text: '#94a3b8' },
};

const DC_COND_LABELS = {
    healthy    : 'Healthy',
    filled     : 'Filled',
    decay      : 'Decay',
    impacted   : 'Impacted',
    missing    : 'Missing',
    crown      : 'Crown',
    impacted_ne: 'Impacted (NE)',
};

// ── FDI order ──────────────────────────────────────────────────
const FDI_UPPER = ['18','17','16','15','14','13','12','11','21','22','23','24','25','26','27','28'];
const FDI_LOWER = ['48','47','46','45','44','43','42','41','31','32','33','34','35','36','37','38'];

// SVG ring wedge paths (outer r=28, inner r=12, centre 40,40)
const DC_RING = [
    { key:'B', lx:40, ly:19, d:'M 20.2,20.2 A 28,28 0 0,1 59.8,20.2 L 48.5,31.5 A 12,12 0 0,0 31.5,31.5 Z' },
    { key:'M', lx:57, ly:40, d:'M 59.8,20.2 A 28,28 0 0,1 59.8,59.8 L 48.5,48.5 A 12,12 0 0,0 48.5,31.5 Z' },
    { key:'L', lx:40, ly:61, d:'M 59.8,59.8 A 28,28 0 0,1 20.2,59.8 L 31.5,48.5 A 12,12 0 0,0 48.5,48.5 Z' },
    { key:'D', lx:23, ly:40, d:'M 20.2,59.8 A 28,28 0 0,1 20.2,20.2 L 31.5,31.5 A 12,12 0 0,0 31.5,48.5 Z' },
];

const FF = "'Inter',sans-serif";

// ── Build one tooth SVG ────────────────────────────────────────
function dcBuildSVG(toothNum) {
    const entry    = DC.chartData[toothNum];
    const isActive = DC.activeTooth === toothNum;
    const isMod    = DC.modified.has(toothNum);

    function col(surfKey) {
        if (!entry) return DC_COND.none;
        const surfs = entry.surfaces || {};
        if (surfs[surfKey]) return DC_COND[surfs[surfKey].condition] || DC_COND.none;
        return DC_COND[entry.condition] || DC_COND.none;
    }

    const wedges = DC_RING.map(({key, lx, ly, d}) => {
        const c = col(key);
        return `<path d="${d}" fill="${c.fill}" stroke="${c.stroke}" stroke-width="1.2"
                    onclick="dcSelectSurface('${key}',event)" style="cursor:pointer;"/>
                <text x="${lx}" y="${ly}" text-anchor="middle" dominant-baseline="middle"
                    fill="${c.text}" font-size="8" font-weight="600" font-family=${FF}
                    style="pointer-events:none;">${key}</text>`;
    }).join('');

    const oc = col('O');

    return `<div class="dc-tooth-box${isActive ? ' dc-active' : ''}${isMod ? ' dc-modified' : ''}"
                id="dc_tooth_${toothNum}"
                onclick="dcSelectTooth('${toothNum}',event)">
        <svg class="dc-tooth-svg" viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
            ${wedges}
            <circle cx="40" cy="40" r="12" fill="${oc.fill}" stroke="${oc.stroke}" stroke-width="1.2"
                onclick="dcSelectSurface('O',event)" style="cursor:pointer;"/>
            <text x="40" y="40" text-anchor="middle" dominant-baseline="middle"
                fill="${oc.text}" font-size="8" font-weight="600" font-family=${FF}
                style="pointer-events:none;">O</text>
        </svg>
        <div class="dc-tooth-num">${toothNum}</div>
    </div>`;
}

// ── Render both grids ──────────────────────────────────────────
function dcRenderGrids() {
    const upper = document.getElementById('dc_upperTeeth');
    const lower = document.getElementById('dc_lowerTeeth');
    if (upper) upper.innerHTML = FDI_UPPER.map(dcBuildSVG).join('');
    if (lower) lower.innerHTML = FDI_LOWER.map(dcBuildSVG).join('');
}

// ── Re-render single tooth (cheap update) ─────────────────────
function dcRefreshTooth(toothNum) {
    const el = document.getElementById('dc_tooth_' + toothNum);
    if (!el) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = dcBuildSVG(toothNum);
    el.replaceWith(tmp.firstElementChild);
}

// ── Select a tooth ────────────────────────────────────────────
function dcSelectTooth(toothNum, event) {
    event?.stopPropagation();

    // Deactivate previous
    if (DC.activeTooth) {
        const prev = document.getElementById('dc_tooth_' + DC.activeTooth);
        if (prev) prev.classList.remove('dc-active');
    }

    DC.activeTooth  = toothNum;
    DC.activeSurface = null;

    const el = document.getElementById('dc_tooth_' + toothNum);
    if (el) el.classList.add('dc-active');

    dcShowPanel();
}

// ── Select a surface (click on wedge/circle inside SVG) ───────
function dcSelectSurface(surfKey, event) {
    event?.stopPropagation();

    // If no tooth is active yet, ignore
    if (!DC.activeTooth) return;

    DC.activeSurface = surfKey;

    // Update surface button highlights
    document.querySelectorAll('.dc-surface-btn').forEach(btn => {
        btn.classList.toggle('dc-surf-active', btn.dataset.surf === surfKey);
    });

    // Show surface picker and pre-fill current condition
    const picker    = document.getElementById('dcSurfacePicker');
    const surfLabel = document.getElementById('dcActiveSurface');
    const surfSel   = document.getElementById('dcSurfCondSelect');

    if (picker)    picker.style.display = '';
    if (surfLabel) surfLabel.textContent = surfKey;

    if (surfSel) {
        const entry  = DC.chartData[DC.activeTooth];
        const surfs  = entry?.surfaces || {};
        surfSel.value = surfs[surfKey]?.condition || entry?.condition || 'healthy';
    }
}

// ── Show right panel for active tooth ─────────────────────────
function dcShowPanel() {
    document.getElementById('dcPanelEmpty').style.display  = 'none';
    document.getElementById('dcPanelEditor').style.display = '';
    document.getElementById('dcSurfacePicker').style.display = 'none';

    const label = document.getElementById('dcActiveTooth');
    if (label) label.textContent = DC.activeTooth;

    // Condition buttons
    dcRenderCondButtons();

    // Surface buttons
    dcRenderSurfaceButtons();

    // Notes
    const notesEl = document.getElementById('dcToothNotes');
    if (notesEl) {
        const entry    = DC.chartData[DC.activeTooth];
        notesEl.value = entry?.notes || '';
    }
}

// ── Condition button grid ──────────────────────────────────────
function dcRenderCondButtons() {
    const grid  = document.getElementById('dcCondGrid');
    if (!grid) return;

    const entry   = DC.chartData[DC.activeTooth];
    const current = entry?.condition || 'none';

    grid.innerHTML = Object.entries(DC_COND_LABELS).map(([key, label]) => {
        const dot   = `dc-dot-${key}`;
        const active = key === current ? 'dc-cond-active' : '';
        return `<button class="dc-cond-btn ${active}" onclick="dcSetToothCondition('${key}')">
                    <span class="dc-cond-dot ${dot}"></span>${label}
                </button>`;
    }).join('');
}

// ── Surface button row ─────────────────────────────────────────
function dcRenderSurfaceButtons() {
    const grid = document.getElementById('dcSurfaceGrid');
    if (!grid) return;

    const entry = DC.chartData[DC.activeTooth];
    const surfs = entry?.surfaces || {};

    grid.innerHTML = ['B','M','O','L','D'].map(s => {
        const cond    = surfs[s]?.condition || entry?.condition || 'none';
        const c       = DC_COND[cond] || DC_COND.none;
        const isActive = DC.activeSurface === s ? 'dc-surf-active' : '';
        return `<button class="dc-surface-btn ${isActive}" data-surf="${s}"
                    onclick="dcSelectSurface('${s}',event)">
                    <span class="surf-key">${s}</span>
                    <span class="surf-dot" style="background:${c.fill};border-color:${c.stroke};"></span>
                </button>`;
    }).join('');
}

// ── Set whole-tooth condition ──────────────────────────────────
function dcSetToothCondition(condition) {
    if (!DC.activeTooth) return;

    if (!DC.chartData[DC.activeTooth]) {
        DC.chartData[DC.activeTooth] = { condition: 'healthy', notes: '', surfaces: {} };
    }

    DC.chartData[DC.activeTooth].condition = condition;
    markToothModified();

    dcRenderCondButtons();
    dcRenderSurfaceButtons();
    dcRefreshTooth(DC.activeTooth);
    dcUpdateChangesBadge();
}

// ── Apply surface condition ────────────────────────────────────
function applySurfaceCondition() {
    const surf    = DC.activeSurface;
    const condSel = document.getElementById('dcSurfCondSelect');
    if (!surf || !condSel || !DC.activeTooth) return;

    const condition = condSel.value;

    if (!DC.chartData[DC.activeTooth]) {
        DC.chartData[DC.activeTooth] = { condition: 'healthy', notes: '', surfaces: {} };
    }
    if (!DC.chartData[DC.activeTooth].surfaces) {
        DC.chartData[DC.activeTooth].surfaces = {};
    }

    DC.chartData[DC.activeTooth].surfaces[surf] = { condition, notes: '' };

    markToothModified();
    dcRenderSurfaceButtons();
    dcRefreshTooth(DC.activeTooth);
    dcUpdateChangesBadge();

    Swal.fire({
        icon: 'success',
        title: `Surface ${surf} → ${DC_COND_LABELS[condition]}`,
        toast: true,
        position: 'top-end',
        timer: 1200,
        showConfirmButton: false,
    });
}

// ── Apply same condition to all surfaces ──────────────────────
function applyToAllSurfaces() {
    const condSel = document.getElementById('dcSurfCondSelect');
    if (!condSel || !DC.activeTooth) return;

    const condition = condSel.value;

    if (!DC.chartData[DC.activeTooth]) {
        DC.chartData[DC.activeTooth] = { condition: 'healthy', notes: '', surfaces: {} };
    }

    ['B','M','O','L','D'].forEach(s => {
        DC.chartData[DC.activeTooth].surfaces[s] = { condition, notes: '' };
    });

    markToothModified();
    dcRenderSurfaceButtons();
    dcRefreshTooth(DC.activeTooth);
    dcUpdateChangesBadge();

    Swal.fire({
        icon: 'success',
        title: `All surfaces → ${DC_COND_LABELS[condition]}`,
        toast: true,
        position: 'top-end',
        timer: 1200,
        showConfirmButton: false,
    });
}

// ── Mark tooth as modified ────────────────────────────────────
function markToothModified() {
    if (!DC.activeTooth) return;
    DC.modified.add(DC.activeTooth);

    // Sync notes
    const notesEl = document.getElementById('dcToothNotes');
    if (notesEl && DC.chartData[DC.activeTooth]) {
        DC.chartData[DC.activeTooth].notes = notesEl.value;
    }
}

// ── Changes badge ─────────────────────────────────────────────
function dcUpdateChangesBadge() {
    const badge = document.getElementById('dcChangesBadge');
    const count = document.getElementById('dcChangesCount');
    if (!badge || !count) return;

    const n = DC.modified.size;
    badge.style.display    = n > 0 ? '' : 'none !important';
    badge.style.cssText    = n > 0 ? '' : 'display:none!important';
    count.textContent      = n;
}

// ── Open chart modal ──────────────────────────────────────────
async function openChartModal(t) {
    DC.patientId     = t.patient_id;
    DC.patientName   = t.patient_name;
    DC.treatmentTooth = t.tooth_number || '';
    DC.activeTooth   = null;
    DC.activeSurface = null;
    DC.chartData     = {};
    DC.modified      = new Set();

    document.getElementById('dc_patient_id').value   = t.patient_id;
    document.getElementById('dc_treatment_tooth').value = t.tooth_number || '';
    document.getElementById('dcModalSubtitle').textContent =
        `${t.patient_name}  ·  ${t.treatment_code}  ·  ${t.service_name}`;
    document.getElementById('dcPanelEmpty').style.display  = '';
    document.getElementById('dcPanelEditor').style.display = 'none';
    document.getElementById('dcError').style.display       = 'none';
    dcUpdateChangesBadge();

    // Show modal first with loading grid
    new bootstrap.Modal(document.getElementById('dentalChartModal')).show();
    dcRenderGrids();

    // Fetch existing chart data
    try {
        const res  = await fetch(`api/dental_chart.php?patient_id=${t.patient_id}`);
        const data = await res.json();
        if (data.success && data.chart) {
            data.chart.forEach(row => {
                DC.chartData[row.tooth_number] = {
                    condition: row.condition || 'healthy',
                    notes    : row.notes     || '',
                    surfaces : {},
                };
                if (row.surfaces) {
                    Object.entries(row.surfaces).forEach(([surf, val]) => {
                        DC.chartData[row.tooth_number].surfaces[surf] = {
                            condition: val.condition || 'healthy',
                            notes    : val.notes     || '',
                        };
                    });
                }
            });
        }
    } catch (e) {
        console.warn('Chart fetch error:', e);
    }

    // Re-render with real data
    dcRenderGrids();

    // Auto-select treatment tooth(s) if present
    if (DC.treatmentTooth) {
        const nums = DC.treatmentTooth.split(',').map(s => s.trim()).filter(Boolean);
        if (nums.length > 0) {
            // Slight delay so modal is fully visible
            setTimeout(() => dcSelectTooth(nums[0]), 350);
        }
    }
}

// ── Save all pending changes ──────────────────────────────────
async function saveDentalChart() {
    if (DC.modified.size === 0) {
        Swal.fire({ icon: 'info', title: 'No changes to save.', timer: 1500, showConfirmButton: false });
        return;
    }

    const btn    = document.getElementById('dcSaveBtn');
    const errBox = document.getElementById('dcError');
    errBox.style.display = 'none';
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';

    const source  = document.getElementById('dcSource').value;
    const changes = [];

    DC.modified.forEach(toothNum => {
        const entry = DC.chartData[toothNum];
        if (!entry) return;

        const notes = entry.notes || '';

        // Whole-tooth change
        changes.push({
            type        : 'tooth',
            tooth_number: toothNum,
            surface     : null,
            condition   : entry.condition || 'healthy',
            notes,
        });

        // Surface changes
        Object.entries(entry.surfaces || {}).forEach(([surf, val]) => {
            changes.push({
                type        : 'surface',
                tooth_number: toothNum,
                surface     : surf,
                condition   : val.condition || 'healthy',
                notes       : val.notes || '',
            });
        });
    });

    try {
        const res  = await fetch('api/dental_chart.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body   : JSON.stringify({
                action    : 'upsert_batch',
                patient_id: DC.patientId,
                source,
                changes,
            }),
        });
        const data = await res.json();

        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-save me-1"></i> Save Chart';

        if (data.success) {
            DC.modified.clear();
            dcUpdateChangesBadge();
            dcRenderGrids();

            bootstrap.Modal.getInstance(document.getElementById('dentalChartModal'))?.hide();

            Swal.fire({
                icon : 'success',
                title: 'Dental Chart Saved',
                html : `${data.saved} tooth/surface change(s) saved for <strong>${DC.patientName}</strong>.`,
                timer: 2200,
                showConfirmButton: false,
            });
        } else {
            errBox.textContent   = data.message || 'Save failed.';
            errBox.style.display = '';
        }
    } catch (e) {
        btn.disabled  = false;
        btn.innerHTML = '<i class="feather-save me-1"></i> Save Chart';
        errBox.textContent   = 'Network error. Please try again.';
        errBox.style.display = '';
    }
}