<?php
// =============================================================
//  partials/secure_controller.php
//  ─────────────────────────────────────────────────────────────
//  DROP-IN SECURITY GUARD FOR EVERY PROTECTED PAGE
//
//  WHAT THIS DOES (in order):
//    1. Starts (or resumes) the session safely
//    2. Regenerates the session ID on first load — stops
//       session-fixation attacks where an attacker plants
//       a session ID before login
//    3. Sends HTTP cache-control headers so the browser
//       NEVER stores a copy of this page on disk or in RAM
//    4. Validates the session — if the user is not logged in,
//       they are sent to login.php immediately
//    5. Enforces role-based access when the calling page
//       defines REQUIRED_ROLES before including this file
//
//  HOW TO USE IN ANY PROTECTED PAGE:
//
//      // (optional) restrict to specific roles:
//      define('REQUIRED_ROLES', ['owner', 'admin']);
//
//      // Always required — boot session + send headers + check auth:
//      require_once __DIR__ . '/partials/secure_headers.php';
//
//  That is ALL you need. No extra session_start() calls.
//  No manual header() calls for cache control.
//
//  IMPORTANT: This file must be included BEFORE any HTML output.
//  PHP cannot send HTTP headers once output has started.
// =============================================================


// ── 1. SESSION BOOTSTRAP ─────────────────────────────────────
//
//  PHP's default session configuration stores the session ID
//  in a cookie. The settings below harden that cookie:
//
//  session.cookie_httponly = 1
//    JavaScript cannot read the session cookie.
//    Stops XSS attacks from stealing the session ID.
//
//  session.cookie_samesite = Strict
//    The cookie is not sent on cross-site requests.
//    Stops CSRF attacks that use the session cookie.
//
//  session.use_strict_mode = 1
//    PHP rejects session IDs it did not itself generate.
//    Stops session-fixation attacks.
//
if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie BEFORE session_start()
    // ini_set only works before the cookie is sent
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    session_start();
}


// ── 2. SESSION-FIXATION PREVENTION ───────────────────────────
//
//  After a user logs in, a new session ID is issued so any
//  session ID that existed before login (possibly planted by
//  an attacker) is no longer valid.
//
//  We track this with the 'id_regenerated' flag. We only need
//  to regenerate ONCE per login — doing it on every page load
//  causes race conditions on concurrent AJAX requests.
//
if (!empty($_SESSION['logged_in']) && empty($_SESSION['id_regenerated'])) {
    session_regenerate_id(true);          // true = delete old session file
    $_SESSION['id_regenerated'] = true;
}


// ── 3. CACHE-CONTROL HEADERS ─────────────────────────────────
//
//  These headers tell the browser (and any proxy/CDN in front
//  of the server) NEVER to store a copy of this page.
//
//  Cache-Control: no-store, no-cache, must-revalidate
//    no-store       — do NOT write to disk cache or memory cache
//    no-cache       — always re-validate with the server
//    must-revalidate— obey the above even for "force-cached" modes
//
//  Pragma: no-cache
//    HTTP/1.0 fallback for very old browsers/proxies.
//
//  Expires: 0 (or a past date)
//    Marks the response as immediately expired.
//    Belt-and-suspenders for proxies that ignore Cache-Control.
//
//  WHY THIS FIXES THE BACK-BUTTON BUG:
//    Without these headers the browser saves a snapshot of the
//    page in its local cache. When the user presses Back, the
//    browser shows that snapshot WITHOUT making a new request to
//    the server — so session_start() never runs and the old page
//    appears to load fine. With no-store, no snapshot is saved,
//    so Back forces a fresh server request — which then hits the
//    session check below and redirects to login.
//
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false); // IE compat
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');          // deep past
}


// ── 4. SESSION VALIDATION ─────────────────────────────────────
//
//  If the user is not authenticated, redirect immediately.
//  We check three things:
//    a) logged_in flag   — set by loginUser() on success
//    b) user_id          — the real user record from the DB
//    c) session timeout  — idle sessions expire after 2 hours
//
//  SESSION TIMEOUT (optional but recommended):
//    Every time a validated user visits a protected page, we
//    update 'last_activity'. If more than SESSION_TIMEOUT
//    seconds pass without any activity, the session is treated
//    as expired and the user must log in again.
//
define('SESSION_TIMEOUT', 7200); // 2 hours in seconds

$isLoggedIn = !empty($_SESSION['logged_in'])
           && !empty($_SESSION['user_id']);

if ($isLoggedIn) {
    // Check idle timeout
    if (isset($_SESSION['last_activity'])) {
        $idle = time() - (int)$_SESSION['last_activity'];
        if ($idle > SESSION_TIMEOUT) {
            // Session expired — nuke it and send to login
            session_unset();
            session_destroy();
            header('Location: login.php?reason=timeout');
            exit;
        }
    }
    // Stamp current activity time
    $_SESSION['last_activity'] = time();
} else {
    // Not logged in — redirect to login, preserving the
    // intended destination so we can redirect back after login
    $intended = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: login.php' . ($intended ? '?redirect=' . $intended : ''));
    exit;
}


// ── 5. ROLE-BASED ACCESS CONTROL ─────────────────────────────
//
//  Protected pages can declare which roles are allowed BEFORE
//  including this file:
//
//      define('REQUIRED_ROLES', ['owner', 'admin']);
//      require_once __DIR__ . '/partials/secure_headers.php';
//
//  If the current user's role is not in that list, they are
//  redirected to an "unauthorised" page instead of login —
//  they ARE logged in, just not allowed here.
//
//  If no REQUIRED_ROLES is defined, any logged-in user passes.
//
if (defined('REQUIRED_ROLES') && is_array(REQUIRED_ROLES)) {
    $currentRole = $_SESSION['role'] ?? '';
    if (!in_array($currentRole, REQUIRED_ROLES, true)) {
        header('Location: unauthorized.php');
        exit;
    }
}