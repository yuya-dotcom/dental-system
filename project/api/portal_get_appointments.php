<?php
// =============================================================
//  api/portal_get_appointments.php
//  GET — fetch all appointments for the logged-in portal user,
//        including all services from appointment_services.
//  Used by portal-dashboard.php to render the appointments table
//  and the Appointment Details modal.
//
//  Extra data for the Appointment Details modal (each is a
//  separate, optional query — if one fails, the list still loads):
//    • appointment_type, created_at, updated_at, branch address
//    • services_total  — sum of services.base_price
//    • invoice         — latest invoice for the appointment, with
//                        the latest payment's payment_method
//    • patient         — the logged-in patient's details (top level)
//
//  The queries live in pl_appointments() (controllers/portal_loaders.php)
//  and run in parallel waves; the dashboard's 'bootstrap' action uses the
//  same loader for the first load.
// =============================================================

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../portal-session-guard.php';

// Expired (idle / max duration) sessions are logged out, like the other portal
// APIs. A list refresh is not user activity, so it does not extend the session.
$accountId = $_SESSION['portal_account_id'] ?? null;
if (!$accountId || portal_session_expired() !== null) {
    session_write_close();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
// Release the session lock now — parallel portal requests no longer wait on this one
session_write_close();

require_once __DIR__ . '/../controllers/portal_loaders.php';

echo json_encode(portal_run_one(pl_appointments((int)$accountId, trim($_GET['status'] ?? ''))));
