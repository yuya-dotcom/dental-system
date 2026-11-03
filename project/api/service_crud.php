<?php
// =============================================================
//  api/service_crud.php  —  services for service-records.php
//
//  GET  ?action=list                    → all services + material counts
//  POST {action:'add',    ...fields}    → owner only
//  POST {action:'edit',   service_id, ...fields}
//         owner: every field · admin: type, duration, description, status
//  POST {action:'status', service_id, status}   → owner + admin
//  POST {action:'delete', service_id}           → owner only, unused services only
//
//  Materials are handled by api/service_materials.php (unchanged).
// =============================================================
session_start();
require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/../controllers/auth_controller.php';
require_once __DIR__ . '/../controllers/service_controller.php';
if (file_exists(__DIR__ . '/../controllers/audit_controller.php')) {
    require_once __DIR__ . '/../controllers/audit_controller.php';
}

header('Content-Type: application/json');
requireLogin();

$isOwner = isOwner();
if (!$isOwner && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (($_GET['action'] ?? 'list') !== 'list') {
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        exit;
    }
    $data = getServiceRecords();
    echo json_encode(['success' => true] + $data);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':    $out = svcAdd($input, $isOwner);    break;
    case 'edit':   $out = svcEdit($input, $isOwner);   break;
    case 'status': $out = svcStatus($input);           break;
    case 'delete': $out = svcDelete($input, $isOwner); break;
    default:       $out = ['success' => false, 'message' => 'Invalid action.'];
}
echo json_encode($out);
exit;

// ─────────────────────────────────────────────────────────────

function svcLog(string $action, int $id, string $name, string $desc): void
{
    if (function_exists('logActivity')) {
        try { logActivity('service-records', $action, $id, $name, $desc); } catch (Throwable $e) { /* never block a save */ }
    }
}

/** Case-insensitive duplicate-name check (ignores $exceptId). */
function svcNameTaken(string $name, int $exceptId = 0): bool
{
    $r = supabase_request('services', 'GET', [], 'select=service_id,service_name&limit=1000');
    foreach (is_array($r['data']) ? $r['data'] : [] as $s) {
        if ((int)$s['service_id'] !== $exceptId
            && mb_strtolower(trim($s['service_name'])) === mb_strtolower($name)) {
            return true;
        }
    }
    return false;
}

/**
 * Validate the submitted fields. Only keys present in $d are checked/returned,
 * so an edit can send just what changed. Returns [fields, error].
 */
function svcFields(array $d, bool $requireAll): array
{
    $f = [];

    if ($requireAll || array_key_exists('service_name', $d)) {
        $name = preg_replace('/\s+/', ' ', trim((string)($d['service_name'] ?? '')));
        if ($name === '')                         return [null, 'Service name is required.'];
        if (mb_strlen($name) > SERVICE_NAME_MAX)  return [null, 'Service name is too long (' . SERVICE_NAME_MAX . ' characters max).'];
        $f['service_name'] = $name;
    }
    if ($requireAll || array_key_exists('service_type', $d)) {
        $type = (string)($d['service_type'] ?? '');
        if (!in_array($type, SERVICE_TYPES, true)) return [null, 'Please choose a valid service type.'];
        $f['service_type'] = $type;
    }
    if ($requireAll || array_key_exists('base_price', $d)) {
        $price = $d['base_price'] ?? null;
        if (!is_numeric($price) || (float)$price < 0 || (float)$price > 10000000) {
            return [null, 'Please enter a valid base price (0 or more).'];
        }
        $f['base_price'] = round((float)$price, 2);
    }
    if ($requireAll || array_key_exists('duration_minutes', $d)) {
        if (!isValidServiceDuration($d['duration_minutes'] ?? null)) {
            return [null, 'Duration must be between 15 minutes and 8 hours, in 15-minute steps.'];
        }
        $f['duration_minutes'] = (int)$d['duration_minutes'];
    }
    if (array_key_exists('description', $d)) {
        $desc = trim((string)($d['description'] ?? ''));
        if (mb_strlen($desc) > SERVICE_DESC_MAX) return [null, 'Description is too long (' . SERVICE_DESC_MAX . ' characters max).'];
        $f['description'] = $desc === '' ? null : $desc;
    }
    // Optional dental-chart outcome suggested when this service is completed
    // (the dentist still confirms it before the chart changes). '' = none.
    if (array_key_exists('chart_outcome', $d)) {
        $oc = (string)($d['chart_outcome'] ?? '');
        if ($oc !== '' && !in_array($oc, ['healthy', 'filled', 'decay', 'crown', 'impacted', 'impacted_ne', 'missing'], true)) {
            return [null, 'Invalid dental chart outcome.'];
        }
        $f['chart_outcome'] = $oc === '' ? null : $oc;
    }
    if (array_key_exists('status', $d)) {
        if (!in_array($d['status'], ['active', 'inactive'], true)) return [null, 'Invalid status.'];
        $f['status'] = $d['status'];
    }
    return [$f, null];
}

function svcAdd(array $d, bool $isOwner): array
{
    if (!$isOwner) return ['success' => false, 'message' => 'Only the owner can add services.'];

    [$f, $err] = svcFields($d, true);
    if ($err) return ['success' => false, 'message' => $err];
    if (svcNameTaken($f['service_name'])) {
        return ['success' => false, 'message' => 'A service with this name already exists.'];
    }
    $f['status'] = 'active';

    $r = supabase_request('services', 'POST', $f);
    $id = (int)($r['data'][0]['service_id'] ?? 0);
    if ($r['error'] || !$id) {
        return ['success' => false, 'message' => svcDbError($r['error'], 'Could not add the service.')];
    }
    svcLog('INSERT', $id, $f['service_name'], 'Service added');

    $row = getServiceById($id) ?? normalizeServiceRow($r['data'][0]);
    return ['success' => true, 'message' => 'Service added.', 'service' => $row + ['material_count' => 0]];
}

function svcEdit(array $d, bool $isOwner): array
{
    $id = (int)($d['service_id'] ?? 0);
    $current = getServiceById($id);
    if (!$current) return ['success' => false, 'message' => 'Service not found.'];

    [$f, $err] = svcFields($d, false);
    if ($err) return ['success' => false, 'message' => $err];

    // Admins may not change the name or the price
    if (!$isOwner) {
        if (isset($f['service_name']) && $f['service_name'] !== $current['service_name']) {
            return ['success' => false, 'message' => 'Only the owner can change the service name.'];
        }
        if (isset($f['base_price']) && abs($f['base_price'] - $current['base_price']) > 0.001) {
            return ['success' => false, 'message' => 'Only the owner can change the price.'];
        }
        unset($f['service_name'], $f['base_price']);
    }

    if (isset($f['service_name']) && $f['service_name'] !== $current['service_name']
        && svcNameTaken($f['service_name'], $id)) {
        return ['success' => false, 'message' => 'A service with this name already exists.'];
    }
    if (!$f) return ['success' => true, 'message' => 'No changes to save.', 'service' => $current];

    $r = supabase_request('services', 'PATCH', $f, 'service_id=eq.' . $id);
    if ($r['error']) return ['success' => false, 'message' => svcDbError($r['error'], 'Could not save the service.')];

    $changes = [];
    foreach ($f as $k => $v) {
        $labels = ['service_name' => 'name', 'service_type' => 'type', 'base_price' => 'price',
                   'duration_minutes' => 'duration', 'description' => 'description', 'status' => 'status',
                   'chart_outcome' => 'dental chart outcome'];
        if (($current[$k] ?? null) != $v) $changes[] = $labels[$k] ?? $k;
    }
    svcLog('UPDATE', $id, $f['service_name'] ?? $current['service_name'],
        $changes ? 'Updated ' . implode(', ', $changes) : 'Service saved');

    return ['success' => true, 'message' => 'Service saved.', 'service' => getServiceById($id) ?? array_merge($current, $f)];
}

function svcStatus(array $d): array
{
    $id     = (int)($d['service_id'] ?? 0);
    $status = $d['status'] ?? '';
    if (!in_array($status, ['active', 'inactive'], true)) return ['success' => false, 'message' => 'Invalid status.'];

    $current = getServiceById($id);
    if (!$current) return ['success' => false, 'message' => 'Service not found.'];

    $r = supabase_request('services', 'PATCH', ['status' => $status], 'service_id=eq.' . $id);
    if ($r['error']) return ['success' => false, 'message' => svcDbError($r['error'], 'Could not update the status.')];

    svcLog('UPDATE', $id, $current['service_name'], 'Status changed to ' . ucfirst($status));
    return ['success' => true, 'message' => $status === 'active' ? 'Service activated.' : 'Service deactivated.',
            'service' => array_merge($current, ['status' => $status])];
}

function svcDelete(array $d, bool $isOwner): array
{
    if (!$isOwner) return ['success' => false, 'message' => 'Only the owner can delete services.'];

    $id = (int)($d['service_id'] ?? 0);
    $current = getServiceById($id);
    if (!$current) return ['success' => false, 'message' => 'Service not found.'];

    if (isServiceInUse($id)) {
        return ['success' => false, 'in_use' => true,
                'message' => 'This service is used by appointments or treatments, so it can\'t be deleted. Deactivate it instead.'];
    }

    // Its material list goes with it
    supabase_request('service_materials', 'DELETE', [], 'service_id=eq.' . $id);

    $r = supabase_request('services', 'DELETE', [], 'service_id=eq.' . $id);
    if ($r['error']) return ['success' => false, 'message' => svcDbError($r['error'], 'Could not delete the service.')];

    svcLog('DELETE', $id, $current['service_name'], 'Service deleted');
    return ['success' => true, 'message' => 'Service deleted.'];
}

/** Friendly text for common database errors. */
function svcDbError(mixed $error, string $fallback): string
{
    $msg = is_string($error) ? $error : json_encode($error);
    if (stripos($msg, 'duration_minutes') !== false) {
        return 'The duration column is missing. Please run the service-durations SQL file in Supabase first.';
    }
    if (stripos($msg, 'foreign key') !== false) {
        return 'This service is still linked to other records. Deactivate it instead.';
    }
    return $fallback;
}