<?php
// =============================================================
//  api/dentist_crud.php  —  add, edit, delete dentists
//  Owner + Admin.
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';

header('Content-Type: application/json');
requireLogin();

if (!isOwner() && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':    echo json_encode(addDentist($input));    break;
    case 'edit':   echo json_encode(editDentist($input));   break;
    case 'delete': echo json_encode(deleteDentist($input)); break;
    default:       echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

function addDentist(array $d): array {
    if (empty($d['full_name']))  return ['success' => false, 'message' => 'Full name is required.'];
    if (empty($d['branch_id']))  return ['success' => false, 'message' => 'Branch is required.'];

    // Admins can only add dentists to their own branch
    if (isAdmin() && (int)$d['branch_id'] !== getSessionBranch()) {
        return ['success' => false, 'message' => 'You can only add dentists to your own branch.'];
    }

    $payload = [
        'full_name'      => trim($d['full_name']),
        'specialization' => $d['specialization'] ?? null,
        'contact_number' => $d['contact_number'] ?? null,
        'branch_id'      => (int)$d['branch_id'],
        'status'         => $d['status'] ?? 'active',
        'created_at'     => date('c'),
        'updated_at'     => date('c'),
    ];

    $r = supabase_request('dentists', 'POST', $payload);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Dentist added successfully.'];
}

function editDentist(array $d): array {
    if (empty($d['dentist_id'])) return ['success' => false, 'message' => 'Missing dentist ID.'];
    if (empty($d['full_name']))  return ['success' => false, 'message' => 'Full name is required.'];

    if (isAdmin() && (int)$d['branch_id'] !== getSessionBranch()) {
        return ['success' => false, 'message' => 'You can only manage dentists in your own branch.'];
    }

    $payload = [
        'full_name'      => trim($d['full_name']),
        'specialization' => $d['specialization'] ?? null,
        'contact_number' => $d['contact_number'] ?? null,
        'branch_id'      => (int)$d['branch_id'],
        'status'         => $d['status'] ?? 'active',
        'updated_at'     => date('c'),
    ];

    $r = supabase_request('dentists', 'PATCH', $payload, 'dentist_id=eq.' . (int)$d['dentist_id']);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Dentist updated successfully.'];
}

function deleteDentist(array $d): array {
    if (empty($d['dentist_id'])) return ['success' => false, 'message' => 'Missing dentist ID.'];

    $r = supabase_request('dentists', 'DELETE', [], 'dentist_id=eq.' . (int)$d['dentist_id']);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Dentist deleted successfully.'];
}