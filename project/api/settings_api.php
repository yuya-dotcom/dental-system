<?php
// =============================================================
//  api/settings_api.php — Settings page (settings.php)
//
//  GET  ?action=status          OWNER. Live database / backup status:
//                               connection + response time, backup
//                               function installed?, server version,
//                               database size, table / row counts,
//                               last backup and last schema download.
//  POST {action:'change_password', current_password, new_password,
//        confirm_password}      ANY staff account, for ITSELF only.
//                               Current password checked on the server
//                               (bcrypt; shared wrong-password limit).
//
//  Never returns keys, connection strings, hashes or file paths.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/audit_controller.php';
require_once __DIR__ . '/../controllers/settings_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $method === 'GET' ? (string)($_GET['action'] ?? '') : '';
$body   = [];
if ($method === 'POST') {
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) apiDeny(415, 'Unsupported request.');
    $body   = json_decode(file_get_contents('php://input'), true);
    $body   = is_array($body) ? $body : [];
    $action = (string)($body['action'] ?? '');
}

switch ($method . ' ' . $action) {
    case 'GET status':
        requireStaffApi('owner');
        session_write_close();
        echo json_encode(setStatusPayload());
        break;

    case 'POST change_password':
        requireStaffApi('owner', 'admin', 'dentist');
        echo json_encode(setChangeOwnPassword($body));
        break;

    default:
        apiDeny(400, 'Invalid action.');
}

function setStatusPayload(): array
{
    $db   = setBackupInfo();
    $last = setLastExports();
    $fmt  = function (?array $row): ?array {
        if (!$row) return null;
        $a = is_array($row['after_data'] ?? null) ? $row['after_data'] : [];
        return [
            'at'        => $row['created_at'],
            'at_label'  => date('M j, Y · g:i A', strtotime($row['created_at'])),
            'by'        => (string)($row['username'] ?? ''),
            'format'    => (string)($a['format'] ?? ''),
            'encrypted' => !empty($a['encrypted']),
            'tables'    => isset($a['tables']) ? (int)$a['tables'] : null,
            'rows'      => isset($a['rows']) ? (int)$a['rows'] : null,
            'size'      => isset($a['bytes']) ? setBytes((int)$a['bytes']) : null,
            'sha256'    => (string)($a['sha256'] ?? ''),
        ];
    };
    $state = setBackupState($last['full'], $last['ok']);
    $info  = $db['info'] ?? [];
    return [
        'success'  => true,
        'database' => [
            'connected'      => $db['ok'],
            'ms'             => $db['ms'],
            'error'          => $db['error'],
            'installed'      => $db['installed'],
            'setup_file'     => SET_BACKUP_SQL_FILE,
            'server_version' => isset($info['server_version']) ? 'PostgreSQL ' . $info['server_version'] : null,
            'size'           => isset($info['database_size']) ? setBytes((int)$info['database_size']) : null,
            'tables'         => isset($info['tables']) ? (int)$info['tables'] : null,
            'rows_estimate'  => isset($info['rows_estimate']) ? (int)$info['rows_estimate'] : null,
        ],
        'backup'   => [
            'state'       => $state['state'],
            'label'       => $state['label'],
            'days'        => $state['days'],
            'due_days'    => SET_BACKUP_DUE_DAYS,
            'last'        => $fmt($last['full']),
            'last_schema' => $fmt($last['schema']),
            'zip_aes'     => setZipAesAvailable(),
        ],
        'checked_at' => date('g:i:s A'),
    ];
}

function setChangeOwnPassword(array $d): array
{
    $cur = is_string($d['current_password'] ?? null) ? $d['current_password'] : '';
    $new = is_string($d['new_password'] ?? null) ? $d['new_password'] : '';
    $cfm = is_string($d['confirm_password'] ?? null) ? $d['confirm_password'] : '';

    if ($fail = setCheckOwnPassword($cur, ['owner', 'admin', 'dentist'])) {
        if (($fail['code'] ?? '') === 'EMPTY') $fail['message'] = 'Please enter your current password.';
        return $fail + ['field' => 'current_password'];
    }
    if ($err = setNewPasswordError($new, $cfm, $cur)) {
        return ['success' => false, 'message' => $err, 'field' => $new !== $cfm && $new !== '' && strlen($new) >= 8 ? 'confirm_password' : 'new_password'];
    }

    $r = supabase_request('users', 'PATCH',
        ['password' => password_hash($new, PASSWORD_BCRYPT), 'updated_at' => date('c')],
        'user_id=eq.' . (int)$_SESSION['user_id']);
    if ($r['error'] || ($r['status'] ?? 0) >= 300) {
        return ['success' => false, 'message' => "Your password couldn't be saved right now. Please try again."];
    }
    session_regenerate_id(true);   // a fresh session id after a credential change
    logActivity('accounts', 'UPDATE', (int)$_SESSION['user_id'], (string)($_SESSION['full_name'] ?? ''),
        'Changed own password (Settings › Security)');
    return ['success' => true, 'message' => 'Your password was changed. Use the new password the next time you sign in.'];
}
