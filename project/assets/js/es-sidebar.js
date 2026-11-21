/* =============================================================
   Collapsed ("mini") staff sidebar — tooltips, group flyouts and a
   consistent collapsed/expanded state across pages.
   Loaded (defer) by partials/sidebar.php; styles in assets/css/es-sidebar.css.

   - Hover / keyboard focus on an icon shows its label as a tooltip.
   - Clicking a group icon (Dashboards, Appointments, …) opens a flyout
     with that group's existing links; Logout stays a direct link.
   - The user's collapse choice (saved by the theme's toggle buttons under
     the key below) is applied on every page and after window resizes, so
     the sidebar keeps the same width from page to page. The early restore
     (before first paint) is the inline script in partials/sidebar.php.
   ============================================================= */
(function () {
    'use strict';

    var KEY = 'nexel-classic-dashboard-menu-mini-theme';   // theme's own key
    var DESKTOP_MIN = 1025;                                  // theme drops .minimenu at <= 1024px
    var html = document.documentElement;
    var nav = document.querySelector('.nxl-navigation');
    if (!nav) return;
    var content = nav.querySelector('.navbar-content');

    function savedMini() {
        try { return localStorage.getItem(KEY) === 'menu-mini-theme'; } catch (e) { return false; }
    }
    function isDesktop() { return window.innerWidth >= DESKTOP_MIN; }
    function isMini() { return isDesktop() && html.classList.contains('minimenu'); }

    // ── Tooltip + flyout elements (appended to <body> so the rail's
    //    overflow never clips them) ─────────────────────────────────
    var tip = document.createElement('div');
    tip.className = 'es-nav-tip';
    tip.id = 'esNavTip';
    tip.setAttribute('role', 'tooltip');
    var fly = document.createElement('div');
    fly.className = 'es-nav-flyout';
    fly.id = 'esNavFlyout';
    document.body.appendChild(tip);
    document.body.appendChild(fly);

    var topLinks = Array.prototype.slice.call(
        nav.querySelectorAll('.nxl-navbar > .nxl-item:not(.nxl-caption) > .nxl-link'));
    topLinks.forEach(function (a) {
        var t = a.querySelector('.nxl-mtext');
        a.setAttribute('data-es-label', t ? t.textContent.trim() : '');
        // Hidden label must still name the link for screen readers
        if (t && !a.hasAttribute('aria-label')) a.setAttribute('aria-label', t.textContent.trim());
        if (a.parentElement.classList.contains('nxl-hasmenu')) a.setAttribute('aria-haspopup', 'true');
    });

    function railRight() { return nav.getBoundingClientRect().right; }

    // ── Tooltip ────────────────────────────────────────────────────
    function showTip(a) {
        if (!isMini() || (flyOwner === a && fly.classList.contains('show'))) return;
        var label = a.getAttribute('data-es-label');
        if (!label) return;
        tip.textContent = label;
        tip.classList.add('show');
        var r = a.getBoundingClientRect();
        tip.style.left = (railRight() + 10) + 'px';
        tip.style.top = Math.round(r.top + r.height / 2 - tip.offsetHeight / 2) + 'px';
        a.setAttribute('aria-describedby', 'esNavTip');
    }
    function hideTip() {
        tip.classList.remove('show');
        topLinks.forEach(function (a) { a.removeAttribute('aria-describedby'); });
    }

    // ── Flyout (a group's links) ───────────────────────────────────
    var flyOwner = null;
    function openFly(a, focusFirst) {
        var li = a.parentElement;
        var sub = li.querySelector(':scope > .nxl-submenu');
        if (!sub) return;
        hideTip();
        fly.innerHTML = '';
        var title = document.createElement('div');
        title.className = 'es-nav-flyout-title';
        title.textContent = a.getAttribute('data-es-label');
        fly.appendChild(title);
        sub.querySelectorAll(':scope > .nxl-item > .nxl-link').forEach(function (src) {
            var link = document.createElement('a');
            link.href = src.getAttribute('href');
            link.textContent = src.textContent.trim();
            if (src.parentElement.classList.contains('active')) {
                link.className = 'active';
                link.setAttribute('aria-current', 'page');
            }
            fly.appendChild(link);
        });
        fly.classList.add('show');
        var r = a.getBoundingClientRect();
        var top = Math.min(r.top, window.innerHeight - fly.offsetHeight - 8);
        fly.style.left = (railRight() + 10) + 'px';
        fly.style.top = Math.max(8, Math.round(top)) + 'px';
        flyOwner = a;
        a.setAttribute('aria-expanded', 'true');
        if (focusFirst) { var f = fly.querySelector('a'); if (f) f.focus(); }
    }
    function closeFly(returnFocus) {
        if (!fly.classList.contains('show')) return;
        fly.classList.remove('show');
        if (flyOwner) {
            flyOwner.setAttribute('aria-expanded', 'false');
            if (returnFocus) flyOwner.focus();
        }
        flyOwner = null;
    }
    function closeAll() { hideTip(); closeFly(false); }

    // Capture phase: runs before the theme's jQuery <li> click handlers, so in
    // the collapsed rail a group icon opens the flyout instead of sliding its
    // submenu open inside the 100px column.
    nav.addEventListener('click', function (e) {
        if (!isMini()) return;
        var a = e.target.closest('.nxl-navbar > .nxl-hasmenu > .nxl-link');
        if (!a || !nav.contains(a)) return;
        e.preventDefault();
        e.stopPropagation();
        if (flyOwner === a && fly.classList.contains('show')) closeFly(false);
        else { closeFly(false); openFly(a, e.detail === 0); }
    }, true);

    topLinks.forEach(function (a) {
        a.addEventListener('mouseenter', function () { showTip(a); });
        a.addEventListener('mouseleave', hideTip);
        a.addEventListener('focus', function () { showTip(a); });
        a.addEventListener('blur', hideTip);
    });

    document.addEventListener('click', function (e) {
        if (fly.classList.contains('show') && !fly.contains(e.target) && !nav.contains(e.target)) closeFly(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (fly.classList.contains('show')) closeFly(true);
            hideTip();
            return;
        }
        if (!fly.classList.contains('show') || !fly.contains(document.activeElement)) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            var items = Array.prototype.slice.call(fly.querySelectorAll('a'));
            var i = items.indexOf(document.activeElement);
            var n = e.key === 'ArrowDown' ? (i + 1) % items.length : (i - 1 + items.length) % items.length;
            items[n].focus();
            e.preventDefault();
        }
    });
    fly.addEventListener('focusout', function (e) {
        if (e.relatedTarget && !fly.contains(e.relatedTarget) && e.relatedTarget !== flyOwner) closeFly(false);
    });
    if (content) content.addEventListener('scroll', closeAll, { passive: true });

    // ── Collapsed / expanded state ─────────────────────────────────
    function syncButtons() {
        var mini = html.classList.contains('minimenu');
        var miniBtn = document.getElementById('menu-mini-button');
        var expBtn = document.getElementById('menu-expend-button');
        if (miniBtn) miniBtn.style.display = mini ? 'none' : '';
        if (expBtn) expBtn.style.display = mini ? '' : 'none';
    }
    function rebindTheme() {
        // The theme binds its expand/collapse submenu clicks only when the page
        // loads expanded; re-run it so groups work after expanding.
        // (vendors.min.js names it menuclick(); the standalone nxlNavigation.js
        // build names it initMenuClick())
        var fn = window.menuclick || window.initMenuClick;
        if (typeof fn === 'function') fn();
    }
    function applySaved() {
        if (!isDesktop()) return;                  // mobile: theme's off-canvas menu
        html.classList.toggle('minimenu', savedMini());
        syncButtons();
    }

    ['menu-mini-button', 'menu-expend-button'].forEach(function (id) {
        var btn = document.getElementById(id);
        // setTimeout: the theme's own handler (bound later, on DOM ready) must
        // flip .minimenu first, or initMenuClick() sees the old state.
        if (btn) btn.addEventListener('click', function () { closeAll(); setTimeout(rebindTheme, 0); });
    });

    // Runs after the theme's resize handlers (registered earlier), which
    // otherwise force-collapse at 1024–1600px and force-expand above that.
    var resizeTimer;
    window.addEventListener('resize', function () {
        closeAll();
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            var was = html.classList.contains('minimenu');
            applySaved();
            if (was !== html.classList.contains('minimenu')) rebindTheme();
        }, 0);
    });

    applySaved();
})();
