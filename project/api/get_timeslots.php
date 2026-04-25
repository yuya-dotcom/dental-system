<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../dbconfig.php'; 

$date     = $_GET['date']   ?? '';
$branchId = $_GET['branch'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['error' => 'Invalid date.']);
    exit;
}

if ($date < date('Y-m-d')) {
    echo json_encode(['booked' => []]);
    exit;
}

$filters = [
    'appointment_date=eq.' . $date,
    'status=neq.cancelled',
    'status=neq.no_show',
    'select=appointment_time',
];

if ($branchId !== '') {
    $filters[] = 'branch_id=eq.' . (int)$branchId;
}

$query = implode('&', $filters);

$result = supabase_request('appointments', 'GET', [], $query);

if ($result['error']) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not load time slots.']);
    exit;
}

$booked = array_map(
    fn($row) => substr($row['appointment_time'], 0, 5),
    $result['data'] ?? []
);

echo json_encode(['booked' => $booked]);