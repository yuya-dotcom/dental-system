<?php
// =============================================================
//  api/patients.php
//  GET ?branch_id=X  — fetch active patients for a branch
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

$q = 'select=patient_id,full_name,contact_number&status=eq.active&order=full_name.asc';
if ($branchId) $q .= '&branch_id=eq.' . $branchId;

$res = supabase_request('patients', 'GET', [], $q);
echo json_encode($res['data'] ?? []);