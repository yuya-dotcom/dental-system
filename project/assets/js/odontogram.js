/* =============================================================
   assets/js/odontogram.js
   Shared dental chart (odontogram) renderer — styles: assets/css/odontogram.css.

   One source for the tooth drawings, Universal 1–32 numbering, condition
   labels / colours, legend and surface diagram, used by:
     • portal-dashboard.php → My Dental Chart (read-only, details panel)
     • treatments-active.php → Start Treatment modal (tooth selection)
   Each page keeps its own state and data loading; this file only draws.

   Chart data shape (from api/dental_chart.php or portal_auth get_dental_chart):
     { "14": { condition, notes, updated_at, surfaces: { B|M|O|L|D: { condition, notes } } } }
   ============================================================= */
(function () {
  'use strict';

  /* Condition values are the ones api/dental_chart.php accepts */
  const COND_LABEL = {
    healthy: 'Healthy', filled: 'Filled', decay: 'Decay', crown: 'Crown',
    impacted: 'Impacted', impacted_ne: 'Impacted (Not Erupted)', missing: 'Missing'
  };
  const DOT_COLOR = {
    healthy: '#22c55e', filled: '#3b82f6', decay: '#ef4444', crown: '#f59e0b',
    impacted: '#8b5cf6', impacted_ne: '#a78bfa', missing: '#94a3b8'
  };
  const SURF_NAME = { B: 'Buccal', M: 'Mesial', O: 'Occlusal', L: 'Lingual', D: 'Distal' };

  /* ── Tooth anatomy (Universal 1–32) ── */
  const SHAPES = {
    molar: { w: 40,
      root: 'M7 45 C5 31 6 15 9 6 Q11 1 13.5 6 L17 30 Q20 12 23 30 L26.5 6 Q29 1 31 6 C34 15 35 31 33 45 Z',
      crown: 'M5 44 C3 52 3 65 6 73 C8 79 14 80 20 76 C26 80 32 79 34 73 C37 65 37 52 35 44 Z' },
    premolar: { w: 30,
      root: 'M8 45 C7 31 9 13 13 4 Q15 0 17 4 C21 13 23 31 22 45 Z',
      crown: 'M5 44 C3 54 4 67 8 74 C11 79 19 79 22 74 C26 67 27 54 25 44 Z' },
    canine: { w: 28,
      root: 'M8 45 C7 29 9 9 13 1 Q14 -1 15 1 C19 9 21 29 20 45 Z',
      crown: 'M5 44 C4 56 7 68 14 80 C21 68 24 56 23 44 Z' },
    incisor: { w: 26,
      root: 'M8 45 C8 31 10 13 12.5 5 Q13 3 13.5 5 C16 13 18 31 18 45 Z',
      crown: 'M5 44 L4 71 Q4 79 13 79 Q22 79 22 71 L21 44 Z' }
  };
  const QUAD_TYPES = ['molar', 'molar', 'molar', 'premolar', 'premolar', 'canine', 'incisor', 'incisor'];
  const QUAD_NAMES = ['Third Molar', 'Second Molar', 'First Molar', 'Second Premolar',
    'First Premolar', 'Canine', 'Lateral Incisor', 'Central Incisor'];

  function toothInfo(n) {
    // index 0 = back molar … 7 = central incisor, within each quadrant
    let jaw, side, idx;
    if (n <= 8)       { jaw = 'Upper'; side = 'Right'; idx = n - 1; }
    else if (n <= 16) { jaw = 'Upper'; side = 'Left';  idx = 16 - n; }
    else if (n <= 24) { jaw = 'Lower'; side = 'Left';  idx = n - 17; }
    else              { jaw = 'Lower'; side = 'Right'; idx = 32 - n; }
    return { num: n, jaw: jaw, type: QUAD_TYPES[idx], name: jaw + ' ' + side + ' ' + QUAD_NAMES[idx] };
  }

  // Display order (viewer faces the patient: patient's right on the left)
  const UPPER_ORDER = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
  const LOWER_ORDER = [32, 31, 30, 29, 28, 27, 26, 25, 24, 23, 22, 21, 20, 19, 18, 17];

  function esc(v) {
    return String(v === null || v === undefined ? '' : v)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function condKey(c) { return String(c || '').trim().toLowerCase(); }
  function condLabel(c) {
    const k = condKey(c);
    return COND_LABEL[k] || (k ? String(c) : 'Not charted');
  }
  function condClass(c) {
    const k = condKey(c);
    if (!k) return 'none';
    return COND_LABEL[k] ? k : 'other';
  }
  function badge(c) {
    return '<span class="odo-badge odo-badge-' + condClass(c) + '">' + esc(condLabel(c)) + '</span>';
  }

  /* A surface "finding" = a recorded surface whose condition differs from the tooth's */
  const FINDING_PRIORITY = ['decay', 'filled', 'crown', 'impacted', 'impacted_ne', 'missing', 'healthy'];
  function surfaceFinding(entry) {
    if (!entry || !entry.surfaces) return null;
    const tooth = condKey(entry.condition);
    const found = Object.keys(entry.surfaces)
      .map(function (k) { return condKey(entry.surfaces[k] && entry.surfaces[k].condition); })
      .filter(function (c) { return c && c !== tooth; });
    if (!found.length) return null;
    found.sort(function (a, b) {
      const ia = FINDING_PRIORITY.indexOf(a), ib = FINDING_PRIORITY.indexOf(b);
      return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
    });
    return found[0];
  }
  function surfaceConds(entry) {
    const out = [];
    if (entry && entry.surfaces) {
      Object.keys(entry.surfaces).forEach(function (k) {
        const c = condKey(entry.surfaces[k] && entry.surfaces[k].condition);
        if (c) out.push(c);
      });
    }
    return out;
  }

  /* ── One tooth (button + SVG) ── */
  function toothSVG(info, entry, jaw) {
    const sh = SHAPES[info.type], w = sh.w;
    const c = condClass(entry && entry.condition);
    const finding = surfaceFinding(entry);
    const lift = c === 'impacted_ne' ? ' transform="translate(0,-9)"' : '';
    const flip = jaw === 'lower' ? ' transform="translate(0,74) scale(1,-1)"' : '';
    let extra = '';
    if (c === 'crown') extra += '<path class="odo-band" d="M6 51 H' + (w - 6) + '"/>';
    if (c === 'missing') {
      extra += '<path class="odo-x" d="M' + (w * 0.28) + ' 53 L' + (w * 0.72) + ' 72 M' +
        (w * 0.72) + ' 53 L' + (w * 0.28) + ' 72"/>';
    }
    if (finding) {
      extra += '<circle class="odo-dot" cx="' + (w / 2) + '" cy="62" r="4.2" fill="' +
        (DOT_COLOR[finding] || '#475569') + '"/>';
    }
    return '<svg class="odo-svg" viewBox="0 -10 ' + w + ' 94" aria-hidden="true" focusable="false">' +
      '<g' + flip + '><g' + lift + '>' +
      '<path class="odo-root" d="' + sh.root + '"/>' +
      '<path class="odo-crown" d="' + sh.crown + '"/>' + extra +
      '</g></g></svg>';
  }

  function toothButton(n, i, jaw, chart, isSelected, isPlanned) {
    const info = toothInfo(n);
    const key = String(n);
    const entry = chart[key];
    const c = condClass(entry && entry.condition);
    const finding = surfaceFinding(entry);
    const on = !!isSelected(key);
    const planned = !!(isPlanned && isPlanned(key));
    const d = (i - 7.5) / 7.5;
    const arc = -Math.round(12 * d * d);   // gentle "smile" curve: back teeth sit higher
    const label = 'Tooth ' + n + ', ' + info.name + ': ' + condLabel(entry && entry.condition) +
      (finding ? ', with a surface finding (' + condLabel(finding) + ')' : '') +
      (planned ? ', procedure planned' : '');
    const num = '<span class="odo-num">' + n + '</span>';
    const svg = toothSVG(info, entry, jaw);
    return '<button type="button" class="odo-tooth odo-c-' + c + (on ? ' is-selected' : '') + (planned ? ' is-planned' : '') + '"' +
      ' data-tooth="' + key + '" data-jaw="' + jaw + '" data-index="' + i + '"' +
      ' aria-pressed="' + (on ? 'true' : 'false') + '"' +
      ' aria-label="' + esc(label) + '" title="' + esc(label) + '"' +
      ' style="--w:' + SHAPES[info.type].w + ';--arc:' + arc + 'px;">' +
      (jaw === 'upper' ? svg + num : num + svg) + '</button>';
  }

  function rowHTML(order, jaw, chart, isSelected, isPlanned) {
    const half = function (list, offset, side) {
      return '<div class="odo-half odo-half-' + side + '">' +
        '<div class="odo-half-label">' + (side === 'r' ? "Patient's Right" : "Patient's Left") + '</div>' +
        '<div class="odo-teeth">' + list.map(function (n, j) { return toothButton(n, offset + j, jaw, chart, isSelected, isPlanned); }).join('') + '</div>' +
        '</div>';
    };
    return half(order.slice(0, 8), 0, 'r') + half(order.slice(8), 8, 'l');
  }

  /* Both arches into their containers. isSelected(key) → bool.
     Optional isPlanned(key) → bool adds an .is-planned marker (Start Treatment);
     callers that omit it (portal, chart editor) render exactly as before. */
  function renderArches(upperEl, lowerEl, chart, isSelected, isPlanned) {
    const sel = isSelected || function () { return false; };
    upperEl.innerHTML = rowHTML(UPPER_ORDER, 'upper', chart || {}, sel, isPlanned);
    lowerEl.innerHTML = rowHTML(LOWER_ORDER, 'lower', chart || {}, sel, isPlanned);
  }

  /* Rows from the API → { chart, outside } (only Universal 1–32 are drawn) */
  function normalize(rows) {
    const chart = {}, outside = [];
    (rows || []).forEach(function (row) {
      const key = String(row.tooth_number || '').trim();
      const n = parseInt(key, 10);
      if (String(n) === key && n >= 1 && n <= 32) chart[key] = row;
      else if (key) outside.push(key);
    });
    return { chart: chart, outside: outside };
  }

  /* Legend — the same items everywhere the chart appears */
  function legendHTML() {
    return ['healthy', 'filled', 'decay', 'crown', 'impacted', 'impacted_ne', 'missing'].map(function (k) {
      return '<span class="odo-legend-item"><span class="odo-swatch odo-sw-' + k + '"></span>' + COND_LABEL[k] + '</span>';
    }).join('') +
      '<span class="odo-legend-item"><span class="odo-swatch odo-sw-none"></span>Not charted</span>' +
      '<span class="odo-legend-item"><span class="odo-legend-dot"></span>Surface finding</span>';
  }

  /* SVG hatch used by "Impacted (Not Erupted)" — added once per page */
  function ensureDefs() {
    if (document.getElementById('odoHatch')) return;
    const holder = document.createElement('div');
    holder.innerHTML = '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>' +
      '<pattern id="odoHatch" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">' +
      '<rect width="5" height="5" fill="#ede9fe"></rect><line x1="0" y1="0" x2="0" y2="5" stroke="#8b5cf6" stroke-width="2"></line>' +
      '</pattern></defs></svg>';
    document.body.appendChild(holder.firstChild);
  }

  /* ── Surface diagram ── */
  const RING = [
    { k: 'B', d: 'M 20.2,20.2 A 28,28 0 0,1 59.8,20.2 L 48.5,31.5 A 12,12 0 0,0 31.5,31.5 Z', x: 40, y: 20 },
    { k: 'M', d: 'M 59.8,20.2 A 28,28 0 0,1 59.8,59.8 L 48.5,48.5 A 12,12 0 0,0 48.5,31.5 Z', x: 60, y: 40 },
    { k: 'L', d: 'M 59.8,59.8 A 28,28 0 0,1 20.2,59.8 L 31.5,48.5 A 12,12 0 0,0 48.5,48.5 Z', x: 40, y: 60 },
    { k: 'D', d: 'M 20.2,59.8 A 28,28 0 0,1 20.2,20.2 L 31.5,31.5 A 12,12 0 0,0 31.5,48.5 Z', x: 20, y: 40 }
  ];
  // Labels sit in the middle of each ring segment (radius 20 = halfway
  // between the inner circle r=12 and the outer edge r=28). Centring is
  // set on the element itself (text-anchor + dy) so it does not depend
  // on the font or on dominant-baseline, which browsers handle differently.
  const LBL = ' text-anchor="middle" dy="0.35em" font-size="9" font-weight="700"';
  /* opts (optional): { pickable: true, picked: ['M','O'] } turns each surface
     into a toggle (data-surface="X", .is-picked). Without opts the diagram is
     the same read-only picture used by the portal. */
  function surfaceDiagram(entry, opts) {
    // An unrecorded surface shows the tooth's condition
    const base = condClass(entry && entry.condition);
    const pick = !!(opts && opts.pickable);
    const picked = (opts && opts.picked) || [];
    const cls = function (k) {
      const s = entry && entry.surfaces && entry.surfaces[k];
      return 'odo-s-' + (s && s.condition ? condClass(s.condition) : base) +
        (pick ? ' odo-s-pick' + (picked.indexOf(k) > -1 ? ' is-picked' : '') : '');
    };
    const attrs = function (k) {
      return pick ? ' data-surface="' + k + '" role="checkbox" tabindex="0" aria-checked="' + (picked.indexOf(k) > -1) +
        '" aria-label="' + SURF_NAME[k] + ' surface"' : '';
    };
    return '<svg class="odo-surface-svg' + (pick ? ' is-pickable' : '') + '" viewBox="0 0 80 80" role="' + (pick ? 'group' : 'img') +
      '" aria-label="' + (pick ? 'Select tooth surfaces' : 'Tooth surfaces diagram') + '">' +
      RING.map(function (r) {
        return '<path class="' + cls(r.k) + '" d="' + r.d + '"' + attrs(r.k) + '/>' +
          '<text x="' + r.x + '" y="' + r.y + '"' + LBL + ' pointer-events="none">' + r.k + '</text>';
      }).join('') +
      '<circle class="' + cls('O') + '" cx="40" cy="40" r="12"' + attrs('O') + '/><text x="40" y="40"' + LBL + ' pointer-events="none">O</text></svg>';
  }

  window.Odontogram = {
    COND_LABEL: COND_LABEL, DOT_COLOR: DOT_COLOR, SURF_NAME: SURF_NAME,
    UPPER_ORDER: UPPER_ORDER, LOWER_ORDER: LOWER_ORDER,
    esc: esc, condKey: condKey, condLabel: condLabel, condClass: condClass, badge: badge,
    toothInfo: toothInfo, surfaceFinding: surfaceFinding, surfaceConds: surfaceConds,
    renderArches: renderArches, normalize: normalize, legendHTML: legendHTML,
    ensureDefs: ensureDefs, surfaceDiagram: surfaceDiagram
  };
})();
