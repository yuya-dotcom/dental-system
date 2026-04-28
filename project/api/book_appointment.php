<?php
// =============================================================
//  api/book_appointment.php  —  API Endpoint
//
//  Handles guest booking form submission.
//  This file: validates input only, then delegates to controllers.
//  Zero DB logic here.
//
//  Business logic:
//    - controllers/appointment_controller.php (slot check, create)
//    - controllers/patient_controller.php     (find or create patient)
// =============================================================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../controllers/appointment_controller.php';
require_once __DIR__ . '/../controllers/patient_controller.php';

// ── Parse incoming JSON ───────────────────────────────────────
$body       = json_decode(file_get_contents('php://input'), true);
$firstName  = trim($body['first_name']     ?? '');
$middleName = trim($body['middle_name']    ?? '');
$lastName   = trim($body['last_name']      ?? '');
$suffix     = trim($body['suffix']         ?? '');
$birthdate  = trim($body['birthdate']      ?? '');
$phone      = trim($body['contact_number'] ?? '');
$date       = trim($body['date']           ?? '');
$time       = trim($body['time']           ?? '');
$branchId   = (int)($body['branch_id']     ?? 0);
$serviceId  = isset($body['service_id']) ? (int)$body['service_id'] : null;

// ── Validation (UI rule checks — no DB here) ─────────────────
$errors = [];
if (strlen($firstName) < 2)                            $errors[] = 'First name is too short.';
if (strlen($lastName)  < 2)                            $errors[] = 'Last name is too short.';
if (!preg_match('/^\+639\d{9}$/', $phone))             $errors[] = 'Invalid PH mobile number.';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) $errors[] = 'Invalid birthdate.';
if (strtotime($birthdate) >= strtotime('today'))       $errors[] = 'Birthdate cannot be today or in the future.';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))      $errors[] = 'Invalid appointment date.';
if (!preg_match('/^\d{2}:\d{2}$/', $time))             $errors[] = 'Invalid time format.';
if ($branchId < 1)                                     $errors[] = 'No branch selected.';
if ($date < date('Y-m-d'))                             $errors[] = 'Cannot book a past date.';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── Build full name ───────────────────────────────────────────
$fullName  = $lastName;
if ($suffix)     $fullName .= ' ' . $suffix;
$fullName .= ', ' . $firstName;
if ($middleName) $fullName .= ' ' . $middleName;

$timeForDB = $time . ':00'; // "09:00" → "09:00:00"

// ── STEP 1: Check slot availability (AppointmentController) ──
if (!isSlotAvailable($date, $timeForDB, $branchId)) {
    echo json_encode(['success' => false, 'message' => 'Sorry, that slot was just taken. Please pick another time.']);
    exit;
}

// ── STEP 2: Find or create patient (PatientController) ────────
$patient = findOrCreatePatient($fullName, $phone, $birthdate, $branchId, $date);

if ($patient['error']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $patient['error']]);
    exit;
}

// ── STEP 3: Create appointment (AppointmentController) ────────
$appointment = createAppointment($patient['patient_id'], $branchId, $date, $timeForDB, $serviceId);

if ($appointment['error']) {
    // 409 = slot taken — show as 200 so JS can display the message in the modal
    $code = str_contains($appointment['error'], 'just taken') ? 200 : 500;
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $appointment['error']]);
    exit;
}

// ── Done ──────────────────────────────────────────────────────
echo json_encode([
    'success'          => true,
    'appointment_code' => $appointment['appointment_code'],
    'patient_id'       => $patient['patient_id'],
    'message'          => 'Appointment booked successfully!',
]);