<?php
/* ============================================================================
 * THE PUBLIC PAGE. The only screen in this app a stranger may see.
 * ============================================================================
 *
 * A separate, unauthenticated route showing the watched collection and nothing
 * else. No Coming Soon, no To Watch, no notes on anything private, no edit
 * controls, and nothing linking to a private screen.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A SEPARATE FILE AND NOT A FLAG ON index.php.
 * ---------------------------------------------------------------------------
 *
 * Book Tracker renders both its surfaces from one template with a $private
 * boolean. That works, and its own docs explain the care it takes — but it
 * means every future edit to that file has to be reasoned about twice, once
 * for the surface that may link to add.php and once for the surface that must
 * never mention it. The brief for this app asks for a separate route, and for
 * this app it is also the safer shape: a page that shares no chrome with the
 * private screens cannot leak the private chrome by accident.
 *
 * So this file deliberately does NOT use lib/layout.php. page_head() and
 * page_foot() render the tab bar, the hamburger and the logout form, and the
 * tab bar IS a list of the private URLs.
 *
 * ---------------------------------------------------------------------------
 * THREE LAYERS BETWEEN THIS PAGE AND THE PRIVATE DATA, AND EACH IS ENOUGH.
 * ---------------------------------------------------------------------------
 *
 *   1. movies_public() calls movies_watched(), whose query says
 *      `WHERE status = 'watched'`. THE STATUS FILTER IS IN THE QUERY, not in a
 *      template — a template-level filter is one edit away from publishing the
 *      Coming Soon list.
 *   2. Every row goes through movie_public(), which builds a NEW array from an
 *      explicit whitelist rather than removing fields from the row. A column
 *      added to `movies` next year is private by default.
 *   3. This file has no way to ask for another status. movies_public() takes
 *      no parameter, so there is nothing to pass one through.
 *
 * tools/tests-repo.php asserts all three, including that a seeded coming-soon
 * title cannot appear in the output.
 *
 * ---------------------------------------------------------------------------
 * NOT NOINDEX. This is the one page that SHOULD be indexed — it is the point
 * of having it. Every private entry point calls noindex() from inside
 * require_admin(); this one deliberately does not.
 * ========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/render.php';

$movies = movies_public();
$count  = count($movies);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#41b7ab">
<title>Movies I've watched</title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
</head>
<body>
<main class="wrap wrap-public">
  <h1 class="public-head">Movies I've watched</h1>
  <p class="public-sub"><?= $count === 1 ? '1 film' : $count . ' films' ?></p>

<?php
/* NO href AND NO onclick. render_movie_card() renders an unlinked tile as an
 * inert <div> — not a role="button" — precisely for this page: there is no
 * JavaScript here at all, so a tile that announced itself as a button would
 * promise an interaction that does not exist.
 *
 * Book Tracker's CONTRACTS.md flags this as the thing to fix before publishing
 * its own to-read tab. It is fixed here rather than inherited. */
echo render_movie_grid(
    $movies,
    null,
    static fn(array $m): array => array('sub' => render_year_sub($m['year'])),
    'Nothing here yet.'
);
?>
</main>
</body>
</html>
