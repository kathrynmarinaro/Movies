<?php
/* The manual "Check release date" button on the movie screen.
 *
 * POST id=N  ->  redirect back to movie.php with the answer as a flash.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS AT ALL.
 * ---------------------------------------------------------------------------
 *
 * Release dates in this app are FROZEN at save time — nothing re-syncs them
 * nightly, because a value that rewrites itself under you is one you cannot
 * reason about (schema.sql, movies.release_date). That leaves two deliberate
 * ways for a date to move, and this is one of them; the other is the cron's
 * verify-before-send, which runs immediately before an email would go out.
 *
 * Both call reminder_verify_release_date(), so they cannot disagree about what
 * "the date moved" means or about recomputing heads_up_eligible afterwards.
 *
 * ---------------------------------------------------------------------------
 * A FORM POST, NOT A fetch().
 * ---------------------------------------------------------------------------
 *
 * The movie screen loads no JavaScript of its own, and this button is the only
 * action on it. A fetch would mean shipping a module to that screen so that one
 * control works — and a control that silently does nothing when a module fails
 * to load is worse than a page reload.
 *
 * Being a plain form is also why this does NOT call require_same_origin():
 * that check demands an X-Requested-With header a <form> cannot send. The CSRF
 * exposure is bounded and understood — the worst a forged post can do is make
 * this app ask TMDB for a release date it is already allowed to ask for, and
 * write the answer to a row the owner can see. It cannot read anything back.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/repo.php';
require_once __DIR__ . '/../../lib/tmdb.php';
require_once __DIR__ . '/../../lib/reminders.php';

require_method('POST');

/* require_admin(), not require_admin_api(), even though this file lives under
 * api/. The caller is a <form> in a browser, so an expired session should land
 * on the login screen and come back here — require_admin_api() would answer a
 * form post with a JSON 401, which renders as a page of braces. The directory
 * says where the file lives; the CALLER decides which gate is right. */
require_admin();

$id    = (int) ($_POST['id'] ?? 0);
$movie = $id > 0 ? movie_get($id) : null;

if ($movie === null) {
    /* Back to the collection rather than a JSON 404, for the same reason. */
    header('Location: ../index.php');
    exit;
}

$today  = movies_today();
$result = reminder_verify_release_date($movie, $today);

/* Back to the movie, with what happened in the query string. A redirect rather
 * than rendering here, so a refresh doesn't re-run the check — and so the
 * answer is read on the screen that shows the date it is about. */
$flash = match ($result['result']) {
    'changed'     => 'moved',
    'unreachable' => 'unreachable',
    default       => 'unchanged',
};

header('Location: ../movie.php?' . http_build_query(array('id' => $id, 'checked' => $flash)));
exit;
