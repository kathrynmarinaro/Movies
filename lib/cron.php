<?php
/* The daily reminder run: find what releases today or in a week, email once.
 *
 * ---------------------------------------------------------------------------
 * THE LOGIC LIVES HERE, IN lib/, AND THE TWO ENTRY POINTS ARE BOTH THIN.
 * ---------------------------------------------------------------------------
 *
 * Hostinger plans differ: some give a real command cron, others only a URL
 * fetch, and tools/ is denied over HTTP. So there are two ways in —
 * tools/cron-reminders.php and public/cron.php — and they must run IDENTICAL
 * code, because two copies of a job that runs unattended once a day is two
 * copies of which only one ever gets fixed.
 *
 * The siblings solve this with a tools/ file that is both a script and a
 * library, guarded by a check on SCRIPT_FILENAME so it only runs itself when
 * invoked directly. This app puts the function in lib/ instead, which is the
 * same idea with less cleverness: a library file is a library file, both entry
 * points require it, and the test suite can load it without also loading an
 * entry point's bootstrapping.
 *
 * ---------------------------------------------------------------------------
 * ONE CLOCK PER RUN.
 * ---------------------------------------------------------------------------
 *
 * movies_today() is called ONCE, by the entry point, and passed in. A run that
 * straddles midnight must not decide a movie is due against one date and
 * record the send against another — the ledger is keyed on that date, so a
 * mismatch there means the same email again tomorrow.
 *
 * ---------------------------------------------------------------------------
 * THE SEQUENCE, AND WHY IT IS THIS ORDER.
 * ---------------------------------------------------------------------------
 *
 *   foreach (reminders_due($today))   -- computed, not stored; see reminders.php
 *       reminder_claim()              -- 1. the ledger, BEFORE the network,
 *                                     --    which is what makes a second email
 *                                     --    impossible even if this run dies
 *       reminder_verify_release_date()-- 2. has TMDB moved the date?
 *       send_release_email()          -- 3. and mark sent or failed
 *
 * CLAIM BEFORE VERIFY, AND VERIFY BEFORE SEND. The claim is first because it
 * is the only step that cannot be repeated safely — if the process dies
 * between the verify and the send, the ledger row already exists with sent_at
 * NULL and tomorrow retries it. If the claim came last, a crash after sending
 * would send again.
 *
 * A SKIPPED SEND IS RECORDED, NOT FORGOTTEN. When the verify says the date
 * moved, the ledger row stays with sent_at NULL and last_error saying why. It
 * is not a failure — nothing is retried, because the movie is now on a
 * different schedule and reminders_due() will find it there — but the row is
 * the only record that the app noticed, and "why didn't I get an email about
 * this" deserves an answer.
 *
 * ---------------------------------------------------------------------------
 * FAIL SOFT, LOUDLY.
 * ---------------------------------------------------------------------------
 *
 * One broken row, missing movie or refused SMTP connection costs that one
 * email and nothing else — the loop catches per row. But the run reports a
 * non-zero failure count, and both entry points turn that into a non-zero exit
 * status or an HTTP 500, because a cron nobody watches is a cron whose only
 * signal is its exit status. A failure that reports success is worse than no
 * cron at all. */

declare(strict_types=1);

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/tmdb.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/mailer.php';

/**
 * One whole run. Returns the tally; throws nothing it can help throwing.
 *
 * @param string $today   Y-m-d, from ONE movies_today() at the entry point.
 * @param bool   $dryRun  Report what would be sent, touch nothing.
 * @return array{today:string, due:int, sent:int, skipped:int, moved:int, failed:int, capped:int, errors:array<int,string>}
 */
function cron_reminders_run(string $today, bool $dryRun = false): array
{
    $cap = max(1, (int) cfg('reminders.max_per_run', 20));

    $tally = array(
        'today'   => $today,
        'due'     => 0,
        'sent'    => 0,
        'skipped' => 0,
        'moved'   => 0,
        'failed'  => 0,
        'capped'  => 0,
        'errors'  => array(),
    );

    $due = reminders_due($today);
    $tally['due'] = count($due);

    /* THE TIMESTAMP FOR THE LEDGER, computed once for the whole run.
     *
     * The DATE half is $today, so a run that crosses midnight cannot stamp a
     * send with a date the rest of the run disagrees about, and so the one
     * clock stays lib/dates.php's. Only the time of day comes from date(), and
     * it is a diagnostic. It is NOT NOW(): the database's idea of the hour is
     * pinned separately in lib/db.php, and this way nothing depends on the two
     * agreeing. */
    $sentAt = $today . ' ' . date('H:i:s');

    $handled = 0;

    foreach ($due as $item) {
        $movie   = $item['movie'];
        $kind    = $item['kind'];
        $trigger = $item['trigger_date'];
        $movieId = (int) $movie['id'];

        /* The cap is a guard against a data accident — a bulk add sharing one
         * release date — turning into a hundred messages from your own address
         * in one morning. Anything over it waits for tomorrow, and because the
         * claim has not happened for these rows, nothing is lost.
         *
         * Note this only defers a DAY-OF reminder meaningfully; a heads-up
         * deferred past its day is gone, per reminders_due(). At a cap of 20 on
         * a personal watchlist that is a theoretical concern, and the
         * alternative — no cap — is a worse failure. */
        if ($handled >= $cap) {
            $tally['capped']++;
            continue;
        }

        try {
            if ($dryRun) {
                $handled++;
                $tally['sent']++;   // "would send"
                continue;
            }

            /* 1. The claim, BEFORE any network call. Already delivered → this
             * run stays quiet. A previous failure left sent_at NULL and is
             * retried here. */
            if (!reminder_claim($movieId, $kind, $trigger)) {
                $tally['skipped']++;
                continue;
            }
            $handled++;

            /* 2. Has TMDB moved the date out from under this email? */
            $check = reminder_verify_release_date($movie, $today);
            if ($check['result'] === 'changed') {
                $was = (string) ($movie['release_date'] ?? 'none');
                $now = (string) ($check['release_date'] ?? 'none');
                reminder_mark_failed(
                    $movieId,
                    $kind,
                    $trigger,
                    'not sent: TMDB moved the release date from ' . $was . ' to ' . $now
                );
                $tally['moved']++;
                continue;
            }
            // 'unreachable' falls through and sends on the frozen date, on
            // purpose. See reminder_verify_release_date().

            // The verify may have restamped the row; send what is current.
            $movie['release_date'] = $check['release_date'];

            /* 3. */
            if (send_release_email($movie, $kind, $today)) {
                reminder_mark_sent($movieId, $kind, $trigger, $sentAt);
                $tally['sent']++;
            } else {
                $why = mailer_last_error();
                reminder_mark_failed($movieId, $kind, $trigger, $why);
                $tally['failed']++;
                $tally['errors'][] = (string) $movie['title'] . ': ' . $why;
            }
        } catch (Throwable $e) {
            /* Anything the loop did not anticipate. The row is left with
             * sent_at NULL, so tomorrow tries again. */
            $tally['failed']++;
            $tally['errors'][] = 'movie ' . $movieId . ': ' . $e->getMessage();
            error_log('cron-reminders: movie ' . $movieId . ' failed: ' . $e->getMessage());

            try {
                reminder_mark_failed($movieId, $kind, $trigger, $e->getMessage());
            } catch (Throwable $inner) {
                /* The database itself is unhappy. The exit code still reports
                 * it; there is nowhere else left to write. */
                error_log('cron-reminders: could not record the failure either: ' . $inner->getMessage());
            }
        }
    }

    return $tally;
}

/**
 * The one-line summary. One line because it is what a cron emails you, and a
 * report you skim is a report you read.
 *
 * "already sent" is the HEALTHY number, not a warning: it is every reminder
 * whose email already went out and whose ledger row is doing its job.
 */
function cron_reminders_summary(array $tally, bool $dryRun = false): string
{
    return sprintf(
        'cron-reminders %s:%s %d due, %d sent, %d already sent, %d skipped (date moved), %d failed, %d over cap',
        $tally['today'],
        $dryRun ? ' [DRY RUN]' : '',
        $tally['due'],
        $tally['sent'],
        $tally['skipped'],
        $tally['moved'],
        $tally['failed'],
        $tally['capped']
    );
}
