<?php
// =============================================================
//  controllers/module_access.php — per-account sidebar modules
//
//  The owner can limit an admin / dentist account to some modules
//  (Account Records → Sidebar Access). Stored in users.allowed_modules:
//    NULL            → every module the role allows (default, as before)
//    {a,b,…}         → only those modules (an empty list = none)
//
//  Enforced in TWO places, so hiding a link is never the only guard:
//    • partials/sidebar.php       — only assigned modules are listed
//    • controllers/secure_controller.php — every protected page checks
//      its module; a direct URL to an unassigned module is refused.
//  The role always caps access: a module the role can't use is never
//  granted (ES_MODULES roles mirror each page's REQUIRED_ROLES).
//  Owner accounts are never restricted.
// =============================================================

/** key => [label, icon, roles that may use it, pages that belong to it] */
const ES_MODULES = [
    'dashboard'     => ['Dashboard',            'feather-airplay',   ['admin', 'dentist'], ['dashboard.php', 'analytics.php']],
    'appointments'  => ['Appointments',         'feather-calendar',  ['admin', 'dentist'], ['appointments-records.php', 'appointments-schedule.php']],
    'treatments'    => ['Treatments',           'feather-activity',  ['admin', 'dentist'], ['treatments-records.php', 'treatments-active.php']],
    'patients'      => ['Patients',             'feather-clipboard', ['admin', 'dentist'], ['patients-list.php', 'patient-records.php', 'patient-record-print.php']],
    'billing'       => ['Billing',              'feather-file-text', ['admin'],            ['billing-records.php']],
    'inventory'     => ['Inventory',            'feather-package',   ['admin', 'dentist'], ['inventory-records.php', 'inventory-stock-movement.php', 'inventory-transfers.php', 'inventory-procurement.php']],
    'clinic_config' => ['Clinic Configuration', 'feather-tool',      ['admin'],            ['service-records.php', 'service-materials.php', 'discount-vouchers.php']],
    'audit'         => ['Audit Trail',          'feather-shield',    ['admin', 'dentist'], ['audit-records.php']],
    'notifications' => ['Notifications',        'feather-bell',      ['admin'],            ['notification-records.php']],
];

/** How long a signed-in user's module list is trusted before it is re-read (seconds). */
const ES_MODULES_TTL = 60;

/** Modules a role may be given (owner: none listed — owners are never restricted). */
function esRoleModules(string $role): array
{
    return array_keys(array_filter(ES_MODULES, fn($m) => in_array($role, $m[2], true)));
}

/** Module key for a page file name, or null when the page isn't part of a module. */
function esPageModule(string $page): ?string
{
    foreach (ES_MODULES as $key => $m) if (in_array($page, $m[3], true)) return $key;
    return null;
}

/**
 * Clean a submitted module list for a role: known keys the role may use,
 * in ES_MODULES order. null = "all modules" (no restriction stored).
 */
function esNormalizeModules($list, string $role): ?array
{
    if ($list === null || $role === 'owner') return null;
    if (!is_array($list)) return [];
    $allowed = esRoleModules($role);
    $picked  = array_values(array_intersect($allowed, array_map('strval', $list)));
    return count($picked) === count($allowed) ? null : $picked;   // every module ticked → no restriction
}

/** Parse users.allowed_modules (PostgREST returns text[] as a JSON array). */
function esParseModules($value): ?array
{
    if ($value === null) return null;
    if (is_array($value)) return array_values(array_map('strval', $value));
    if (is_string($value) && preg_match('/^\{(.*)\}$/s', $value, $m)) {   // '{a,b}' literal
        return $m[1] === '' ? [] : array_map(fn($s) => trim($s, '" '), explode(',', $m[1]));
    }
    return null;
}

/**
 * The signed-in user's module list: null = unrestricted.
 * Cached in the session and re-read every ES_MODULES_TTL seconds, so the
 * owner's changes reach an already signed-in account within a minute.
 */
function esCurrentModules(): ?array
{
    $role = $_SESSION['role'] ?? '';
    if ($role === 'owner' || empty($_SESSION['user_id'])) return null;

    $cache = $_SESSION['es_modules'] ?? null;
    if (is_array($cache) && ($cache['at'] ?? 0) > time() - ES_MODULES_TTL) return $cache['list'];

    $list = null;
    if (function_exists('supabase_request')) {
        $r = supabase_request('users', 'GET', [], 'user_id=eq.' . (int)$_SESSION['user_id'] . '&select=allowed_modules&limit=1');
        if (!$r['error'] && isset($r['data'][0])) {
            $list = esParseModules($r['data'][0]['allowed_modules'] ?? null);
        } elseif ($r['error'] && is_array($cache)) {
            $list = $cache['list'];   // database hiccup: keep the last known list (never widen access)
        }
        // Column missing (SQL not applied yet) → no restriction, exactly as before
    }
    // Always keep it for the rest of this request: pages release the session lock
    // early, and the sidebar asks once per menu item (16 lookups → 1). It is only
    // saved for later requests while the session is still open, as before.
    $_SESSION['es_modules'] = ['at' => time(), 'list' => $list];
    return $list;
}

/** May the signed-in user open this module? (role caps first, then the owner's selection) */
function esCanUseModule(string $module): bool
{
    $role = $_SESSION['role'] ?? '';
    if ($role === 'owner') return true;
    if (!in_array($role, ES_MODULES[$module][2] ?? [], true)) return false;
    $list = esCurrentModules();
    return $list === null || in_array($module, $list, true);
}

/** First page the signed-in user may open (landing page), or null when none. */
function esModuleHome(): ?string
{
    if (($_SESSION['role'] ?? '') === 'owner') return 'appointments-schedule.php';
    foreach (['appointments' => 'appointments-schedule.php', 'dashboard' => 'dashboard.php'] as $mod => $page) {
        if (esCanUseModule($mod)) return $page;
    }
    foreach (ES_MODULES as $key => $m) if (esCanUseModule($key)) return $m[3][0];
    return null;
}
