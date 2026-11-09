<?php
// =============================================================
//  api/portal_book_appointment.php
//  Portal-authenticated appointment booking.
//  Supports 1–3 services via appointment_services junction table.
//
//  Checked here (not only in the browser):
//    • signed in, and the profile is complete
//    • branch exists and is active
//    • date is after today (Manila time) and the branch is open that day
//    • at least one active service (max 3)
//    • the whole visit (sum of service durations) fits before closing
//      and does not overlap another booking at the branch
//  Error responses carry a `code` the booking page reacts to:
//    profile_incomplete · slot_taken · validation
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
require_once __DIR__ . '/../controllers/branch_config.php';

function bookFail(string $message, string $code = 'validation'): void
{
    echo json_encode(['success' => false, 'code' => $code, 'message' => $message]);
    exit;
}

// ── Auth guard ────────────────────────────────────────────────
// Same idle / max-duration expiry as the rest of the portal, so a tab left
// open after the session expired can't book.
require_once __DIR__ . '/../portal-session-guard.php';
if (portal_session_expired() !== null) {
    portal_destroy_session();
}
$accountId = $_SESSION['portal_account_id'] ?? null;
if (!$accountId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'code' => 'auth', 'message' => 'Not authenticated. Please sign in.']);
    exit;
}
portal_require_contact_verified_api((int)$accountId);   // verified email OR phone

// ── Parse body ────────────────────────────────────────────────
$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$branchId = (int)($body['branch_id'] ?? 0);
$date     = trim((string)($body['date']  ?? ''));
$time     = trim((string)($body['time']  ?? ''));
$notes    = trim((string)($body['notes'] ?? ''));
if (mb_strlen($notes) > 1000) $notes = mb_substr($notes, 0, 1000);

// service_ids: accept array (new) or single int (legacy fallback)
$rawServices = $body['service_ids'] ?? [];
if (!is_array($rawServices)) {
    $rawServices = isset($body['service_id']) && $body['service_id'] !== ''
        ? [(int)$body['service_id']] : [];
}
$serviceIds = cleanServiceIds($rawServices); // integers, unique, max 3

// ── Validate request ──────────────────────────────────────────
$branch = getBookingBranch($branchId);
if (!$branch)                                        bookFail('Please select a branch.');
if (!$branch['active'])                              bookFail('This branch is not accepting online bookings right now. Please choose another branch.');
if (!$serviceIds)                                    bookFail('Please choose at least one service.');
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time))  bookFail('Invalid time format.');

switch (dateUnavailableReason($branch, $date)) {
    case 'invalid': bookFail('Invalid appointment date.');
    case 'past':    bookFail('Please choose a date from tomorrow onwards.');
    case 'closed':  bookFail('The ' . $branch['branch_name'] . ' branch is closed on that day. It is open ' . $branch['days_label'] . '.');
    case 'inactive':bookFail('This branch is not accepting online bookings right now.');
}
// Same booking window as the calendar (about six months ahead)
if ($date > date('Y-m-d', strtotime(bookingToday() . ' +180 days'))) {
    bookFail('Please choose a date within the next six months.');
}

$time      = substr($time, 0, 5);
$timeForDB = $time . ':00';

// ── Fetch account (and check the profile is complete) ────────
$accRes = supabase_request(
    'patient_accounts', 'GET', [],
    'account_id=eq.' . (int)$accountId .
    '&select=account_id,first_name,last_name,middle_name,suffix,birthdate,contact_number,patient_id,is_profile_complete&limit=1'
);
if (empty($accRes['data'])) bookFail('Account not found.', 'auth');

$account = $accRes['data'][0];
if (empty($account['is_profile_complete'])) {
    bookFail('Please complete your profile before booking an appointment.', 'profile_incomplete');
}
$patientId = $account['patient_id'] ? (int)$account['patient_id'] : null;

// ── Visit length + availability ───────────────────────────────
$visit = visitDurationFor($serviceIds);
if ($visit['error']) bookFail($visit['error']);
$duration = $visit['minutes'];

$ranges = getBookedRanges($branchId, $date, $date);
if (isset($ranges['__error'])) bookFail('Could not check availability. Please try again.');

if (!canStartAt($branch, $time, $duration, $ranges[$date] ?? [])) {
    $slot = null;
    foreach (buildDaySlots($branch, $duration, $ranges[$date] ?? []) as $s) {
        if ($s['time'] === $time) { $slot = $s; break; }
    }
    if (!$slot) {
        bookFail('That start time isn\'t offered at this branch. Please pick one of the listed times.', 'slot_taken');
    }
    if ($slot['reason'] === 'closing') {
        bookFail('Your visit (' . visitLengthLabel($duration) . ') would end after closing time ('
            . minutesToLabel($branch['close_min']) . '). Please pick an earlier time.', 'slot_taken');
    }
    bookFail('Sorry, that time was just taken. Please pick another time.', 'slot_taken');
}

// ── Ensure patient row exists ─────────────────────────────────
if (!$patientId) {
    $firstName  = trim($account['first_name']  ?? '');
    $lastName   = trim($account['last_name']   ?? '');
    $middleName = trim($account['middle_name'] ?? '');
    $suffix     = trim($account['suffix']      ?? '');
    $nameParts  = array_filter([$firstName, $middleName, $lastName, $suffix]);
    $fullName   = implode(' ', $nameParts) ?: ('Portal Patient #' . $accountId);
    $contactNum = trim($account['contact_number'] ?? '') ?: ('__PORTAL_' . $accountId . '__');
    $patientCode = 'PAT-P' . str_pad($accountId, 5, '0', STR_PAD_LEFT);

    $newPat = supabase_request('patients', 'POST', [
        'patient_code'   => $patientCode,
        'full_name'      => $fullName,
        'contact_number' => $contactNum,
        'birthdate'      => $account['birthdate'] ?: null,
        'branch_id'      => $branchId,
        'last_visit'     => $date,
        'status'         => 'active',
    ]);

    if (empty($newPat['data'][0]['patient_id'])) {
        bookFail('Could not create patient record. Please complete your profile first.');
    }

    $patientId = (int)$newPat['data'][0]['patient_id'];
    supabase_request('patient_accounts', 'PATCH',
        ['patient_id' => $patientId],
        'account_id=eq.' . (int)$accountId
    );
}

// ── Generate appointment code ─────────────────────────────────
$lastRes  = supabase_request('appointments', 'GET', [],
    'select=appointment_code&order=appointment_id.desc&limit=1'
);
$lastCode = $lastRes['data'][0]['appointment_code'] ?? 'APT-00000';
$lastNum  = (int)preg_replace('/[^0-9]/', '', $lastCode);
$aptCode  = 'APT-' . str_pad($lastNum + 1, 5, '0', STR_PAD_LEFT);

// ── Insert appointment ────────────────────────────────────────
// Keep service_id = first selected service for backward compatibility
$payload = [
    'appointment_code' => $aptCode,
    'patient_id'       => $patientId,
    'account_id'       => (int)$accountId,
    'branch_id'        => $branchId,
    'service_id'       => $serviceIds[0],
    'appointment_date' => $date,
    'appointment_time' => $timeForDB,
    'duration_minutes' => $duration,
    'appointment_type' => 'Consultation',
    'notes'            => $notes ?: null,
    'status'           => 'pending',
    'payment_status'   => 'unpaid',
];

$result = supabase_request('appointments', 'POST', $payload);

// The duration column only exists after the SQL file is run
if (!empty($result['error']) && str_contains(is_string($result['error']) ? $result['error'] : json_encode($result['error']), 'duration_minutes')) {
    unset($payload['duration_minutes']);
    $result = supabase_request('appointments', 'POST', $payload);
}

// ── Catch unique constraint violation (double-submit / race) ──
if (!empty($result['error'])) {
    $errMsg = is_string($result['error']) ? $result['error'] : json_encode($result['error']);

    // Postgres unique constraint on (branch_id, appointment_date, appointment_time)
    if (str_contains($errMsg, 'appointments_branch_id_appointment_date_appointment_time')
        || str_contains($errMsg, 'duplicate key value')
        || str_contains($errMsg, 'unique constraint')) {
        bookFail('Sorry, that time slot was just taken. Please go back and choose a different time.', 'slot_taken');
    }
    bookFail('Booking failed. Please try again.', 'error');
}

if (empty($result['data'][0]['appointment_id'])) {
    bookFail('Booking failed. Please try again.', 'error');
}

$appointmentId = (int)$result['data'][0]['appointment_id'];

// ── Re-check after insert ─────────────────────────────────────
// The unique key only blocks identical start times. Two overlapping visits
// with different starts sent at the same moment both pass the check above,
// so recheck against bookings saved before this one: the earlier one keeps
// the slot and this one is removed.
$q = implode('&', [
    'branch_id=eq.'        . $branchId,
    'appointment_date=eq.' . $date,
    'appointment_id=lt.'   . $appointmentId,
    'status=neq.cancelled',
    'status=neq.no_show',
    'select=appointment_time',
]);
$earlier = supabase_request('appointments', 'GET', [], $q . ',duration_minutes');
if ($earlier['error']) $earlier = supabase_request('appointments', 'GET', [], $q);
if (!$earlier['error'] && is_array($earlier['data'])) {
    $earlierRanges = [];
    foreach ($earlier['data'] as $row) {
        $start = timeToMinutes($row['appointment_time'] ?? null);
        if ($start === null) continue;
        $earlierRanges[] = [$start, $start + blockedMinutes((int)($row['duration_minutes'] ?? BOOKING_SLOT_MINUTES))];
    }
    if (!canStartAt($branch, $time, $duration, $earlierRanges)) {
        supabase_request('appointments', 'DELETE', [], 'appointment_id=eq.' . $appointmentId);
        bookFail('Sorry, that time was just taken. Please pick another time.', 'slot_taken');
    }
}

notif_appointment($result['data'][0]);   // "request received" — only after the insert succeeded

// ── Insert into appointment_services junction table ───────────
foreach ($serviceIds as $order => $sid) {
    supabase_request('appointment_services', 'POST', [
        'appointment_id' => $appointmentId,
        'service_id'     => $sid,
        'sort_order'     => $order + 1,
    ]);
}

// ── Update patient branch + last visit ───────────────────────
supabase_request('patients', 'PATCH', [
    'branch_id'  => $branchId,
    'last_visit' => $date,
    'updated_at' => date('c'),
], 'patient_id=eq.' . $patientId);

// ── Saved details for the confirmation popup (from the stored row, not the form) ──
$saved     = $result['data'][0];
$svcNames  = [];
$svcRes    = supabase_request('services', 'GET', [],
    'service_id=in.(' . implode(',', array_map('intval', $serviceIds)) . ')&select=service_id,service_name');
$byId      = array_column(is_array($svcRes['data'] ?? null) ? $svcRes['data'] : [], 'service_name', 'service_id');
foreach ($serviceIds as $sid) {
    if (!empty($byId[$sid])) $svcNames[] = $byId[$sid];   // keep booking order
}

// The dashboard's upcoming count changed: drop its 60 s cache (session is still open here)
unset($_SESSION['dash_cache_' . (int)$accountId]);

// ── Done ──────────────────────────────────────────────────────
echo json_encode([
    'success'          => true,
    'appointment_code' => $saved['appointment_code'] ?? $aptCode,
    'duration'         => $duration,
    'message'          => 'Your appointment has been booked successfully!',
    'appointment'      => [
        'status'      => $saved['status']           ?? 'pending',
        'date'        => $saved['appointment_date'] ?? $date,
        'time'        => substr((string)($saved['appointment_time'] ?? $timeForDB), 0, 5),
        'branch_name' => $branch['branch_name']     ?? '',
        'services'    => $svcNames,
    ],
]);