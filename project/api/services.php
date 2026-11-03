<?php
// =============================================================
//  api/services.php
//  GET — list services (used by admin appointment modal picker)
//  Supports: ?status=active|inactive|all  ?limit=N
// =============================================================

session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$status    = trim($_GET['status']     ?? 'active');
$limit     = max(1, min(500, (int)($_GET['limit'] ?? 200)));
$serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

$query = 'select=service_id,service_name,service_type,base_price,status,requires_tooth_selection,chart_outcome'
       . '&order=service_name.asc'
       . '&limit=' . $limit;

// Single service lookup — bypass status filter so inactive services still load
if ($serviceId > 0) {
    $query .= '&service_id=eq.' . $serviceId;
} elseif ($status !== 'all') {
    $query .= '&status=eq.' . urlencode($status);
}

$res  = supabase_request('services', 'GET', [], $query);
$list = is_array($res['data']) ? $res['data'] : [];

// Return a flat array — consistent with what the portal uses
echo json_encode($list);