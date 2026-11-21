/* =============================================================
   EssenciaSmile Admin — shared helpers for the record pages
   File: assets/js/es-records.js
   Pages: dentist-records.php, branches-records.php, accounts-records.php
   Styles: assets/css/es-records.css (.rec-phone, .rec-info, .rec-del-popup)

   • +63 mobile field  — <div class="rec-phone"><span class="rec-phone-prefix">+63</span>
                          <input class="js-rec-phone"></div>
     digits only, at most 10, starting with 9. Stored as "+639XXXXXXXXX"
     (same as the Patient Portal / controllers/portal_validation.php).
   • Info icon         — <button class="rec-info" data-bs-title="…"> shows the
     message on hover, focus or tap (Bootstrap tooltip).
   • Delete alerts     — recDeleteClass() = the danger alert with a larger "!".
   ============================================================= */
(function () {
    'use strict';

    /** "+639171234567" / "09171234567" / "9171234567" → "9171234567" ('' when it isn't a PH mobile). */
    window.recPhoneDigits = function (stored) {
        let d = String(stored || '').replace(/\D/g, '');
        if (d.startsWith('63') && d.length === 12) d = d.slice(2);
        else if (d.startsWith('0') && d.length === 11) d = d.slice(1);
        return /^9\d{9}$/.test(d) ? d : '';
    };

    /** Value to save from a .js-rec-phone input: "+639XXXXXXXXX", or '' when empty. */
    window.recPhoneValue = function (input) {
        const d = String(input.value || '').replace(/\D/g, '');
        return d ? '+63' + d : '';
    };

    /** Error text for a .js-rec-phone input, or '' when valid. */
    window.recPhoneError = function (input, required) {
        const d = String(input.value || '').replace(/\D/g, '');
        if (!d) return required ? 'Please enter a contact number.' : '';
        if (d[0] !== '9')    return 'The number must start with 9 (e.g. 917 123 4567).';
        if (d.length !== 10) return 'Enter all 10 digits after +63 (e.g. 917 123 4567).';
        return '';
    };

    /** "+639171234567" → "+63 917 123 4567" (other values unchanged). */
    window.recPhoneFormat = function (stored) {
        const d = window.recPhoneDigits(stored);
        return d ? '+63 ' + d.slice(0, 3) + ' ' + d.slice(3, 6) + ' ' + d.slice(6) : String(stored || '');
    };

    // Numbers only, always starting with 9, 10 digits max. Anything in front of the
    // first 9 is dropped, which also cleans pasted "+63 917-123-4567" / "0917 123 4567".
    document.addEventListener('input', function (e) {
        const el = e.target;
        if (!el.classList || !el.classList.contains('js-rec-phone')) return;
        let d = el.value.replace(/\D/g, '');
        // Deleting the 9 to retype it must not wipe the rest; typing / pasting can't start with another digit
        if (!String(e.inputType || '').startsWith('delete')) d = d.replace(/^[^9]+/, '');
        d = d.slice(0, 10);
        if (el.value !== d) el.value = d;
    });
    document.addEventListener('keydown', function (e) {
        const el = e.target;
        if (!el.classList || !el.classList.contains('js-rec-phone')) return;
        if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;   // shortcuts, arrows, Backspace…
        if (!/\d/.test(e.key)) return e.preventDefault();
        // The first digit can only be 9
        if ((el.selectionStart ?? 0) === 0 && e.key !== '9') e.preventDefault();
    });

    // Info icons: hover / focus on desktop, tap on touch screens
    function initInfo(root) {
        if (!window.bootstrap || !bootstrap.Tooltip) return;
        (root || document).querySelectorAll('.rec-info:not([data-rec-info-ready])').forEach(function (btn) {
            btn.setAttribute('data-rec-info-ready', '');
            new bootstrap.Tooltip(btn, { trigger: 'hover focus click', placement: btn.dataset.bsPlacement || 'top', container: 'body' });
        });
    }
    window.recInitInfo = initInfo;
    // An info icon inside a <label>: don't let the click also jump into the field
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('.rec-info')) e.preventDefault();
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { initInfo(); });
    else initInfo();
    // Close an open info tooltip when its modal closes
    document.addEventListener('hide.bs.modal', function (e) {
        e.target.querySelectorAll('.rec-info').forEach(function (btn) {
            const t = window.bootstrap && bootstrap.Tooltip.getInstance(btn);
            if (t) t.hide();
        });
    });

    // Bootstrap keeps focus inside an open modal; let a SweetAlert shown on top
    // of it (password prompt, confirmation) take the keyboard (as in appointments.js)
    document.addEventListener('focusin', function (e) {
        if (e.target && e.target.closest && e.target.closest('.swal2-container')) e.stopImmediatePropagation();
    }, true);

    /** Danger alert classes with the larger warning icon. */
    window.recDeleteClass = function () {
        const base = window.ES_SWAL_TONE ? ES_SWAL_TONE('danger') : {};
        return Object.assign({}, base, { popup: ((base.popup || '') + ' rec-del-popup').trim() });
    };
})();
