<?php
// =============================================================
//  api/services.php
//  GET — fetch all active services
//  GET ?service_id=X — fetch single service
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$serviceId = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

$q = 'select=service_id,service_name,service_type,base_price&status=neq.inactive&order=service_name.asc';
if ($serviceId) $q .= '&service_id=eq.' . $serviceId;

$res = supabase_request('services', 'GET', [], $q);

// treatments.js expects 'price' for autoFillCost — map base_price to price
$data = array_map(function($s) {
    $s['price'] = $s['base_price'] ?? null;
    return $s;
}, $res['data'] ?? []);

echo json_encode($data);