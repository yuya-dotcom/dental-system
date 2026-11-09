<?php
// =============================================================
//  api/portal_feedback.php — patient feedback (one per appointment)
//
//  GET  ?action=status                      → { submitted: {appointment_id: {category, created_at}} }
//  GET  ?action=context&appointment_id=N    → appointment summary + the dentist that may be reviewed
//  POST {action:'submit', appointment_id, category, rating, message}
//
//  The patient comes from the server session and the dentist from the
//  appointment record; ids sent by the browser are only used to look up
//  the patient's own appointment. Rules live in feedback_controller.php.
// =============================================================

session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../portal-session-guard.php';
require_once __DIR__ . '/../controllers/feedback_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';

portal_require_login_api();

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

function fbOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function fbFail(string $message, string $code = 'validation', int $http = 422): void
{
    fbOut(['success' => false, 'code' => $code, 'message' => $message], $http);
}

$accountId = (int)$_SESSION['portal_account_id'];
// Session-cached patient lookup + parallel status queries (controllers/portal_loaders.php)
require_once __DIR__ . '/../controllers/portal_loaders.php';
$patientId = portal_run_one(pl_patient_id($accountId));

// ── STATUS: which of my appointments already have feedback ──────
// Same rules as feedbackSubmittedMap(); also served by portal_auth 'bootstrap'.
if ($action === 'status') {
    fbOut(portal_run_one(pl_feedback_status($accountId)));
}

if (!$patientId) fbFail('Your account is not linked to a patient record yet.', 'not_found', 404);
$appointmentId = (int)($_GET['appointment_id'] ?? $input['appointment_id'] ?? 0);
if ($appointmentId <= 0) fbFail('Missing appointment.');

// ── CONTEXT: what the form shows for this appointment ───────────
if ($action === 'context') {
    $chk = feedbackLoadAppointment($patientId, $appointmentId);
    if (!$chk['ok']) fbOut(['success' => false, 'code' => $chk['code'], 'message' => $chk['message']]);
    $a = $chk['appointment'];
    fbOut([
        'success'     => true,
        'appointment' => [
            'appointment_id'   => (int)$a['appointment_id'],
            'appointment_code' => $a['appointment_code'] ?? '',
            'appointment_date' => $a['appointment_date'] ?? null,
            'services'         => appointmentServicesLabel($a, ''),
            'branch_name'      => $a['branches']['branch_name'] ?? '',
        ],
        'dentist'     => $chk['dentist'] ? ['full_name' => $chk['dentist']['full_name']] : null,
    ]);
}

// ── SUBMIT ──────────────────────────────────────────────────────
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = FEEDBACK_PATIENT_CATEGORY; // dentist feedback only — never taken from the request
    $rating   = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    $message  = trim(str_replace("\r\n", "\n", (string)($input['message'] ?? '')));

    if ($rating === false)                      fbFail('Please give a rating from 1 to 5 stars.');
    if (mb_strlen($message) > FEEDBACK_MAX_LEN) fbFail('Please keep your feedback under ' . FEEDBACK_MAX_LEN . ' characters.');

    $chk = feedbackLoadAppointment($patientId, $appointmentId);
    if (!$chk['ok']) fbFail($chk['message'], $chk['code'], $chk['code'] === 'already_submitted' ? 409 : 422);
    $apt     = $chk['appointment'];
    $dentist = $chk['dentist'];

    $res = supabase_request('patient_feedback', 'POST', [
        'appointment_id' => $appointmentId,
        'patient_id'     => $patientId,
        'account_id'     => $accountId,
        'branch_id'      => $apt['branch_id'] ? (int)$apt['branch_id'] : null,
        'category'       => $category,
        'dentist_id'     => $dentist['dentist_id'], // resolved from the completed treatment
        'rating'         => $rating,
        'message'        => $message !== '' ? $message : null,
    ]);

    if (!empty($res['error'])) {
        $err = is_string($res['error']) ? $res['error'] : json_encode($res['error']);
        // Double click / second tab / race: the unique key on appointment_id won
        if (str_contains($err, 'patient_feedback_appointment_uq') || str_contains($err, 'duplicate key')) {
            fbFail('You have already sent feedback for this appointment.', 'already_submitted', 409);
        }
        error_log('[portal_feedback] insert failed: ' . $err);
        fbFail('We could not save your feedback. Please try again.', 'error', 500);
    }
    $saved = $res['data'][0] ?? null;
    if (empty($saved['feedback_id'])) fbFail('We could not save your feedback. Please try again.', 'error', 500);

    // The Owner reads every audit log, so this entry names no patient and no
    // appointment — otherwise it would undo feedback anonymity.
    logActivity(
        'feedback', 'INSERT', $saved['feedback_id'],
        'Patient feedback',
        FEEDBACK_CATEGORIES[$category] . ' submitted (' . $rating . '/5)',
        $apt['branch_id'] ? (int)$apt['branch_id'] : null,
        $apt['branches']['branch_name'] ?? null,
        ['username' => 'Portal patient', 'role' => 'patient']
    );

    fbOut([
        'success'        => true,
        'appointment_id' => $appointmentId,
        'category'       => $category,
        'created_at'     => $saved['created_at'] ?? null,
    ]);
}

fbFail('Unknown action.', 'validation', 400);
