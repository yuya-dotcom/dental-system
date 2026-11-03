<?php
// =============================================================
//  api/get_timeslots.php
//  GET ?date=YYYY-MM-DD&branch=ID[&services=1,2,3]
//
//  Start times for one branch and day, built from the branch's
//  open/close time. When services are given, each start time is checked
//  for the whole visit (sum of service durations): it must end by
//  closing time and not overlap another booking.
//
//  Response:
//    slots:    [{time:'09:00', label:'9:00 AM', ends:'9:30 AM', available:true, reason:null}, …]
//              reason: 'booked' | 'closing'
//    booked:   start times already taken (kept for older callers)
//    duration: visit length in minutes · unavailable: why the whole day is
//              unavailable (past | closed | inactive | invalid) or null
// =============================================================

header('Content-Type: application/json');
require_once __DIR__ . '/../controllers/branch_config.php';

$date       = trim($_GET['date'] ?? '');
$branchId   = (int)($_GET['branch'] ?? 0);
$serviceIds = cleanServiceIds($_GET['services'] ?? '');

if (!isValidIsoDate($date)) {
    echo json_encode(['error' => 'Invalid date.']);
    exit;
}

$branch = getBookingBranch($branchId);
if (!$branch) {
    echo json_encode(['error' => 'Please select a branch.']);
    exit;
}

$visit = visitDurationFor($serviceIds);
if ($visit['error']) {
    echo json_encode(['error' => $visit['error']]);
    exit;
}
$duration = $visit['minutes'];

$base = [
    'date'           => $date,
    'duration'       => $duration,
    'duration_label' => visitLengthLabel($duration),
    'open'           => $branch['open_time'],
    'close'          => $branch['close_time'],
];

$reason = dateUnavailableReason($branch, $date);
if ($reason) {
    echo json_encode($base + ['unavailable' => $reason, 'slots' => [], 'booked' => []]);
    exit;
}

$ranges = getBookedRanges($branchId, $date, $date);
if (isset($ranges['__error'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load time slots.']);
    exit;
}
$dayRanges = $ranges[$date] ?? [];

echo json_encode($base + [
    'unavailable' => null,
    'slots'       => buildDaySlots($branch, $duration, $dayRanges),
    'booked'      => array_values(array_unique(array_map(fn($r) => minutesToTime($r[0]), $dayRanges))),
]);