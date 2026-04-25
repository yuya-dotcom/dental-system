<?php
// =============================================================
//  api/user_crud.php  —  add, edit, delete, reset_password
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
    case 'add':            echo json_encode(addUser($input));        break;
    case 'edit':           echo json_encode(editUser($input));       break;
    case 'delete':         echo json_encode(deleteUser($input));     break;
    case 'reset_password': echo json_encode(resetPassword($input));  break;
    default:               echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

function generateUserCode(): string {
    $r    = supabase_request('users', 'GET', [], 'select=user_code&order=user_id.desc&limit=1');
    $last = $r['data'][0]['user_code'] ?? 'USR-000';
    $num  = (int)substr($last, 4) + 1;
    return 'USR-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

function usernameExists(string $username, int $excludeId = 0): bool {
    $q = 'select=user_id&username=eq.' . urlencode($username);
    if ($excludeId) $q .= '&user_id=neq.' . $excludeId;
    $r = supabase_request('users', 'GET', [], $q);
    return !empty($r['data']);
}

function emailExists(string $email, int $excludeId = 0): bool {
    $q = 'select=user_id&email=eq.' . urlencode($email);
    if ($excludeId) $q .= '&user_id=neq.' . $excludeId;
    $r = supabase_request('users', 'GET', [], $q);
    return !empty($r['data']);
}

function addUser(array $d): array {
    if (empty($d['full_name']))  return ['success' => false, 'message' => 'Full name is required.'];
    if (empty($d['username']))   return ['success' => false, 'message' => 'Username is required.'];
    if (empty($d['email']))      return ['success' => false, 'message' => 'Email is required.'];
    if (empty($d['password']))   return ['success' => false, 'message' => 'Password is required.'];
    if (strlen($d['password']) < 6) return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    if (empty($d['role']))       return ['success' => false, 'message' => 'Role is required.'];

    if (usernameExists($d['username'])) return ['success' => false, 'message' => 'Username is already taken.'];
    if (emailExists($d['email']))       return ['success' => false, 'message' => 'Email is already in use.'];

    $payload = [
        'user_code'  => generateUserCode(),
        'full_name'  => trim($d['full_name']),
        'email'      => trim($d['email']),
        'username'   => trim($d['username']),
        'password'   => password_hash($d['password'], PASSWORD_BCRYPT),
        'role'       => $d['role'],
        'branch_id'  => ($d['role'] === 'owner') ? null : ((int)($d['branch_id']) ?: null),
        'status'     => $d['status'] ?? 'active',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];

    $r = supabase_request('users', 'POST', $payload);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'User account created successfully.'];
}

function editUser(array $d): array {
    if (empty($d['user_id']))   return ['success' => false, 'message' => 'Missing user ID.'];
    if (empty($d['full_name'])) return ['success' => false, 'message' => 'Full name is required.'];
    if (empty($d['username']))  return ['success' => false, 'message' => 'Username is required.'];
    if (empty($d['email']))     return ['success' => false, 'message' => 'Email is required.'];

    $id = (int)$d['user_id'];
    if (usernameExists($d['username'], $id)) return ['success' => false, 'message' => 'Username is already taken.'];
    if (emailExists($d['email'],       $id)) return ['success' => false, 'message' => 'Email is already in use.'];

    $payload = [
        'full_name'  => trim($d['full_name']),
        'email'      => trim($d['email']),
        'username'   => trim($d['username']),
        'role'       => $d['role'],
        'branch_id'  => ($d['role'] === 'owner') ? null : ((int)($d['branch_id']) ?: null),
        'status'     => $d['status'] ?? 'active',
        'updated_at' => date('c'),
    ];

    $r = supabase_request('users', 'PATCH', $payload, 'user_id=eq.' . $id);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Account updated successfully.'];
}

function deleteUser(array $d): array {
    if (empty($d['user_id'])) return ['success' => false, 'message' => 'Missing user ID.'];
    if ((int)$d['user_id'] === (int)($_SESSION['user_id'] ?? 0)) {
        return ['success' => false, 'message' => 'You cannot delete your own account.'];
    }
    $r = supabase_request('users', 'DELETE', [], 'user_id=eq.' . (int)$d['user_id']);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Account deleted successfully.'];
}

function resetPassword(array $d): array {
    if (empty($d['user_id']))      return ['success' => false, 'message' => 'Missing user ID.'];
    if (empty($d['new_password'])) return ['success' => false, 'message' => 'New password is required.'];
    if (strlen($d['new_password']) < 6) return ['success' => false, 'message' => 'Password must be at least 6 characters.'];

    $payload = [
        'password'   => password_hash($d['new_password'], PASSWORD_BCRYPT),
        'updated_at' => date('c'),
    ];
    $r = supabase_request('users', 'PATCH', $payload, 'user_id=eq.' . (int)$d['user_id']);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];
    return ['success' => true, 'message' => 'Password reset successfully.'];
}