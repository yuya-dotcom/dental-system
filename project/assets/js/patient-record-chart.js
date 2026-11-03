/* =============================================================
   EssenciaSmile — Patient Record → Dental Chart tab
   File: assets/js/patient-record-chart.js

   Draws the selected patient's chart with the shared odontogram
   (assets/js/odontogram.js + assets/css/odontogram.css) — the same
   teeth, numbering, colours, legend and surface diagram used by
   portal-dashboard.php (My Dental Chart) and treatments-active.php.

   Staff with chart access can record conditions here. Edits stay
   pending until "Save changes", then go through api/dental_chart.php
   (action=upsert_batch), which writes dental_chart /
   dental_chart_surfaces and logs every change to dental_chart_history.

   Public API: PatientChart.init({ patientId, patientName, canEdit })
               PatientChart.load()      (called when the tab is shown)
   ============================================================= */

(function () {
    'use strict';

    const O = window.Odontogram;
    if (!O) return;

    const CONDS = ['healthy', 'filled', 'decay', 'crown', 'impacted', 'impacted_ne', 'missing'];
    const SOURCE_LABEL = { consultation: 'Consultation', treatment_record: 'Treatment record', correction: 'Correction' };
    const $ = id => document.getElementById(id);
    const esc = O.esc, badge = O.badge;

    const S = {
        patientId: 0,
        patientName: '',
        canEdit: false,
        saved: {},          // chart as stored: "14" → { condition, notes, updated_at, surfaces }
        draft: {},          // saved + pending edits (what the chart shows)
        changes: new Map(), // "14|" (tooth) or "14|O" (surface) → upsert_batch change
        history: {},        // tooth → rows (cached per tooth)
        selected: null,
        picked: [],         // surfaces picked in the diagram
        loaded: false,
        loading: false,
    };

    const clone = v => JSON.parse(JSON.stringify(v || {}));
    const isSelected = key => key === S.selected;
    const hasPending = key => {
        for (const k of S.changes.keys()) if (k.split('|')[0] === key) return true;
        return false;
    };

    function fmtDay(d) {
        if (!d) return '—';
        const dt = new Date(String(d).length === 10 ? d + 'T00:00:00' : d);
        return isNaN(dt) ? '—' : dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function draftEntry(key, create) {
        if (!S.draft[key] && create) S.draft[key] = { condition: 'healthy', notes: '', surfaces: {} };
        if (S.draft[key] && !S.draft[key].surfaces) S.draft[key].surfaces = {};
        return S.draft[key] || null;
    }

    /* ── Chart + summary ── */
    function renderChart() {
        const up = $('pr_odoUpper'), lo = $('pr_odoLower');
        if (up && lo) O.renderArches(up, lo, S.draft, isSelected, hasPending);
    }

    function renderSummary() {
        let charted = 0, decay = 0, restored = 0, missing = 0;
        for (let n = 1; n <= 32; n++) {
            const e = S.saved[String(n)];
            if (!e) continue;
            charted++;
            const t = O.condKey(e.condition), s = O.surfaceConds(e);
            if (t === 'decay' || s.includes('decay')) decay++;
            if (t === 'filled' || t === 'crown' || s.includes('filled') || s.includes('crown')) restored++;
            if (t === 'missing') missing++;
        }
        const set = (id, v) => { const el = $(id); if (el) el.textContent = v; };
        set('pr_stat_charted', charted);
        set('pr_stat_decay', decay);
        set('pr_stat_restored', restored);
        set('pr_stat_missing', missing);
    }

    function renderSaveBar() {
        const bar = $('pr_chartSaveBar');
        if (!bar) return;
        const n = S.changes.size;
        bar.style.display = S.canEdit ? '' : 'none';
        $('pr_chartPending').textContent = n ? n + ' unsaved change' + (n === 1 ? '' : 's') : 'No unsaved changes';
        $('pr_chartPending').classList.toggle('is-dirty', n > 0);
        $('pr_chartSave').disabled = n === 0;
        $('pr_chartDiscard').disabled = n === 0;
    }

    /* ── Details / editor panel ── */
    function renderPanel() {
        const empty = $('pr_odoPanelEmpty'), body = $('pr_odoPanelBody');
        if (!S.selected) { empty.style.display = ''; body.style.display = 'none'; return; }

        const key = S.selected, n = parseInt(key, 10);
        const info = O.toothInfo(n);
        const entry = S.draft[key] || null;
        const saved = S.saved[key] || null;

        let html = '<div class="odo-p-head">' +
            '<div class="odo-p-num">' + n + '</div>' +
            '<div class="odo-p-titles"><div class="odo-p-title">Tooth ' + n + '</div>' +
            '<div class="odo-p-name">' + esc(info.name) + '</div></div>' +
            '<div class="odo-p-badge">' + badge(entry && entry.condition) + '</div></div>';

        if (hasPending(key)) {
            html += '<div class="pr-odo-pending"><i class="feather-edit-2"></i>Unsaved changes on this tooth</div>';
        }

        // Whole-tooth condition (editors only)
        if (S.canEdit) {
            const cur = O.condKey(entry && entry.condition);
            html += '<div class="odo-p-section"><div class="odo-p-label">Tooth Condition</div>' +
                '<div class="pr-odo-conds" role="group" aria-label="Whole-tooth condition">' +
                CONDS.map(c => '<button type="button" class="pr-odo-cond' + (c === cur ? ' is-active' : '') + '" data-cond="' + c + '"' +
                    ' aria-pressed="' + (c === cur) + '"><span class="odo-swatch odo-sw-' + c + '"></span>' + esc(O.COND_LABEL[c]) + '</button>').join('') +
                '</div></div>';
        }

        // Surfaces
        if (entry || S.canEdit) {
            const recorded = entry ? ['B', 'M', 'O', 'L', 'D'].filter(k => entry.surfaces && entry.surfaces[k] && entry.surfaces[k].condition) : [];
            html += '<div class="odo-p-section"><div class="odo-p-label">Surfaces</div>' +
                '<div class="odo-p-surfaces">' +
                O.surfaceDiagram(entry, S.canEdit ? { pickable: true, picked: S.picked } : null) +
                '<div class="odo-p-surface-list">';
            if (recorded.length) {
                recorded.forEach(k => {
                    const s = entry.surfaces[k];
                    html += '<div class="odo-p-surface"><span class="odo-p-skey">' + k + '</span>' +
                        '<div><div>' + esc(O.SURF_NAME[k]) + ' · ' + badge(s.condition) + '</div>' +
                        (s.notes ? '<div class="odo-p-note">' + esc(s.notes) + '</div>' : '') + '</div></div>';
                });
            } else {
                html += '<p class="odo-p-muted">' + (entry
                    ? 'No surface-level findings. The whole tooth is recorded as ' + esc(O.condLabel(entry.condition)) + '.'
                    : 'No condition has been recorded for this tooth yet.') + '</p>';
            }
            html += '</div></div>';

            if (S.canEdit) {
                html += S.picked.length
                    ? '<div class="pr-odo-surf-edit">' +
                        '<label class="form-label small fw-semibold mb-1" for="pr_surfCond">Set ' +
                        esc(S.picked.map(k => O.SURF_NAME[k]).join(', ')) + ' to</label>' +
                        '<div class="d-flex gap-2">' +
                        '<select id="pr_surfCond" class="form-select form-select-sm">' +
                        CONDS.map(c => '<option value="' + c + '">' + esc(O.COND_LABEL[c]) + '</option>').join('') + '</select>' +
                        '<button type="button" class="btn btn-primary btn-sm" id="pr_surfApply">Apply</button>' +
                        '<button type="button" class="btn btn-light btn-sm" id="pr_surfClear">Clear</button>' +
                        '</div></div>'
                    : '<p class="pr-odo-hint">Click surfaces on the diagram to record a surface finding.</p>';
            }
            html += '</div>';
        } else {
            html += '<p class="odo-p-muted">No condition has been recorded for this tooth yet.</p>';
        }

        // Notes
        html += '<div class="odo-p-section"><div class="odo-p-label">Dentist\'s Notes</div>';
        if (S.canEdit) {
            html += '<textarea id="pr_toothNotes" class="form-control form-control-sm" rows="3" maxlength="1000"' +
                ' placeholder="Clinical notes for this tooth (optional)">' + esc(entry ? entry.notes || '' : '') + '</textarea>';
        } else {
            html += entry && String(entry.notes || '').trim()
                ? '<p class="odo-p-text">' + esc(entry.notes) + '</p>'
                : '<p class="odo-p-muted">No notes for this tooth.</p>';
        }
        if (saved && saved.updated_at) {
            html += '<div class="odo-p-meta"><i class="feather-clock me-1"></i>Last updated ' + esc(fmtDay(saved.updated_at)) + '</div>';
        }
        html += '</div>';

        // Condition history (loaded per tooth)
        html += '<div class="odo-p-section"><div class="odo-p-label">Condition History</div>';
        const hist = S.history[key];
        if (hist === undefined) {
            html += '<p class="odo-p-muted">Loading history…</p>';
        } else if (!hist.length) {
            html += '<p class="odo-p-muted">No changes recorded.</p>';
        } else {
            html += '<ol class="odo-p-history">' + hist.map(h => {
                const what = h.surface ? 'Surface ' + esc(h.surface) + ' (' + esc(O.SURF_NAME[String(h.surface).toUpperCase()] || h.surface) + ')' : 'Whole tooth';
                return '<li><div class="odo-p-hdate">' + esc(fmtDay(h.changed_at)) +
                    (h.source ? ' · ' + esc(SOURCE_LABEL[h.source] || h.source) : '') + '</div>' +
                    '<div>' + what + ': ' + (h.old_condition ? badge(h.old_condition) + ' <span class="odo-p-arrow">→</span> ' : '') +
                    badge(h.new_condition) + '</div>' +
                    (h.notes ? '<div class="odo-p-note">' + esc(h.notes) + '</div>' : '') + '</li>';
            }).join('') + '</ol>';
        }
        html += '</div>';

        body.innerHTML = html;
        empty.style.display = 'none';
        body.style.display = '';
    }

    async function loadHistory(key) {
        if (S.history[key] !== undefined) return;
        try {
            const res = await fetch('api/dental_chart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_history', patient_id: S.patientId, tooth_number: key }),
            });
            const data = await res.json();
            S.history[key] = data.success && Array.isArray(data.history) ? data.history : [];
        } catch (e) {
            S.history[key] = [];
        }
        if (S.selected === key) renderPanel();
    }

    function select(key, focusAfter) {
        if (S.selected !== key) S.picked = [];
        S.selected = key;
        renderChart();
        renderPanel();
        loadHistory(key);
        if (window.innerWidth < 1200) {
            const panel = $('pr_odoPanel');
            const r = panel.getBoundingClientRect();
            if (r.top > window.innerHeight - 80) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        if (focusAfter) {
            const btn = document.querySelector('#pr_odoChart .odo-tooth[data-tooth="' + key + '"]');
            if (btn) btn.focus();
        }
    }

    /* ── Pending edits ── */
    function sameAsSaved(key, surface, condition, notes) {
        const s = S.saved[key];
        if (!surface) {
            return !!s && O.condKey(s.condition) === condition && String(s.notes || '') === notes;
        }
        const sv = s && s.surfaces && s.surfaces[surface];
        return !!sv && O.condKey(sv.condition) === condition;
    }

    function setTooth(key, condition) {
        const e = draftEntry(key, true);
        e.condition = condition;
        queueTooth(key);
    }

    function queueTooth(key) {
        const e = S.draft[key];
        const notes = String(e.notes || '');
        const ck = key + '|';
        if (sameAsSaved(key, '', O.condKey(e.condition), notes)) S.changes.delete(ck);
        else S.changes.set(ck, { type: 'tooth', tooth_number: key, surface: null, condition: O.condKey(e.condition), notes: notes });
    }

    function setSurfaces(key, surfaces, condition) {
        const e = draftEntry(key, true);
        surfaces.forEach(sf => {
            const prev = e.surfaces[sf] || {};
            e.surfaces[sf] = { condition: condition, notes: prev.notes || '' };
            const ck = key + '|' + sf;
            if (sameAsSaved(key, sf, condition)) S.changes.delete(ck);
            // notes '' → the API keeps any existing surface note
            else S.changes.set(ck, { type: 'surface', tooth_number: key, surface: sf, condition: condition, notes: '' });
        });
        // A new tooth row is created as "healthy" by the API — mirror that in the draft
        if (!S.saved[key] && !S.changes.has(key + '|')) queueTooth(key);
    }

    function discard() {
        S.draft = clone(S.saved);
        S.changes.clear();
        S.picked = [];
        renderChart();
        renderPanel();
        renderSaveBar();
    }

    async function save() {
        if (!S.changes.size) return;
        const btn = $('pr_chartSave');
        const source = $('pr_chartSource').value;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
        try {
            const res = await fetch('api/dental_chart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'upsert_batch',
                    patient_id: S.patientId,
                    source: source,
                    changes: Array.from(S.changes.values()),
                }),
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Save failed.');
            const errs = (data.errors || []).length;
            S.changes.clear();
            S.history = {};
            await load(true);
            Swal.fire({
                icon: errs ? 'warning' : 'success',
                title: errs ? 'Saved with issues' : 'Dental Chart Saved',
                html: esc(data.saved) + ' change(s) saved for <strong>' + esc(S.patientName) + '</strong>.' +
                    (errs ? '<br><small>' + esc(data.errors.join(', ')) + '</small>' : ''),
                timer: errs ? undefined : 2000,
                showConfirmButton: !!errs,
            });
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Could not save chart', text: e.message || 'Network error. Please try again.' });
        } finally {
            btn.innerHTML = '<i class="feather-save me-1"></i> Save changes';
            renderSaveBar();
        }
    }

    /* ── Load ── */
    async function load(force) {
        if (S.loading || (S.loaded && !force)) return;
        S.loading = true;
        const loadingEl = $('pr_odoLoading'), errEl = $('pr_odoError'), chartEl = $('pr_odoChart');
        if (!force) { loadingEl.style.display = ''; chartEl.style.display = 'none'; }
        errEl.style.display = 'none';
        try {
            const res = await fetch('api/dental_chart.php?patient_id=' + encodeURIComponent(S.patientId));
            const data = await res.json();
            if (!data.success || !Array.isArray(data.chart)) throw new Error(data.message || 'Could not load the dental chart.');
            const norm = O.normalize(data.chart);
            S.saved = norm.chart;
            S.draft = clone(S.saved);
            S.picked = [];
            S.loaded = true;

            const extra = $('pr_odoExtra');
            if (norm.outside.length) {
                extra.textContent = norm.outside.length + ' record(s) use a tooth number outside 1–32 (' +
                    norm.outside.join(', ') + ') and are not shown on this chart.';
                extra.style.display = '';
            } else {
                extra.style.display = 'none';
            }
            renderChart();
            renderSummary();
            renderPanel();
            renderSaveBar();
            if (S.selected) loadHistory(S.selected);
            loadingEl.style.display = 'none';
            chartEl.style.display = '';
        } catch (e) {
            loadingEl.style.display = 'none';
            chartEl.style.display = 'none';
            errEl.innerHTML = '<i class="feather-alert-circle"></i><span>' + esc(e.message || 'Could not load the dental chart.') + '</span>';
            errEl.style.display = '';
        } finally {
            S.loading = false;
        }
    }

    /* ── Events (delegated once, scoped to the tab) ── */
    function bind() {
        const root = $('pane-dentalchart');
        if (!root) return;

        root.addEventListener('click', e => {
            const tooth = e.target.closest('#pr_odoChart .odo-tooth');
            if (tooth) { select(tooth.dataset.tooth, false); return; }
            if (!S.canEdit || !S.selected) return;

            const cond = e.target.closest('.pr-odo-cond');
            if (cond) { setTooth(S.selected, cond.dataset.cond); refresh(); return; }

            const surf = e.target.closest('#pr_odoPanel [data-surface]');
            if (surf) { togglePick(surf.dataset.surface); return; }

            if (e.target.closest('#pr_surfApply')) {
                setSurfaces(S.selected, S.picked.slice(), $('pr_surfCond').value);
                S.picked = [];
                refresh();
                return;
            }
            if (e.target.closest('#pr_surfClear')) { S.picked = []; renderPanel(); }
        });

        root.addEventListener('keydown', e => {
            const surf = e.target.closest && e.target.closest('#pr_odoPanel [data-surface]');
            if (surf && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                togglePick(surf.dataset.surface);
                return;
            }
            // Arrow keys move between teeth (← → along the row, ↑ ↓ between arches)
            const btn = e.target.closest && e.target.closest('#pr_odoChart .odo-tooth');
            if (!btn || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
            e.preventDefault();
            const i = parseInt(btn.dataset.index, 10);
            let order = btn.dataset.jaw === 'upper' ? O.UPPER_ORDER : O.LOWER_ORDER, j = i;
            if (e.key === 'ArrowLeft') j = Math.max(0, i - 1);
            if (e.key === 'ArrowRight') j = Math.min(15, i + 1);
            if (e.key === 'ArrowUp') order = O.UPPER_ORDER;
            if (e.key === 'ArrowDown') order = O.LOWER_ORDER;
            select(String(order[j]), true);
        });

        root.addEventListener('input', e => {
            if (e.target.id !== 'pr_toothNotes' || !S.canEdit || !S.selected) return;
            const key = S.selected;
            const entry = draftEntry(key, true);
            entry.notes = e.target.value;
            queueTooth(key);
            renderChart();
            renderSaveBar();
        });

        if (!S.canEdit) return;
        $('pr_chartSave').addEventListener('click', save);
        $('pr_chartDiscard').addEventListener('click', discard);

        window.addEventListener('beforeunload', e => {
            if (S.changes.size) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    function togglePick(sf) {
        const i = S.picked.indexOf(sf);
        if (i > -1) S.picked.splice(i, 1); else S.picked.push(sf);
        renderPanel();
        const again = document.querySelector('#pr_odoPanel [data-surface="' + sf + '"]');
        if (again) again.focus();
    }

    function refresh() {
        renderChart();
        renderPanel();
        renderSaveBar();
    }

    window.PatientChart = {
        init: function (opts) {
            S.patientId = parseInt(opts.patientId, 10) || 0;
            S.patientName = opts.patientName || '';
            S.canEdit = !!opts.canEdit;
            O.ensureDefs();
            const legend = $('pr_odoLegend');
            if (legend) {
                legend.innerHTML = O.legendHTML() + (S.canEdit
                    ? '<span class="odo-legend-item"><span class="pr-odo-pending-swatch"></span>Unsaved change</span>'
                    : '');
            }
            bind();
            renderSaveBar();
        },
        load: () => load(false),
    };
})();
