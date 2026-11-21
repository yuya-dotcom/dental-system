/* =============================================================
   assets/js/es-notifications.js — Staff Notification Center
   • Navbar bell (#esnBell): unread badge (polled every 60 s while the tab is
     visible), dropdown list with All / Unread + category filter, "Waiting for
     You" inventory tasks, Mark as Read / Mark All as Read.
   • View All page (#esnCenter, notification-center.php): same rows with
     status / category / priority / branch filters and paging.
   Everything comes from api/staff_notifications.php, which decides on the
   server what this user may see. Alerts and confirmations use SweetAlert2.
   ============================================================= */
(function () {
  "use strict";

  var API = "api/staff_notifications.php";
  var POLL_MS = 60000;
  var bell = document.getElementById("esnBell");
  var page = document.getElementById("esnCenter");
  if (!bell && !page) return;

  var uid = (bell || page).getAttribute("data-user") || "0";
  var CACHE_KEY = "esn_badge_" + uid;
  var unread = 0;
  var tasks = [];

  // ── helpers ────────────────────────────────────────────────
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function ago(iso) {
    var d = new Date(iso), s = Math.max(0, (Date.now() - d.getTime()) / 1000);
    if (s < 60) return "Just now";
    if (s < 3600) return Math.floor(s / 60) + " min ago";
    if (s < 86400) { var h = Math.floor(s / 3600); return h + (h === 1 ? " hr ago" : " hrs ago"); }
    if (s < 7 * 86400) { var dd = Math.floor(s / 86400); return dd === 1 ? "Yesterday" : dd + " days ago"; }
    var o = { month: "short", day: "numeric" };
    if (d.getFullYear() !== new Date().getFullYear()) o.year = "numeric";
    return d.toLocaleDateString("en-PH", o);
  }
  function fullTime(iso) {
    try { return new Date(iso).toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" }); } catch (e) { return iso; }
  }
  function swalError(msg) {
    if (window.Swal) Swal.fire({ icon: "error", title: "Something went wrong", text: msg || "Please try again." });
  }
  function getJSON(url) {
    return fetch(url, { credentials: "same-origin", cache: "no-store" }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (r.status === 401) { location.href = "login.php"; throw new Error("signed out"); }
        if (!r.ok || !j.success) throw new Error(j.message || "Request failed (" + r.status + ")");
        return j;
      });
    });
  }
  function postJSON(body, keepalive) {
    return fetch(API, {
      method: "POST", credentials: "same-origin", keepalive: !!keepalive,
      headers: { "Content-Type": "application/json" }, body: JSON.stringify(body),
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok || !j.success) throw new Error(j.message || "Request failed (" + r.status + ")");
        return j;
      });
    });
  }

  // ── badge ──────────────────────────────────────────────────
  function setCounts(u, t) {
    if (typeof u === "number") unread = u;
    if (Array.isArray(t)) tasks = t;
    var total = unread + tasks.length;
    try { sessionStorage.setItem(CACHE_KEY, String(total)); } catch (e) { /* storage blocked */ }
    paintBadge(total);
    var head = document.getElementById("esnHeadCount");
    if (head) { head.hidden = unread < 1; head.textContent = unread > 99 ? "99+" : String(unread); }
    document.querySelectorAll("[data-esn-markall]").forEach(function (b) { b.disabled = unread < 1; });
    var pc = document.getElementById("esnPageUnread");
    if (pc) pc.textContent = unread > 99 ? "99+ unread" : unread + " unread";
  }
  function paintBadge(total) {
    var b = document.getElementById("esnBadge");
    if (!b) return;
    b.hidden = total < 1;
    b.textContent = total > 9 ? "9+" : String(total);
    var link = document.getElementById("esnToggle");
    if (link) link.setAttribute("aria-label", "Notifications" + (total ? ", " + unread + " unread" + (tasks.length ? ", " + tasks.length + " waiting for you" : "") : ""));
  }

  // ── rows ───────────────────────────────────────────────────
  var PRIO = { critical: "Critical", important: "Important" };
  function rowHTML(n) {
    var title = n.url
      ? '<a class="esn-title esn-hit" href="' + esc(n.url) + '">' + esc(n.title) + "</a>"
      : '<span class="esn-title">' + esc(n.title) + "</span>";
    return '<div class="esn-item p-' + esc(n.priority) + (n.unread ? " is-unread" : "") + '" data-id="' + n.id + '"' +
      (n.url ? "" : ' tabindex="0" role="button"') + ">" +
      '<span class="esn-icon" aria-hidden="true"><i class="' + esc(n.icon) + '"></i></span>' +
      '<div class="esn-body">' +
        '<div class="esn-titlerow">' + title + (PRIO[n.priority] ? '<span class="esn-prio ' + esc(n.priority) + '">' + PRIO[n.priority] + "</span>" : "") +
          (n.unread ? '<span class="visually-hidden">(unread)</span>' : "") + "</div>" +
        '<div class="esn-msg">' + esc(n.message) + "</div>" +
        '<div class="esn-meta"><span>' + esc(n.cat_label) + "</span>" +
          (n.branch ? '<span><i class="feather-map-pin"></i>' + esc(n.branch) + "</span>" : "") +
          '<time datetime="' + esc(n.at) + '" title="' + esc(fullTime(n.at)) + '"><i class="feather-clock"></i>' + esc(ago(n.at)) + "</time></div>" +
      "</div>" +
      '<div class="esn-side">' + (n.unread
        ? '<span class="esn-dot" aria-hidden="true"></span><button type="button" class="esn-read-btn" data-read="' + n.id + '" title="Mark as read" aria-label="Mark as read"><i class="feather-check"></i></button>'
        : "") + "</div>" +
      "</div>";
  }
  function taskHTML(t) {
    return '<div class="esn-item esn-task"><span class="esn-icon" aria-hidden="true"><i class="' + esc(t.icon) + '"></i></span>' +
      '<div class="esn-body"><div class="esn-titlerow"><a class="esn-title esn-hit" href="' + esc(t.url) + '">' + esc(t.title) + "</a></div>" +
      '<div class="esn-meta"><span>' + esc(t.sub) + "</span></div></div></div>";
  }
  function emptyHTML(text, error) {
    return '<div class="esn-empty' + (error ? " is-error" : "") + '"><i class="' + (error ? "feather-alert-circle" : "feather-check-circle") + '"></i>' + esc(text) + "</div>";
  }
  var LOADING = '<div class="esn-loading"><span class="spinner-border text-primary" role="status"></span>Loading notifications…</div>';

  // Mark one row read in the UI
  function paintRead(el) {
    if (!el) return;
    el.classList.remove("is-unread");
    var side = el.querySelector(".esn-side");
    if (side) side.innerHTML = "";
    var hid = el.querySelector(".visually-hidden");
    if (hid) hid.remove();
  }
  function markRead(id, keepalive) {
    document.querySelectorAll('.esn-item[data-id="' + id + '"]').forEach(paintRead);
    return postJSON({ action: "read", ids: [id] }, keepalive).then(function (j) { setCounts(j.unread); return j; });
  }

  // Click / keyboard on a list (shared by bell + page)
  function wireList(container, onChanged) {
    container.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-read]");
      if (btn) {
        e.preventDefault(); e.stopPropagation();
        markRead(btn.getAttribute("data-read")).then(onChanged).catch(function (err) { swalError(err.message); });
        return;
      }
      var item = e.target.closest(".esn-item[data-id]");
      if (!item) return;
      var id = item.getAttribute("data-id");
      var link = item.querySelector("a.esn-hit");
      if (link && !e.target.closest("a")) { link.click(); return; }   // re-enters with the link as target
      if (item.classList.contains("is-unread")) {
        // keepalive: the request finishes even though the link navigates away now
        markRead(id, !!link).catch(function () { /* the next refresh shows the true state */ });
      }
    });
    container.addEventListener("keydown", function (e) {
      if ((e.key === "Enter" || e.key === " ") && e.target.matches(".esn-item[role=button]")) { e.preventDefault(); e.target.click(); }
    });
  }

  function markAllConfirm(category, label) {
    if (unread < 1) return Promise.resolve(false);
    var go = function () {
      return postJSON({ action: "read_all", category: category || "" }).then(function (j) { setCounts(j.unread); return true; });
    };
    if (!window.Swal) return go();
    return Swal.fire({
      icon: "question",
      title: "Mark all as read?",
      text: category ? "All unread " + label + " notifications will be marked as read." : "All your unread notifications will be marked as read.",
      showCancelButton: true,
      confirmButtonText: "Mark all as read",
      cancelButtonText: "Cancel",
      focusCancel: false,
    }).then(function (r) { return r.isConfirmed ? go() : false; });
  }

  // ── Bell ───────────────────────────────────────────────────
  var summaryBusy = false;
  function refreshSummary() {
    if (summaryBusy) return;
    summaryBusy = true;
    getJSON(API + "?action=summary").then(function (j) {
      setCounts(j.unread, j.tasks || []);
      if (bell && bell.querySelector(".dropdown-menu.show") && !j.ready) renderBell(null);
    }).catch(function () { /* keep the last badge */ }).then(function () { summaryBusy = false; });
  }

  if (bell) {
    var list = document.getElementById("esnList");
    var state = { state: "all", category: "" };
    var reqSeq = 0;
    try { var c = parseInt(sessionStorage.getItem(CACHE_KEY) || "", 10); if (c > 0) paintBadge(c); } catch (e) { /* ignore */ }

    function renderBell(data) {
      var html = "";
      var showTasks = state.state === "all" && (state.category === "" || state.category === "inventory") && tasks.length;
      if (showTasks) {
        html += '<p class="esn-section"><i class="feather-check-square me-1"></i>Waiting for You · ' + tasks.length + "</p>" + tasks.map(taskHTML).join("");
      }
      if (data === null) {
        html += emptyHTML("The Notification Center isn't set up yet. Ask the owner to run the notification SQL file.", false);
      } else if (data.rows.length) {
        if (showTasks) html += '<p class="esn-section">Notifications</p>';
        html += data.rows.map(rowHTML).join("");
      } else if (!showTasks) {
        html += emptyHTML(state.state === "unread" ? "You're all caught up — no unread notifications." : "No notifications yet.", false);
      }
      list.innerHTML = html;
    }

    function loadBell() {
      var mine = ++reqSeq;
      list.innerHTML = LOADING;
      var q = API + "?action=list&per=15&state=" + encodeURIComponent(state.state) + "&category=" + encodeURIComponent(state.category);
      getJSON(q).then(function (j) {
        if (mine !== reqSeq) return;
        setCounts(j.unread);
        renderBell(j.ready ? j : null);
      }).catch(function (err) {
        if (mine !== reqSeq) return;
        list.innerHTML = emptyHTML(err.message || "Notifications could not be loaded.", true);
      });
    }

    bell.addEventListener("show.bs.dropdown", function () { refreshSummary(); loadBell(); });
    bell.querySelectorAll("[data-esn-state]").forEach(function (b) {
      b.addEventListener("click", function () {
        state.state = b.getAttribute("data-esn-state");
        bell.querySelectorAll("[data-esn-state]").forEach(function (x) {
          x.classList.toggle("active", x === b); x.setAttribute("aria-pressed", x === b ? "true" : "false");
        });
        loadBell();
      });
    });
    var catSel = document.getElementById("esnCat");
    if (catSel) catSel.addEventListener("change", function () { state.category = catSel.value; loadBell(); });

    // Category filter = shared custom dropdown (custom-select.js). Escape closes only
    // its list (not the whole bell), and closing the bell never leaves it open.
    function catDropdown() { return bell.querySelector(".esn-filters .modal-cs"); }
    function closeCat(focusTrigger) {
      var cs = catDropdown();
      if (!cs || !cs.classList.contains("open")) return false;
      cs.classList.remove("open");
      var t = cs.querySelector(".modal-cs-trigger");
      if (t) { t.setAttribute("aria-expanded", "false"); if (focusTrigger) t.focus(); }
      return true;
    }
    window.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && bell.contains(e.target) && closeCat(true)) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);   // window capture: runs before Bootstrap's dropdown Escape handler
    bell.addEventListener("hide.bs.dropdown", function () { closeCat(false); });
    var markAll = document.getElementById("esnMarkAll");
    if (markAll) markAll.addEventListener("click", function () {
      var label = catSel && catSel.value ? catSel.options[catSel.selectedIndex].text : "";
      markAllConfirm(state.category, label).then(function (done) { if (done) loadBell(); }).catch(function (err) { swalError(err.message); });
    });
    wireList(list, function () { if (state.state === "unread") loadBell(); });

    refreshSummary();
    setInterval(function () { if (!document.hidden) refreshSummary(); }, POLL_MS);
    document.addEventListener("visibilitychange", function () { if (!document.hidden) refreshSummary(); });
  }

  // ── View All page ──────────────────────────────────────────
  if (page) {
    var plist = document.getElementById("esnPageList");
    var pfoot = document.getElementById("esnPageFoot");
    var params = new URLSearchParams(location.search);
    var f = {
      state: params.get("state") || "all",
      category: params.get("category") || "",
      priority: params.get("priority") || "",
      branch: params.get("branch") || "",
      page: Math.max(1, parseInt(params.get("page") || "1", 10) || 1),
    };
    var PER = 20;
    var pseq = 0;
    var sel = {
      state: document.getElementById("esnFState"),
      category: document.getElementById("esnFCategory"),
      priority: document.getElementById("esnFPriority"),
      branch: document.getElementById("esnFBranch"),
    };
    Object.keys(sel).forEach(function (k) {
      var s = sel[k];
      if (!s) return;
      s.value = f[k];
      s.addEventListener("change", function () { f[k] = s.value; f.page = 1; loadPage(); });
    });

    function syncUrl() {
      var u = new URL(location.href);
      ["state", "category", "priority", "branch"].forEach(function (k) {
        if (f[k] && !(k === "state" && f[k] === "all")) u.searchParams.set(k, f[k]); else u.searchParams.delete(k);
      });
      if (f.page > 1) u.searchParams.set("page", String(f.page)); else u.searchParams.delete("page");
      history.replaceState(history.state, "", u.href);
    }

    function loadPage() {
      var mine = ++pseq;
      syncUrl();
      plist.innerHTML = LOADING;
      var q = API + "?action=list&per=" + PER + "&page=" + f.page +
        "&state=" + encodeURIComponent(f.state) + "&category=" + encodeURIComponent(f.category) +
        "&priority=" + encodeURIComponent(f.priority) + "&branch=" + encodeURIComponent(f.branch);
      getJSON(q).then(function (j) {
        if (mine !== pseq) return;
        setCounts(j.unread);
        if (!j.ready) {
          plist.innerHTML = emptyHTML("The Notification Center isn't set up yet. Ask the owner to run database/proposed/2026-11-19_staff_notification_center.sql.", false);
          pfoot.hidden = true;
          return;
        }
        if (f.page > 1 && !j.rows.length && j.total > 0) { f.page = j.pages; loadPage(); return; }
        var filtered = f.state !== "all" || f.category || f.priority || f.branch;
        plist.innerHTML = j.rows.length ? j.rows.map(rowHTML).join("")
          : emptyHTML(f.state === "unread" ? "You're all caught up — no unread notifications" + (filtered ? " here." : ".") : (filtered ? "No notifications match these filters." : "No notifications yet."), false);
        var from = j.total ? (f.page - 1) * PER + 1 : 0, to = Math.min(j.total, f.page * PER);
        pfoot.hidden = j.total <= 0;
        pfoot.innerHTML = '<span>Showing ' + from + "–" + to + " of " + j.total + "</span>" +
          '<span class="esn-pager">' +
          '<button type="button" class="btn btn-sm btn-light" data-page="' + (f.page - 1) + '"' + (f.page <= 1 ? " disabled" : "") + ' aria-label="Previous page"><i class="feather-chevron-left"></i></button>' +
          '<span class="align-self-center px-2">Page ' + f.page + " of " + j.pages + "</span>" +
          '<button type="button" class="btn btn-sm btn-light" data-page="' + (f.page + 1) + '"' + (f.page >= j.pages ? " disabled" : "") + ' aria-label="Next page"><i class="feather-chevron-right"></i></button>' +
          "</span>";
      }).catch(function (err) {
        if (mine !== pseq) return;
        plist.innerHTML = emptyHTML(err.message || "Notifications could not be loaded.", true);
      });
    }

    pfoot.addEventListener("click", function (e) {
      var b = e.target.closest("[data-page]");
      if (!b || b.disabled) return;
      f.page = Math.max(1, parseInt(b.getAttribute("data-page"), 10) || 1);
      loadPage();
      page.scrollIntoView({ block: "start", behavior: "smooth" });
    });
    var pm = document.getElementById("esnPageMarkAll");
    if (pm) pm.addEventListener("click", function () {
      var label = f.category && sel.category ? sel.category.options[sel.category.selectedIndex].text : "";
      markAllConfirm(f.category, label).then(function (done) { if (done) loadPage(); }).catch(function (err) { swalError(err.message); });
    });
    var reset = document.getElementById("esnPageReset");
    if (reset) reset.addEventListener("click", function () {
      f = { state: "all", category: "", priority: "", branch: "", page: 1 };
      Object.keys(sel).forEach(function (k) { if (sel[k]) sel[k].value = f[k]; });
      loadPage();
    });
    wireList(plist, function () { if (f.state === "unread") loadPage(); });
    loadPage();
  }
})();
