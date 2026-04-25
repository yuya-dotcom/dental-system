<?php
// =============================================================
//  logout.php  — SECURE VERSION
//  ─────────────────────────────────────────────────────────────
//  Called when the user clicks "Logout" anywhere in the admin.
//
//  STEP-BY-STEP WHAT HAPPENS:
//
//  1. Session is started so we can read and then destroy it.
//  2. Cache-control headers are set so THIS redirect response
//     is also never cached (belt-and-suspenders).
//  3. session_unset() clears every $_SESSION variable.
//     Think of this as emptying the wallet before shredding it.
//  4. session_destroy() deletes the server-side session file.
//     Now even if someone has the old session cookie, the server
//     has nothing to match it against.
//  5. The session cookie itself is overwritten with an expired
//     version so the browser deletes it immediately.
//     Without this step the browser still holds the old cookie
//     and would send it on future requests (even though the
//     server-side session is gone).
//  6. Redirect to login.php with a ?logged_out=1 flag so the
//     login page can show a "You have been signed out" message.
// =============================================================

// Step 1 — Start the session so we can destroy it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Step 2 — Prevent this response from being cached
// (keeps the redirect itself out of browser history cache)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

// Step 3 — Clear all session variables
session_unset();

// Step 4 — Destroy the server-side session file
session_destroy();

// Step 5 — Expire the session cookie in the browser
//
//  session_destroy() does NOT delete the cookie that the browser
//  holds. If we skip this, the browser keeps sending the old
//  (now invalid) session ID on every request. While harmless
//  server-side (no matching session exists), it is cleaner and
//  safer to explicitly expire it.
//
//  We do this by setting the same cookie name with an expiry
//  in the past (time() - 3600 = one hour ago).
//
if (isset($_COOKIE[session_name()])) {
    setcookie(
        session_name(),   // same cookie name PHP used
        '',               // empty value
        [
            'expires'  => time() - 3600,   // expired 1 hour ago
            'path'     => '/',             // match the original scope
            'secure'   => isset($_SERVER['HTTPS']),  // https only if site uses it
            'httponly' => true,            // JS cannot read it
            'samesite' => 'Strict',        // no cross-site sending
        ]
    );
}

// Step 6 — Send to login with a flag for the "signed out" notice
header('Location: login.php?logged_out=1');
exit;