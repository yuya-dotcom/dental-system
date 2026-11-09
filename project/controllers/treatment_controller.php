<?php
// =============================================================
//  controllers/treatment_controller.php
//  Business Process Controller — Treatments & Invoices
//
//  Covers:
//    - treatments-records.php  (all treatments, paginated)
//    - treatments-active.php   (active/ongoing only, paginated)
//    - sales-records.php       (invoices, paginated)
//
//  Zero HTML. Zero $_GET reading.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';
require_once __DIR__ . '/appointment_controller.php'; // for resolveCount() + buildPageResult()

const TRT_PER_PAGE = 10;
const INV_PER_PAGE = 10;

// ─────────────────────────────────────────────────────────────
//  TREATMENT QUERIES
// ─────────────────────────────────────────────────────────────

/**
 * All treatments ordered by treatment_code.
 * Used by: treatments-records.php
 */
// $order: validated 'order=…' clause; $extra: prebuilt PostgREST filters (list_filters.php)
function getTreatmentRecords(int $page, int $branchId, string $status, string $order = 'order=treatment_code.asc', array $extra = []): array
{
    $offset = ($page - 1) * TRT_PER_PAGE;
    $q = treatmentRecordsQuery($page, $branchId, $status, $order, $extra);

    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $result = (function_exists('admin_get') ? admin_get('treatments', implode('&', $q), ['Prefer: count=exact']) : supabase_request('treatments', 'GET', [], implode('&', $q), ['Prefer: count=exact']));
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'treatments', $q);

    // Build appointment_id => appointment_code for the rows on this page
    // (one batched request), then merge it into each row.
    $apptCodeMap = [];
    $apptIds = array_values(array_unique(array_filter(
        array_map(fn($r) => (int)($r['appointment_id'] ?? 0), $rows)
    )));
    if ($apptIds) {
        $apptRes = supabase_request(
            'appointments',
            'GET',
            [],
            'appointment_id=in.(' . implode(',', $apptIds) . ')&select=appointment_id,appointment_code'
        );
        foreach (($apptRes['data'] ?? []) as $a) {
            $apptCodeMap[(int)$a['appointment_id']] = $a['appointment_code'] ?? '—';
        }
    }

    foreach ($rows as &$row) {
        $aid = isset($row['appointment_id']) ? (int)$row['appointment_id'] : null;
        $row['appointment_code'] = $aid ? ($apptCodeMap[$aid] ?? '—') : '—';
    }
    unset($row);

    return buildPageResult($rows, $total, $page, $offset, TRT_PER_PAGE);
}

/** The PostgREST filter parts for getTreatmentRecords() (also used to prefetch it). */
function treatmentRecordsQuery(int $page, int $branchId, string $status, string $order = 'order=treatment_code.asc', array $extra = []): array
{
    $offset = ($page - 1) * TRT_PER_PAGE;

    $q = [
        'select=treatment_id,treatment_code,treatment_date,status,current_stage,'
            . 'tooth_number,cost,procedure_notes,appointment_id,patient_id,'
            . 'dentist_id,branch_id,service_id,'
            . 'patients(full_name),branches(branch_name),'
            . 'dentists(full_name),services(service_name,requires_tooth_selection),'
            . 'appointments!treatments_appointment_id_fkey(appointment_code,appointment_time),'
            . 'treatment_procedures(tooth_number,surfaces,unit_price,status,services(service_name))',
        $order,
        'limit='  . TRT_PER_PAGE,
        'offset=' . $offset,
        ...$extra,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;

    // Treatment Records only shows completed/cancelled
    // If a specific status filter is passed, use it — otherwise default to both
    if ($status) {
        $q[] = 'status=eq.' . urlencode($status);
    } else {
        $q[] = 'status=in.(completed,cancelled)';
    }

    return $q;
}

/**
 * Active treatments — pending/ongoing/in_progress treatments
 * whose linked appointment is checked_in (or has no appointment).
 * Used by: treatments-active.php
 */
// $order: validated 'order=…' clause; $extra: prebuilt PostgREST filters (list_filters.php)
function getActiveTreatments(int $page, int $branchId, string $order = 'order=treatment_date.asc', array $extra = []): array
{
    $offset = ($page - 1) * TRT_PER_PAGE;

    // Step 1: Get all checked_in appointment IDs for this branch
    $apptQ   = activeTreatmentsApptQuery($branchId);
    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $apptRes      = (function_exists('admin_get') ? admin_get('appointments', implode('&', $apptQ), []) : supabase_request('appointments', 'GET', [], implode('&', $apptQ), []));
    $apptData     = is_array($apptRes['data']) ? $apptRes['data'] : [];
    $checkedInIds = array_column($apptData, 'appointment_id');
    $apptCodeMap  = [];
    foreach ($apptData as $a) {
        $apptCodeMap[(int)$a['appointment_id']] = $a['appointment_code'] ?? '—';
    }
    if (empty($checkedInIds)) {
        return buildPageResult([], 0, $page, $offset, TRT_PER_PAGE);
    }

    // Step 2: Fetch treatments linked to those checked_in appointments
    $idList = implode(',', array_map('intval', $checkedInIds));
    $q = [
        'select=treatment_id,treatment_code,treatment_date,status,current_stage,'
            . 'tooth_number,cost,procedure_notes,appointment_id,patient_id,'
            . 'dentist_id,branch_id,service_id,'
            . 'patients(full_name),branches(branch_name),'
            . 'dentists(full_name),services(service_name,requires_tooth_selection)',
        'appointment_id=in.(' . $idList . ')',
        'status=in.(pending,ongoing,in_progress)',
        $order,
        'limit='  . TRT_PER_PAGE,
        'offset=' . $offset,
        ...$extra,
    ];

    $result = supabase_request('treatments', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'treatments', $q);

    foreach ($rows as &$row) {
        $aid = isset($row['appointment_id']) ? (int)$row['appointment_id'] : null;
        $row['appointment_code'] = $aid ? ($apptCodeMap[$aid] ?? '—') : '—';
    }
    unset($row);

    return buildPageResult($rows, $total, $page, $offset, TRT_PER_PAGE);
}

/** Step 1 of getActiveTreatments(): the checked-in appointments (also used to prefetch it). */
function activeTreatmentsApptQuery(int $branchId): array
{
    $apptQ = ['select=appointment_id,appointment_code', 'status=eq.checked_in', 'limit=500'];
    if ($branchId > 0) $apptQ[] = 'branch_id=eq.' . $branchId;
    return $apptQ;
}

// ─────────────────────────────────────────────────────────────
//  INVOICE QUERIES
// ─────────────────────────────────────────────────────────────

/**
 * All invoices ordered by invoice_code.
 * Used by: sales-records.php
 */
// $order: validated 'order=…' clause; $extra: prebuilt PostgREST filters (list_filters.php)
function getInvoiceRecords(int $page, int $branchId, string $paymentStatus, string $order = 'order=invoice_code.asc', array $extra = []): array
{
    $offset = ($page - 1) * INV_PER_PAGE;
    $q = invoiceRecordsQuery($page, $branchId, $paymentStatus, $order, $extra);

    // admin_get(): reuses the page's parallel prefetch (controllers/admin_batch.php) when present
    $result = (function_exists('admin_get') ? admin_get('invoices', implode('&', $q), ['Prefer: count=exact']) : supabase_request('invoices', 'GET', [], implode('&', $q), ['Prefer: count=exact']));
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'invoices', $q);

    return buildPageResult($rows, $total, $page, $offset, INV_PER_PAGE);
}

/** The PostgREST filter parts for getInvoiceRecords() (also used to prefetch it). */
function invoiceRecordsQuery(int $page, int $branchId, string $paymentStatus, string $order = 'order=invoice_code.asc', array $extra = []): array
{
    $offset = ($page - 1) * INV_PER_PAGE;

    $q = [
        'select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,'
            . 'balance,payment_status,patient_id,branch_id,appointment_id,created_at,'
            . 'patients(full_name,patient_code),branches(branch_name),treatments(treatment_code)',
        $order,
        'limit='  . INV_PER_PAGE,
        'offset=' . $offset,
        ...$extra,
    ];

    if ($branchId !== 0)   $q[] = 'branch_id=eq.'      . $branchId;   // -1 (no scope) matches nothing
    if ($paymentStatus)    $q[] = 'payment_status=eq.'  . urlencode($paymentStatus);

    return $q;
}

// ─────────────────────────────────────────────────────────────
//  BADGE & FORMAT HELPERS
// ─────────────────────────────────────────────────────────────

function trtStatusBadge(?string $status): string
{
    return match (strtolower($status ?? '')) {
        'completed'   => 'bg-soft-success text-success',
        'ongoing',
        'in_progress' => 'bg-soft-primary text-primary',
        'cancelled'   => 'bg-soft-danger text-danger',
        'pending'     => 'bg-soft-warning text-warning',
        default       => 'bg-soft-secondary text-secondary',
    };
}

function trtStatusLabel(?string $status): string
{
    return match (strtolower($status ?? '')) {
        'in_progress' => 'In Progress',
        default       => ucfirst($status ?? '—'),
    };
}

function invPaymentBadge(?string $status): string
{
    return match (strtolower($status ?? 'unpaid')) {
        'paid'    => 'bg-soft-success text-success',
        'partial' => 'bg-soft-warning text-warning',
        default   => 'bg-soft-danger text-danger',
    };
}

function formatCost(?float $amount): string
{
    if ($amount === null) return '—';
    return '₱' . number_format($amount, 2);
}

function formatTrtDate(?string $date): string
{
    return empty($date) ? '—' : date('M. d, Y', strtotime($date));
}