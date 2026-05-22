<?php
// =============================================================
//  api/user_crud.php  —  add, edit, delete, reset_password
//  Owner only.
//  Auto-syncs dentists table when role = 'dentist'
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

// =============================================================
//  DENTIST SYNC HELPERS
// =============================================================

/**
 * Find a dentist row linked to a user_id.
 * Returns the dentist row array or null.
 */
function findDentistByUserId(int $userId): ?array {
    $r = supabase_request('dentists', 'GET', [],
        'user_id=eq.' . $userId . '&select=dentist_id,full_name,branch_id,status&limit=1'
    );
    return $r['data'][0] ?? null;
}

/**
 * When a dentist user is created → INSERT a dentists row.
 */
function syncDentistOnCreate(int $userId, string $fullName, ?int $branchId): void {
    if (!$branchId) return; // dentist must have a branch

    supabase_request('dentists', 'POST', [
        'user_id'    => $userId,
        'full_name'  => $fullName,
        'branch_id'  => $branchId,
        'status'     => 'active',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ]);
}

/**
 * When a dentist user is updated → UPDATE the dentists row.
 * Syncs: full_name, branch_id, status
 */
function syncDentistOnUpdate(int $userId, string $fullName, ?int $branchId, string $status): void {
    $existing = findDentistByUserId($userId);

    if ($existing) {
        // Update existing dentist record
        supabase_request('dentists', 'PATCH', [
            'full_name'  => $fullName,
            'branch_id'  => $branchId,
            'status'     => $status === 'active' ? 'active' : 'inactive',
            'updated_at' => date('c'),
        ], 'dentist_id=eq.' . $existing['dentist_id']);
    } else {
        // No dentist record yet — create one (handles edge case where
        // user was created before this sync logic existed)
        if ($branchId) {
            syncDentistOnCreate($userId, $fullName, $branchId);
        }
    }
}

/**
 * When a dentist user's role is changed away from 'dentist'
 * → set their dentist record to inactive (preserve treatment history).
 */
function deactivateDentist(int $userId): void {
    $existing = findDentistByUserId($userId);
    if (!$existing) return;

    supabase_request('dentists', 'PATCH', [
        'status'     => 'inactive',
        'updated_at' => date('c'),
    ], 'dentist_id=eq.' . $existing['dentist_id']);
}

/**
 * When a dentist user is hard-deleted
 * → set their dentist record to inactive (preserve treatment history).
 */
function deactivateDentistOnDelete(int $userId): void {
    deactivateDentist($userId);
}

// =============================================================
//  USER CRUD FUNCTIONS
// =============================================================

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

    $role     = $d['role'];
    $branchId = ($role === 'owner') ? null : ((int)($d['branch_id']) ?: null);

    $payload = [
        'user_code'  => generateUserCode(),
        'full_name'  => trim($d['full_name']),
        'email'      => trim($d['email']),
        'username'   => trim($d['username']),
        'password'   => password_hash($d['password'], PASSWORD_BCRYPT),
        'role'       => $role,
        'branch_id'  => $branchId,
        'status'     => $d['status'] ?? 'active',
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];

    $r = supabase_request('users', 'POST', $payload, 'select=user_id');
    if ($r['error']) return ['success' => false, 'message' => $r['error']];

    // ── Sync dentists table if role is dentist ─────────────────
    if ($role === 'dentist') {
        $newUserId = $r['data'][0]['user_id'] ?? null;

        // Fallback: fetch the newly created user by username
        if (!$newUserId) {
            $lookup = supabase_request('users', 'GET', [],
                'username=eq.' . urlencode(trim($d['username'])) . '&select=user_id&limit=1'
            );
            $newUserId = $lookup['data'][0]['user_id'] ?? null;
        }

        if ($newUserId) {
            syncDentistOnCreate((int)$newUserId, trim($d['full_name']), $branchId);
        }
    }

    return ['success' => true, 'message' => 'User account created successfully.'];
}

function editUser(array $d): array {
    if (empty($d['user_id']))   return ['success' => false, 'message' => 'Missing user ID.'];
    if (empty($d['full_name'])) return ['success' => false, 'message' => 'Full name is required.'];
    if (empty($d['username']))  return ['success' => false, 'message' => 'Username is required.'];
    if (empty($d['email']))     return ['success' => false, 'message' => 'Email is required.'];

    $id   = (int)$d['user_id'];
    $role = $d['role'];

    if (usernameExists($d['username'], $id)) return ['success' => false, 'message' => 'Username is already taken.'];
    if (emailExists($d['email'],       $id)) return ['success' => false, 'message' => 'Email is already in use.'];

    $branchId = ($role === 'owner') ? null : ((int)($d['branch_id']) ?: null);
    $status   = $d['status'] ?? 'active';

    // Fetch previous role to detect role changes
    $prevUser = supabase_request('users', 'GET', [],
        'user_id=eq.' . $id . '&select=role&limit=1'
    );
    $prevRole = $prevUser['data'][0]['role'] ?? null;

    $payload = [
        'full_name'  => trim($d['full_name']),
        'email'      => trim($d['email']),
        'username'   => trim($d['username']),
        'role'       => $role,
        'branch_id'  => $branchId,
        'status'     => $status,
        'updated_at' => date('c'),
    ];

    $r = supabase_request('users', 'PATCH', $payload, 'user_id=eq.' . $id);
    if ($r['error']) return ['success' => false, 'message' => $r['error']];

    // ── Sync dentists table based on role change ───────────────
    if ($role === 'dentist') {
        // Role is dentist (new or existing) — upsert dentist record
        syncDentistOnUpdate($id, trim($d['full_name']), $branchId, $status);
    } elseif ($prevRole === 'dentist' && $role !== 'dentist') {
        // Role was dentist but changed to something else — deactivate
        deactivateDentist($id);
    }

    return ['success' => true, 'message' => 'Account updated successfully.'];
}

function deleteUser(array $d): array {
    if (empty($d['user_id'])) return ['success' => false, 'message' => 'Missing user ID.'];
    if ((int)$d['user_id'] === (int)($_SESSION['user_id'] ?? 0)) {
        return ['success' => false, 'message' => 'You cannot delete your own account.'];
    }

    $id = (int)$d['user_id'];

    // Fetch role before deleting to check if dentist sync is needed
    $prevUser = supabase_request('users', 'GET', [],
        'user_id=eq.' . $id . '&select=role&limit=1'
    );
    $prevRole = $prevUser['data'][0]['role'] ?? null;

    // If dentist — deactivate their dentist record first (preserve history)
    if ($prevRole === 'dentist') {
        deactivateDentistOnDelete($id);
    }

    $r = supabase_request('users', 'DELETE', [], 'user_id=eq.' . $id);
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