<?php
// =============================================================
//  api/appointment_services_list.php
//  GET ?appointment_id=X
//  Returns all services linked to an appointment via the
//  appointment_services junction table, with base_price.
//  Used by treatments-active.js to load multi-service materials
//  and calculate combined base price.
// =============================================================

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$appointmentId = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

if (!$appointmentId) {
    echo json_encode(['success' => false, 'message' => 'appointment_id is required.', 'services' => []]);
    exit;
}

$res = supabase_request(
    'appointment_services',
    'GET',
    [],
    'appointment_id=eq.' . $appointmentId .
    '&select=id,service_id,sort_order,services(service_id,service_name,base_price,requires_tooth_selection,chart_outcome)' .
    '&order=sort_order.asc'
);

$rows     = is_array($res['data']) ? $res['data'] : [];
$services = [];

foreach ($rows as $row) {
    $svc = $row['services'] ?? null;
    if (!$svc) continue;
    $services[] = [
        'service_id'               => (int)$svc['service_id'],
        'service_name'             => $svc['service_name']             ?? '—',
        'base_price'               => (float)($svc['base_price']       ?? 0),
        'requires_tooth_selection' => (bool)($svc['requires_tooth_selection'] ?? false),
        'chart_outcome'            => $svc['chart_outcome'] ?? null,
        'sort_order'               => (int)($row['sort_order']          ?? 1),
    ];
}

// If junction table has no rows, fall back to the legacy service_id on appointments
if (empty($services)) {
    $aptRes = supabase_request(
        'appointments',
        'GET',
        [],
        'appointment_id=eq.' . $appointmentId .
        '&select=service_id,services(service_id,service_name,base_price,requires_tooth_selection,chart_outcome)' .
        '&limit=1'
    );
    $apt = $aptRes['data'][0] ?? null;
    if ($apt && !empty($apt['services']['service_id'])) {
        $svc      = $apt['services'];
        $services = [[
            'service_id'               => (int)$svc['service_id'],
            'service_name'             => $svc['service_name']             ?? '—',
            'base_price'               => (float)($svc['base_price']       ?? 0),
            'requires_tooth_selection' => (bool)($svc['requires_tooth_selection'] ?? false),
            'chart_outcome'            => $svc['chart_outcome'] ?? null,
            'sort_order'               => 1,
        ]];
    }
}

echo json_encode(['success' => true, 'services' => $services]);