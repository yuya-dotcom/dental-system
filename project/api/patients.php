<?php
// =============================================================
//  api/patients.php
//  GET ?branch_id=X  — active patients with an appointment at a branch
//                      (non-owners: always their own branch)
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();
requireStaffApi('owner', 'admin', 'dentist');

// Same rule as patients-list.php: patients have no permanent branch, so a
// branch's patients are those with an appointment there (any status).
// Admin / Dentist: always their session branch (?branch_id is ignored).
// Owner: every patient, or the requested branch's.
$branchId = scopedBranch(isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0);

$q = 'select=patient_id,full_name,contact_number&status=eq.active&order=full_name.asc';
if ($branchId !== 0) {   // -1 (no valid branch) matches nobody
    $q = 'select=patient_id,full_name,contact_number,vis:appointments!inner(appointment_id)'
       . '&vis.branch_id=eq.' . $branchId . '&vis.limit=1&status=eq.active&order=full_name.asc';
}

$res  = supabase_request('patients', 'GET', [], $q);
$rows = is_array($res['data'] ?? null) && array_is_list($res['data']) ? $res['data'] : [];
echo json_encode(array_map(fn($r) => ['patient_id' => $r['patient_id'], 'full_name' => $r['full_name'], 'contact_number' => $r['contact_number']], $rows));