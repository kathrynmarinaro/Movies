<?php
/* Tests for the reminder cron — the riskiest code in the app, because it is
 * the only part nobody watches run.
 *
 * Required by tools/run-tests.php, which owns the harness and the assertions.
 *
 * WHAT THESE COVER, and each is a rule somebody could plausibly "simplify"
 * away later:
 *
 *   - a heads-up fires on its day, and NOT the day before or after
 *   - a movie added inside the window gets no heads-up at all
 *   - the day-of email is opt-in
 *   - the ledger refuses a second send for the same trigger
 *   - a FAILED send is retried; a delivered one never is
 *   - a moved release date cancels the email and corrects the stored date
 *   - an unreachable TMDB sends anyway
 *   - marking a movie watched stops its reminders
 *
 * No SMTP server and no network: lib/mailer.php and lib/tmdb.php both carry a
 * test seam ($GLOBALS['mailer_send_hook'], $GLOBALS['tmdb_http_hook']) that
 * these install. */

declare(strict_types=1);

/** Every email the fake transport was handed this test. */
function test_mail_outbox(?array $set = null): array
{
    static $box = array();
    if ($set !== null) {
        $box = $set;
    }
    return $box;
}

/**
 * Install a fake SMTP transport. $accept=false makes every send fail, which is
 * how the retry path is exercised.
 */
function test_install_mailer(bool $accept = true): void
{
    test_mail_outbox(array());
    $GLOBALS['mailer_send_hook'] = function (string $subject, string $text, string $html) use ($accept) {
        if ($accept) {
            $box   = test_mail_outbox();
            $box[] = array('subject' => $subject, 'text' => $text, 'html' => $html);
            test_mail_outbox($box);
        }
        return $accept;
    };
}

/**
 * Install a fake TMDB. $dates maps tmdb_id => release date (or null).
 * Passing null for $dates makes every request fail, i.e. TMDB unreachable.
 */
function test_install_tmdb(?array $dates): void
{
    $GLOBALS['tmdb_http_hook'] = function (string $url, int $timeout, bool $auth) use ($dates) {
        if ($dates === null) {
            return null;   // unreachable
        }
        if (preg_match('#/movie/(\d+)(\?|$)#', $url, $m)) {
            $id = (int) $m[1];
            if (!array_key_exists($id, $dates)) {
                return array('status' => 404, 'body' => '{}');
            }
            return array('status' => 200, 'body' => json_encode(array(
                'id'           => $id,
                'title'        => 'Fixture ' . $id,
                // TMDB serves "" rather than null for an undated film.
                'release_date' => $dates[$id] ?? '',
            )));
        }
        return array('status' => 200, 'body' => '{}');
    };
}

function test_clear_hooks(): void
{
    unset($GLOBALS['mailer_send_hook'], $GLOBALS['tmdb_http_hook']);
}

/** A coming_soon movie. Returns its id. */
function test_add_coming_soon(array $fields, string $today): int
{
    return movie_save(array_merge(array(
        'title'           => 'Test Movie',
        'status'          => 'coming_soon',
        'source'          => 'tmdb',
        'day_of_reminder' => 0,
    ), $fields), null, $today);
}

/** Y-m-d, $days from $today. */
function test_date(string $today, int $days): string
{
    return (new DateTimeImmutable($today, new DateTimeZone('UTC')))
        ->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function tests_reminders(): void
{
    $today = '2026-06-01';

    /* ---------------------------------------------------------------- dates */

    t_group('date arithmetic');

    t_is(heads_up_date('2026-06-15', 7), '2026-06-08', 'heads-up is release minus the lead');
    t_is(heads_up_date(null, 7), null, 'an undated movie has no heads-up date');
    t_is(days_until('2026-06-08', $today), 7, 'days_until counts forward');
    t_is(days_until('2026-05-30', $today), -2, 'days_until goes negative in the past');

    /* A DST boundary in the app's own timezone. lib/dates.php parses in UTC
     * precisely so this is 1 and not 0 — get it wrong and a March reminder
     * fires on two consecutive days or on neither. */
    t_is(days_until('2026-03-09', '2026-03-08'), 1, 'a DST-boundary day is still one day');

    t_is(fmt_countdown('2026-06-01', $today), 'Out today', 'countdown: today');
    t_is(fmt_countdown('2026-06-02', $today), 'Tomorrow', 'countdown: tomorrow');
    t_is(fmt_countdown('2026-05-29', $today), 'Out 3 days ago', 'countdown: already out');
    t_is(fmt_countdown(null, $today), 'Date TBA', 'countdown: undated');

    /* -------------------------------------------------- the heads-up window */

    t_group('the heads-up fires on its day and no other');

    t_reset();
    test_install_mailer();
    test_install_tmdb(array());   // reachable, but no movie has a tmdb_id here

    // Added well in advance, releasing in 7 days: due today.
    $id = test_add_coming_soon(
        array('title' => 'On Time', 'release_date' => test_date($today, 7)),
        test_date($today, -30)
    );

    t_is((int) movie_get($id)['heads_up_eligible'], 1,
        'a movie added a month out is heads-up eligible');

    $due = reminders_due($today);
    t_is(count($due), 1, 'exactly one reminder is due on the day');
    t_is($due[0]['kind'], 'heads_up', 'and it is the heads-up');

    t_is(count(reminders_due(test_date($today, -1))), 0, 'nothing is due the day before');
    t_is(count(reminders_due(test_date($today, 1))), 0, 'nothing is due the day after');

    /* ------------------------------------------------------- the late add */

    t_group('a late add gets no heads-up');

    t_reset();
    test_install_mailer();

    // Released in 3 days, added today: the window has already passed.
    $late = test_add_coming_soon(
        array('title' => 'Late Add', 'release_date' => test_date($today, 3)),
        $today
    );

    t_is((int) movie_get($late)['heads_up_eligible'], 0,
        'a movie added inside the window is NOT heads-up eligible');

    // Walk every day from now to release. None of them may produce a heads-up.
    $found = 0;
    for ($d = 0; $d <= 3; $d++) {
        foreach (reminders_due(test_date($today, $d)) as $item) {
            if ($item['kind'] === 'heads_up') {
                $found++;
            }
        }
    }
    t_is($found, 0, 'no heads-up fires on any day up to release');

    /* -------------------------------------------------------- the day-of */

    t_group('the day-of reminder is opt-in');

    t_reset();
    test_install_mailer();

    $off = test_add_coming_soon(
        array('title' => 'No Day Of', 'release_date' => $today, 'day_of_reminder' => 0),
        test_date($today, -30)
    );
    t_is(count(reminders_due($today)), 0, 'day-of does not fire when the toggle is off');

    $on = test_add_coming_soon(
        array('title' => 'Day Of', 'release_date' => $today, 'day_of_reminder' => 1),
        test_date($today, -30)
    );
    $due = reminders_due($today);
    t_is(count($due), 1, 'day-of fires when the toggle is on');
    t_is($due[0]['kind'], 'day_of', 'and it is the day-of kind');

    /* ------------------------------------------------------- the ledger */

    t_group('the ledger makes a double-send impossible');

    t_reset();
    test_install_mailer();
    test_install_tmdb(array());

    $id = test_add_coming_soon(
        array('title' => 'Once Only', 'release_date' => test_date($today, 7)),
        test_date($today, -30)
    );

    $first = cron_reminders_run($today);
    t_is($first['sent'], 1, 'the first run sends one email');
    t_is(count(test_mail_outbox()), 1, 'and the transport saw exactly one message');

    $second = cron_reminders_run($today);
    t_is($second['sent'], 0, 'a second run the same day sends nothing');
    t_is($second['skipped'], 1, 'and reports it as already sent');
    t_is(count(test_mail_outbox()), 1, 'the transport still saw only one message');

    /* --------------------------------------------------------- the retry */

    t_group('a failed send is retried, a delivered one is not');

    t_reset();
    test_install_tmdb(array());
    test_install_mailer(false);   // every send fails

    $id = test_add_coming_soon(
        array('title' => 'Retry Me', 'release_date' => test_date($today, 7)),
        test_date($today, -30)
    );

    $run = cron_reminders_run($today);
    t_is($run['failed'], 1, 'a refused send is counted as failed');

    $row = q('SELECT sent_at, attempts, last_error FROM movie_reminder_sends WHERE movie_id = ?',
        array($id))->fetch();
    t_is($row['sent_at'], null, 'sent_at stays NULL after a failure');
    t_ok((string) $row['last_error'] !== '', 'and the reason is recorded beside the row');

    // Same day, transport now working: it must try again.
    test_install_mailer(true);
    $run = cron_reminders_run($today);
    t_is($run['sent'], 1, 'the retry sends');

    $row = q('SELECT sent_at, attempts FROM movie_reminder_sends WHERE movie_id = ?',
        array($id))->fetch();
    t_ok($row['sent_at'] !== null, 'sent_at is stamped once delivered');
    t_is((int) $row['attempts'], 2, 'and both attempts were counted');

    /* ------------------------------------------- verify before send: moved */

    t_group('a moved release date cancels the email');

    t_reset();
    test_install_mailer();

    $id = test_add_coming_soon(array(
        'title'        => 'Slipped',
        'tmdb_id'      => 555,
        'release_date' => test_date($today, 7),
    ), test_date($today, -30));

    // TMDB now says it releases three months later.
    $moved = test_date($today, 97);
    test_install_tmdb(array(555 => $moved));

    $run = cron_reminders_run($today);
    t_is($run['moved'], 1, 'the run reports the reminder as skipped for a moved date');
    t_is($run['sent'], 0, 'and sends nothing');
    t_is(count(test_mail_outbox()), 0, 'the transport saw no message at all');

    $after = movie_get($id);
    t_is($after['release_date'], $moved, 'the stored release date is corrected');
    t_is((int) $after['heads_up_eligible'], 1,
        'and the heads-up window is re-opened for the new date');

    // The ledger row says why, and stays unsent.
    $row = q('SELECT sent_at, last_error FROM movie_reminder_sends WHERE movie_id = ?',
        array($id))->fetch();
    t_is($row['sent_at'], null, 'the skipped send is not marked delivered');
    t_ok(str_contains((string) $row['last_error'], 'moved the release date'),
        'and the ledger records that TMDB moved it');

    // On the new heads-up day it fires normally.
    $newDay = test_date($moved, -7);
    test_install_tmdb(array(555 => $moved));
    $run = cron_reminders_run($newDay);
    t_is($run['sent'], 1, 'the reminder fires on the new date');

    /* --------------------------------------- verify before send: unreachable */

    t_group('an unreachable TMDB does not cancel a reminder');

    t_reset();
    test_install_mailer();
    test_install_tmdb(null);   // every request fails

    $id = test_add_coming_soon(array(
        'title'        => 'API Down',
        'tmdb_id'      => 777,
        'release_date' => test_date($today, 7),
    ), test_date($today, -30));

    $run = cron_reminders_run($today);
    t_is($run['sent'], 1, 'the email is sent on the frozen date when TMDB is down');
    t_is($run['moved'], 0, 'and nothing is treated as moved');

    /* --------------------------------------- verify: unchanged date sends */

    t_group('an unchanged date sends and stamps the check');

    t_reset();
    test_install_mailer();

    $release = test_date($today, 7);
    $id = test_add_coming_soon(array(
        'title'        => 'Still On',
        'tmdb_id'      => 999,
        'release_date' => $release,
    ), test_date($today, -30));
    test_install_tmdb(array(999 => $release));

    $run = cron_reminders_run($today);
    t_is($run['sent'], 1, 'an unchanged date sends');
    t_ok(movie_get($id)['release_date_checked_at'] !== null,
        'and the movie records when it was last confirmed');

    /* ------------------------------------------------ watched stops reminders */

    t_group('marking a movie watched stops its reminders');

    t_reset();
    test_install_mailer();
    test_install_tmdb(array());

    $id = test_add_coming_soon(
        array('title' => 'Seen It', 'release_date' => test_date($today, 7)),
        test_date($today, -30)
    );
    t_is(count(reminders_due($today)), 1, 'due while it is still coming soon');

    movie_set_status($id, 'watched', array('date_watched' => $today, 'rating' => 4), $today);
    t_is(count(reminders_due($today)), 0, 'and not due once it is marked watched');

    /* ------------------------------------------------------------ subjects */

    t_group('email phrasing');

    $movie = array(
        'id' => 1, 'title' => 'Dune: Part Three',
        'release_date' => '2026-06-08', 'notes' => '', 'genres' => array(),
    );
    t_is(
        mailer_subject($movie, REMINDER_HEADS_UP, $today),
        'In theatres next Monday: Dune: Part Three — June 8',
        'the heads-up subject names the title first'
    );
    t_is(
        mailer_subject(array('id' => 1, 'title' => 'Dune: Part Three', 'release_date' => $today),
            REMINDER_DAY_OF, $today),
        'Out today: Dune: Part Three',
        'the day-of subject is short'
    );

    /* An undated movie must not produce "In theatres : Title —". */
    t_is(
        mailer_subject(array('id' => 1, 'title' => 'Untitled', 'release_date' => null),
            REMINDER_HEADS_UP, $today),
        'In theatres soon: Untitled',
        'an undated movie still gets a readable subject'
    );

    test_clear_hooks();
}
