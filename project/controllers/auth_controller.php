<?php
// =============================================================
//  controllers/auth_controller.php
//  Authentication & Session Management
//
//  Functions:
//    - loginUser()        — validate credentials, start session
//    - requireLogin()     — redirect to login if not logged in
//    - requireRole()      — redirect if role not allowed
//    - getCurrentUser()   — return session user data
//    - logoutUser()       — destroy session
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

// ─────────────────────────────────────────────────────────────
//  SESSION BOOTSTRAP
//  Call once at top of every protected page via requireLogin()
// ─────────────────────────────────────────────────────────────
function bootSession(): void
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

// ─────────────────────────────────────────────────────────────
//  LOGIN
// ─────────────────────────────────────────────────────────────

/**
 * Attempt login with email + password.
 * Returns ['success' => bool, 'message' => string]
 */
function loginUser(string $email, string $password): array
{
    bootSession();

    if (empty($email) || empty($password)) {
        return ['success' => false, 'message' => 'Email and password are required.'];
    }

    // Fetch user by email
    $result = supabase_request('users', 'GET', [], implode('&', [
        'username=eq.' . urlencode(trim($email)),
        'status=eq.active',
        'select=user_id,user_code,full_name,email,username,password,role,branch_id',
        'limit=1',
    ]));

    if ($result['error'] || empty($result['data'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    $user = $result['data'][0];

    // Verify password
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Store in session
    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['user_code'] = $user['user_code'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['branch_id'] = $user['branch_id'];
    $_SESSION['logged_in'] = true;

    // ── Resolve branch_name once at login for audit logs ────
    // Storing it in session avoids an extra DB call on every action.
    if ($user['role'] === 'owner') {
        $_SESSION['branch_name'] = 'All Branches';
    } elseif (!empty($user['branch_id'])) {
        $branchRes = supabase_request(
            'branches', 'GET', [],
            'branch_id=eq.' . (int)$user['branch_id'] . '&select=branch_name&limit=1'
        );
        $_SESSION['branch_name'] = $branchRes['data'][0]['branch_name'] ?? null;
    } else {
        $_SESSION['branch_name'] = null;
    }

    return ['success' => true, 'message' => 'Login successful.'];
}

// ─────────────────────────────────────────────────────────────
//  PROTECTION GUARDS
// ─────────────────────────────────────────────────────────────

/**
 * Redirect to login if not authenticated.
 * Call at the top of every admin page.
 */
function requireLogin(): void
{
    bootSession();
    if (empty($_SESSION['logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Restrict page to specific roles.
 * Pass one or more allowed roles.
 * e.g. requireRole('owner') or requireRole('owner', 'admin')
 */
function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        header('Location: unauthorized.php');
        exit;
    }
}

/**
 * Return current logged-in user data from session.
 */
function getCurrentUser(): array
{
    bootSession();
    return [
        'user_id'     => $_SESSION['user_id']     ?? null,
        'user_code'   => $_SESSION['user_code']   ?? null,
        'full_name'   => $_SESSION['full_name']   ?? 'Unknown',
        'email'       => $_SESSION['email']       ?? '',
        'role'        => $_SESSION['role']         ?? '',
        'branch_id'   => $_SESSION['branch_id']   ?? null,
        'branch_name' => $_SESSION['branch_name'] ?? null,
    ];
}

/**
 * Check if current user is owner.
 */
function isOwner(): bool
{
    bootSession();
    return ($_SESSION['role'] ?? '') === 'owner';
}

/**
 * Check if current user is admin.
 */
function isAdmin(): bool
{
    bootSession();
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Check if current user is dentist.
 */
function isDentist(): bool
{
    bootSession();
    return ($_SESSION['role'] ?? '') === 'dentist';
}

/**
 * Get the branch_id filter for the current user.
 * Owner returns 0 (all branches), others return their branch_id.
 */
function getSessionBranch(): int
{
    bootSession();
    if (($_SESSION['role'] ?? '') === 'owner') return 0;
    return (int)($_SESSION['branch_id'] ?? 0);
}

// ─────────────────────────────────────────────────────────────
//  LOGOUT
// ─────────────────────────────────────────────────────────────

function logoutUser(): void
{
    bootSession();
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}