<?php
// =============================================================
//  controllers/appointment_edit_grant.php
//  Permission to edit one appointment after the staff member re-entered
//  their password (api/verify_staff_password.php), kept in the server
//  session only. Checked by api/appointments.php (PUT).
// =============================================================

const APT_EDIT_GRANT_SECONDS = 900;     // 15 minutes to finish the edit
const APT_EDIT_MAX_FAILS     = 5;       // wrong passwords in a row …
const APT_EDIT_LOCK_SECONDS  = 300;     // … then wait 5 minutes

function aptEditGrant(int $appointmentId): void
{
    $grants = is_array($_SESSION['apt_edit_grants'] ?? null) ? $_SESSION['apt_edit_grants'] : [];
    foreach ($grants as $id => $until) if ($until < time()) unset($grants[$id]);   // tidy expired
    $grants[$appointmentId] = time() + APT_EDIT_GRANT_SECONDS;
    $_SESSION['apt_edit_grants'] = $grants;
    unset($_SESSION['apt_edit_fails']);
}

function aptEditHasGrant(int $appointmentId): bool
{
    $until = (int)($_SESSION['apt_edit_grants'][$appointmentId] ?? 0);
    return $until >= time();
}

/** Used up once the edit is saved — the next edit asks again. */
function aptEditConsume(int $appointmentId): void
{
    unset($_SESSION['apt_edit_grants'][$appointmentId]);
}

/** Records a wrong password; returns attempts left before the wait (0 = now locked). */
function aptEditRecordFailure(): int
{
    $f = is_array($_SESSION['apt_edit_fails'] ?? null) ? $_SESSION['apt_edit_fails'] : ['n' => 0, 'until' => 0];
    $f['n'] = (int)$f['n'] + 1;
    if ($f['n'] >= APT_EDIT_MAX_FAILS) {
        $f = ['n' => 0, 'until' => time() + APT_EDIT_LOCK_SECONDS];
        $_SESSION['apt_edit_fails'] = $f;
        return 0;
    }
    $_SESSION['apt_edit_fails'] = $f;
    return APT_EDIT_MAX_FAILS - $f['n'];
}

function aptEditLockSeconds(): int
{
    return max(0, (int)($_SESSION['apt_edit_fails']['until'] ?? 0) - time());
}

function aptEditWaitLabel(int $seconds): string
{
    $m = (int)ceil($seconds / 60);
    return $m <= 1 ? 'a minute' : $m . ' minutes';
}
