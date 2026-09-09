<?php
/* Render every screen and check what came out.
 *
 *   php tools/smoke-screens.php
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A SECOND ENTRY POINT AND NOT PART OF tools/run-tests.php.
 * ---------------------------------------------------------------------------
 *
 * run-tests.php deliberately avoids lib/bootstrap.php — it defines the app
 * constants itself and loads a fake config in memory, so the suite runs on a
 * fresh checkout with no credentials anywhere. That is the right trade for
 * testing libraries.
 *
 * It is the wrong trade for testing SCREENS. A screen is an entry point: it
 * starts with `require_once lib/bootstrap.php`, and that is exactly the code
 * path worth exercising. Loading both would redeclare every function in
 * bootstrap (PHP binds top-level function declarations at compile time, so no
 * runtime guard can prevent it).
 *
 * So this file goes the other way: it uses the REAL bootstrap, and therefore
 * needs a real config.php. It supplies the database itself through the
 * CLI-only PDO override in lib/db.php, so no MySQL server is involved.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS CATCHES THAT run-tests.php CANNOT.
 * ---------------------------------------------------------------------------
 *
 * Undefined variables in a template, a function a screen forgot to require, a
 * fatal in a match arm — all of which are invisible to a library test and
 * visible as a blank page in production. And, most importantly:
 *
 *   THE PUBLIC PAGE'S ACTUAL RENDERED BYTES ARE SEARCHED FOR PRIVATE DATA.
 *
 * tools/tests-repo.php asserts movies_public() withholds the right things.
 * This asserts the thing a stranger actually receives contains none of it —
 * which is the claim that matters, and which no amount of testing the layer
 * underneath can make on its own.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* EVERYTHING THIS SCRIPT PRINTS IS BUFFERED UNTIL IT EXITS.
 *
 * The screens call header() — noindex() on every private one, and a redirect
 * on some — and PHP refuses a header once any output has left the buffer,
 * which would turn this run into a wall of "headers already sent" warnings and
 * would stop auth_start_session() from working at all. An outer buffer keeps
 * the whole run in the "nothing sent yet" state that a real request is in.
 *
 * The cost is that the report appears all at once at the end. */
ob_start();

$root = dirname(__DIR__);

if (!is_file($root . '/config.php')) {
    fwrite(STDERR,
        "config.php is missing, and this check needs one because it exercises the\n"
        . "real bootstrap. Copy the example and fill in anything — no database is\n"
        . "contacted, the rows come from an in-memory SQLite built from schema.sql:\n\n"
        . "    cp config.example.php config.php\n\n"
        . "Set 'password_hash' to any real hash (php tools/make-hash.php) so the\n"
        . "private screens can be reached.\n");
    ob_end_flush();
    exit(1);
}

$pass = 0;
$fail = 0;
$failures = array();

function s_ok(bool $condition, string $label): void
{
    if ($condition) {
        $GLOBALS['pass']++;
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['fail']++;
    $GLOBALS['failures'][] = $label;
    echo "  FAIL {$label}\n";
}

/* Each screen is rendered by tools/render-screen.php in its OWN PROCESS.
 *
 * Not included into this one, because a screen is an entry point and entry
 * points call exit(): movie.php's 404 branch, api/release-check.php's
 * redirect, require_admin()'s redirect to login. Including them here would end
 * this process on the first such branch, and the run would report a pass on
 * everything it never got to.
 *
 * One process per screen is also just what a request is, so no session state,
 * output buffer or global from one render can reach the next and make a broken
 * screen look fine. */
function render_screen(string $file, array $get = array()): string
{
    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(dirname(__FILE__) . '/render-screen.php')
        . ' ' . escapeshellarg($file)
        . ' ' . escapeshellarg(json_encode($get, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $out = array();
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);

    $html = implode("\n", $out);

    /* A non-zero exit or a PHP error in the output is a broken screen, however
     * much HTML came with it. Marked so every assertion below fails loudly
     * rather than one of them happening to still match. */
    if ($code !== 0
        || str_contains($html, 'Fatal error')
        || str_contains($html, 'PHP Warning')
        || str_contains($html, 'Uncaught')
    ) {
        return '<<BROKE>> ' . $html;
    }

    return $html;
}

echo "\nPrivate screens\n";

$html = render_screen('index.php');
s_ok(!str_contains($html, '<<BROKE>>'), 'index.php renders');
s_ok(str_contains($html, 'Arrival'), 'index.php lists a watched movie');
s_ok(str_contains($html, 'poster-grid'), 'index.php renders the grid');
s_ok(str_contains($html, 'class="tabbar"'), 'index.php has the tab bar');
/* The seed includes a title made of markup precisely so a missing h() shows
 * up. If this fails, some template is echoing a title raw. */
s_ok(!str_contains($html, '<script>alert(1)</script>'), 'index.php escapes a title containing markup');
/* The undated watched movie must be present, not dropped. */
s_ok(str_contains($html, 'Something I Forgot To Date'), 'index.php keeps the undated watched movie');

$html = render_screen('index.php', array('f' => 'stars-5'));
s_ok(str_contains($html, 'Arrival'), 'the 5-star filter keeps a five-star movie');
s_ok(!str_contains($html, 'The Room'), 'and drops a one-star one');

$html = render_screen('index.php', array('f' => 'nonsense-from-a-stale-bookmark'));
s_ok(str_contains($html, 'Arrival'), 'an unknown filter shows the collection rather than erroring');

$html = render_screen('coming-soon.php');
s_ok(!str_contains($html, '<<BROKE>>'), 'coming-soon.php renders');
s_ok(str_contains($html, 'Dune: Part Three'), 'coming-soon.php lists an upcoming movie');
s_ok(str_contains($html, 'Date TBA'), 'an undated film reads "Date TBA", not a fake date');
s_ok(str_contains($html, 'Tomorrow'), 'a film out tomorrow says so');
s_ok(str_contains($html, 'is-out'), 'an already-released film is marked as out');

$html = render_screen('watchlist.php');
s_ok(!str_contains($html, '<<BROKE>>'), 'watchlist.php renders');
s_ok(str_contains($html, 'Past Lives'), 'watchlist.php lists a to-watch movie');

/* The detail screen, once per section, because each takes a different branch.
 *
 * Movies are named rather than numbered: the child process resolves
 * __id_title to an id against its own seeded database, because this process
 * has no database of its own to look one up in. */

$html = render_screen('movie.php', array('__id_title' => 'Arrival'));
s_ok(!str_contains($html, '<<BROKE>>'), 'movie.php renders a watched movie');
s_ok(str_contains($html, 'Watched '), 'and shows when it was watched');
s_ok(str_contains($html, 'Not on any subscription service'), 'streaming degrades to a sentence');

$html = render_screen('movie.php', array('__id_title' => 'Dune: Part Three'));
s_ok(!str_contains($html, '<<BROKE>>'), 'movie.php renders a coming-soon movie');
s_ok(str_contains($html, 'Week-ahead email on'), 'and says when the week-ahead email fires');
s_ok(str_contains($html, 'Release-day email on'), 'and that the day-of email is on');
s_ok(str_contains($html, 'Mark as watched'), 'and offers the promotion');

/* THE LATE ADD. The screen must say why no week-ahead email is coming, rather
 * than leaving somebody to wonder later. */
$html = render_screen('movie.php', array('__id_title' => 'Added Too Late'));
s_ok(str_contains($html, 'No week-ahead email'), 'a late add explains why it gets no week-ahead email');

$html = render_screen('movie.php', array('__id_title' => 'Past Lives'));
s_ok(!str_contains($html, '<<BROKE>>'), 'movie.php renders a to-watch movie');

$html = render_screen('movie.php', array('id' => 99999));
s_ok(str_contains($html, 'not here'), 'movie.php 404s cleanly on a bad id');

$html = render_screen('add.php', array('to' => 'coming_soon'));
s_ok(!str_contains($html, '<<BROKE>>'), 'add.php renders');
s_ok(str_contains($html, 'addflow.js'), 'and loads the add flow module');

$html = render_screen('edit.php', array('__id_title' => 'Arrival'));
s_ok(!str_contains($html, '<<BROKE>>'), 'edit.php renders an existing movie');
s_ok(str_contains($html, 'Date watched'), 'and shows the watched fields');

$html = render_screen('edit.php', array('__id_title' => 'Dune: Part Three'));
s_ok(str_contains($html, 'Theatrical release date'), 'edit.php shows the release date for coming soon');
s_ok(!str_contains($html, 'Date watched'), 'and hides the watched fields');

$html = render_screen('edit.php', array('__id_title' => 'Dune: Part Three', 'watched' => '1'));
s_ok(str_contains($html, 'Date watched'), 'the promotion reveals the watched fields');

$html = render_screen('edit.php', array('new' => '1', 'to' => 'to_watch', 'title' => 'Typed By Hand'));
s_ok(!str_contains($html, '<<BROKE>>'), 'edit.php renders a blank manual entry');
s_ok(str_contains($html, 'Typed By Hand'), 'and carries the typed title through');

/* ========================================================================= */
echo "\nTHE PUBLIC PAGE\n";

/* Signed OUT for this one. The public page must not depend on a session, and
 * must not behave differently with one. */
$html = render_screen('collection.php', array('__signed_out' => true));

s_ok(!str_contains($html, '<<BROKE>>'), 'collection.php renders signed out');
s_ok(str_contains($html, 'Arrival'), 'and shows the watched collection');

/* Every coming-soon and to-watch title the seed creates, by name. This is the
 * assertion the whole public/private split exists for.
 *
 * Listed literally rather than queried, deliberately. A list built from the
 * same database the page was rendered from would silently shrink to nothing if
 * the seed ever stopped creating private movies — and an empty list passes
 * this check while proving nothing. Hardcoding it means the day somebody
 * renames a seeded movie, this fails and gets looked at. */
$mustNotAppear = array(
    'Dune: Part Three', 'The Next One', 'Added Too Late', 'Out Last Week',
    'Untitled Sequel', 'Past Lives', 'Perfect Days', 'The Zone of Interest',
);

$leaked = array();
foreach ($mustNotAppear as $title) {
    if (str_contains($html, $title)) {
        $leaked[] = $title;
    }
}
s_ok($leaked === array(), 'no coming-soon or to-watch title appears on the public page'
    . ($leaked === array() ? '' : ' — LEAKED: ' . implode(', ', $leaked)));

/* Private notes, private links, private chrome. */
s_ok(!str_contains($html, 'Obviously.'), 'no private note appears on the public page');
s_ok(!str_contains($html, 'edit.php'), 'the public page links to no edit screen');
s_ok(!str_contains($html, 'add.php'), 'the public page links to no add screen');
s_ok(!str_contains($html, 'coming-soon.php'), 'the public page links to no coming-soon screen');
s_ok(!str_contains($html, 'watchlist.php'), 'the public page links to no watchlist');
s_ok(!str_contains($html, 'logout.php'), 'the public page carries no logout form');
s_ok(!str_contains($html, 'class="tabbar"'), 'the public page has no tab bar');
s_ok(!str_contains($html, 'class="fab"'), 'the public page has no add button');
s_ok(!str_contains($html, 'role="button"'), 'public tiles are inert, not fake buttons');

/* The exact date watched is private; the YEAR is public. The seed watches
 * Arrival 12 days ago, so that date must appear nowhere in the response —
 * computed here rather than imported from the seed, because this process
 * deliberately loads no app code beyond what it needs to shell out. */
$watchedOn = (new DateTimeImmutable('-12 days'))->format('Y-m-d');
s_ok(!str_contains($html, $watchedOn), 'the exact date watched is not published');

/* And it escapes, on the one surface where an XSS would be somebody else's
 * problem too. */
s_ok(!str_contains($html, '<script>alert(1)</script>'), 'the public page escapes a title containing markup');

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    ob_end_flush();
    exit(1);
}
ob_end_flush();
exit(0);
