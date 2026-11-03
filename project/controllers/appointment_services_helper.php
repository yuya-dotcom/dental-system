<?php
// =============================================================
//  controllers/appointment_services_helper.php
//  An appointment can have 1–3 services (appointment_services
//  junction table). The legacy appointments.service_id column only
//  holds the first one, so pages that join services(service_name)
//  would show a single service. Use this to attach every service.
//
//  Requires dbconfig.php (supabase_request).
// =============================================================

if (!function_exists('attachAppointmentServiceNames')) {

/**
 * Adds 'all_service_names' (string[], in booking order) to each row.
 * Falls back to the joined services.service_name for older appointments
 * that have no junction rows. One request for the whole list.
 */
function attachAppointmentServiceNames(array $rows): array
{
    $ids = array_values(array_unique(array_filter(array_map(
        fn($r) => (int)($r['appointment_id'] ?? 0), $rows))));

    $map = [];
    if ($ids) {
        $res = supabase_request('appointment_services', 'GET', [],
            'appointment_id=in.(' . implode(',', $ids) . ')' .
            '&select=appointment_id,sort_order,services(service_name)' .
            '&order=appointment_id.asc,sort_order.asc');
        foreach ((is_array($res['data'] ?? null) ? $res['data'] : []) as $row) {
            $name = $row['services']['service_name'] ?? null;
            if ($name) $map[(int)$row['appointment_id']][] = $name;
        }
    }

    foreach ($rows as &$r) {
        $names = $map[(int)($r['appointment_id'] ?? 0)] ?? [];
        if (!$names && !empty($r['services']['service_name'])) $names = [$r['services']['service_name']];
        $r['all_service_names'] = $names;
    }
    unset($r);
    return $rows;
}

/** "Cleaning, Extraction" — or $empty when no service is recorded. */
function appointmentServicesLabel(array $row, string $empty = '—'): string
{
    $names = $row['all_service_names'] ?? [];
    if (!$names && !empty($row['services']['service_name'])) $names = [$row['services']['service_name']];
    return $names ? implode(', ', $names) : $empty;
}

}
