<?php
// =============================================================
//  controllers/record_ui.php — table cell markup in the Appointment Records
//  design (appointments-records.php), shared by every records page.
//  Styles: assets/css/analytics.css (tokens, .an-pill, .an-dot, .t-*) and
//  assets/css/appointments-records.css (.ar-page .ar-table, .ar-who, .ar-when …).
//  Display only: nothing here reads or changes data.
// =============================================================
if (!function_exists('aptInitials')) {

/** Initials for the patient avatar ("Juan Dela Cruz" → "JC"). */
function aptInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, fn($p) => $p !== '' && $p !== '—'));
    if (!$parts) return '?';
    $first = mb_substr($parts[0], 0, 1, 'UTF-8');
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';
    return mb_strtoupper($first . $last, 'UTF-8');
}

/** Stable avatar tint per patient. */
function aptAvatarTone(int $patientId): string
{
    return ['blue', 'teal', 'violet', 'orange', 'amber', 'slate'][$patientId % 6];
}

/** Same clinic colours as Analytics / Dashboard: by branch_id order. */
function aptBranchColor(string $branchName, array $branches): string
{
    $ids = array_map(fn($b) => (int)$b['branch_id'], $branches);
    sort($ids);
    foreach ($branches as $b) {
        if ((string)$b['branch_name'] === $branchName) return '--an-c' . ((array_search((int)$b['branch_id'], $ids, true) % 3) + 1);
    }
    return '--an-other';
}

// Display-only name cleanup: "JuAN  DeLa cruz" → "Juan Dela Cruz".
// Capitalises after spaces, hyphens and apostrophes (Mary-Ann, O'Brien).
function aptNameCase(?string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    if ($name === '') return '—';
    return preg_replace_callback(
        "/(^|[\\s\\-'’])(\\p{L})/u",
        fn($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
        mb_strtolower($name, 'UTF-8')
    );
}

/** Appointment status → pill tone (.an-pill tones). */
function aptStatusPillClass(string $s): string
{
    return match ($s) {
        'pending'    => 'warn',
        'confirmed'  => 'info',
        'checked_in' => 'violet',
        'completed'  => 'up',
        'cancelled'  => 'down',
        default      => 'slate',
    };
}

}

if (!function_exists('recWho')) {

/** Patient cell: tinted initials avatar, name (link when $href), optional code line. */
function recWho(?string $name, int $id, string $href = '', string $code = ''): string
{
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $n = aptNameCase($name);
    $nameHtml = $href !== ''
        ? '<a href="' . $e($href) . '" class="ar-who-name">' . $e($n) . '</a>'
        : '<span class="ar-who-name">' . $e($n) . '</span>';
    return '<div class="ar-who"><span class="ar-avatar t-' . aptAvatarTone($id) . '" aria-hidden="true">' . $e(aptInitials($n)) . '</span>'
         . '<div class="min-w-0">' . $nameHtml
         . (trim($code) !== '' ? '<div class="ar-who-code" title="Patient number">' . $e(trim($code)) . '</div>' : '')
         . '</div></div>';
}

/** Date cell: date on top, clock + time (or $sub) underneath. $date is already formatted. */
function recWhen(string $date, string $time = '', bool $today = false, string $icon = 'feather-clock'): string
{
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    return '<div class="ar-when"><div class="min-w-0"><div class="ar-when-date">' . $e($date)
         . ($today ? ' <span class="an-pill info ms-1">Today</span>' : '') . '</div>'
         . ($time !== '' ? '<div class="ar-when-time"><i class="' . $e($icon) . '"></i>' . $e($time) . '</div>' : '')
         . '</div></div>';
}

/** Branch cell: clinic colour dot + name ('—' when empty). */
function recBranch(?string $name, array $branches = []): string
{
    $name = trim((string)$name);
    if ($name === '') return '<span class="ar-branch text-muted">—</span>';
    return '<span class="ar-branch"><span class="an-dot" style="background:var(' . aptBranchColor($name, $branches) . ')"></span>'
         . htmlspecialchars($name, ENT_QUOTES) . '</span>';
}

/** Status pill with a dot. $tone: up | down | warn | info | violet | slate. */
function recPill(string $label, string $tone): string
{
    return '<span class="an-pill ar-status ' . htmlspecialchars($tone, ENT_QUOTES) . '"><span class="ar-sdot"></span>'
         . htmlspecialchars($label, ENT_QUOTES) . '</span>';
}

/** Bootstrap soft-badge classes ("bg-soft-success text-success") → the matching pill tone. */
function recToneFromBadge(string $badge): string
{
    foreach (['success' => 'up', 'danger' => 'down', 'warning' => 'warn', 'primary' => 'info', 'info' => 'info', 'purple' => 'violet', 'indigo' => 'violet'] as $k => $tone) {
        if (str_contains($badge, $k)) return $tone;
    }
    return 'slate';
}

/** Card-title count pill: "12 total" / "3 found". */
function recCountPill(int $n, bool $filtered = false): string
{
    return '<span class="an-pill info ms-2">' . number_format($n) . ' ' . ($filtered ? 'found' : 'total') . '</span>';
}

}
