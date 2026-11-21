/* =============================================================
   assets/js/es-actions.js — row action menus, every admin module.
   Loaded from partials/sidebar.php (styles: assets/css/es-actions.css).

   When a row's ⋮ menu (.dropdown-menu.action-dropdown) is about to open,
   its items are rebuilt in the Appointment Records design: a tinted icon
   chip, the label, and a short description for the common actions. The
   item element itself is kept (its onclick / href / data-* stay as they
   are) and its original <i> and label nodes are MOVED, not recreated, so
   page scripts that hold references to them (e.g. billing's PDF spinner)
   keep working. Destructive items get the red style. Runs once per menu.
   ============================================================= */
(function () {
  'use strict';
  if (window.EsActions) return;

  // Short descriptions for the actions used across the modules (exact label match)
  const DESCRIPTIONS = {
    'view details': 'Open the full record',
    'view appointment': 'Open the full appointment',
    'view appointments': 'See the related appointments',
    'view movements': 'Stock in and out history',
    'edit': 'Change the details',
    'edit appointment': 'Change the date, time or services',
    'delete': 'Remove this record',
    'reset password': 'Set a new password',
    'confirm appointment': 'Mark as confirmed',
    'check in patient': 'Mark the patient as arrived',
    'cancel appointment': 'Cancel with a reason',
    'go to active treatments': 'Finish it to complete the visit',
    'add payment': 'Record a payment',
    'print receipt': 'Open a printable receipt',
    'download pdf': 'Save the receipt as a PDF',
    'restore': 'Bring it back to the list',
    'log movement': 'Record stock in or out',
    'activate': 'Make it available again',
    'deactivate': 'Hide it without deleting',
    'schedule exceptions': 'Open or close specific dates',
    'start treatment': 'Open the treatment form',
    'update treatment': 'Change the treatment record',
  };
  const DANGER_ICON = /feather-(trash|trash-2|x-octagon)\b/;

  /** Icon chip tone from the item's colour class, then its icon. */
  function toneOf(item, icon) {
    const c = item.className;
    if (/\btext-danger\b/.test(c) || (icon && DANGER_ICON.test(icon.className))) return 'red';
    if (/\btext-success\b/.test(c)) return 'green';
    if (/\btext-warning\b/.test(c)) return 'amber';
    if (/\btext-(primary|info)\b/.test(c)) return 'violet';
    const ic = icon ? icon.className : '';
    if (/feather-(edit|edit-2|edit-3)\b/.test(ic)) return 'slate';
    if (/feather-(key|lock)\b/.test(ic)) return 'amber';
    if (/feather-(calendar|clock)\b/.test(ic)) return 'teal';
    return 'blue';
  }

  function buildItem(item) {
    if (item.classList.contains('ar-mi')) return;
    const icon = item.querySelector(':scope > i, :scope > span > i');
    const tone = toneOf(item, icon);
    const danger = tone === 'red';

    // Label: an existing label span (kept, e.g. .rc-pdf-label) or the item's text
    let labelEl = item.querySelector(':scope > .rc-pdf-label, :scope > span:not(.badge)');
    if (labelEl && labelEl.contains(icon)) labelEl = null;
    const text = (labelEl ? labelEl.textContent : [...item.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join(' '))
      .replace(/\s+/g, ' ').trim();
    if (!labelEl) {
      labelEl = document.createElement('span');
      labelEl.textContent = text;
    }
    labelEl.classList.add('t');

    const chip = document.createElement('span');
    chip.className = 'ar-mi-ico t-' + tone;
    chip.setAttribute('aria-hidden', 'true');
    if (icon) chip.appendChild(icon);
    else { const i = document.createElement('i'); i.className = 'feather-chevron-right'; chip.appendChild(i); }

    const txt = document.createElement('span');
    txt.className = 'ar-mi-txt';
    txt.appendChild(labelEl);
    const desc = DESCRIPTIONS[text.toLowerCase()];
    if (desc) {
      const d = document.createElement('span');
      d.className = 'd';
      d.textContent = desc;
      txt.appendChild(d);
    }

    // Remove the leftover text nodes / empty wrappers, keep any badge
    [...item.childNodes].forEach((n) => { if (n.nodeType === 3 || (n.nodeType === 1 && !n.classList.contains('badge') && !n.textContent.trim() && !n.querySelector('i'))) n.remove(); });
    item.insertBefore(txt, item.firstChild);
    item.insertBefore(chip, item.firstChild);
    item.classList.add('ar-mi');
    item.classList.remove('text-danger', 'text-success', 'text-warning', 'text-primary', 'text-info');
    if (danger) item.classList.add('ar-mi-danger');
  }

  /** Rebuild one menu in place (idempotent). */
  function enhance(menu) {
    if (!menu || menu.dataset.esMenu === '1') return;
    menu.dataset.esMenu = '1';
    menu.classList.add('ar-menu');
    if (menu.querySelector('.ar-mi')) return;                  // already in the new markup (Appointment Records)
    const items = [...menu.querySelectorAll(':scope > .dropdown-item, :scope > li > .dropdown-item')];
    items.forEach(buildItem);
    // Destructive actions sit apart at the bottom: separator before the first one if missing
    const firstDanger = items.find((it) => it.classList.contains('ar-mi-danger'));
    if (firstDanger && items.indexOf(firstDanger) > 0) {
      let prev = firstDanger.previousElementSibling;
      while (prev && !prev.classList.contains('dropdown-item') && !prev.classList.contains('dropdown-divider')) prev = prev.previousElementSibling;
      if (!prev || !prev.classList.contains('dropdown-divider')) {
        const sep = document.createElement('div');
        sep.className = 'ar-menu-sep';
        sep.setAttribute('role', 'separator');
        firstDanger.parentNode.insertBefore(sep, firstDanger);
      }
    }
    // A divider right at the top (nothing above it) is just noise
    const first = menu.firstElementChild;
    if (first && first.classList.contains('dropdown-divider')) first.remove();
  }

  /** The menu a toggle opens: Bootstrap's own reference first (some pages move menus to <body>). */
  function menuOf(toggle) {
    const inst = window.bootstrap && bootstrap.Dropdown.getInstance && bootstrap.Dropdown.getInstance(toggle);
    return (inst && inst._menu) || (toggle.parentNode && toggle.parentNode.querySelector('.dropdown-menu'));
  }

  // Capture phase: runs before page scripts that move the menu elsewhere on show
  document.addEventListener('show.bs.dropdown', (e) => {
    const toggle = e.target;
    const menu = menuOf(toggle);
    if (menu && menu.classList.contains('action-dropdown')) {
      enhance(menu);
      if (toggle.getAttribute('data-bs-offset') === null) {
        try { const inst = bootstrap.Dropdown.getInstance(toggle); if (inst && inst._config) inst._config.offset = [0, 6]; } catch (err) { /* keep default */ }
      }
    }
  }, true);

  window.EsActions = { enhance };
})();
