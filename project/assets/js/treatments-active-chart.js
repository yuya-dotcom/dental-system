/* =============================================================
   EssenciaSmile — Start Treatment modal: tooth chart
   File: assets/js/treatments-active-chart.js

   Draws the patient's CURRENT dental chart with the shared odontogram
   (assets/js/odontogram.js + assets/css/odontogram.css) — the same chart
   patients see in portal-dashboard.php → My Dental Chart.

   Clicking a tooth only FOCUSES it so the dentist can choose procedures
   for it (treatments-active.js → tooth panel). It never changes the
   tooth's condition; chart updates are confirmed separately on finish.

   Public API (used by treatments-active.js):
     initWMChart(patientId)   load + draw the patient's chart
     wmFocus(toothKey|null)   focus a tooth (or clear)
     wmRefresh()              redraw (e.g. after the plan changed)
     wmGetEntry(toothKey)     recorded chart entry for a tooth, or null
     wmIsLoaded()
   Calls back: window._wmOnToothFocus(toothKey|null)
   Reads:      window._wmIsPlanned(toothKey) → bool (planned-tooth marker)
   ============================================================= */

(function () {
    const O = window.Odontogram;

    const WM = {
        patientId : 0,
        chartData : {},   // { "14": { condition, notes, surfaces: { B: { condition } } } }
        focused   : null, // focused tooth number (string)
        loaded    : false,
        loadSeq   : 0,    // ignores a slow response for a previously opened patient
    };

    const $ = id => document.getElementById(id);
    const isFocused = key => key === WM.focused;
    const isPlanned = key => typeof window._wmIsPlanned === 'function' && !!window._wmIsPlanned(key);

    function render() {
        const up = $('wm_upperTeeth'), lo = $('wm_lowerTeeth');
        if (up && lo) O.renderArches(up, lo, WM.chartData, isFocused, isPlanned);
    }

    function notify() {
        if (typeof window._wmOnToothFocus === 'function') window._wmOnToothFocus(WM.focused);
    }

    window.wmFocus = function (toothKey) {
        WM.focused = toothKey ? String(toothKey) : null;
        render();
        notify();
        if (WM.focused) {
            const btn = document.querySelector('#wm_chart_grids .odo-tooth[data-tooth="' + WM.focused + '"]');
            if (btn) btn.focus({ preventScroll: true });
        }
    };

    window.wmRefresh  = render;
    window.wmIsLoaded = () => WM.loaded;
    window.wmGetEntry = key => WM.chartData[String(key)] || null;

    window.initWMChart = async function (patientId) {
        const seq = ++WM.loadSeq;
        WM.patientId = patientId;
        WM.focused   = null;
        WM.chartData = {};
        WM.loaded    = false;

        O.ensureDefs();
        const legend = $('wm_legend');
        if (legend && !legend.innerHTML) {
            legend.innerHTML = O.legendHTML() +
                '<span class="odo-legend-item"><span class="tw-planned-swatch"></span>Procedure planned</span>';
        }

        const loadEl = $('wm_chart_loading'), gridEl = $('wm_chart_grids');
        if (loadEl) loadEl.style.display = '';
        if (gridEl) gridEl.style.display = 'none';
        render();
        notify();

        // The patient's current chart (same records the portal shows)
        try {
            const res  = await fetch('api/dental_chart.php?patient_id=' + encodeURIComponent(patientId));
            const data = await res.json();
            if (seq !== WM.loadSeq) return;
            if (data.success && Array.isArray(data.chart)) WM.chartData = O.normalize(data.chart).chart;
        } catch (e) {
            console.warn('[WMChart] Fetch failed:', e);
        }
        if (seq !== WM.loadSeq) return;

        WM.loaded = true;
        if (loadEl) loadEl.style.display = 'none';
        if (gridEl) gridEl.style.display = '';
        render();
        notify();
    };

    // Tooth clicks (buttons, so Enter / Space work from the keyboard too).
    // Clicking the focused tooth again clears the focus.
    document.addEventListener('click', function (e) {
        const tooth = e.target.closest('#wm_chart_grids .odo-tooth');
        if (tooth) window.wmFocus(tooth.dataset.tooth === WM.focused ? null : tooth.dataset.tooth);
    });
})();
