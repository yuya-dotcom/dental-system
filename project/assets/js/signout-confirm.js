/* =============================================================
   assets/js/signout-confirm.js
   Shared "Sign out of your account?" confirmation (SweetAlert2),
   used by BOTH the patient portal and the admin side so they look
   identical. Each side keeps its own logout action:

   - Portal:  essenciaSignOut({ onConfirm: () => location.href = 'portal-logout.php' })
   - Admin:   <a href="logout.php" data-signout-confirm> — the click is
              intercepted, and the link's own href is followed on confirm.

   Cancel, Escape and backdrop clicks only close the popup.
   ============================================================= */
(function () {
  'use strict';
  if (window.essenciaSignOut) return; // loaded twice → keep the first copy (no duplicate handlers)

  const SWAL_SRC = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';

  // ── Styles (scoped to .es-signout so other Swal dialogs are untouched) ──
  const css = `
    .swal2-container.es-signout-backdrop { background: rgba(15, 23, 42, .45) !important; padding: 16px; }
    .swal2-popup.es-signout {
      width: min(400px, 100%); padding: 28px 24px 22px; border-radius: 18px; background: #fff;
      box-shadow: 0 24px 60px -12px rgba(15, 23, 42, .28), 0 2px 6px rgba(15, 23, 42, .06);
      font-family: 'Sora', 'Plus Jakarta Sans', system-ui, sans-serif;
    }
    .swal2-popup.es-signout .swal2-title {
      display: flex !important; /* Swal sets an inline display:block on the title */
      flex-direction: column; align-items: center; gap: 14px; margin: 0; padding: 0;
      font-family: inherit; font-size: 1.2rem; font-weight: 700; line-height: 1.35; color: #0f1f3d; letter-spacing: -.01em;
    }
    .es-signout-icon {
      display: inline-flex; align-items: center; justify-content: center; width: 62px; height: 62px;
      border-radius: 50%; background: #e8f0fe; color: #2563eb; box-shadow: 0 0 0 7px #f3f7ff;
    }
    .es-signout-icon svg { width: 27px; height: 27px; margin-left: 3px; }
    .swal2-popup.es-signout .swal2-html-container {
      margin: 8px 0 0; padding: 0; font-family: inherit; font-size: .9rem; line-height: 1.55; color: #64748b;
    }
    .swal2-popup.es-signout .swal2-actions {
      width: 100%; margin: 22px 0 0; padding: 0; gap: 10px; flex-wrap: nowrap;
    }
    .es-signout-btn {
      flex: 1 1 0; min-height: 46px; margin: 0; padding: 0 16px; border-radius: 10px; cursor: pointer;
      font-family: inherit; font-size: .88rem; font-weight: 600; line-height: 1; white-space: nowrap;
      transition: background-color .15s ease, border-color .15s ease, box-shadow .15s ease, color .15s ease;
    }
    .es-signout-btn:focus { outline: none; }
    .es-signout-btn:focus-visible { box-shadow: 0 0 0 3px rgba(37, 99, 235, .2); }
    .es-signout-confirm { border: 1.5px solid #2563eb; background: #2563eb; color: #fff; }
    .es-signout-confirm:hover { background: #1d4ed8; border-color: #1d4ed8; }
    .es-signout-cancel { border: 1.5px solid #cfd8e6; background: #fff; color: #334155; }
    .es-signout-cancel:hover { border-color: #9fb0c8; background: #f8fafc; color: #0f1f3d; }
    @keyframes es-signout-in  { from { opacity: 0; transform: translateY(8px) scale(.97); } to { opacity: 1; transform: none; } }
    @keyframes es-signout-out { from { opacity: 1; transform: none; } to { opacity: 0; transform: scale(.97); } }
    .es-signout-show { animation: es-signout-in .2s ease-out both; }
    .es-signout-hide { animation: es-signout-out .14s ease-in both; }
    @media (max-width: 360px) {
      .swal2-popup.es-signout { padding: 24px 18px 18px; }
      .swal2-popup.es-signout .swal2-actions { flex-direction: column; }
      .es-signout-btn { width: 100%; flex: none; }
    }
    @media (prefers-reduced-motion: reduce) {
      .es-signout-show, .es-signout-hide { animation: none; }
    }`;

  function injectAssets() {
    if (document.getElementById('es-signout-style')) return;
    const style = document.createElement('style');
    style.id = 'es-signout-style';
    style.textContent = css;
    document.head.appendChild(style);
    if (!document.querySelector('link[href*="family=Sora"]')) {
      const font = document.createElement('link');
      font.rel = 'stylesheet';
      font.href = 'https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap';
      document.head.appendChild(font);
    }
  }

  // Pages that don't already include SweetAlert2 get the same CDN build the project uses
  let swalLoading = null;
  function loadSwal() {
    if (window.Swal) return Promise.resolve(window.Swal);
    if (!swalLoading) {
      swalLoading = new Promise(function (resolve, reject) {
        const s = document.createElement('script');
        s.src = SWAL_SRC;
        s.onload = function () { window.Swal ? resolve(window.Swal) : reject(); };
        s.onerror = reject;
        document.head.appendChild(s);
      }).catch(function () { swalLoading = null; return null; });
    }
    return swalLoading;
  }

  const ICON = '<span class="es-signout-icon" aria-hidden="true">' +
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>';

  let open = false; // one popup at a time, even on double clicks

  /**
   * Show the sign-out confirmation. Resolves true when the user confirmed
   * (onConfirm has then been called exactly once), false otherwise.
   */
  window.essenciaSignOut = async function (opts) {
    opts = opts || {};
    if (open) return false;
    open = true;
    try {
      injectAssets();
      const Swal = await loadSwal();
      const text = opts.text || 'You’ll need to sign in again to access your portal.';
      let confirmed;
      if (Swal) {
        const res = await Swal.fire({
          title: ICON + '<span>Sign out of your account?</span>',
          text: text,
          showCancelButton: true,
          confirmButtonText: 'Yes, Sign Out',
          cancelButtonText: 'Stay Logged In',
          buttonsStyling: false,
          focusConfirm: false,
          focusCancel: true, // Enter on open keeps the user signed in
          allowOutsideClick: true, // backdrop / Escape only dismiss
          allowEscapeKey: true,
          returnFocus: true,
          showClass: { popup: 'es-signout-show' },
          hideClass: { popup: 'es-signout-hide' },
          customClass: {
            container: 'es-signout-backdrop',
            popup: 'es-signout',
            confirmButton: 'es-signout-btn es-signout-confirm',
            cancelButton: 'es-signout-btn es-signout-cancel',
          },
        });
        confirmed = res.isConfirmed === true;
      } else {
        // SweetAlert2 couldn't load (offline CDN): still ask before signing out
        confirmed = window.confirm('Sign out of your account?\n\n' + text);
      }
      if (confirmed && typeof opts.onConfirm === 'function') opts.onConfirm();
      return confirmed;
    } finally {
      open = false;
    }
  };

  // Admin links: <a href="logout.php" data-signout-confirm>
  document.addEventListener('click', function (e) {
    const link = e.target.closest && e.target.closest('a[data-signout-confirm]');
    if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    window.essenciaSignOut({
      text: link.dataset.signoutText,
      onConfirm: function () { window.location.href = link.href; },
    });
  });
})();
