<?php
// =============================================================
//  api/branch_crud.php  —  add, edit, delete branches
//  Owner only.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

if (!isOwner()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':    echo json_encode(addBranch($input));    break;
    case 'edit':   echo json_encode(editBranch($input));   break;
    case 'delete': echo json_encode(deleteBranch($input)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

function generateBranchCode(): string {
    $r   = supabase_request('branches', 'GET', [], 'select=branch_code&order=branch_id.desc&limit=1');
    $last = $r['data'][0]['branch_code'] ?? 'BRN-000';
    $num  = (int)substr($last, 4) + 1;
    return 'BRN-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function addBranch(array $d): array {
    if (empty($d['branch_name'])) return ['success' => false, 'message' => 'Branch name is required.'];

    $payload = [
        'branch_code'    => generateBranchCode(),
        'branch_name'    => trim($d['branch_name']),
        'address'        => $d['address']        ?? null,
        'contact_number' => $d['contact_number'] ?? null,
        'open_time'      => $d['open_time']       ?: null,
        'close_time'     => $d['close_time']      ?: null,
        'status'         => $d['status']          ?? 'active',
        'created_at'     => date('c'),
        'updated_at'     => date('c'),
    ];

    $r = supabase_request('branches', 'POST', $payload);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Branch added successfully.'];
}

function editBranch(array $d): array {
    if (empty($d['branch_id']))   return ['success' => false, 'message' => 'Missing branch ID.'];
    if (empty($d['branch_name'])) return ['success' => false, 'message' => 'Branch name is required.'];

    $payload = [
        'branch_name'    => trim($d['branch_name']),
        'address'        => $d['address']        ?? null,
        'contact_number' => $d['contact_number'] ?? null,
        'open_time'      => $d['open_time']       ?: null,
        'close_time'     => $d['close_time']      ?: null,
        'status'         => $d['status']          ?? 'active',
        'updated_at'     => date('c'),
    ];

    $r = supabase_request('branches', 'PATCH', $payload, 'branch_id=eq.' . (int)$d['branch_id']);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Branch updated successfully.'];
}

function deleteBranch(array $d): array {
    if (empty($d['branch_id'])) return ['success' => false, 'message' => 'Missing branch ID.'];

    $r = supabase_request('branches', 'DELETE', [], 'branch_id=eq.' . (int)$d['branch_id']);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Branch deleted successfully.'];
}