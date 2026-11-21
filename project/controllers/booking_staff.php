<?php
// =============================================================
//  controllers/booking_staff.php
//  Staff-only extra for the shared availability APIs
//  (api/get_timeslots.php, api/get_booked_days.php):
//  when an owner / admin edits an appointment, ?exclude=ID leaves that
//  appointment out so it doesn't block its own time. Anyone else (patients,
//  signed-out visitors, dentists, another branch's admin) gets 0 → the
//  ordinary availability, exactly as the Patient Portal sees it.
// =============================================================

require_once __DIR__ . '/../dbconfig.php';

function bookingStaffExclude(int $appointmentId): int
{
    if ($appointmentId < 1) return 0;
    if (session_status() === PHP_SESSION_NONE) session_start();
    require_once __DIR__ . '/auth_controller.php';
    $ok = !empty($_SESSION['logged_in']) && in_array($_SESSION['role'] ?? '', ['owner', 'admin'], true);
    session_write_close();   // read-only: don't hold the session lock
    if (!$ok) return 0;

    $r = supabase_request('appointments', 'GET', [], 'appointment_id=eq.' . $appointmentId . '&select=branch_id&limit=1');
    $branch = (int)($r['data'][0]['branch_id'] ?? 0);
    return ($branch > 0 && canAccessBranch($branch)) ? $appointmentId : 0;
}
