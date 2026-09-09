<?php
/* The command-line entry point for the daily reminder job.
 *
 *   php tools/cron-reminders.php [--dry-run] [--today=YYYY-MM-DD]
 *
 * Run once a day, early morning in the configured timezone.
 *
 * NOT ONE LINE OF REMINDER LOGIC LIVES HERE. All of it is lib/cron.php's,
 * where public/cron.php — the wrapper for plans that only offer a URL fetch —
 * calls the identical function. This file is argument parsing, the one
 * movies_today(), and the exit code.
 *
 * --today exists for testing and for the morning you need to re-run yesterday.
 * --dry-run reports what would be sent and writes nothing, which is the safe
 * way to look at a new deploy before pointing a scheduler at it. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    /* tools/.htaccess already denies this over HTTP. This is the second lock,
     * needing no Apache modules: a job that sends email must not be runnable by
     * anyone who finds the path if that deny is ever mis-deployed. */
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/cron.php';

$dryRun = in_array('--dry-run', $argv, true);

/* ONE movies_today(), here, passed down. See lib/dates.php. */
$today = movies_today();
foreach ($argv as $arg) {
    if (preg_match('/^--today=(\d{4}-\d{2}-\d{2})$/', (string) $arg, $m)) {
        $today = $m[1];
    }
}

$tally = cron_reminders_run($today, $dryRun);

fwrite(STDOUT, cron_reminders_summary($tally, $dryRun) . PHP_EOL);

foreach ($tally['errors'] as $error) {
    fwrite(STDERR, '  ' . $error . PHP_EOL);
}

/* Non-zero so a monitored cron surfaces it. Nothing else in this app has a way
 * to tell you an email did not go. */
exit($tally['failed'] > 0 ? 1 : 0);
