/* =============================================================
   EssenciaSmile — Work Modal Dental Chart
   File: assets/js/treatments-active-chart.js

   Purpose: Renders the interactive SVG dental chart inside the
   Work Modal (treatments-active.php). Shows existing patient
   tooth conditions as read-only colours. Clicking a tooth
   SELECTS it for the treatment's tooth_number field.

   Calls back into treatments-active.js via:
     window._wmOnToothToggle(selectedTeethArray)
   ============================================================= */

(function () {

    // ── Internal state ─────────────────────────────────────────
    const WM = {
        patientId   : 0,
        chartData   : {},   // { tooth_number: { condition, surfaces: {} } }
        selected    : [],   // array of selected tooth number strings
        loaded      : false,
        basePricePerTooth: 0,
    };

    // ── Condition colour map ───────────────────────────────────
    const COND = {
        healthy    : { fill: '#ffffff', stroke: '#86efac', text: '#64748b' },
        filled     : { fill: '#3b82f6', stroke: '#2563eb', text: '#ffffff' },
        decay      : { fill: '#ef4444', stroke: '#dc2626', text: '#ffffff' },
        impacted   : { fill: '#a855f7', stroke: '#9333ea', text: '#ffffff' },
        missing    : { fill: '#9ca3af', stroke: '#6b7280', text: '#ffffff' },
        crown      : { fill: '#eab308', stroke: '#ca8a04', text: '#1e293b' },
        impacted_ne: { fill: '#22c55e', stroke: '#16a34a', text: '#ffffff' },
        none       : { fill: '#f8fafc', stroke: '#e2e8f0', text: '#94a3b8' },
    };

    // ── FDI order ──────────────────────────────────────────────
const FDI_UPPER = ['1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16'];
const FDI_LOWER = ['17','18','19','20','21','22','23','24','25','26','27','28','29','30','31','32'];

    // ── SVG ring wedges ────────────────────────────────────────
    const RING = [
        { key:'B', lx:40, ly:19, d:'M 20.2,20.2 A 28,28 0 0,1 59.8,20.2 L 48.5,31.5 A 12,12 0 0,0 31.5,31.5 Z' },
        { key:'M', lx:57, ly:40, d:'M 59.8,20.2 A 28,28 0 0,1 59.8,59.8 L 48.5,48.5 A 12,12 0 0,0 48.5,31.5 Z' },
        { key:'L', lx:40, ly:61, d:'M 59.8,59.8 A 28,28 0 0,1 20.2,59.8 L 31.5,48.5 A 12,12 0 0,0 48.5,48.5 Z' },
        { key:'D', lx:23, ly:40, d:'M 20.2,59.8 A 28,28 0 0,1 20.2,20.2 L 31.5,31.5 A 12,12 0 0,0 31.5,48.5 Z' },
    ];

    const FF = "'Inter',sans-serif";

    // ── Resolve condition colour for a surface ─────────────────
    function colFor(toothNum, surfKey) {
        const entry = WM.chartData[toothNum];
        if (!entry) return COND.none;
        const surfs = entry.surfaces || {};
        if (surfs[surfKey]) return COND[surfs[surfKey].condition] || COND.none;
        return COND[entry.condition] || COND.none;
    }

    // ── Build one tooth HTML ───────────────────────────────────
    function buildTooth(toothNum) {
        const isSelected = WM.selected.includes(toothNum);

        const wedges = RING.map(({ key, lx, ly, d }) => {
            const c = colFor(toothNum, key);
            return `<path d="${d}" fill="${c.fill}" stroke="${c.stroke}" stroke-width="1.2"/>
                    <text x="${lx}" y="${ly}" text-anchor="middle" dominant-baseline="middle"
                        fill="${c.text}" font-size="7" font-weight="600"
                        font-family=${FF} style="pointer-events:none;">${key}</text>`;
        }).join('');

        const oc = colFor(toothNum, 'O');

        // Selection ring overlay
        const selRing = isSelected
            ? `<circle cx="40" cy="40" r="38" fill="none" stroke="#3454d1" stroke-width="3.5" stroke-dasharray="4 2" opacity="0.85"/>`
            : '';

        return `<div class="wm-tooth-box${isSelected ? ' wm-selected' : ''}"
                    id="wm_tooth_${toothNum}"
                    onclick="wmToggleTooth('${toothNum}')"
                    title="Tooth ${toothNum}${WM.chartData[toothNum] ? ' — ' + (WM.chartData[toothNum].condition || 'healthy') : ''}">
            <svg class="dc-tooth-svg" viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
                ${wedges}
                <circle cx="40" cy="40" r="12" fill="${oc.fill}" stroke="${oc.stroke}" stroke-width="1.2"/>
                <text x="40" y="40" text-anchor="middle" dominant-baseline="middle"
                    fill="${oc.text}" font-size="7" font-weight="600"
                    font-family=${FF} style="pointer-events:none;">O</text>
                ${selRing}
            </svg>
            <div class="wm-tooth-num">${toothNum}</div>
        </div>`;
    }

    // ── Render both grids ──────────────────────────────────────
    function renderGrids() {
        const upper = document.getElementById('wm_upperTeeth');
        const lower = document.getElementById('wm_lowerTeeth');
        if (upper) upper.innerHTML = FDI_UPPER.map(buildTooth).join('');
        if (lower) lower.innerHTML = FDI_LOWER.map(buildTooth).join('');
    }

    // ── Refresh single tooth ───────────────────────────────────
    function refreshTooth(toothNum) {
        const el = document.getElementById('wm_tooth_' + toothNum);
        if (!el) return;
        const tmp = document.createElement('div');
        tmp.innerHTML = buildTooth(toothNum);
        el.replaceWith(tmp.firstElementChild);
    }

    // ── Toggle tooth selection ─────────────────────────────────
    window.wmToggleTooth = function (toothNum) {
        const idx = WM.selected.indexOf(toothNum);
        if (idx === -1) {
            WM.selected.push(toothNum);
        } else {
            WM.selected.splice(idx, 1);
        }
        WM.selected.sort((a, b) => parseInt(a) - parseInt(b));
        refreshTooth(toothNum);

        // Update hidden input and label
        _wmSyncDisplay();

        // Callback into treatments-active.js cost recalc
        if (typeof window._wmOnToothToggle === 'function') {
            window._wmOnToothToggle(WM.selected);
        }
    };

    // ── Sync display label + hidden input ─────────────────────
    function _wmSyncDisplay() {
        const label    = document.getElementById('tooth_selected_label');
        const clearBtn = document.getElementById('tooth_clear_btn');
        const hidden   = document.getElementById('work_tooth_number');

        if (WM.selected.length === 0) {
            if (label)    label.textContent     = 'No teeth selected';
            if (clearBtn) clearBtn.style.display = 'none';
            if (hidden)   hidden.value           = '';
        } else {
            if (label)    label.textContent     = 'Selected: ' + WM.selected.join(', ');
            if (clearBtn) clearBtn.style.display = '';
            if (hidden)   hidden.value           = WM.selected.join(', ');
        }
    }

    // ── Public: clear all selections ──────────────────────────
    window.wmClearSelection = function () {
        const prev = [...WM.selected];
        WM.selected = [];
        prev.forEach(refreshTooth);
        _wmSyncDisplay();
        if (typeof window._wmOnToothToggle === 'function') {
            window._wmOnToothToggle([]);
        }
    };

    // ── Public: get selected teeth ─────────────────────────────
    window.wmGetSelected = function () { return [...WM.selected]; };

    // ── Public: set base price (called from treatments-active.js)
    window.wmSetBasePrice = function (price) {
        WM.basePricePerTooth = parseFloat(price) || 0;
    };

    // ── Public: pre-select teeth ───────────────────────────────
    window.wmPreSelect = function (toothStr) {
        if (!toothStr) return;
        const nums = toothStr.split(',').map(s => s.trim()).filter(Boolean);
        nums.forEach(n => { if (!WM.selected.includes(n)) WM.selected.push(n); });
        WM.selected.sort((a, b) => parseInt(a) - parseInt(b));
        renderGrids();
        _wmSyncDisplay();
    };

    // ── Public: init chart for a patient ──────────────────────
    window.initWMChart = async function (patientId, preSelectedTeeth) {
        WM.patientId = patientId;
        WM.selected  = [];
        WM.chartData = {};
        WM.loaded    = false;

        // Show loading state
        const loadEl = document.getElementById('wm_chart_loading');
        const gridEl = document.getElementById('wm_chart_grids');
        if (loadEl) loadEl.style.display = '';
        if (gridEl) gridEl.style.display = 'none';

        // Render blank grid immediately (so layout doesn't jump)
        renderGrids();

        // Fetch existing chart data
        try {
            const res  = await fetch(`api/dental_chart.php?patient_id=${patientId}`);
            const data = await res.json();
            if (data.success && Array.isArray(data.chart)) {
                data.chart.forEach(row => {
                    WM.chartData[row.tooth_number] = {
                        condition: row.condition || 'healthy',
                        notes    : row.notes     || '',
                        surfaces : {},
                    };
                    if (row.surfaces) {
                        Object.entries(row.surfaces).forEach(([s, val]) => {
                            WM.chartData[row.tooth_number].surfaces[s] = {
                                condition: val.condition || 'healthy',
                            };
                        });
                    }
                });
            }
        } catch (e) {
            console.warn('[WMChart] Fetch failed:', e);
        }

        WM.loaded = true;
        if (loadEl) loadEl.style.display = 'none';
        if (gridEl) gridEl.style.display = '';

        // Re-render with real data
        renderGrids();

        // Pre-select saved teeth
        if (preSelectedTeeth) {
            wmPreSelect(preSelectedTeeth);
        }

        _wmSyncDisplay();
    };

})();