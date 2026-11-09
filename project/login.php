<?php
// =============================================================
//  login.php  — SECURE VERSION
//  ─────────────────────────────────────────────────────────────
//  Changes from original:
//    - Cache-control headers sent immediately so the login page
//      itself is never cached (fixes back-button seeing login
//      page after user navigates away)
//    - Shows a "You have been signed out" notice when arriving
//      from logout.php (?logged_out=1)
//    - Shows a "Session expired" notice (?reason=timeout)
//    - Shows a "Password reset successfully" notice when arriving
//      from reset-password.php (?reset=1)
//    - Shows a "Redirect after login" behaviour when protected
//      page stored ?redirect=... in the query string
//    - session_regenerate_id() called after successful login to
//      prevent session-fixation attacks
// =============================================================

require_once __DIR__ . '/controllers/auth_controller.php';

bootSession();

// ── Send cache-control for the login page itself ──────────────
// This stops the browser caching the login form, which would
// let someone press Back to see a cached copy of the form
// after navigating away.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}

// ── Already logged in ─────────────────────────────────────────
if (!empty($_SESSION['logged_in'])) {
    header('Location: appointments-schedule.php');
    exit;
}

// ── Read notice / redirect params from URL ────────────────────
$loggedOut   = isset($_GET['logged_out']);                 // came from logout.php
$timedOut    = ($_GET['reason'] ?? '') === 'timeout';       // session expired
$passwordReset = isset($_GET['reset']);                     // came from reset-password.php
$redirect    = $_GET['redirect'] ?? '';                     // where to go after login
// Only allow a path on this same site for the redirect target — never
// let an attacker redirect to an external site after login.
// Blocks: "//evil.com", "/\evil.com", "http(s)://…", "javascript:…",
// and anything containing line breaks or backslashes.
if ($redirect !== '') {
    $isSafePath = str_starts_with($redirect, '/')
        && !str_starts_with($redirect, '//')
        && !preg_match('/[\\\r\n]/', $redirect)
        && !preg_match('/^[a-z][a-z0-9+.\-]*:/i', $redirect);
    if (!$isSafePath) {
        $redirect = '';
    }
}

$error   = '';
$success = '';

if ($loggedOut)      { $success = 'You have been signed out successfully.'; }
if ($timedOut)       { $error   = 'Your session expired due to inactivity. Please sign in again.'; }
if ($passwordReset)  { $success = 'Your password has been reset successfully. Please sign in.'; }

// ── Handle POST (form submission) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $result = loginUser($email, $password);

    if ($result['success']) {
        // Issue a fresh session ID now that the user is authenticated.
        // This is the primary defence against session-fixation attacks.
        session_regenerate_id(true);
        $_SESSION['id_regenerated']  = true;   // tells secure_headers.php not to do it again
        $_SESSION['last_activity']   = time(); // start the idle timer

        // Determine where to send the user
        $dest = match ($_SESSION['role']) {
            'owner'   => 'appointments-schedule.php',
            'admin'   => 'appointments-schedule.php',
            'dentist' => 'appointments-schedule.php',
            default   => 'dashboard.php',
        };

        // If a safe redirect target was stored, honour it.
        // Keep the leading "/" — the redirect is already the full path
        // (e.g. /project/project/page.php). Removing the slash made the
        // browser add the folder again: /project/project/project/project/…
        if ($redirect) {
            $dest = $redirect;
        }

        header('Location: ' . $dest);
        exit;
    } else {
        $error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EssenciaSmile | Login</title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/images/essencia-logo.ico">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.min.css">
    <link href="assets/fonts/sora/sora-300-800.css" rel="stylesheet"><!-- Sora, self-hosted (was Google Fonts) -->
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-page admin-login">

    <!-- ── Left: Promo panel ── -->
    <aside class="al-hero">
        <span class="al-shape al-shape-1" aria-hidden="true"></span>
        <span class="al-shape al-shape-2" aria-hidden="true"></span>
        <span class="al-shape al-shape-3" aria-hidden="true"></span>

        <div class="al-hero-copy">
            <h2 class="al-hero-title">EssenciaSmile Admin Portal</h2>
            <p class="al-hero-sub">Smarter clinic management. Better patient care.</p>
            <p class="al-hero-text">Manage appointments, treatments, billing, and day-to-day clinic operations securely in one place.</p>
            <ul class="al-features" aria-label="Portal features">
                <li><i class="feather-calendar" aria-hidden="true"></i>Appointments</li>
                <li><i class="feather-clipboard" aria-hidden="true"></i>Treatments &amp; charts</li>
                <li><i class="feather-credit-card" aria-hidden="true"></i>Billing</li>
            </ul>
        </div>

        <div class="al-hero-media">
            <img src="assets/images/dashboard-mockup.svg" class="al-no-drag al-hero-img"
                 alt="EssenciaSmile admin dashboard preview" draggable="false"
                 width="4448" height="3490" decoding="async">
        </div>
    </aside>

    <!-- ── Right: Form ── -->
    <main class="login-form-col">
        <div class="login-form-inner">

            <!-- Brand -->
            <div class="login-brand">
                <img src="assets/images/EssenciaSmile-Original-Logo.svg" class="al-no-drag al-logo"
                     alt="EssenciaSmile" draggable="false" width="2202" height="386">
            </div>

            <h1 class="login-heading">Login your account</h1>
            <p class="login-sub">Sign in to manage clinic operations.</p>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 px-3 mb-3" role="alert">
                <i class="feather-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 px-3 mb-3" role="alert">
                <i class="feather-alert-circle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <form method="POST"
                  action="login.php<?= $redirect ? '?redirect=' . urlencode($redirect) : '' ?>"
                  autocomplete="off">

                <!-- Username -->
                <label class="login-label">Username</label>
                <div class="login-input-wrap">
                    <i class="feather-user input-icon"></i>
                    <input type="text" name="username" placeholder="Enter your username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           required autofocus>
                </div>

                <!-- Password -->
                <label class="login-label">Password</label>
                <div class="login-input-wrap">
                    <i class="feather-lock input-icon"></i>
                    <input type="password" name="password" id="passwordInput"
                           placeholder="Enter your password" required>
                    <button type="button" class="input-icon-right" onclick="togglePassword()"
                            id="togglePwBtn" aria-label="Show password" aria-pressed="false">
                        <i class="feather-eye" id="eyeIcon" aria-hidden="true"></i>
                    </button>
                </div>
                <a href="users-forgot-password.php" class="forgot-link">Forgot password?</a>

                <!-- Submit -->
                <button type="submit" class="btn-signin">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                    Sign In
                </button>

            </form>

            <p class="al-secure-note">
                <i class="feather-shield" aria-hidden="true"></i>
                Authorized personnel only. All access is monitored.
            </p>

        </div>
    </main>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script>
        function togglePassword() {
            const input = document.getElementById('passwordInput');
            const icon  = document.getElementById('eyeIcon');
            const btn   = document.getElementById('togglePwBtn');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('feather-eye', 'feather-eye-off');
            } else {
                input.type = 'password';
                icon.classList.replace('feather-eye-off', 'feather-eye');
            }
            const shown = input.type === 'text';
            btn.setAttribute('aria-pressed', shown);
            btn.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
        }

        // ── Back-navigation guard on the login page ───────────────────
        // If a user presses Back to reach the login page AFTER logging in
        // via another tab (or if they bookmarked it), and their session is
        // still active, redirect them to the dashboard immediately instead
        // of showing the login form.
        // We use the Navigation Timing API to detect back/forward cache
        // restores (bfcache) which bypass normal page load events.
        window.addEventListener('pageshow', function (e) {
            // e.persisted = true means the page was loaded from bfcache
            if (e.persisted) {
                // Force a fresh load so the PHP session check at the top
                // of this file runs again — it will redirect if logged in
                window.location.reload();
            }
        });
    </script>
</body>
</html>