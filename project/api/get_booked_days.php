<?php

header('Content-Type: application/json');
require_once __DIR__ . '/../dbconfig.php';

$start    = $_GET['start']  ?? '';
$end      = $_GET['end']    ?? '';
$branchId = (int)($_GET['branch'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)   ||
    $branchId < 1) {
    echo json_encode(['fully_booked' => []]);
    exit;
}

$result = supabase_request('appointments', 'GET', [], implode('&', [
    'appointment_date=gte.' . $start,
    'appointment_date=lte.' . $end,
    'branch_id=eq.'         . $branchId,
    'status=neq.cancelled',
    'status=neq.no_show',
    'select=appointment_date,appointment_time',
]));

if ($result['error'] || empty($result['data'])) {
    echo json_encode(['fully_booked' => []]);
    exit;
}

$bookedByDate = [];
foreach ($result['data'] as $row) {
    $date = $row['appointment_date'];
    $bookedByDate[$date][] = substr($row['appointment_time'], 0, 5);
}

function getTotalSlotsForDate(string $dateStr): int {
    return 17; // 9:00,9:30,10:00...16:30,17:00
}

$fullyBooked = [];
foreach ($bookedByDate as $date => $slots) {
    $uniqueBooked = array_unique($slots);
    if (count($uniqueBooked) >= getTotalSlotsForDate($date)) {
        $fullyBooked[] = $date;
    }
}

echo json_encode(['fully_booked' => $fullyBooked]);