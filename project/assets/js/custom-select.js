/* =============================================================
   assets/js/custom-select.js
   Behaviour for the shared .modal-cs dropdown
   (styles: assets/css/custom-select.css).

   Usage: add class="js-custom-select" to a short <select>,
          or class="js-date-picker" to an <input type="date">.
   The real <select> stays in the page (hidden) and remains the
   source of truth, so existing code that reads or sets
   select.value keeps working — the dropdown display follows it.
   ============================================================= */
(function () {
  'use strict';

  // Only what custom-select.css doesn't cover: hiding the native <select>,
  // keyboard focus styles, and the search box height / icon used by the
  // portal's address dropdowns.
  const css = `
    select.cs-native-hidden { position:absolute !important; width:1px; height:1px; padding:0; margin:-1px;
      overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0; opacity:0; pointer-events:none; }
    .modal-cs .modal-cs-trigger:focus-visible { outline:none; border-color:#185fa5; box-shadow:0 0 0 3px rgba(24,95,165,.15); }
    .modal-cs .modal-cs-list li.cs-focus { background:#e8f0fb; color:#185fa5; }
    .modal-cs .modal-cs-list li.active.cs-focus { background:#185fa5; color:#fff; }
    .modal-cs-list li[hidden] { display:none; }`;
  const style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  const valueDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
  let uid = 0;

  function closeAll(except) {
    document.querySelectorAll('.modal-cs.open').forEach(function (w) {
      if (w !== except) {
        w.classList.remove('open');
        const t = w.querySelector('.modal-cs-trigger');
        if (t) t.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function enhance(sel) {
    if (sel.dataset.csEnhanced) return;
    sel.dataset.csEnhanced = '1';
    const id = sel.id || ('cs_auto_' + (++uid));

    const wrap = document.createElement('div');
    // <select data-cs-filter>: compact admin header filter whose list floats (never clipped)
    // <select data-cs-float>: normal-size field whose list floats (e.g. inside a scrolling modal body)
    const isFilter = 'csFilter' in sel.dataset;
    const floating = isFilter || 'csFloat' in sel.dataset;
    if (isFilter && sel.options.length > 7) sel.dataset.csSearch = ''; // long filter lists get a search box
    wrap.className = 'modal-cs' + (isFilter ? ' cs-filter' + (sel.dataset.csFilter === 'wide' ? ' cs-wide' : '') : '') +
      (floating ? ' cs-float' : '') +
      (sel.classList.contains('form-select-sm') ? ' cs-sm' : ''); // compact forms keep their row height
    wrap.id = id + '_cs';
    wrap.innerHTML =
      '<div class="modal-cs-trigger" role="combobox" tabindex="0" aria-haspopup="listbox" aria-expanded="false"' +
      ' aria-controls="' + id + '_cslist" id="' + id + '_cstrigger">' +
      '<span class="modal-cs-value"></span><i class="fa-solid fa-chevron-down modal-cs-arrow" aria-hidden="true"></i></div>' +
      '<div class="modal-cs-dropdown">' +
      // Opt-in search box: <select data-cs-search> (same markup/styles as the portal's address dropdowns)
      ('csSearch' in sel.dataset
        ? '<div class="modal-cs-search-wrap"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>' +
          '<input type="text" class="modal-cs-search" placeholder="Search..." autocomplete="off"' +
          ' aria-label="Search options" aria-controls="' + id + '_cslist"></div>'
        : '') +
      '<ul class="modal-cs-list" role="listbox" id="' + id + '_cslist"></ul></div>';
    sel.insertAdjacentElement('afterend', wrap);
    sel.classList.add('cs-native-hidden');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    const trigger = wrap.querySelector('.modal-cs-trigger');
    const valueEl = wrap.querySelector('.modal-cs-value');
    const list = wrap.querySelector('.modal-cs-list');
    const search = wrap.querySelector('.modal-cs-search');

    // The field's <label for="…"> now points at the visible trigger
    if (sel.id) {
      document.querySelectorAll('label[for="' + sel.id + '"]').forEach(function (l) {
        l.htmlFor = trigger.id;
        trigger.setAttribute('aria-labelledby', l.id || (l.id = sel.id + '_label'));
        l.addEventListener('click', function (e) { e.preventDefault(); trigger.focus(); }); // a div can't take label focus by itself
      });
    }

    function placeholderText(t) {
      // "— Select —" → "Select"
      return String(t || '').replace(/^[\s—–-]+|[\s—–-]+$/g, '') || 'Select';
    }

    function build() {
      list.innerHTML = '';
      Array.from(sel.options).forEach(function (o, i) {
        // <select data-cs-no-placeholder>: the empty option ("None"/"Select") is only
        // shown on the closed field, never as a choice in the list
        if (o.value === '' && 'csNoPlaceholder' in sel.dataset) return;
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.dataset.index = i;
        li.textContent = o.value === '' ? placeholderText(o.text) : o.text;
        list.appendChild(li);
      });
      if (search) {
        const empty = document.createElement('li');
        empty.className = 'cs-disabled cs-empty';
        empty.textContent = 'No results found';
        empty.hidden = true;
        list.appendChild(empty);
      }
    }

    // Options currently shown (search may hide some); keyboard focus moves within these
    function visibleItems() {
      return Array.from(list.querySelectorAll('li[data-index]')).filter(function (li) { return !li.hidden; });
    }

    function filter(q) {
      q = q.trim().toLowerCase();
      list.querySelectorAll('li[data-index]').forEach(function (li) {
        const isPlaceholder = sel.options[+li.dataset.index].value === '';
        li.hidden = q !== '' && (isPlaceholder || !li.textContent.toLowerCase().includes(q));
      });
      const items = visibleItems();
      const empty = list.querySelector('.cs-empty');
      if (empty) empty.hidden = items.length > 0;
      focusItem(0);
    }

    function chooseFocused() {
      const li = visibleItems()[wrap._focus || 0];
      li ? choose(+li.dataset.index) : close(true);
    }

    function refresh() {
      wrap.classList.toggle('is-disabled', sel.disabled);
      trigger.setAttribute('aria-disabled', sel.disabled ? 'true' : 'false');
      trigger.tabIndex = sel.disabled ? -1 : 0;
      const i = sel.selectedIndex;
      const o = sel.options[i];
      const hasValue = !!(o && o.value !== '');
      valueEl.textContent = o ? (hasValue ? o.text : placeholderText(o.text)) : 'Select';
      valueEl.classList.toggle('cs-selected', hasValue);
      list.querySelectorAll('li').forEach(function (li) {
        const on = +li.dataset.index === i;
        li.classList.toggle('active', on && (hasValue || isFilter)); // filters: "All …" is a real choice
        li.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }

    function focusItem(n) {
      list.querySelectorAll('li.cs-focus').forEach(function (li) { li.classList.remove('cs-focus'); });
      const items = visibleItems();
      if (!items.length) return;
      n = Math.max(0, Math.min(items.length - 1, n));
      items.forEach(function (li, k) { li.classList.toggle('cs-focus', k === n); });
      wrap._focus = n;
      items[n].scrollIntoView({ block: 'nearest' });
    }

    function open() {
      if (sel.disabled) return;
      closeAll(wrap);
      wrap.classList.add('open');
      trigger.setAttribute('aria-expanded', 'true');
      if (search) {
        search.value = '';
        filter('');
      }
      // highlight the current choice (list positions can differ from option indexes)
      focusItem(Math.max(0, visibleItems().findIndex(function (li) { return +li.dataset.index === sel.selectedIndex; })));
      if (floating) place();
      if (search) search.focus({ preventScroll: floating });
      // Near the bottom of a scrolling panel, bring the opened list into view
      if (!floating) list.scrollIntoView({ block: 'nearest' });
    }

    // Floating list (filters): fixed to the viewport so no card, table or
    // scroll area clips it; below the field when it fits, otherwise above.
    const dropdown = wrap.querySelector('.modal-cs-dropdown');
    function place() {
      const EDGE = 8, MIN_W = 200;
      const r = trigger.getBoundingClientRect();
      const vw = document.documentElement.clientWidth, vh = window.innerHeight;
      const w = Math.min(Math.max(r.width, MIN_W), vw - EDGE * 2);
      const detached = w > r.width + 1;
      // --cs-gap (set by staff pages in admin.css): panel floats below the field as a separate card
      const sepGap = parseFloat(getComputedStyle(wrap).getPropertyValue('--cs-gap')) || 0;
      const GAP = detached ? 6 : (sepGap || -1);
      dropdown.style.width = w + 'px';
      list.style.maxHeight = '';
      const h = dropdown.offsetHeight;
      const below = vh - r.bottom - GAP - EDGE, above = r.top - GAP - EDGE;
      const extra = h - list.offsetHeight; // search strip + borders
      let top;
      if (h <= below || below >= above) {
        if (h > below) list.style.maxHeight = Math.max(120, below - extra) + 'px';
        top = r.bottom + GAP;
      } else {
        if (h > above) list.style.maxHeight = Math.max(120, above - extra) + 'px';
        top = r.top - GAP - dropdown.offsetHeight;
      }
      let left = r.left;
      if (left + w > vw - EDGE) left = vw - EDGE - w;
      dropdown.style.top = Math.round(top) + 'px';
      dropdown.style.left = Math.round(Math.max(EDGE, left)) + 'px';
      wrap.classList.toggle('cs-above', top < r.top);
      wrap.classList.toggle('cs-detached', detached);
    }
    if (floating) {
      const onMove = function (e) {
        if (!wrap.classList.contains('open')) return;
        if (e && e.target && e.target.nodeType === 1 && dropdown.contains(e.target)) return; // scrolling the list
        const r = trigger.getBoundingClientRect();
        if (r.bottom < 0 || r.top > window.innerHeight) return close(false);
        place();
      };
      window.addEventListener('scroll', onMove, true);
      window.addEventListener('resize', onMove);
    }

    function close(returnFocus) {
      wrap.classList.remove('open');
      trigger.setAttribute('aria-expanded', 'false');
      if (returnFocus) trigger.focus();
    }

    function choose(i) {
      if (i !== sel.selectedIndex) {
        valueDesc.set.call(sel, sel.options[i].value);
        refresh();
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      close(true);
    }

    trigger.addEventListener('click', function () {
      wrap.classList.contains('open') ? close(false) : open();
    });
    list.addEventListener('click', function (e) {
      const li = e.target.closest('li');
      if (li && li.dataset.index !== undefined) choose(+li.dataset.index);
    });
    trigger.addEventListener('keydown', function (e) {
      const isOpen = wrap.classList.contains('open');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!isOpen) return open();
        focusItem((wrap._focus || 0) + (e.key === 'ArrowDown' ? 1 : -1));
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        isOpen ? chooseFocused() : open();
      } else if (e.key === 'Escape' && isOpen) {
        e.preventDefault();
        close(true);
      } else if (e.key === 'Tab' && isOpen) {
        close(false);
      }
    });

    if (search) {
      search.addEventListener('input', function () { filter(search.value); });
      search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          focusItem((wrap._focus || 0) + (e.key === 'ArrowDown' ? 1 : -1));
        } else if (e.key === 'Enter') {
          e.preventDefault();
          chooseFocused();
        } else if (e.key === 'Escape') {
          e.preventDefault();
          close(true);
        } else if (e.key === 'Tab') {
          close(false);
        }
      });
    }

    // Code that sets select.value directly (e.g. loading the profile) updates the display too
    Object.defineProperty(sel, 'value', {
      configurable: true,
      get: function () { return valueDesc.get.call(this); },
      set: function (v) { valueDesc.set.call(this, v); refresh(); },
    });
    sel.addEventListener('change', refresh);
    sel._csRefresh = refresh;
    // Call after adding/removing <option>s at runtime
    sel._csRebuild = function () { build(); refresh(); };
    // Options replaced or the field enabled/disabled by page code (e.g. a list that
    // depends on another field): keep the dropdown in step automatically
    new MutationObserver(function () { build(); refresh(); })
      .observe(sel, { childList: true, attributes: true, attributeFilter: ['disabled'] });

    build();
    refresh();
  }

  // Close when clicking anywhere else
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.modal-cs')) closeAll(null);
  });

  function enhanceAll() {
    document.querySelectorAll('select.js-custom-select').forEach(enhance);
  }
  window.enhanceCustomSelects = enhanceAll;
  window.refreshCustomSelects = function () {
    document.querySelectorAll('select[data-cs-enhanced]').forEach(function (s) { if (s._csRefresh) s._csRefresh(); });
  };

  // ── Keyboard support for hand-built .modal-cs dropdowns ─────
  // (the cascading Province / City / Barangay and registration lists,
  // whose markup + click handlers live in the page). Only adds focus and
  // key handling; choosing an option still fires the page's own click
  // handler, so cascading logic and stored values are untouched.
  function isManual(wrap) { return wrap && !wrap.querySelector('.modal-cs-trigger[role="combobox"][aria-controls$="_cslist"]'); }

  function manualItems(wrap) {
    return Array.from(wrap.querySelectorAll('.modal-cs-list li')).filter(function (li) {
      return li.style.display !== 'none' && !li.hidden && !li.classList.contains('cs-disabled');
    });
  }

  function manualFocus(wrap, n) {
    const items = manualItems(wrap);
    wrap.querySelectorAll('.modal-cs-list li.cs-focus').forEach(function (li) { li.classList.remove('cs-focus'); });
    if (!items.length) return;
    n = Math.max(0, Math.min(items.length - 1, n));
    wrap._focus = n;
    items[n].classList.add('cs-focus');
    items[n].scrollIntoView({ block: 'nearest' });
  }

  function prepManual() {
    document.querySelectorAll('.modal-cs').forEach(function (wrap) {
      if (!isManual(wrap)) return;
      const t = wrap.querySelector('.modal-cs-trigger');
      if (t && !t.hasAttribute('tabindex')) {
        t.tabIndex = 0;
        t.setAttribute('role', 'button');
        t.setAttribute('aria-haspopup', 'listbox');
      }
    });
  }

  document.addEventListener('keydown', function (e) {
    const wrap = e.target.closest && e.target.closest('.modal-cs');
    if (!wrap || !isManual(wrap)) return;
    const trigger = wrap.querySelector('.modal-cs-trigger');
    const isOpen = wrap.classList.contains('open');

    if (e.target === trigger) {
      if (e.key === 'Enter' || e.key === ' ' || (e.key === 'ArrowDown' && !isOpen)) {
        e.preventDefault();
        trigger.click();
      } else if (e.key === 'Escape' && isOpen) {
        e.preventDefault();
        closeAll(null);
      }
      return;
    }
    if (!e.target.classList.contains('modal-cs-search')) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      manualFocus(wrap, (wrap._focus == null ? -1 : wrap._focus) + (e.key === 'ArrowDown' ? 1 : -1));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const li = manualItems(wrap)[wrap._focus || 0];
      if (li) li.click();
      closeAll(null);
      if (trigger) trigger.focus();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      closeAll(null);
      if (trigger) trigger.focus();
    } else if (e.key === 'Tab') {
      closeAll(null);
    }
  });
  // Typing in the search resets the highlight to the first match
  document.addEventListener('input', function (e) {
    if (!e.target.classList || !e.target.classList.contains('modal-cs-search')) return;
    const wrap = e.target.closest('.modal-cs');
    if (wrap && isManual(wrap)) { wrap._focus = null; wrap.querySelectorAll('li.cs-focus').forEach(function (li) { li.classList.remove('cs-focus'); }); }
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { enhanceAll(); prepManual(); });
  else { enhanceAll(); prepManual(); }
})();

/* =============================================================
   Date picker in the same .modal-cs style.
   Usage: <input type="date" class="js-date-picker">
     <input type="datetime-local" class="js-date-picker"> adds an hour / minute /
     AM-PM row (value stays YYYY-MM-DDTHH:MM)
     data-dp-max="today"   → future dates can't be picked
     data-dp-start="years" → opens on the year list when empty (birthdates)
   The real date input stays in the page (hidden) and keeps its
   value as YYYY-MM-DD, so validation, saving and onchange handlers
   (e.g. calcAge) keep working.
   ============================================================= */
(function () {
  'use strict';

  const css = `
    input.cs-native-hidden { position:absolute !important; width:1px; height:1px; padding:0; margin:-1px;
      overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0; opacity:0; pointer-events:none; }
    /* The calendar floats (position:fixed) so no parent card or scroll area can clip it.
       JS places it below or above the field, whichever has room, and keeps it on screen. */
    .modal-cs.cs-date .modal-cs-dropdown { position:fixed; top:0; left:0; right:auto; z-index:1080; overflow:auto; }
    /* Opening upward: the calendar joins the top edge of the field instead */
    .modal-cs.cs-date.dp-above .modal-cs-dropdown { border-top:1.5px solid #185fa5; border-bottom:none; border-radius:9px 9px 0 0;
      box-shadow:0 -8px 24px rgba(10,35,66,.12); }
    .modal-cs.cs-date.open.dp-above .modal-cs-trigger { border-radius:0 0 9px 9px; }
    /* Wider than a narrow field (phones): a standalone rounded box */
    .modal-cs.cs-date.dp-detached .modal-cs-dropdown { border:1.5px solid #185fa5; border-radius:9px; }
    .modal-cs.cs-date.open.dp-detached .modal-cs-trigger { border-radius:5px; }
    .modal-cs.cs-date .modal-cs-arrow { margin-left:8px; }
    .modal-cs.cs-date.open .modal-cs-arrow { transform:none; color:#185fa5; }
    .dp-panel { padding:10px; }
    .dp-head { display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:8px; }
    .dp-nav { width:30px; height:30px; border:none; background:transparent; border-radius:6px; color:#64748b; cursor:pointer;
      display:inline-flex; align-items:center; justify-content:center; }
    .dp-nav:hover { background:#e8f0fb; color:#185fa5; }
    .dp-nav:disabled { opacity:.3; cursor:default; background:transparent; }
    .dp-title { display:flex; gap:4px; }
    .dp-title button { border:none; background:transparent; font:inherit; font-size:.86rem; font-weight:700; color:#1a1a2e;
      padding:5px 8px; border-radius:6px; cursor:pointer; }
    .dp-title button:hover { background:#e8f0fb; color:#185fa5; }
    .dp-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; }
    .dp-grid.dp-big { grid-template-columns:repeat(3,1fr); gap:4px; }
    .dp-dow { font-size:.7rem; font-weight:700; color:#9da6b0; text-align:center; padding:4px 0; text-transform:uppercase; }
    .dp-cell { border:none; background:transparent; font:inherit; font-size:.82rem; color:#1a1a2e; height:31px; border-radius:6px;
      cursor:pointer; transition:background .12s,color .12s; }
    .dp-big .dp-cell { height:40px; }
    .dp-cell:hover { background:#e8f0fb; color:#185fa5; }
    .dp-cell.dp-out { color:#c4cbd6; }
    .dp-cell.dp-today { box-shadow:inset 0 0 0 1.5px #185fa5; }
    .dp-cell.dp-sel { background:#185fa5; color:#fff; font-weight:600; }
    .dp-cell.dp-focus { outline:2px solid #93b4dd; outline-offset:-2px; }
    .dp-cell:disabled { color:#d1d5db; cursor:not-allowed; background:transparent; box-shadow:none; }
    .dp-foot { display:flex; justify-content:space-between; margin-top:8px; padding-top:8px; border-top:1px solid #eef2f7; }
    .dp-foot button { border:none; background:transparent; font:inherit; font-size:.8rem; font-weight:600; color:#185fa5;
      padding:5px 8px; border-radius:6px; cursor:pointer; }
    .dp-foot button:hover { background:#e8f0fb; }
    .dp-foot button.dp-clear { color:#64748b; }
    .dp-foot-end { display:flex; gap:4px; }
    .dp-foot button.dp-done { background:#185fa5; color:#fff; }
    .dp-foot button.dp-done:hover { background:#134d86; }
    .dp-time { display:flex; align-items:center; gap:6px; margin-top:8px; padding-top:8px; border-top:1px solid #eef2f7; }
    .dp-time > i { color:#64748b; font-size:.85rem; margin-right:2px; }
    .dp-time-label { font-size:.78rem; font-weight:600; color:#64748b; margin-right:auto; }
    .dp-time select { height:32px; padding:0 6px; border:1.2px solid #e5e7eb; border-radius:6px; background:#fff;
      font:inherit; font-size:.82rem; color:#1a1a2e; cursor:pointer; }
    .dp-time select:focus-visible { outline:none; border-color:#185fa5; box-shadow:0 0 0 3px rgba(24,95,165,.15); }`;
  const style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
  const valueDesc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  const pad = n => String(n).padStart(2, '0');
  const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  function parse(v) {
    const m = /^(\d{4})-(\d{2})-(\d{2})(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?$/.exec(v || '');
    if (!m) return null;
    const d = new Date(+m[1], +m[2] - 1, +m[3]);
    return isNaN(d) ? null : d;
  }
  const pretty = d => MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
  const timeLabel = (h, mi) => ((h % 12) || 12) + ':' + pad(mi) + ' ' + (h < 12 ? 'AM' : 'PM');
  let uid = 0;

  function enhance(input) {
    if (input.dataset.dpEnhanced) return;
    input.dataset.dpEnhanced = '1';
    const id = input.id || ('dp_auto_' + (++uid));
    const withTime = input.type === 'datetime-local';

    const wrap = document.createElement('div');
    // <input data-cs-filter>: compact admin list filter (same size as the header dropdowns)
    wrap.className = 'modal-cs cs-date' + ('csFilter' in input.dataset ? ' cs-filter' : '');
    wrap.id = id + '_dp';
    wrap.innerHTML =
      '<div class="modal-cs-trigger" role="button" tabindex="0" aria-haspopup="dialog" aria-expanded="false" id="' + id + '_dptrigger">' +
      '<span class="modal-cs-value"></span><i class="fa-regular fa-calendar modal-cs-arrow" aria-hidden="true"></i></div>' +
      '<div class="modal-cs-dropdown" role="dialog" aria-label="' + (withTime ? 'Choose a date and time' : 'Choose a date') + '"><div class="dp-panel"></div></div>';
    input.insertAdjacentElement('afterend', wrap);
    input.classList.add('cs-native-hidden');
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');

    const trigger = wrap.querySelector('.modal-cs-trigger');
    const valueEl = wrap.querySelector('.modal-cs-value');
    const panel = wrap.querySelector('.dp-panel');

    if (input.id) {
      document.querySelectorAll('label[for="' + input.id + '"]').forEach(function (l) {
        l.htmlFor = trigger.id;
        trigger.setAttribute('aria-labelledby', l.id || (l.id = input.id + '_label'));
        l.addEventListener('click', function (e) { e.preventDefault(); trigger.focus(); });
      });
    }

    // data-dp-max="today": no future dates (birthdate, last visit)
    if (input.dataset.dpMax === 'today') input.max = iso(new Date()) + (withTime ? 'T23:59' : '');

    const minD = () => parse(input.min);
    const maxD = () => parse(input.max);
    const allowed = d => !((minD() && d < minD()) || (maxD() && d > maxD()));

    let view = 'days';          // days | months | years
    let cursor = new Date();    // month being shown / focused day
    let yearPage = 0;

    // Time mode: [hour 0-23, minute] from the value, else the current time
    function curTime() {
      const t = /T(\d{2}):(\d{2})/.exec(valueDesc.get.call(input) || '');
      if (t) return [+t[1], +t[2]];
      const n = new Date();
      return [n.getHours(), n.getMinutes()];
    }
    const compose = (dateIso, h, mi) => withTime ? dateIso + 'T' + pad(h) + ':' + pad(mi) : dateIso;

    function refresh() {
      const d = parse(valueDesc.get.call(input));
      const t = withTime && d ? curTime() : null;
      valueEl.textContent = d ? pretty(d) + (t ? ' · ' + timeLabel(t[0], t[1]) : '')
        : (input.getAttribute('placeholder') || (withTime ? 'Select date & time' : 'Select date'));
      valueEl.classList.toggle('cs-selected', !!d);
    }

    function render() {
      const sel = parse(valueDesc.get.call(input));
      const today = new Date();
      const y = cursor.getFullYear(), m = cursor.getMonth();
      let html = '';
      if (view === 'days') {
        const prevOk = !minD() || new Date(y, m, 0) >= new Date(minD().getFullYear(), minD().getMonth(), 1);
        const nextOk = !maxD() || new Date(y, m + 1, 1) <= maxD();
        html += '<div class="dp-head"><button type="button" class="dp-nav" data-dp="prev" aria-label="Previous month"' + (prevOk ? '' : ' disabled') + '><i class="fa-solid fa-chevron-left"></i></button>' +
          '<div class="dp-title"><button type="button" data-dp="months">' + MONTHS[m] + '</button><button type="button" data-dp="years">' + y + '</button></div>' +
          '<button type="button" class="dp-nav" data-dp="next" aria-label="Next month"' + (nextOk ? '' : ' disabled') + '><i class="fa-solid fa-chevron-right"></i></button></div>';
        html += '<div class="dp-grid">' + DOW.map(d => '<div class="dp-dow">' + d + '</div>').join('');
        const firstDow = new Date(y, m, 1).getDay();
        const start = new Date(y, m, 1 - firstDow);
        // Only the weeks this month needs (4–6 rows), so the calendar stays compact
        const cells = Math.ceil((firstDow + new Date(y, m + 1, 0).getDate()) / 7) * 7;
        for (let i = 0; i < cells; i++) {
          const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
          const cls = ['dp-cell'];
          if (d.getMonth() !== m) cls.push('dp-out');
          if (iso(d) === iso(today)) cls.push('dp-today');
          if (sel && iso(d) === iso(sel)) cls.push('dp-sel');
          if (iso(d) === iso(cursor)) cls.push('dp-focus');
          html += '<button type="button" class="' + cls.join(' ') + '" data-date="' + iso(d) + '"' +
            (allowed(d) ? '' : ' disabled') + ' aria-label="' + pretty(d) + '">' + d.getDate() + '</button>';
        }
        html += '</div>';
      } else if (view === 'months') {
        html += '<div class="dp-head"><button type="button" class="dp-nav" data-dp="prevYear" aria-label="Previous year"><i class="fa-solid fa-chevron-left"></i></button>' +
          '<div class="dp-title"><button type="button" data-dp="years">' + y + '</button></div>' +
          '<button type="button" class="dp-nav" data-dp="nextYear" aria-label="Next year"><i class="fa-solid fa-chevron-right"></i></button></div><div class="dp-grid dp-big">';
        MONTHS.forEach(function (name, i) {
          const last = new Date(y, i + 1, 0), first = new Date(y, i, 1);
          const ok = !((minD() && last < minD()) || (maxD() && first > maxD()));
          const cls = ['dp-cell'];
          if (sel && sel.getFullYear() === y && sel.getMonth() === i) cls.push('dp-sel');
          html += '<button type="button" class="' + cls.join(' ') + '" data-month="' + i + '"' + (ok ? '' : ' disabled') + '>' + name.slice(0, 3) + '</button>';
        });
        html += '</div>';
      } else {
        const base = Math.floor(y / 12) * 12 + yearPage * 12;
        html += '<div class="dp-head"><button type="button" class="dp-nav" data-dp="prevYears" aria-label="Earlier years"><i class="fa-solid fa-chevron-left"></i></button>' +
          '<div class="dp-title"><button type="button" tabindex="-1">' + base + ' – ' + (base + 11) + '</button></div>' +
          '<button type="button" class="dp-nav" data-dp="nextYears" aria-label="Later years"><i class="fa-solid fa-chevron-right"></i></button></div><div class="dp-grid dp-big">';
        for (let yy = base; yy < base + 12; yy++) {
          const ok = !((minD() && yy < minD().getFullYear()) || (maxD() && yy > maxD().getFullYear()));
          const cls = ['dp-cell'];
          if (sel && sel.getFullYear() === yy) cls.push('dp-sel');
          if (yy === today.getFullYear()) cls.push('dp-today');
          html += '<button type="button" class="' + cls.join(' ') + '" data-year="' + yy + '"' + (ok ? '' : ' disabled') + '>' + yy + '</button>';
        }
        html += '</div>';
      }
      if (withTime && view === 'days') {
        const t = curTime(), h12 = (t[0] % 12) || 12;
        let hrs = '', mins = '';
        for (let i = 1; i <= 12; i++) hrs += '<option value="' + i + '"' + (i === h12 ? ' selected' : '') + '>' + i + '</option>';
        for (let i = 0; i < 60; i++) mins += '<option value="' + i + '"' + (i === t[1] ? ' selected' : '') + '>' + pad(i) + '</option>';
        html += '<div class="dp-time"><i class="fa-regular fa-clock" aria-hidden="true"></i><span class="dp-time-label">Time</span>' +
          '<select data-t="h" aria-label="Hour">' + hrs + '</select>' +
          '<select data-t="m" aria-label="Minute">' + mins + '</select>' +
          '<select data-t="ap" aria-label="AM or PM"><option value="AM"' + (t[0] < 12 ? ' selected' : '') + '>AM</option>' +
          '<option value="PM"' + (t[0] >= 12 ? ' selected' : '') + '>PM</option></select></div>';
      }
      const todayOk = allowed(new Date(today.getFullYear(), today.getMonth(), today.getDate()));
      html += '<div class="dp-foot"><button type="button" class="dp-clear" data-dp="clear">Clear</button><span class="dp-foot-end">' +
        (todayOk ? '<button type="button" data-dp="today">' + (withTime ? 'Now' : 'Today') + '</button>' : '') +
        (withTime ? '<button type="button" class="dp-done" data-dp="done">Done</button>' : '') + '</span></div>';
      panel.innerHTML = html;
      if (wrap.classList.contains('open')) place();
    }

    // Below the field when it fits, otherwise above (whichever side has more
    // room); kept inside the window horizontally; scrolls inside if neither fits.
    const dropdown = wrap.querySelector('.modal-cs-dropdown');
    function place() {
      const EDGE = 8, MIN_W = 284;
      const r = trigger.getBoundingClientRect();
      const vw = document.documentElement.clientWidth, vh = window.innerHeight;
      // Same width as the field, like the other dropdowns; never narrower than the calendar needs
      const w = Math.min(Math.max(r.width, MIN_W), vw - EDGE * 2);
      const detached = w > r.width + 1;
      // --cs-gap (set by staff pages in admin.css): panel floats below the field as a separate card
      const sepGap = parseFloat(getComputedStyle(wrap).getPropertyValue('--cs-gap')) || 0;
      const GAP = detached ? 6 : (sepGap || -1); // attached (no --cs-gap): overlap the field's border
      dropdown.style.width = w + 'px';
      dropdown.style.maxHeight = '';
      const h = dropdown.offsetHeight;
      const below = vh - r.bottom - GAP - EDGE;
      const above = r.top - GAP - EDGE;
      let top;
      if (h <= below || below >= above) {
        top = r.bottom + GAP;
        if (h > below) dropdown.style.maxHeight = Math.max(160, below) + 'px';
      } else {
        const fit = Math.min(h, above);
        if (h > above) dropdown.style.maxHeight = Math.max(160, above) + 'px';
        top = r.top - GAP - fit;
      }
      let left = r.left;
      if (left + w > vw - EDGE) left = vw - EDGE - w;
      dropdown.style.top = Math.round(top) + 'px';
      dropdown.style.left = Math.round(Math.max(EDGE, left)) + 'px';
      wrap.classList.toggle('dp-above', top < r.top);
      wrap.classList.toggle('dp-detached', detached);
    }

    // Follow the field while the page (or any scroll area) moves; close once it scrolls away
    function onMove(e) {
      if (!wrap.classList.contains('open')) return;
      if (e && e.target && e.target.nodeType === 1 && dropdown.contains(e.target)) return; // scrolling inside the calendar
      const r = trigger.getBoundingClientRect();
      if (r.bottom < 0 || r.top > window.innerHeight) return close(false);
      place();
    }
    window.addEventListener('scroll', onMove, true);
    window.addEventListener('resize', onMove);

    function setValue(v) {
      if (v === valueDesc.get.call(input)) return;
      valueDesc.set.call(input, v);
      refresh();
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function open() {
      document.querySelectorAll('.modal-cs.open').forEach(w => { if (w !== wrap) w.classList.remove('open'); });
      const d = parse(valueDesc.get.call(input));
      cursor = d || (maxD() && maxD() < new Date() ? new Date(maxD()) : new Date());
      // Birthdates usually start far back: open on the year list when empty
      view = (!d && input.dataset.dpStart === 'years') ? 'years' : 'days';
      yearPage = 0;
      wrap.classList.add('open');
      render(); // also places it
      trigger.setAttribute('aria-expanded', 'true');
    }

    function close(returnFocus) {
      wrap.classList.remove('open');
      trigger.setAttribute('aria-expanded', 'false');
      if (returnFocus) trigger.focus();
    }

    trigger.addEventListener('click', () => wrap.classList.contains('open') ? close(false) : open());

    panel.addEventListener('click', function (e) {
      const b = e.target.closest('button');
      if (!b || b.disabled) return;
      e.stopPropagation();
      const y = cursor.getFullYear(), m = cursor.getMonth();
      if (b.dataset.date) {
        if (!withTime) { setValue(b.dataset.date); close(true); return; }
        // Time mode stays open so the time can be adjusted; keeps the chosen time
        const t = curTime();
        setValue(compose(b.dataset.date, t[0], t[1]));
        cursor = parse(b.dataset.date);
        return render();
      }
      if (b.dataset.month) { cursor = new Date(y, +b.dataset.month, 1); view = 'days'; return render(); }
      if (b.dataset.year) { cursor = new Date(+b.dataset.year, m, 1); view = 'months'; yearPage = 0; return render(); }
      switch (b.dataset.dp) {
        case 'prev': cursor = new Date(y, m - 1, 1); break;
        case 'next': cursor = new Date(y, m + 1, 1); break;
        case 'prevYear': cursor = new Date(y - 1, m, 1); break;
        case 'nextYear': cursor = new Date(y + 1, m, 1); break;
        case 'prevYears': yearPage--; break;
        case 'nextYears': yearPage++; break;
        case 'months': view = 'months'; break;
        case 'years': view = 'years'; yearPage = 0; break;
        case 'today': { const n = new Date(); setValue(compose(iso(n), n.getHours(), n.getMinutes())); close(true); return; }
        case 'done': close(true); return;
        case 'clear': setValue(''); close(true); return;
      }
      render();
    });

    trigger.addEventListener('keydown', function (e) {
      const isOpen = wrap.classList.contains('open');
      if (!isOpen) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); open(); }
        return;
      }
      const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
      if (step && view === 'days') {
        e.preventDefault();
        cursor = new Date(cursor.getFullYear(), cursor.getMonth(), cursor.getDate() + step);
        render();
      } else if (e.key === 'PageUp' || e.key === 'PageDown') {
        e.preventDefault();
        cursor = new Date(cursor.getFullYear(), cursor.getMonth() + (e.key === 'PageUp' ? -1 : 1), 1);
        view = 'days';
        render();
      } else if ((e.key === 'Enter' || e.key === ' ') && view === 'days') {
        e.preventDefault();
        if (allowed(cursor)) {
          const t = curTime();
          setValue(compose(iso(cursor), t[0], t[1]));
          if (withTime) render(); else close(true);
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        close(true);
      } else if (e.key === 'Tab' && !withTime) {
        close(false);
      }
    });

    if (withTime) {
      // Hour / minute / AM-PM selects write straight into the value
      panel.addEventListener('change', function (e) {
        if (!e.target.closest('select')) return;
        const get = k => panel.querySelector('[data-t="' + k + '"]').value;
        const h = (+get('h') % 12) + (get('ap') === 'PM' ? 12 : 0);
        const had = parse(valueDesc.get.call(input));
        setValue(compose(iso(had || cursor), h, +get('m')));
        if (!had) render(); // highlight the day that was just filled in; otherwise keep focus on the select
      });
      // Close once focus leaves the field and its calendar (keyboard users)
      wrap.addEventListener('focusout', function (e) {
        if (wrap.classList.contains('open') && e.relatedTarget && !wrap.contains(e.relatedTarget)) close(false);
      });
      panel.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); close(true); }
      });
    }

    // Code that sets input.value directly (loading the profile) updates the display too
    Object.defineProperty(input, 'value', {
      configurable: true,
      get: function () { return valueDesc.get.call(this); },
      set: function (v) { valueDesc.set.call(this, v); refresh(); },
    });
    input.addEventListener('change', refresh);
    refresh();
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.modal-cs.cs-date')) {
      document.querySelectorAll('.modal-cs.cs-date.open').forEach(w => {
        w.classList.remove('open');
        w.querySelector('.modal-cs-trigger').setAttribute('aria-expanded', 'false');
      });
    }
  });

  function enhanceAll() {
    document.querySelectorAll('input[type="date"].js-date-picker, input[type="datetime-local"].js-date-picker').forEach(enhance);
  }
  window.enhanceDatePickers = enhanceAll;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhanceAll);
  else enhanceAll();
})();