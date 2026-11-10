<?php
// =============================================================
//  controllers/service_controller.php
//  Business Process Controller — Services
//
//  Covers:
//    - service-records.php   (services list, details, materials)
//    - api/service_crud.php  (add / edit / status / delete)
//
//  Zero HTML. Zero $_GET/$_POST reading.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

/** Allowed values of services.service_type (matches the database check). */
const SERVICE_TYPES = ['Procedure', 'Consultation', 'Cosmetic', 'Orthodontic', 'Other'];

/** services.duration_minutes limits (matches the database check). */
const SERVICE_DURATION_MIN     = 15;
const SERVICE_DURATION_MAX     = 480;
const SERVICE_DURATION_STEP    = 15;
const SERVICE_DURATION_DEFAULT = 30;

const SERVICE_NAME_MAX = 120;
const SERVICE_DESC_MAX = 500;

// ─────────────────────────────────────────────────────────────
//  QUERIES
// ─────────────────────────────────────────────────────────────

/**
 * Every service (active and inactive) with its number of materials.
 * Returns ['services' => [...], 'needs_migration' => bool]
 *   needs_migration = true when services.duration_minutes does not exist yet
 *   (the SQL file has not been run); durations then show the 30-minute default.
 */
// Queries of getServiceRecords() / getMaterialInventoryItems(), shared with the page prefetch
const SERVICE_RECORDS_QUERY         = 'select=service_id,service_name,service_type,base_price,description,status,created_at,duration_minutes,chart_outcome&order=service_name.asc&limit=1000';
const SERVICE_MATERIAL_COUNTS_QUERY = 'select=service_id&limit=10000';
const MATERIAL_ITEMS_QUERY          = 'select=item_id,item_code,item_name,category,stock_quantity&status=neq.inactive&archived_at=is.null&order=item_name.asc';

function getServiceRecords(): array
{
    $cols  = 'service_id,service_name,service_type,base_price,description,status,created_at';
    $order = '&order=service_name.asc&limit=1000';

    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $res = (function_exists('admin_get') ? admin_get('services', SERVICE_RECORDS_QUERY, []) : supabase_request('services', 'GET', [], SERVICE_RECORDS_QUERY, []));
    $needsMigration = false;
    if ($res['error']) {
        // Most likely the duration column is missing — load without it
        $res = supabase_request('services', 'GET', [], 'select=' . $cols . $order);
        $needsMigration = !$res['error'];
    }
    $rows = is_array($res['data']) ? $res['data'] : [];

    // Material counts in one request, tallied here
    $counts = [];
    $mat = (function_exists('admin_get') ? admin_get('service_materials', SERVICE_MATERIAL_COUNTS_QUERY, []) : supabase_request('service_materials', 'GET', [], SERVICE_MATERIAL_COUNTS_QUERY, []));
    foreach (is_array($mat['data']) ? $mat['data'] : [] as $m) {
        $sid = (int)($m['service_id'] ?? 0);
        $counts[$sid] = ($counts[$sid] ?? 0) + 1;
    }

    $services = array_map(function ($s) use ($counts) {
        return normalizeServiceRow($s) + ['material_count' => $counts[(int)$s['service_id']] ?? 0];
    }, $rows);

    return ['services' => $services, 'needs_migration' => $needsMigration];
}

/** One service by ID, or null. */
function getServiceById(int $serviceId): ?array
{
    if ($serviceId < 1) return null;
    $res = supabase_request('services', 'GET', [],
        'service_id=eq.' . $serviceId
        . '&select=service_id,service_name,service_type,base_price,duration_minutes,chart_outcome,description,status,created_at&limit=1'
    );
    if ($res['error']) {
        $res = supabase_request('services', 'GET', [],
            'service_id=eq.' . $serviceId
            . '&select=service_id,service_name,service_type,base_price,description,status,created_at&limit=1'
        );
    }
    $row = $res['data'][0] ?? null;
    return $row ? normalizeServiceRow($row) : null;
}

/** Inventory items for the Add Material dropdown. */
function getMaterialInventoryItems(): array
{
    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $res = (function_exists('admin_get') ? admin_get('inventory', MATERIAL_ITEMS_QUERY, []) : supabase_request('inventory', 'GET', [], MATERIAL_ITEMS_QUERY, []));
    return is_array($res['data']) ? $res['data'] : [];
}

/**
 * Whether a service is referenced by appointments or treatments.
 * Used to stop deleting a service that old records still point to.
 */
function isServiceInUse(int $serviceId): bool
{
    $checks = [
        ['appointments',         'appointment_id'],
        ['appointment_services', 'id'],
        ['treatments',           'treatment_id'],
    ];
    foreach ($checks as [$table, $idCol]) {
        $r = supabase_request($table, 'GET', [], 'service_id=eq.' . $serviceId . '&select=' . $idCol . '&limit=1');
        if ($r['error'])        return true;   // can't confirm — be safe
        if (!empty($r['data'])) return true;
    }
    return false;
}

// ─────────────────────────────────────────────────────────────
//  VALIDATION + FORMAT HELPERS
// ─────────────────────────────────────────────────────────────

/** Consistent types for a service row coming from Supabase. */
function normalizeServiceRow(array $s): array
{
    return [
        'service_id'       => (int)$s['service_id'],
        'service_name'     => (string)($s['service_name'] ?? ''),
        'service_type'     => (string)($s['service_type'] ?? 'Other'),
        'base_price'       => round((float)($s['base_price'] ?? 0), 2),
        'duration_minutes' => (int)($s['duration_minutes'] ?? SERVICE_DURATION_DEFAULT),
        'description'      => (string)($s['description'] ?? ''),
        'chart_outcome'    => $s['chart_outcome'] ?? null,
        'status'           => ($s['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
        'created_at'       => $s['created_at'] ?? null,
    ];
}

function isValidServiceDuration(mixed $minutes): bool
{
    $m = filter_var($minutes, FILTER_VALIDATE_INT);
    return $m !== false
        && $m >= SERVICE_DURATION_MIN && $m <= SERVICE_DURATION_MAX
        && $m % SERVICE_DURATION_STEP === 0;
}

/** Every allowed duration, for the Duration dropdown. */
function serviceDurationOptions(): array
{
    return range(SERVICE_DURATION_MIN, SERVICE_DURATION_MAX, SERVICE_DURATION_STEP);
}

/** 30 → "30 min", 60 → "1 hr", 90 → "1 hr 30 min" */
function formatServiceDuration(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h && $m) return $h . ' hr ' . $m . ' min';
    if ($h)       return $h . ' hr';
    return $m . ' min';
}