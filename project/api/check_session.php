<?php
// =============================================================
//  api/check_session.php
//  ─────────────────────────────────────────────────────────────
//  Ultra-lightweight endpoint called by the JS focus-guard on
//  every protected page whenever the browser tab regains focus.
//
//  Returns JSON: { "valid": true }  or  { "valid": false }
//
//  The JS guard in every protected page does:
//      fetch('api/check_session.php')
//          .then(r => r.json())
//          .then(d => { if (!d.valid) window.location.href = 'login.php'; })
//
//  This catches the edge case where:
//    1. User has two tabs open
//    2. Logs out in Tab A (session destroyed)
//    3. Switches back to Tab B (still showing a protected page)
//    4. The focus event fires → this endpoint is called → session
//       is gone → JS redirects Tab B to login.php
//
//  No database calls. No heavy logic. Just a session read.
// =============================================================

// Headers first — before session_start()
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Start the session read-only (we never write in this endpoint)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Only POST requests accepted (prevents casual browser GET sniffing)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'reason' => 'Method not allowed']);
    exit;
}

$valid = !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);

echo json_encode(['valid' => $valid]);
exit;