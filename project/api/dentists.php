<?php
// =============================================================
//  api/dentists.php
//  GET ?branch_id=X  — fetch active dentists for a branch
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;

$q = 'select=dentist_id,full_name,specialization&status=eq.active&order=full_name.asc';
if ($branchId) $q .= '&branch_id=eq.' . $branchId;

$res = supabase_request('dentists', 'GET', [], $q);
echo json_encode($res['data'] ?? []);