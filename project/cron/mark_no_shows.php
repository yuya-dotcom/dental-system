<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

// =============================================================
//  cron/mark_no_shows.php
//  Marks ended, never-checked-in appointments as NO SHOW — the same rule the
//  staff pages, staff APIs and Patient Portal run on every request
//  (controllers/appointment_lifecycle.php → aptSweepNoShows()). Running it
//  on a schedule (e.g. every 15 minutes via cron/run_no_shows.bat) keeps the
//  statuses current even when nobody opens the system.
//
//  php mark_no_shows.php            mark them
//  php mark_no_shows.php --dry-run  only list what would be marked
// =============================================================

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../controllers/appointment_lifecycle.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);
foreach (array_slice($argv, 1) as $arg) {
    if ($arg !== '--dry-run') {
        fwrite(STDERR, "Unknown argument: {$arg}\nUsage: php mark_no_shows.php [--dry-run]\n");
        exit(2);
    }
}

$ids = aptSweepNoShows(true, $dryRun);
$now = (new DateTimeImmutable('now', bookingTz()))->format('Y-m-d H:i');
echo "[$now] " . ($dryRun ? 'Would mark' : 'Marked') . ' ' . count($ids) . ' appointment(s) as no_show'
    . ($ids ? ': #' . implode(', #', $ids) : '') . PHP_EOL;
