// assets/js/portal-idle-timeout.js
// Requires PORTAL_IDLE_TIMEOUT_MS, PORTAL_ABSOLUTE_TIMEOUT_MS, PORTAL_SESSION_START_MS
// to already be defined on the page (see portal-dashboard.php bridge script).

(function () {
  if (typeof PORTAL_IDLE_TIMEOUT_MS === "undefined") return;

  let idleTimer = null;

  function doAutoLogout(reason) {
    window.location.href = "portal-logout.php?reason=" + reason;
  }

  function resetIdleTimer() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(function () {
      doAutoLogout("inactivity");
    }, PORTAL_IDLE_TIMEOUT_MS);
  }

  ["mousemove", "mousedown", "keydown", "scroll", "touchstart", "click"].forEach(
    function (evt) {
      document.addEventListener(evt, resetIdleTimer, { passive: true });
    }
  );
  resetIdleTimer();

  if (typeof PORTAL_ABSOLUTE_TIMEOUT_MS !== "undefined") {
    const remaining = PORTAL_SESSION_START_MS + PORTAL_ABSOLUTE_TIMEOUT_MS - Date.now();
    if (remaining <= 0) {
      doAutoLogout("max_duration");
    } else {
      setTimeout(function () {
        doAutoLogout("max_duration");
      }, remaining);
    }
  }
})();