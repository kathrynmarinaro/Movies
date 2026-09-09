<?php
/* Render ONE screen against an in-memory database and print the HTML.
 *
 *   php tools/render-screen.php index.php '{"f":"stars-5"}'
 *
 * A development harness. tools/smoke-screens.php runs this once per screen and
 * checks what comes back; it is separate and spawned per screen because a
 * screen is an entry point and entry points call exit() — a 404 branch, a
 * redirect, a fatal — which would end a parent process that had merely
 * included them. One process per screen is also simply what a request is, so
 * nothing about session state, output buffering or globals can leak from one
 * render into the next and make a broken screen look fine.
 *
 * The database is built from schema.sql into SQLite in memory and seeded, so
 * this contacts no MySQL server and no network. See lib/db.php's CLI-only PDO
 * override.
 *
 * $_GET arrives as JSON in argv[2]. The key "__id_title" is special: it is
 * replaced with the id of the movie with that title, because ids are assigned
 * by the seed and the caller has no way to know them.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* Buffered, so the screens' own header() calls behave as they would in a
 * request rather than warning about output that has already gone out. */
ob_start();

$root   = dirname(__DIR__);
$screen = (string) ($argv[1] ?? '');
$query  = json_decode((string) ($argv[2] ?? '{}'), true);

if ($screen === '' || !is_array($query)) {
    fwrite(STDERR, "usage: render-screen.php <screen.php> '<json $_GET>'\n");
    exit(2);
}

/* Only files that actually live in public/. Not a security control — this is a
 * CLI dev tool — but it turns a typo into a sentence instead of an include
 * warning about a path halfway up the filesystem. */
$path = $root . '/public/' . basename($screen);
if (!is_file($path)) {
    fwrite(STDERR, "no such screen: {$screen}\n");
    exit(2);
}

require_once $root . '/lib/bootstrap.php';
require_once $root . '/lib/repo.php';
require_once $root . '/tools/test-harness.php';
require_once $root . '/tools/seed.php';

harness_pdo($root . '/schema.sql');

/* No network: any screen reaching for TMDB gets a refusal rather than a
 * fifteen-second timeout. providers_for_movie() then serves the empty cache,
 * which is the "not on any subscription service" path. */
$GLOBALS['tmdb_http_hook'] = static fn() => null;

/* SEED IN THE PAST rather than moving the clock forward.
 *
 * "__advance_days: 60" means "show me this screen as it looks sixty days from
 * now", and the way to get there is to build the fixture sixty days ago and
 * render with the real clock. The alternative — an override inside
 * movies_today() — would be production code existing only for a test, and
 * lib/dates.php's whole discipline is that exactly one function asks what day
 * it is and nothing may fake it.
 *
 * This is also the more faithful test: the rows really were written on an
 * older date, so heads_up_eligible and the section were decided then, exactly
 * as they would have been. */
$seedDay = movies_today();
if (isset($query['__advance_days'])) {
    $seedDay = (new DateTimeImmutable('-' . (int) $query['__advance_days'] . ' days'))
        ->format('Y-m-d');
    unset($query['__advance_days']);
}

seed_run($seedDay, false);

/* Resolve a movie id from a title, since the caller cannot know the ids. */
if (isset($query['__id_title'])) {
    $found = q('SELECT id FROM movies WHERE title = ?', array((string) $query['__id_title']))
        ->fetchColumn();
    unset($query['__id_title']);
    $query['id'] = $found === false ? 0 : (int) $found;
}

/* Signed in, unless the caller asked for a signed-out render — which is how
 * the public page is checked. auth_start_session() runs FIRST because
 * session_start() replaces $_SESSION wholesale. */
$signedOut = !empty($query['__signed_out']);
unset($query['__signed_out']);

auth_start_session();
$_SESSION['authed'] = $signedOut ? false : true;

$_GET     = $query;
$_POST    = array();
$_REQUEST = $query;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/' . $screen;
$_SERVER['REQUEST_URI']    = '/' . $screen;
$_SERVER['HTTP_HOST']      = 'movies.test';

include $path;

ob_end_flush();
