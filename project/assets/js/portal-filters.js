/* =============================================================
   assets/js/portal-filters.js
   Shared Show/Hide Filters panel for the Patient Portal lists
   (My Appointments, Treatment History, Billing, Files, Waivers).

   Markup (portal-dashboard.php):
     <div class="pfl" data-pfl="billing">
       <div class="pfl-bar"> …optional quick filters…
         <button type="button" class="pfl-toggle">…</button></div>
       <div class="pfl-panel" hidden>
         <select data-pfl-field="status">…  <input type="date" data-pfl-field="from">…
         <span class="pfl-result"></span> <button type="button" class="pfl-reset">…
     </div>

   JS:
     PortalFilters.bind('billing', { onChange, onReset, extraActive })
     PortalFilters.values('billing')            → { status: 'paid', from: '', … }
     PortalFilters.setOptions('billing', 'status', [[value, label], …])
     PortalFilters.result('billing', shown, total, 'invoice')
     PortalFilters.inRange(date, from, to) / PortalFilters.has(text, query)

   Filtering only hides rows in the browser; each list is still loaded by
   its own ownership-checked endpoint. Hiding the panel keeps the choices;
   Reset Filters clears them.
   ============================================================= */
(function () {
  'use strict';

  const groups = {};

  function root(key) {
    return document.querySelector('.pfl[data-pfl="' + key + '"]');
  }
  function fields(key) {
    const r = root(key);
    return r ? Array.from(r.querySelectorAll('[data-pfl-field]')) : [];
  }

  function values(key) {
    const out = {};
    fields(key).forEach(function (el) {
      out[el.dataset.pflField] = String(el.value || '').trim();
    });
    return out;
  }

  function activeCount(key) {
    const g = groups[key];
    const n = fields(key).filter(function (el) { return String(el.value || '').trim() !== ''; }).length;
    return n + (g && g.extraActive ? (g.extraActive() ? 1 : 0) : 0);
  }

  function syncToggle(key) {
    const r = root(key);
    if (!r) return;
    const panel = r.querySelector('.pfl-panel');
    const btn = r.querySelector('.pfl-toggle');
    const open = !panel.hidden;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.classList.toggle('is-open', open);
    btn.querySelector('.pfl-toggle-label').textContent = open ? 'Hide Filters' : 'Show Filters';
    const icon = btn.querySelector('.pfl-toggle-icon');
    if (icon) icon.className = 'fa-solid ' + (open ? 'fa-chevron-up' : 'fa-sliders') + ' pfl-toggle-icon';

    const n = activeCount(key);
    const badge = btn.querySelector('.pfl-count');
    if (badge) {
      badge.hidden = !n;
      badge.textContent = n;
    }
    btn.setAttribute('aria-label', (open ? 'Hide filters' : 'Show filters') + (n ? ' (' + n + ' active)' : ''));
    const reset = r.querySelector('.pfl-reset');
    if (reset) reset.disabled = !n;

    // "From" later than "To" → say so (the list is simply empty then)
    const v = values(key);
    const warn = r.querySelector('.pfl-warn');
    if (warn) warn.hidden = !(v.from && v.to && v.from > v.to);
  }

  function changed(key) {
    syncToggle(key);
    const g = groups[key];
    if (g && g.onChange) g.onChange(values(key));
  }

  function bind(key, opts) {
    const r = root(key);
    if (!r || groups[key]) return;
    groups[key] = opts || {};
    const panel = r.querySelector('.pfl-panel');

    r.querySelector('.pfl-toggle').addEventListener('click', function () {
      panel.hidden = !panel.hidden; // the choices stay as they are
      syncToggle(key);
      if (!panel.hidden) {
        const first = panel.querySelector('.modal-cs-trigger, input:not([type="hidden"]), select');
        if (first && first.focus && document.activeElement === this) first.focus({ preventScroll: true });
      }
    });

    let t = null;
    r.addEventListener('input', function (e) {
      // Typing in a search box: wait for a short pause (selects and dates
      // are handled by their change event)
      if (!e.target.matches('input[type="search"][data-pfl-field]')) return;
      clearTimeout(t);
      t = setTimeout(function () { changed(key); }, 180);
    });
    r.addEventListener('change', function (e) {
      if (e.target.matches('[data-pfl-field]')) { clearTimeout(t); changed(key); }
    });

    const reset = r.querySelector('.pfl-reset');
    if (reset) reset.addEventListener('click', function () { resetAll(key); });
    syncToggle(key);
  }

  function resetAll(key) {
    fields(key).forEach(function (el) { el.value = ''; }); // custom select / date picker follow .value
    const g = groups[key];
    if (g && g.onReset) g.onReset();
    changed(key);
  }

  // Rebuild a <select>'s options from the loaded data, keeping the choice
  // (a choice that no longer exists falls back to "All")
  function setOptions(key, name, list) {
    const r = root(key);
    const sel = r && r.querySelector('select[data-pfl-field="' + name + '"]');
    if (!sel) return;
    const keep = sel.value;
    const allLabel = sel.dataset.pflAll || 'All';
    const sig = JSON.stringify(list);
    if (sel.dataset.pflSig !== sig) {
      sel.dataset.pflSig = sig;
      sel.innerHTML = '';
      sel.appendChild(new Option(allLabel, ''));
      list.forEach(function (o) {
        const pair = Array.isArray(o) ? o : [o, o];
        sel.appendChild(new Option(pair[1], pair[0]));
      });
    }
    const has = Array.from(sel.options).some(function (o) { return o.value === keep; });
    sel.value = has ? keep : '';
    if (!has && keep) syncToggle(key);
  }

  function result(key, shown, total, noun) {
    const r = root(key);
    const el = r && r.querySelector('.pfl-result');
    if (el) {
      const plural = function (n) { return noun + (n === 1 ? '' : 's'); };
      el.textContent = shown === total ?
        'Showing all ' + total + ' ' + plural(total) :
        'Showing ' + shown + ' of ' + total + ' ' + plural(total);
    }
    syncToggle(key);
  }

  // Dates compare as YYYY-MM-DD text (time / timezone parts are ignored)
  function inRange(date, from, to) {
    if (!from && !to) return true;
    const d = String(date || '').slice(0, 10);
    if (!d) return false;
    return (!from || d >= from) && (!to || d <= to);
  }

  function has(text, query) {
    if (!query) return true;
    return String(text || '').toLowerCase().indexOf(String(query).toLowerCase()) !== -1;
  }

  // Sorted, unique, non-empty values for a select
  function distinct(list, get) {
    const seen = {};
    (list || []).forEach(function (item) {
      const v = String(get(item) || '').trim();
      if (v) seen[v] = true;
    });
    return Object.keys(seen).sort(function (a, b) { return a.localeCompare(b); });
  }

  // "No rows match" table row with a Reset link
  function emptyRow(key, cols, noun) {
    return '<tr><td colspan="' + cols + '"><div class="pfl-empty">' +
      '<i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>' +
      '<p>No ' + noun + ' match your filters.</p>' +
      '<button type="button" class="pfl-empty-reset" onclick="PortalFilters.reset(\'' + key + '\')">Reset Filters</button>' +
      '</div></td></tr>';
  }

  window.PortalFilters = {
    bind: bind, values: values, setOptions: setOptions, result: result,
    reset: resetAll, refresh: syncToggle, active: activeCount,
    inRange: inRange, has: has, distinct: distinct, emptyRow: emptyRow,
  };
})();
