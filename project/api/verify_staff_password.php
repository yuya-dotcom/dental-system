<?php
// =============================================================
//  api/verify_staff_password.php
//  POST JSON { password, appointment_id }
//
//  Appointments Schedule → Edit Appointment asks the signed-in owner /
//  admin to re-enter THEIR OWN account password before the edit form opens.
//  Checked the same way as the admin sign-in (controllers/auth_controller.php
//  → loginUser): the bcrypt hash in users.password via password_verify().
//  Staff accounts are not Supabase Auth users, so no Supabase Auth call.
//
//  Success → a short-lived permission in the server session to edit THAT
//  appointment (APT_EDIT_GRANT_SECONDS). api/appointments.php (PUT) refuses
//  an edit without it, so the check can't be skipped from the browser.
//  The password is never stored, logged or sent back. Wrong passwords are
//  limited per session; the user stays signed in either way.
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/appointment_edit_grant.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
requireLogin();
requireStaffApi('owner', 'admin');   // the roles that can edit appointments

if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiDeny(405, 'Method not allowed.');

$body          = json_decode(file_get_contents('php://input'), true) ?? [];
$password      = is_string($body['password'] ?? null) ? $body['password'] : '';
$appointmentId = (int)($body['appointment_id'] ?? 0);
if ($appointmentId < 1) apiDeny(400, 'Appointment ID required.');

requireAppointmentBranch($appointmentId);   // 404 / 403 for another branch's appointment

// ── Too many wrong passwords in a row → wait ────────────────
$wait = aptEditLockSeconds();
if ($wait > 0) {
    http_response_code(429);
    echo json_encode(['success' => false, 'code' => 'LOCKED',
        'message' => 'Too many incorrect attempts. Please wait ' . aptEditWaitLabel($wait) . ' and try again.']);
    exit;
}

if ($password === '') {
    echo json_encode(['success' => false, 'code' => 'EMPTY', 'message' => 'Please enter your password.']);
    exit;
}

// ── The signed-in staff member's own hash ───────────────────
$res = supabase_request('users', 'GET', [],
    'user_id=eq.' . (int)$_SESSION['user_id'] . '&select=user_id,password,status,role&limit=1');
if ($res['error'] || !is_array($res['data'])) {
    http_response_code(503);
    echo json_encode(['success' => false, 'code' => 'UNAVAILABLE', 'message' => "We couldn't check your password right now. Please try again."]);
    exit;
}
$user = $res['data'][0] ?? null;
if (!$user || ($user['status'] ?? '') !== 'active' || !in_array($user['role'] ?? '', ['owner', 'admin'], true)) {
    apiDeny(403, 'Your account can no longer edit appointments. Please sign in again.');
}

if (!is_string($user['password'] ?? null) || !password_verify($password, $user['password'])) {
    $left = aptEditRecordFailure();
    $msg  = 'Incorrect password. Please try again.';
    if ($left === 0) $msg = 'Incorrect password. Too many attempts — please wait ' . aptEditWaitLabel(aptEditLockSeconds()) . ' and try again.';
    elseif ($left <= 2) $msg .= ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
    echo json_encode(['success' => false, 'code' => 'WRONG_PASSWORD', 'message' => $msg]);
    exit;
}

aptEditGrant($appointmentId);
echo json_encode(['success' => true, 'expires_in' => APT_EDIT_GRANT_SECONDS]);
