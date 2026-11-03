<?php
// =============================================================
//  api/get_booked_days.php
//  GET ?start=YYYY-MM-DD&end=YYYY-MM-DD&branch=ID[&services=1,2,3]
//
//  Dates in [start, end) where the branch is open but no start time is
//  left for the visit (sum of the chosen services' durations; 30 minutes
//  when no services are given). Closed days and past dates are not listed
//  — the booking page already greys those out from the branch's hours.
//
//  Response: { fully_booked: ['2026-10-14', …] }
// =============================================================

header('Content-Type: application/json');
require_once __DIR__ . '/../controllers/branch_config.php';

$start      = trim($_GET['start'] ?? '');
$end        = trim($_GET['end']   ?? '');
$branchId   = (int)($_GET['branch'] ?? 0);
$serviceIds = cleanServiceIds($_GET['services'] ?? '');

$branch = getBookingBranch($branchId);
if (!$branch || !isValidIsoDate($start) || !isValidIsoDate($end) || $end <= $start) {
    echo json_encode(['fully_booked' => []]);
    exit;
}

// A calendar month view is at most 6 weeks; cap the range to keep this cheap
$from = new DateTimeImmutable($start, bookingTz());
$to   = new DateTimeImmutable($end,   bookingTz());
if ($from->diff($to)->days > 62) $to = $from->modify('+62 days');
$lastDay = $to->modify('-1 day')->format('Y-m-d');

$visit = visitDurationFor($serviceIds);
if ($visit['error']) {
    echo json_encode(['fully_booked' => [], 'error' => $visit['error']]);
    exit;
}

$ranges = getBookedRanges($branchId, $from->format('Y-m-d'), $lastDay);
if (isset($ranges['__error'])) {
    echo json_encode(['fully_booked' => []]);
    exit;
}

$full = [];
for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
    $date = $d->format('Y-m-d');
    if (dateUnavailableReason($branch, $date)) continue;

    $hasStart = false;
    foreach (buildDaySlots($branch, $visit['minutes'], $ranges[$date] ?? []) as $slot) {
        if ($slot['available']) { $hasStart = true; break; }
    }
    if (!$hasStart) $full[] = $date;
}

echo json_encode(['fully_booked' => $full]);