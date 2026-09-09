<?php
/* Which reminders are due, and the ledger that stops one being sent twice.
 *
 * Nothing outside this file writes SQL against movie_reminder_sends. Every
 * statement is prepared, via q().
 *
 * ---------------------------------------------------------------------------
 * NOT ONE FUNCTION HERE ASKS WHAT DAY IT IS.
 * ---------------------------------------------------------------------------
 *
 * Every one of them takes $today, from a single movies_today() at the top of a
 * cron run. lib/dates.php's header explains why at length. The short version:
 * a run that straddles midnight must not decide a movie is due against one
 * date and record the send against another, because the ledger is KEYED on
 * that date and a mismatch means the same email again tomorrow.
 *
 * ---------------------------------------------------------------------------
 * THE SCHEDULE IS COMPUTED. ONLY THE SENDING IS STORED.
 * ---------------------------------------------------------------------------
 *
 * There is no `reminders` table. Both trigger dates are derivable from
 * movies.release_date — heads-up is release minus the configured lead, day-of
 * is release itself — so storing them would be a second copy of the same fact,
 * and the copies come apart the instant a release date is corrected.
 *
 * What IS stored is the fact of having sent, in movie_reminder_sends. That
 * table's PRIMARY KEY (movie_id, kind, trigger_date) is the entire double-send
 * guarantee, and it is enforced by the database rather than by code that has
 * to remember to check first.
 *
 * The payoff shows up when a release date moves: the new date is a different
 * key, so a corrected reminder is allowed to send once on its own terms, with
 * no bookkeeping to reconcile. That falls out of the key rather than needing a
 * rule. */

declare(strict_types=1);

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/mailer.php';

/**
 * Every reminder that should go out today, as a flat list.
 *
 *   [ ['movie' => <row>, 'kind' => 'heads_up', 'trigger_date' => '2026-03-13'], ... ]
 *
 * ---------------------------------------------------------------------------
 * EQUALITY, NOT `<=`. THIS IS THE ONE PLACE THIS APP DIVERGES FROM ITS
 * SIBLINGS AND IT IS DELIBERATE.
 * ---------------------------------------------------------------------------
 *
 * The Personal CRM and Shirewatch both query `WHERE next_due <= today` and let
 * the ledger silence the overdue ones. That is right for THEM: a birthday you
 * haven't acted on and a water heater you haven't flushed are still true
 * tomorrow, and the reminder should keep sitting there getting louder.
 *
 * A release date is not like that. "This is out in a week" stops being true a
 * week later, and an overdue-catch-up email would say something false. So a
 * heads-up fires on its date or not at all, and if the cron misses a day — a
 * server reboot, a Hostinger blip — that heads-up is simply gone. The day-of
 * email and the Coming Soon screen both still work.
 *
 * The day-of case uses `<=` reasoning only in the sense that it also fires for
 * a release date in the past... it does NOT. Same rule, same reason.
 *
 * TWO FILTERS THAT ARE NOT ABOUT DATES:
 *   - status = 'coming_soon'. A movie marked watched leaves the list and its
 *     reminders stop, which is what the brief asks for and it needs no
 *     separate cancellation step.
 *   - heads_up_eligible, for the heads-up only. See schema.sql: this is the
 *     stored answer to "was there still time when this was added", and it is
 *     what implements skipping the heads-up for a late add.
 */
function reminders_due(string $today): array
{
    $lead = reminders_lead_days();
    $due  = array();

    /* Heads-up: release_date is exactly $lead days from now.
     *
     * Computed in PHP and compared as a string rather than with DATE_ADD, so
     * the query is an index-friendly equality on movies(status, release_date),
     * is identical on MySQL and on the SQLite the tests run against, and keeps
     * the one clock in lib/dates.php. */
    $headsUpTarget = (new DateTimeImmutable($today, new DateTimeZone('UTC')))
        ->modify('+' . $lead . ' days')->format('Y-m-d');

    $rows = q(
        'SELECT * FROM movies
          WHERE status = ? AND heads_up_eligible = 1 AND release_date = ?',
        array('coming_soon', $headsUpTarget)
    )->fetchAll();

    foreach (movies_attach_genres($rows) as $movie) {
        $due[] = array(
            'movie'        => $movie,
            'kind'         => REMINDER_HEADS_UP,
            // The trigger date is TODAY — the day the email goes out — not the
            // release date. It is what keys the ledger, and keying on the
            // release date instead would mean a slipped date could re-send the
            // heads-up for a day that already had one.
            'trigger_date' => $today,
        );
    }

    /* Day-of: release_date is today, and this movie opted in. */
    $rows = q(
        'SELECT * FROM movies
          WHERE status = ? AND day_of_reminder = 1 AND release_date = ?',
        array('coming_soon', $today)
    )->fetchAll();

    foreach (movies_attach_genres($rows) as $movie) {
        $due[] = array(
            'movie'        => $movie,
            'kind'         => REMINDER_DAY_OF,
            'trigger_date' => $today,
        );
    }

    return $due;
}

/**
 * Stake a claim on sending this reminder. True = go and send.
 *
 * THE ONE STATEMENT THAT MAKES A DOUBLE-SEND IMPOSSIBLE, and it is safe to run
 * twice because the database enforces it: (movie_id, kind, trigger_date) is
 * movie_reminder_sends' PRIMARY KEY, so the insert either creates the row or
 * bumps its attempt count, and nothing can create a second one.
 *
 * It answers false only for a row that has ALREADY BEEN DELIVERED. A previous
 * attempt that failed left sent_at NULL, and retrying it is the intended
 * behaviour — a hung SMTP connection must not cost you the release. (For a
 * heads-up that retry only has one day to happen in, since reminders_due()
 * matches on equality; that is the accepted cost of not sending stale news.)
 */
function reminder_claim(int $movieId, string $kind, string $triggerDate): bool
{
    q(
        'INSERT INTO movie_reminder_sends (movie_id, kind, trigger_date, attempts)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1',
        array($movieId, $kind, $triggerDate)
    );

    $row = q(
        'SELECT sent_at FROM movie_reminder_sends
          WHERE movie_id = ? AND kind = ? AND trigger_date = ?',
        array($movieId, $kind, $triggerDate)
    )->fetch();

    return $row !== false && ($row['sent_at'] === null || $row['sent_at'] === '');
}

/**
 * Record a delivered send.
 *
 * The timestamp comes from the CALLER rather than from NOW(), keeping the one
 * clock rule intact — and it is the answer to "when did that email actually
 * go", which is a question asked exactly once, in a panic.
 */
function reminder_mark_sent(int $movieId, string $kind, string $triggerDate, string $sentAt): void
{
    q(
        'UPDATE movie_reminder_sends SET sent_at = ?, last_error = NULL
          WHERE movie_id = ? AND kind = ? AND trigger_date = ?',
        array($sentAt, $movieId, $kind, $triggerDate)
    );
}

/**
 * Record a failed or skipped send. sent_at stays NULL.
 *
 * Truncated to the column width because this is a diagnostic beside the row,
 * not a log — the full text belongs in error_log.
 */
function reminder_mark_failed(int $movieId, string $kind, string $triggerDate, string $why): void
{
    q(
        'UPDATE movie_reminder_sends SET last_error = ?
          WHERE movie_id = ? AND kind = ? AND trigger_date = ?',
        array(mb_substr($why, 0, 255, 'UTF-8'), $movieId, $kind, $triggerDate)
    );
}

/**
 * Re-check one movie's release date against TMDB and correct it if it moved.
 *
 * ---------------------------------------------------------------------------
 * THIS IS WHY THE CRON DOESN'T SEND STALE NEWS.
 * ---------------------------------------------------------------------------
 *
 * Release dates are FROZEN in this app — nothing re-syncs them nightly,
 * because a value that rewrites itself under you is one you cannot reason
 * about. But that freezing is exactly what makes an email risky: the whole
 * point of the message is a date, and the date might be months out of memory
 * by the time it fires.
 *
 * So the check happens at the only moment it has to: immediately before
 * sending. Three outcomes, and the difference between the last two is the
 * whole design:
 *
 *   'unchanged'    TMDB agrees. Send.
 *   'changed'      TMDB has a different date (or has dropped it). The stored
 *                  date is CORRECTED, heads_up_eligible is recomputed for the
 *                  new date by movie_save(), and the caller does NOT send.
 *                  Tomorrow's run will find it on its new schedule.
 *   'unreachable'  TMDB could not be reached. SEND ANYWAY, on the frozen date.
 *                  An API outage must never silently cancel a reminder —
 *                  that failure is invisible, and a slightly-wrong email beats
 *                  a missing one.
 *
 * A manually-added movie has no tmdb_id and returns 'unchanged': there is
 * nothing to check against, and its date is whatever was typed.
 *
 * @return array{result: string, release_date: ?string}
 */
function reminder_verify_release_date(array $movie, string $today): array
{
    $stored = $movie['release_date'] ?? null;

    if (!cfg('reminders.verify_before_send', true)) {
        return array('result' => 'unchanged', 'release_date' => $stored);
    }

    $tmdbId = $movie['tmdb_id'] ?? null;
    if ($tmdbId === null || (int) $tmdbId <= 0) {
        return array('result' => 'unchanged', 'release_date' => $stored);
    }

    $res = tmdb_release_date((int) $tmdbId);
    if (!$res['ok']) {
        error_log('reminders: could not verify release date for movie '
            . (int) $movie['id'] . ' — sending on the stored date');
        return array('result' => 'unreachable', 'release_date' => $stored);
    }

    $fresh = $res['release_date'];
    if ($fresh === $stored) {
        /* Same date. Stamp the check so the movie screen can say when it was
         * last confirmed, then send. */
        movie_save(
            array('release_date_checked_at' => $today . ' ' . date('H:i:s')),
            (int) $movie['id'],
            $today
        );
        return array('result' => 'unchanged', 'release_date' => $stored);
    }

    /* It moved. Correct the stored date and let movie_save() recompute
     * heads_up_eligible for the new one — a date that slips FORWARD re-opens
     * the heads-up window, and a movie whose new date is a month out should
     * get its heads-up then. */
    movie_save(
        array(
            'release_date'            => $fresh,
            'release_date_checked_at' => $today . ' ' . date('H:i:s'),
        ),
        (int) $movie['id'],
        $today
    );

    return array('result' => 'changed', 'release_date' => $fresh);
}
