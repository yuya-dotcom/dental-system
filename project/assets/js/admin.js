// Rebuilds the list URL from every filter control and loads it (page resets to 1).
// With assets/js/es-ajax.js only the records table is refreshed (no page reload).
// Besides fieldMap, it always includes the shared toolbar controls rendered by
// controllers/list_filters.php (sort / search / date range) and any
// [data-list-param] dropdown, so changing one filter never drops the others.
window._applyFilters = function applyFilters(page, fieldMap) {
    const map = Object.assign({ sort: 'listSort', q: 'listSearch', from: 'listFrom', to: 'listTo' }, fieldMap);
    document.querySelectorAll('[data-list-param]').forEach(el => {
        if (el.id && !(el.dataset.listParam in map)) map[el.dataset.listParam] = el.id;
    });
    const p = new URLSearchParams();
    for (const [key, elId] of Object.entries(map)) {
        const el = document.getElementById(elId);
        if (!el) continue;
        const val = el.value.trim();
        if (!val || val === '0') continue;
        if (el.dataset.default !== undefined && val === el.dataset.default) continue; // default sort: keep URL clean
        p.set(key, val);
    }
    const url = page + (p.toString() ? '?' + p.toString() : '');
    if (window.esNavigate) window.esNavigate(url); else window.location.href = url;
};

// After a successful action: refresh only the records table (falls back to a reload).
function _esAfterAction() { return window.esRefresh ? window.esRefresh() : location.reload(); }

/* ── Password Toggle (login page) ────────────────────────────── */
function togglePassword(inputId = 'passwordInput', iconId = 'eyeIcon') {
    const input   = document.getElementById(inputId);
    const icon    = document.getElementById(iconId);
    if (!input || !icon) return;
    const isHidden = input.type === 'password';
    input.type     = isHidden ? 'text' : 'password';
    icon.className = isHidden ? 'feather-eye-off text-muted' : 'feather-eye text-muted';
}