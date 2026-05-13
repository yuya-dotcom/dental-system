<?php
// =============================================================
//  api/portal_book_appointment.php
//  Portal-authenticated appointment booking.
//
//  Because the patient is logged in, we already know:
//    - name, birthdate  → patient_accounts
//    - patient_id       → patient_accounts.patient_id (may be
//                         null if profile was never saved yet;
//                         we create a minimal patient row then)
//
//  The caller only needs to send:
//    branch_id, date, time, service_id (opt), notes (opt)
// =============================================================

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/appointment_controller.php';

// ── Auth guard ────────────────────────────────────────────────
$accountId = $_SESSION['portal_account_id'] ?? null;
if (!$accountId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please sign in.']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$branchId  = (int)($body['branch_id']  ?? 0);
$date      = trim($body['date']        ?? '');
$time      = trim($body['time']        ?? '');
$serviceId = isset($body['service_id']) && $body['service_id'] !== ''
             ? (int)$body['service_id'] : null;
$notes     = trim($body['notes']       ?? '');

// ── Validate form fields ──────────────────────────────────────
$errors = [];
if ($branchId < 1)                                      $errors[] = 'Please select a branch.';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))       $errors[] = 'Invalid appointment date.';
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time))    $errors[] = 'Invalid time format.';
if ($date !== '' && $date < date('Y-m-d'))              $errors[] = 'Cannot book a past date.';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// Normalise time to HH:MM:SS
$timeForDB = strlen($time) === 5 ? $time . ':00' : $time;

// ── Fetch account data ────────────────────────────────────────
$accRes = supabase_request(
    'patient_accounts',
    'GET',
    [],
    'account_id=eq.' . (int)$accountId .
        '&select=account_id,first_name,last_name,middle_name,suffix,' .
        'birthdate,contact_number,patient_id&limit=1'
);

if (empty($accRes['data'])) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}
$account   = $accRes['data'][0];
$patientId = $account['patient_id'] ? (int)$account['patient_id'] : null;

// ── Ensure a patient row exists ───────────────────────────────
// If the portal account has never completed their profile,
// patient_id may be null.  We create a minimal patient row so
// the appointment can be linked properly.
if (!$patientId) {

    $firstName  = trim($account['first_name']  ?? '');
    $lastName   = trim($account['last_name']   ?? '');
    $middleName = trim($account['middle_name'] ?? '');
    $suffix     = trim($account['suffix']      ?? '');

    // Build full name the same way update_profile does
    $nameParts = array_filter([$firstName, $middleName, $lastName, $suffix]);
    $fullName  = implode(' ', $nameParts) ?: ('Portal Patient #' . $accountId);

    // Contact number: use stored value or a placeholder so NOT NULL is satisfied
    $contactNum = trim($account['contact_number'] ?? '');
    if (!$contactNum) {
        $contactNum = '__PORTAL_' . $accountId . '__';
    }

    $patientCode = 'PAT-P' . str_pad($accountId, 5, '0', STR_PAD_LEFT);

    $newPat = supabase_request('patients', 'POST', [
        'patient_code'   => $patientCode,
        'full_name'      => $fullName,
        'contact_number' => $contactNum,
        'birthdate'      => $account['birthdate'] ?: null,
        'status'         => 'active',
    ]);

    if (empty($newPat['data'][0]['patient_id'])) {
        echo json_encode(['success' => false, 'message' => 'Could not create patient record. Please complete your profile first.']);
        exit;
    }

    $patientId = (int)$newPat['data'][0]['patient_id'];

    // Link patient_id back to the portal account
    supabase_request(
        'patient_accounts',
        'PATCH',
        ['patient_id' => $patientId],
        'account_id=eq.' . (int)$accountId
    );
}

// ── Check slot availability ───────────────────────────────────
if (!isSlotAvailable($date, $timeForDB, $branchId)) {
    echo json_encode(['success' => false, 'message' => 'Sorry, that slot was just taken. Please pick another time.']);
    exit;
}

// ── Generate appointment code ─────────────────────────────────
$lastRes  = supabase_request('appointments', 'GET', [],
    'select=appointment_code&order=appointment_id.desc&limit=1'
);
$lastCode = $lastRes['data'][0]['appointment_code'] ?? 'APT-00000';
$lastNum  = (int)preg_replace('/[^0-9]/', '', $lastCode);
$aptCode  = 'APT-' . str_pad($lastNum + 1, 5, '0', STR_PAD_LEFT);

// ── Insert appointment ────────────────────────────────────────
$payload = [
    'appointment_code' => $aptCode,
    'patient_id'       => $patientId,
    'account_id'       => (int)$accountId,
    'branch_id'        => $branchId,
    'service_id'       => $serviceId,
    'appointment_date' => $date,
    'appointment_time' => $timeForDB,
    'appointment_type' => 'Consultation',
    'notes'            => $notes ?: null,
    'status'           => 'pending',
    'payment_status'   => 'unpaid',
];

$result = supabase_request('appointments', 'POST', $payload);

if ($result['error'] || empty($result['data'][0]['appointment_id'])) {
    echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Booking failed. Please try again.']);
    exit;
}

// ── Done ──────────────────────────────────────────────────────
echo json_encode([
    'success'          => true,
    'appointment_code' => $aptCode,
    'message'          => 'Your appointment has been booked successfully!',
]);