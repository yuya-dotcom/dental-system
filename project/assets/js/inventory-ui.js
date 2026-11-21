/* =============================================================
   EssenciaSmile Admin — shared UI helpers for the Inventory pages
   File: assets/js/inventory-ui.js
   Pages: inventory-records / -stock-movement / -transfers / -procurement
   Depends on: sweetalert2 v11, es-swal.js (ES_SWAL_CLASS / ES_SWAL_TONE),
   custom-select.js, es-records.js (recDeleteClass).

   Every message is a SweetAlert in the system's existing es-swal design
   (assets/css/es-swal.css) — no toasts, no Inventory-only styling:
     success   = the "Account Updated" alert (accounts.js): check glyph, success tone, OK
     error     = the record pages' error alert (dentists.js / branches.js): ES_SWAL_CLASS
     confirm   = the appointment status dialogs (appointments.js): glyph + ES_SWAL_TONE
     delete    = recDeleteClass() (es-records.js)
   Primary action LEFT, "Go Back" RIGHT (es-swal order — never reverseButtons).

     InvUI.ok(msg, title)     success alert
     InvUI.info(msg, title)   information alert
     InvUI.fail(title, msg)   error alert with a single OK
     InvUI.confirm({...})     confirmation → Swal promise
     InvUI.enhance()          style <select>/<input type=date> added at runtime
     InvUI.resetForm(form)    form.reset() that also refreshes custom fields
     InvUI.busy(btn, on)      spinner on a submit button
   ============================================================= */
(function () {
    'use strict';
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    const hasTone = () => typeof window.ES_SWAL_TONE === 'function';

    // System classes only; the reason box / validation line are the ones the
    // appointment "Cancel Appointment?" dialog already uses.
    function classes(tone, del) {
        if (!hasTone()) return {};
        const base = del && window.recDeleteClass ? window.recDeleteClass() : (tone === 'primary' ? window.ES_SWAL_CLASS : window.ES_SWAL_TONE(tone));
        return Object.assign({}, base, { input: 'es-swal-input apt-cancel-reason', validationMessage: 'es-swal-validation' });
    }

    const InvUI = {
        esc,

        /** Display name of a unit: 'pcs' → 'piece' / 'pieces' (the stored value stays 'pcs'). */
        unit(u, n) {
            const v = String(u == null ? '' : u).trim();
            if (v.toLowerCase() !== 'pcs') return v;
            return n != null && Number(n) === 1 ? 'piece' : 'pieces';
        },

        /** Success alert — same as "Account Updated" (accounts.js). */
        ok(msg, title) {
            return Swal.fire({
                icon: 'success', iconHtml: '<i class="feather-check es-swal-glyph"></i>',
                title: title || 'Saved', text: msg || '',
                confirmButtonText: 'OK',
                buttonsStyling: !hasTone(), customClass: hasTone() ? window.ES_SWAL_TONE('success') : undefined,
            });
        },

        /** Information alert (nothing went wrong, nothing was changed). */
        info(msg, title) {
            return Swal.fire({
                icon: 'info', iconHtml: '<i class="feather-info es-swal-glyph"></i>',
                title: title || 'Good to know', text: msg || '',
                confirmButtonText: 'OK',
                buttonsStyling: !window.ES_SWAL_CLASS, customClass: window.ES_SWAL_CLASS || undefined,
            });
        },

        /** Error alert — same as the record pages' "Could not delete" (dentists.js / branches.js). */
        fail(title, msg) {
            return Swal.fire({
                icon: 'error', title: title || 'Something went wrong', text: msg || '',
                confirmButtonText: 'OK',
                buttonsStyling: !window.ES_SWAL_CLASS, customClass: window.ES_SWAL_CLASS || undefined,
            });
        },

        /**
         * opts: tone ('success' | 'warn' | 'danger' | 'primary'), glyph (feather class),
         *       title, text, chip (item / document name), chipIcon, list ([html strings]),
         *       note (html), confirmText, cancelText, del (true = delete style with the large "!"),
         *       reason ({ label, placeholder, required }), preConfirm (value → promise)
         */
        confirm(o) {
            const tone = o.tone || 'primary';
            let html = '';
            if (o.text) html += `<p class="es-swal-text">${o.text}</p>`;
            if (o.chip) html += `<span class="es-swal-chip"><i class="${o.chipIcon || 'feather-package'}"></i> ${esc(o.chip)}</span>`;
            if (o.list && o.list.length) html += `<ul class="es-swal-list inv-swal-list">${o.list.map(li => `<li>${li}</li>`).join('')}</ul>`;
            if (o.note) html += `<div class="es-swal-note"><i class="feather-info"></i><span>${o.note}</span></div>`;
            if (o.reason) html += `<label class="apt-cancel-label" for="swal2-textarea">${esc(o.reason.label || 'Reason')}${o.reason.required !== false ? ' <span class="text-danger">*</span>' : ''}</label>`;
            const cfg = {
                icon: tone === 'danger' || tone === 'warn' ? 'warning' : 'question',
                title: o.title,
                html,
                showCancelButton: true,
                confirmButtonText: o.confirmText || 'Confirm',
                cancelButtonText: o.cancelText || 'Go Back',
                focusCancel: tone === 'danger',
                buttonsStyling: !hasTone(),
                customClass: classes(tone, o.del),
            };
            if (!o.del) cfg.iconHtml = `<i class="${o.glyph || 'feather-help-circle'} es-swal-glyph"></i>`;
            if (o.reason) {
                Object.assign(cfg, {
                    input: 'textarea',
                    inputPlaceholder: o.reason.placeholder || '',
                    inputAttributes: { maxlength: '250', 'aria-label': o.reason.label || 'Reason' },
                    inputValidator: o.reason.required === false ? undefined : v => (!String(v || '').trim() ? 'Please give a reason.' : undefined),
                });
            }
            if (o.preConfirm) {
                Object.assign(cfg, {
                    showLoaderOnConfirm: true,
                    allowOutsideClick: () => !Swal.isLoading(),
                    preConfirm: v => Promise.resolve(o.preConfirm(typeof v === 'string' ? v.trim() : v)).then(d => {
                        if (!d || !d.success) { Swal.showValidationMessage((d && d.message) || 'Could not save.'); return false; }
                        return d;
                    }).catch(() => { Swal.showValidationMessage('Could not connect to the server. Please try again.'); return false; }),
                });
            }
            return Swal.fire(cfg);
        },

        enhance() {
            if (window.enhanceCustomSelects) window.enhanceCustomSelects();
            if (window.enhanceDatePickers) window.enhanceDatePickers();
        },

        resetForm(form) {
            form.reset();
            // form.reset() bypasses the custom fields' value setters: push the defaults through them
            form.querySelectorAll('input.js-date-picker').forEach(i => { i.value = i.defaultValue; });
            if (window.refreshCustomSelects) window.refreshCustomSelects();
        },

        busy(btn, on, label) {
            if (!btn) return;
            btn.disabled = on;
            if (on) { btn.dataset.html = btn.innerHTML; btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> ${label || 'Saving…'}`; }
            else if (btn.dataset.html) btn.innerHTML = btn.dataset.html;
        },
    };
    window.InvUI = InvUI;

    // Row ⋮ menus in the inventory tables: position them "fixed" so they open
    // over the page instead of inside the table (which would clip them and
    // need scrolling), while the table itself can still scroll sideways on a
    // phone. Bootstrap 5.0 ignores data-bs-strategy, so the instance is made
    // here first. Bootstrap's own handler is a CAPTURE listener on document,
    // so this one listens on window (window capture runs before document's).
    function fixedMenu(e) {
        const t = e.target.closest && e.target.closest('.inv-table [data-bs-toggle="dropdown"]');
        if (!t || !window.bootstrap || !bootstrap.Dropdown || bootstrap.Dropdown.getInstance(t)) return;
        new bootstrap.Dropdown(t, { popperConfig: c => Object.assign({}, c, { strategy: 'fixed' }) });
    }
    window.addEventListener('click', fixedMenu, true);

    // A form's top error (.apt-form-alert) describes the form as it was when Save
    // was pressed. As soon as the user changes something in that form, it is out
    // of date: hide it. Save validates again and shows a current message if needed.
    function clearStaleError(e) {
        const form = e.target.closest && e.target.closest('.modal form');
        if (!form || !e.isTrusted && e.type === 'input') return;   // ignore code that only refreshes fields
        form.querySelectorAll('.apt-form-alert').forEach(b => { if (b.style.display !== 'none') { b.style.display = 'none'; b.textContent = ''; } });
    }
    document.addEventListener('input', clearStaleError);
    document.addEventListener('change', clearStaleError);
    window.addEventListener('keydown', fixedMenu, true);   // opened with the keyboard
})();
