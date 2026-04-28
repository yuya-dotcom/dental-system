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
    header('Location: dashboard.php');
    exit;
}

// ── Read notice / redirect params from URL ────────────────────
$loggedOut = isset($_GET['logged_out']);                   // came from logout.php
$timedOut  = ($_GET['reason'] ?? '') === 'timeout';       // session expired
$redirect  = $_GET['redirect'] ?? '';                     // where to go after login
// Only allow relative URLs for the redirect target — never let
// an attacker redirect to an external site after login
if ($redirect && (str_starts_with($redirect, '//') || preg_match('/^https?:\/\//i', $redirect))) {
    $redirect = '';
}

$error   = '';
$success = '';

if ($loggedOut) { $success = 'You have been signed out successfully.'; }
if ($timedOut)  { $error   = 'Your session expired due to inactivity. Please sign in again.'; }

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
            'owner'   => 'dashboard.php',
            'admin'   => 'appointments-schedule.php',
            'dentist' => 'appointments-schedule.php',
            default   => 'dashboard.php',
        };

        // If a safe redirect target was stored, honour it
        if ($redirect) {
            $dest = ltrim($redirect, '/');
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
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-page">

    <!-- ── Left: Photo ── -->
    <div class="login-photo-col"></div>

    <!-- ── Right: Form ── -->
    <div class="login-form-col">
        <div class="login-form-inner">

            <!-- Brand -->
            <div class="login-brand">
                <span class="login-logo"></span>
            </div>

            <h1 class="login-heading">Welcome back</h1>
            <p class="login-sub">Sign in to continue</p>

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
                    <button type="button" class="input-icon-right" onclick="togglePassword()" tabindex="-1">
                        <i class="feather-eye" id="eyeIcon"></i>
                    </button>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-signin">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2.5"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                    SIGN IN
                </button>

            </form>

        </div>
    </div>

    <script src="assets/vendors/js/vendors.min.js"></script>
    <script src="assets/js/admin.js"></script>
    <script>
        function togglePassword() {
            const input = document.getElementById('passwordInput');
            const icon  = document.getElementById('eyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('feather-eye', 'feather-eye-off');
            } else {
                input.type = 'password';
                icon.classList.replace('feather-eye-off', 'feather-eye');
            }
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