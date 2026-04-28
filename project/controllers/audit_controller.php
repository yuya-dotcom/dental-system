<?php
// =============================================================
//  controllers/audit_controller.php
//  Universal Audit Logging — EssenciaSmile
//
//  HOW TO USE IN ANY API FILE:
//    require_once __DIR__ . '/../controllers/audit_controller.php';
//
//    logActivity(
//        module:       'appointment-schedules',
//        action:       'UPDATE',
//        entity_id:    $appointmentId,
//        entity_name:  $patientName,
//        description:  "Status changed from Pending to Confirmed"
//    );
//
//  The function automatically reads the logged-in user's data
//  from the session — you never pass user/role/branch manually.
//
//  MODULE NAMES (use these consistently across all files):
//    'appointment-schedules'   appointments-schedule.php
//    'appointment-records'     appointments-records.php
//    'patients'                patients-list.php / patient-records.php
//    'treatments'              active-treatments / treatment-records
//    'billing'                 billing-records.php
//    'inventory'               inventory-records.php
//    'accounts'                accounts-records.php
//    'branches'                branches-records.php
//    'dentists'                dentist-records.php
//    'services'                service-pricing.php
//
//  ACTION VALUES (always uppercase):
//    'INSERT'   — A new record was created
//    'UPDATE'   — An existing record was modified
//    'DELETE'   — A record was permanently removed
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

/**
 * Log any system action to the audit_logs table.
 *
 * All user/session data is read automatically from $_SESSION.
 * This function silently fails on error — it NEVER breaks the
 * calling operation; logging is always secondary.
 *
 * @param string $module      Slug of the feature area (e.g. 'patients')
 * @param string $action      'INSERT' | 'UPDATE' | 'DELETE'
 * @param mixed  $entity_id   The primary key of the affected record
 * @param string $entity_name Human-readable name of the affected record
 * @param string $description Optional detail about what changed
 */
function logActivity(
    string $module,
    string $action,
    $entity_id,
    string $entity_name,
    string $description = ''
): void {
    // Ensure session is running — safe to call even if already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ── Pull user data from session ──────────────────────────
    // full_name is the person's real name (e.g. "Maria Santos")
    // role is their system role ('owner', 'admin', 'dentist')
    $userId     = $_SESSION['user_id']     ?? null;
    $username   = $_SESSION['full_name']   ?? ($_SESSION['username'] ?? 'System');
    $role       = $_SESSION['role']        ?? 'unknown';
    $branchId   = $_SESSION['branch_id']   ?? null;
    $branchName = $_SESSION['branch_name'] ?? null;

    // Owners span all branches — their branch_id in users table
    // may be NULL. We display 'All Branches' for them.
    if ($role === 'owner' && empty($branchName)) {
        $branchName = 'All Branches';
    }

    // ── Build payload ────────────────────────────────────────
    $payload = [
        'user_id'     => $userId,
        'username'    => $username,
        'role'        => $role,
        'branch_id'   => $branchId   ? (int)$branchId   : null,
        'branch_name' => $branchName ? (string)$branchName : null,
        'module'      => strtolower(trim($module)),
        'action'      => strtoupper(trim($action)),      // Always INSERT/UPDATE/DELETE
        'entity_id'   => (string)$entity_id,
        'entity_name' => trim($entity_name),
        'description' => trim($description),
        'created_at'  => date('c'),                      // ISO 8601 timestamp
    ];

    // ── Insert into Supabase (fire-and-forget) ───────────────
    // We intentionally do NOT check the result — a failed log
    // must never block the actual user operation.
    try {
        supabase_request('audit_logs', 'POST', $payload);
    } catch (\Throwable $e) {
        // Log to server error log but do not surface to user
        error_log('[AuditController] logActivity failed: ' . $e->getMessage());
    }
}

/**
 * Convenience wrapper: fetch a patient's full name by ID.
 * Used by API endpoints that only receive patient_id and need
 * the name for the audit entity_name field.
 *
 * Returns 'Unknown Patient' on failure so logging always works.
 */
function getPatientNameForAudit(int $patientId): string
{
    if ($patientId < 1) return 'Unknown Patient';

    $r = supabase_request(
        'patients', 'GET', [],
        'patient_id=eq.' . $patientId . '&select=full_name&limit=1'
    );

    return $r['data'][0]['full_name'] ?? 'Unknown Patient';
}

/**
 * Convenience wrapper: fetch an appointment's patient name + old status.
 * Used by update_appointment_status.php to build a rich log description.
 *
 * Returns ['patient_name' => string, 'old_status' => string]
 */
function getAppointmentInfoForAudit(int $appointmentId): array
{
    $r = supabase_request(
        'appointments', 'GET', [],
        'appointment_id=eq.' . $appointmentId .
        '&select=status,patients(full_name)&limit=1'
    );

    $row = $r['data'][0] ?? [];

    return [
        'patient_name' => $row['patients']['full_name'] ?? 'Unknown Patient',
        'old_status'   => $row['status']                ?? 'unknown',
    ];
}