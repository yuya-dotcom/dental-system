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
function getTreatmentRecords(int $page, int $branchId, string $status): array
{
    $offset = ($page - 1) * TRT_PER_PAGE;

    $q = [
        'select=treatment_id,treatment_code,treatment_date,status,current_stage,'
            . 'tooth_number,cost,procedure_notes,appointment_id,patient_id,'
            . 'dentist_id,branch_id,service_id,'
            . 'patients(full_name),branches(branch_name),'
            . 'dentists(full_name),services(service_name)',
        'order=treatment_code.asc',
        'limit='  . TRT_PER_PAGE,
        'offset=' . $offset,
    ];

    if ($branchId > 0) $q[] = 'branch_id=eq.' . $branchId;

    // Treatment Records only shows completed/cancelled
    // If a specific status filter is passed, use it — otherwise default to both
    if ($status) {
        $q[] = 'status=eq.' . urlencode($status);
    } else {
        $q[] = 'status=in.(completed,cancelled)';
    }

    $result = supabase_request('treatments', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'treatments', $q);

    return buildPageResult($rows, $total, $page, $offset, TRT_PER_PAGE);
}

/**
 * Active treatments — pending/ongoing/in_progress treatments
 * whose linked appointment is checked_in (or has no appointment).
 * Used by: treatments-active.php
 */
function getActiveTreatments(int $page, int $branchId): array
{
    $offset = ($page - 1) * TRT_PER_PAGE;

    // Step 1: Get all checked_in appointment IDs for this branch
    $apptQ = ['select=appointment_id', 'status=eq.checked_in', 'limit=500'];
    if ($branchId > 0) $apptQ[] = 'branch_id=eq.' . $branchId;
    $apptRes      = supabase_request('appointments', 'GET', [], implode('&', $apptQ));
    $checkedInIds = array_column(is_array($apptRes['data']) ? $apptRes['data'] : [], 'appointment_id');

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
            . 'dentists(full_name),services(service_name)',
        'appointment_id=in.(' . $idList . ')',
        'status=in.(pending,ongoing,in_progress)',
        'order=treatment_date.asc',
        'limit='  . TRT_PER_PAGE,
        'offset=' . $offset,
    ];

    $result = supabase_request('treatments', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'treatments', $q);

    return buildPageResult($rows, $total, $page, $offset, TRT_PER_PAGE);
}

// ─────────────────────────────────────────────────────────────
//  INVOICE QUERIES
// ─────────────────────────────────────────────────────────────

/**
 * All invoices ordered by invoice_code.
 * Used by: sales-records.php
 */
function getInvoiceRecords(int $page, int $branchId, string $paymentStatus): array
{
    $offset = ($page - 1) * INV_PER_PAGE;

    $q = [
        'select=invoice_id,invoice_code,invoice_date,total_amount,amount_paid,'
            . 'balance,payment_status,patient_id,branch_id,'
            . 'patients(full_name),branches(branch_name),treatments(treatment_code)',
        'order=invoice_code.asc',
        'limit='  . INV_PER_PAGE,
        'offset=' . $offset,
    ];

    if ($branchId > 0)     $q[] = 'branch_id=eq.'      . $branchId;
    if ($paymentStatus)    $q[] = 'payment_status=eq.'  . urlencode($paymentStatus);

    $result = supabase_request('invoices', 'GET', [], implode('&', $q), ['Prefer: count=exact']);
    $rows   = is_array($result['data']) ? $result['data'] : [];
    $total  = resolveCount($result, 'invoices', $q);

    return buildPageResult($rows, $total, $page, $offset, INV_PER_PAGE);
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