/* =============================================================
   assets/js/portal-validation.js
   Patient-detail rules for the Patient Portal, shared by registration
   (portal-login.php) and My Profile (portal-dashboard.php).
   Mirrors controllers/portal_validation.php — the server copy is the
   one that counts; this one gives instant feedback.

   1. Typing filters — add data-pv="…" to an input:
        name    letters, spaces, hyphens, apostrophes
        pname   letters, spaces, apostrophes (My Profile first / middle / last name)
        title   the same plus "." (Referred By, Previous Dentist: "Dr. Cruz")
        digits  numbers only
        mobile  numbers only, must start with 9 (the +63 is shown separately;
                a pasted "+63 9…" or "09…" is trimmed to the 10-digit number)
      Disallowed characters are removed as they are typed or pasted.
      data-pv-max="N" caps the length AFTER filtering (an HTML maxlength would
      cut a pasted "(02) 8123-4567" or "+63 917 123 4567" before it is cleaned;
      mobile is always capped at 10).

   2. Checks — PortalValidation.name(value, label, required, allowTitle),
      .birthdate(value), .mobile(value, required), .homePhone(value),
      .zip(value, required): each returns '' when valid, else a message.
   ============================================================= */
(function () {
    'use strict';

    var NAME_RE = /^[\p{L}\p{M}]+(?:[ '’-][\p{L}\p{M}]+)*$/u;
    var TITLED_NAME_RE = /^[\p{L}\p{M}]+(?:\.?[ '’-][\p{L}\p{M}]+)*\.?$/u;
    var NAME_MAX = 100;

    var STRIP = {
        name: /[^\p{L}\p{M} '’-]/gu,
        pname: /[^\p{L}\p{M} '’]/gu,
        title: /[^\p{L}\p{M} '’.-]/gu,
        digits: /\D/g,
    };

    function clean(v) { return String(v == null ? '' : v).replace(/\s+/g, ' ').trim(); }

    function iso(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    var PV = {
        NAME_RE: NAME_RE,
        TITLED_NAME_RE: TITLED_NAME_RE,

        name: function (value, label, required, allowTitle) {
            var v = clean(value);
            if (!v) return required ? label + ' is required.' : '';
            if (v.length > NAME_MAX) return label + ' is too long (' + NAME_MAX + ' characters max).';
            if (!(allowTitle ? TITLED_NAME_RE : NAME_RE).test(v)) {
                return label + " can only contain letters, spaces, hyphens (-) and apostrophes (')" +
                    (allowTitle ? ', and a period after a title (e.g. Dr.).' : '.');
            }
            return '';
        },

        /**
         * My Profile name: no hyphens. `stored` = the value loaded from the
         * profile; an unchanged hyphenated name on file is still accepted.
         */
        profileName: function (value, label, required, stored) {
            var err = PV.name(value, label, required);
            if (err) return err;
            var v = clean(value);
            if (v.indexOf('-') === -1) return '';
            if (stored != null && v.toLowerCase() === clean(stored).toLowerCase()) return '';
            return label + " can only contain letters, spaces and apostrophes (').";
        },

        /** Latest allowed birthdate: yesterday (YYYY-MM-DD). */
        maxBirthdate: function () {
            var d = new Date();
            d.setDate(d.getDate() - 1);
            return iso(d);
        },

        birthdate: function (value) {
            var v = String(value || '').trim();
            if (!v) return 'Birthdate is required.';
            if (!/^\d{4}-\d{2}-\d{2}$/.test(v) || isNaN(new Date(v + 'T00:00:00')) || v < '1900-01-01') return 'Please enter a valid birthdate.';
            if (v > PV.maxBirthdate()) return 'Birthdate must be before today.';
            return '';
        },

        /** The 10 digits typed after the +63 prefix. */
        mobile: function (value, required) {
            var v = String(value || '').trim();
            if (!v) return required ? 'Contact number is required.' : '';
            if (!/^\d+$/.test(v)) return 'Contact number can only contain numbers.';
            if (v.charAt(0) !== '9') return 'Contact number must start with 9.';
            if (v.length !== 10) return 'Contact number must be 10 digits (9XX XXX XXXX).';
            return '';
        },

        homePhone: function (value) {
            var v = String(value || '').trim();
            if (!v) return '';
            if (!/^\d+$/.test(v)) return 'Home phone can only contain numbers.';
            if (v.length > 8) return 'Home phone can have at most 8 digits.';
            if (v.length < 7) return 'Home phone must have 7 or 8 digits.';
            return '';
        },

        zip: function (value, required) {
            var v = String(value || '').trim();
            if (!v) return required ? 'ZIP code is required.' : '';
            if (!/^\d{4}$/.test(v)) return 'ZIP code must be 4 digits.';
            return '';
        },

        /** Mobile filter: digits only, leading +63 / 0 trimmed, must start with 9. */
        filterMobile: function (raw) {
            var d = String(raw || '').replace(/\D/g, '');
            if (d.length > 10 && d.indexOf('63') === 0) d = d.slice(2);
            d = d.replace(/^0+/, '');
            d = d.replace(/^[^9]+/, '');   // first digit must be 9
            return d.slice(0, 10);
        },
    };

    // ── Typing filters ───────────────────────────────────────────────────────
    function filterValue(el) {
        var kind = el.getAttribute('data-pv');
        var before = el.value;
        var after = kind === 'mobile' ? PV.filterMobile(before)
            : STRIP[kind] ? before.replace(STRIP[kind], '') : before;
        var max = parseInt(el.getAttribute('data-pv-max'), 10);
        if (max > 0 && after.length > max) after = after.slice(0, max);
        if (after === before) return;
        var pos = el.selectionStart;
        el.value = after;
        try {
            if (pos != null) {
                var p = Math.max(0, pos - (before.length - after.length));
                el.setSelectionRange(p, p);
            }
        } catch (e) { /* some input types don't support selection */ }
    }

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (el && el.getAttribute && el.getAttribute('data-pv')) filterValue(el);
    }, true);

    /** Clean values set by code (e.g. a saved profile loaded into the form). */
    PV.apply = function (root) {
        (root || document).querySelectorAll('[data-pv]').forEach(filterValue);
    };

    window.PortalValidation = PV;
})();
