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
