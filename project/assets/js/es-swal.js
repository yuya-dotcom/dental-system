// Shared SweetAlert2 classes for EssenciaSmile portal alerts (styles: assets/css/es-swal.css).
// Use with buttonsStyling: false.
window.ES_SWAL_CLASS = {
  popup: "es-swal-popup",
  icon: "es-swal-icon",
  title: "es-swal-title",
  htmlContainer: "es-swal-html",
  actions: "es-swal-actions",
  confirmButton: "es-swal-btn es-swal-btn-primary",
  cancelButton: "es-swal-btn es-swal-btn-outline",
};

// ES_SWAL_TONE('danger' | 'success' | 'warn') → ES_SWAL_CLASS with a tinted icon
// (and a red confirm button for 'danger').
window.ES_SWAL_TONE = function (tone) {
  return Object.assign({}, window.ES_SWAL_CLASS, {
    icon: "es-swal-icon es-swal-tone-" + tone,
    confirmButton: "es-swal-btn " + (tone === "danger" ? "es-swal-btn-danger" : "es-swal-btn-primary"),
  });
};

/* ── Staff pages: one SweetAlert look everywhere ──────────────────────────
   Older admin code calls Swal.fire({ icon, title, text, confirmButtonColor })
   or Swal.fire('Title', 'Text', 'icon') with SweetAlert's default styling.
   On the staff pages (.nxl-container) every such call is given the system
   look here, so each page doesn't have to repeat it:
     • tinted feather glyph icon + es-swal popup/title/buttons
     • red "danger" tone for destructive confirms (red confirm colour or
       "Delete / Remove / Cancel …?" titles)
     • primary action on the LEFT, Cancel on the RIGHT (reverseButtons off)
     • no toast-style popups: toast:true becomes a normal centred alert
   Calls that already pass customClass are left exactly as they are.     */
(function () {
  "use strict";
  var GLYPH = {
    success: "feather-check",
    error: "feather-alert-circle",
    warning: "feather-alert-triangle",
    info: "feather-info",
    question: "feather-help-circle",
  };
  var RED = /^(#(d33|dc3545|dc2626|e3342f|ef4444|b91c1c)|red)$/i;

  function isStaffPage() {
    return !!document.querySelector(".nxl-container");
  }

  function normalise(args) {
    var o;
    if (typeof args[0] === "string" || args.length > 1) {
      // Swal.fire(title, html, icon) — SweetAlert treats the 2nd argument as HTML too
      o = { title: args[0] || "", html: args[1] ? '<p class="es-swal-text">' + args[1] + "</p>" : "", icon: args[2] || undefined };
    } else if (args[0] && typeof args[0] === "object") {
      o = Object.assign({}, args[0]);
    } else {
      return null;
    }
    // No toasts on staff pages: show a normal alert instead
    if (o.toast) {
      delete o.toast; delete o.position; delete o.timer; delete o.timerProgressBar;
      if (o.showConfirmButton === false) o.showConfirmButton = true;
    }
    if (o.showCancelButton) o.reverseButtons = false;
    if (o.customClass || o.buttonsStyling === true || !window.ES_SWAL_TONE) return o;

    var destructive = RED.test(String(o.confirmButtonColor || "").trim()) ||
      (o.showCancelButton && /^(delete|remove|cancel|void|deactivate|discard)\b/i.test(String(o.title || "")));
    var tone = destructive ? "danger"
      : o.icon === "success" ? "success"
      : o.icon === "error" ? "danger"
      : o.icon === "warning" ? "warn"
      : null;
    o.customClass = tone ? window.ES_SWAL_TONE(tone) : Object.assign({}, window.ES_SWAL_CLASS);
    if (destructive && window.recDeleteClass && o.icon === "warning") o.customClass = window.recDeleteClass();
    o.buttonsStyling = false;
    delete o.confirmButtonColor; delete o.cancelButtonColor;
    if (o.icon && !o.iconHtml && GLYPH[o.icon]) {
      o.iconHtml = '<i class="' + (destructive && o.icon === "warning" ? "feather-alert-triangle" : GLYPH[o.icon]) + ' es-swal-glyph"></i>';
    }
    // Plain text bodies read better in the system paragraph style
    if (o.text && !o.html) {
      var esc = String(o.text).replace(/[&<>"']/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
      });
      o.html = '<p class="es-swal-text">' + esc + "</p>";
      delete o.text;
    }
    if (o.showCancelButton && !o.cancelButtonText) o.cancelButtonText = "Cancel";
    return o;
  }

  function install() {
    var S = window.Swal;
    if (!S || S.__esNormalised || !isStaffPage()) return;
    var orig = S.fire;
    S.fire = function () {
      var o = normalise(Array.prototype.slice.call(arguments));
      return o ? orig.call(this, o) : orig.apply(this, arguments);
    };
    S.__esNormalised = true;
  }
  if (document.readyState === "loading") {
    // The Swal bundle may come after this file; install once the page is parsed too
    install();
    document.addEventListener("DOMContentLoaded", install);
  } else {
    install();
  }
})();
