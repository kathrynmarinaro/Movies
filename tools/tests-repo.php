<?php
/* Tests for the schema, the repo layer and — most importantly — the public
 * whitelist.
 *
 * Required by tools/run-tests.php, which owns the harness and the assertions. */

declare(strict_types=1);

/**
 * The schema's own constraints, and the shim's fidelity.
 *
 * These are the assertions that would catch somebody "simplifying" a CHECK or a
 * composite key out of schema.sql, which is exactly the kind of change that
 * looks harmless in a diff.
 */
function tests_schema(): void
{
    t_group('the schema rejects what it should');

    t_reset();

    $today = '2026-06-01';

    // Rating 0 and 6 are both illegal; 1-5 and NULL are not.
    $threw = false;
    try {
        q('INSERT INTO movies (title, rating) VALUES (?, ?)', array('Bad', 0));
    } catch (Throwable $e) {
        $threw = true;
    }
    t_ok($threw, 'a rating of 0 is rejected');

    $threw = false;
    try {
        q('INSERT INTO movies (title, rating) VALUES (?, ?)', array('Bad', 6));
    } catch (Throwable $e) {
        $threw = true;
    }
    t_ok($threw, 'a rating of 6 is rejected');

    $ok = true;
    try {
        q('INSERT INTO movies (title, rating) VALUES (?, ?)', array('Unrated', null));
        q('INSERT INTO movies (title, rating) VALUES (?, ?)', array('Five', 5));
    } catch (Throwable $e) {
        $ok = false;
    }
    t_ok($ok, 'NULL and 5 are both accepted');

    // An unknown status is rejected.
    $threw = false;
    try {
        q('INSERT INTO movies (title, status) VALUES (?, ?)', array('Bad', 'finished'));
    } catch (Throwable $e) {
        $threw = true;
    }
    t_ok($threw, 'an unknown status is rejected');

    /* tmdb_id is unique, but NULL is exempt — many manual entries coexist while
     * one TMDB movie can only be added once. Both halves matter. */
    q('INSERT INTO movies (title, tmdb_id) VALUES (?, ?)', array('TMDB A', 100));
    $threw = false;
    try {
        q('INSERT INTO movies (title, tmdb_id) VALUES (?, ?)', array('TMDB A again', 100));
    } catch (Throwable $e) {
        $threw = true;
    }
    t_ok($threw, 'the same TMDB movie cannot be added twice');

    $ok = true;
    try {
        q('INSERT INTO movies (title, tmdb_id) VALUES (?, ?)', array('Manual 1', null));
        q('INSERT INTO movies (title, tmdb_id) VALUES (?, ?)', array('Manual 2', null));
    } catch (Throwable $e) {
        $ok = false;
    }
    t_ok($ok, 'but any number of manual entries can');

    // The send ledger's composite key is the double-send guarantee.
    $id = (int) db()->lastInsertId();
    q('INSERT INTO movie_reminder_sends (movie_id, kind, trigger_date) VALUES (?, ?, ?)',
        array($id, 'heads_up', '2026-06-01'));
    $threw = false;
    try {
        q('INSERT INTO movie_reminder_sends (movie_id, kind, trigger_date) VALUES (?, ?, ?)',
            array($id, 'heads_up', '2026-06-01'));
    } catch (Throwable $e) {
        $threw = true;
    }
    t_ok($threw, 'the same send cannot be recorded twice');

    $ok = true;
    try {
        // A different kind, and a different date, are both different sends.
        q('INSERT INTO movie_reminder_sends (movie_id, kind, trigger_date) VALUES (?, ?, ?)',
            array($id, 'day_of', '2026-06-01'));
        q('INSERT INTO movie_reminder_sends (movie_id, kind, trigger_date) VALUES (?, ?, ?)',
            array($id, 'heads_up', '2026-09-01'));
    } catch (Throwable $e) {
        $ok = false;
    }
    t_ok($ok, 'a different kind or trigger date is a different send');

    // Deleting a movie takes its genre links and ledger rows with it.
    t_group('cascades');
    t_reset();

    $id = movie_save(array('title' => 'Cascade', 'status' => 'watched'), null, $today);
    genres_set($id, array('Drama', 'Sci-Fi'));
    q('INSERT INTO movie_reminder_sends (movie_id, kind, trigger_date) VALUES (?, ?, ?)',
        array($id, 'day_of', $today));

    t_is((int) q('SELECT COUNT(*) FROM movie_genres')->fetchColumn(), 2, 'genre links exist');

    movie_delete($id);

    t_is((int) q('SELECT COUNT(*) FROM movie_genres')->fetchColumn(), 0,
        'deleting a movie clears its genre links');
    t_is((int) q('SELECT COUNT(*) FROM movie_reminder_sends')->fetchColumn(), 0,
        'and its ledger rows');
    t_is((int) q('SELECT COUNT(*) FROM genres')->fetchColumn(), 2,
        'but the genres themselves survive');

    /* The shim in tools/bootstrap-shim.php must not drift from the real
     * bootstrap. Compared as normalised source text, which catches an edit to
     * one copy and not the other. */
    t_group('the test shim matches lib/bootstrap.php');

    $real = (string) @file_get_contents(dirname(__DIR__) . '/lib/bootstrap.php');
    $shim = (string) @file_get_contents(__DIR__ . '/bootstrap-shim.php');

    foreach (array('cfg', 'h', 'normalize_tag', 'fmt_date', 'asset') as $fn) {
        $a = t_extract_function($real, $fn);
        $b = t_extract_function($shim, $fn);
        t_ok($a !== null && $a === $b, $fn . '() is identical in both files');
    }
}

/**
 * One function's normalised body out of a PHP source string, or null.
 *
 * Brace-counted rather than regex-matched, because a function containing a
 * string with a brace in it is exactly the case a regex gets wrong.
 */
function t_extract_function(string $source, string $name): ?string
{
    $at = strpos($source, 'function ' . $name . '(');
    if ($at === false) {
        return null;
    }
    $open = strpos($source, '{', $at);
    if ($open === false) {
        return null;
    }

    $depth = 0;
    $len   = strlen($source);
    for ($i = $open; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                $body = substr($source, $at, $i - $at + 1);
                return preg_replace('/\s+/', ' ', $body);
            }
        }
    }
    return null;
}

function tests_repo(): void
{
    $today = '2026-06-01';

    /* ------------------------------------------------------------- sections */

    t_group('the three sections are one table');

    t_reset();

    $watched = movie_save(array(
        'title' => 'Arrival', 'year' => 2016, 'status' => 'watched',
        'rating' => 5, 'date_watched' => '2026-05-20', 'is_favorite' => 1,
    ), null, $today);

    $soon = movie_save(array(
        'title' => 'Dune: Part Three', 'status' => 'coming_soon',
        'release_date' => '2026-12-18', 'notes' => 'obviously',
    ), null, $today);

    $later = movie_save(array(
        'title' => 'Paddington 4', 'status' => 'to_watch',
        'notes' => 'Sam says it is the best one',
    ), null, $today);

    t_is(count(movies_watched()), 1, 'the watched grid shows one movie');
    t_is(count(movies_coming_soon()), 1, 'coming soon shows one movie');
    t_is(count(movies_to_watch()), 1, 'to watch shows one movie');

    /* THE PROMOTION. One UPDATE — the id, the genre links and the poster all
     * survive, which is the entire payoff of the one-table decision. */
    t_group('promotion keeps the movie');

    genres_set($soon, array('Sci-Fi', 'Adventure'));
    movie_save(array('poster_path' => 'posters/abc123.jpg'), $soon, $today);

    movie_set_status($soon, 'watched', array(
        'date_watched' => $today, 'rating' => 5, 'is_rewatchable' => 1,
    ), $today);

    $after = movie_get($soon);
    t_is((int) $after['id'], $soon, 'the movie keeps its id');
    t_is($after['status'], 'watched', 'and is now watched');
    t_is($after['poster_path'], 'posters/abc123.jpg', 'and keeps its poster');
    t_is(count($after['genres']), 2, 'and keeps its genres');
    t_is(count(movies_coming_soon()), 0, 'it has left the coming soon list');
    t_is(count(movies_watched()), 2, 'and joined the watched grid');

    /* --------------------------------------------------------------- genres */

    t_group('genres normalise and deduplicate');

    t_reset();
    $id = movie_save(array('title' => 'Genres', 'status' => 'watched'), null, $today);

    // Same genre in three casings plus a duplicate: one row.
    genres_set($id, array('Science Fiction', 'science fiction', 'SCIENCE FICTION', 'Drama'));
    $got = genre_names(movie_get($id)['genres']);
    sort($got);
    t_is($got, array('drama', 'science fiction'), 'casing collapses to one genre');

    // Re-setting replaces rather than appends.
    genres_set($id, array('Horror'));
    t_is(genre_names(movie_get($id)['genres']), array('horror'), 'setting genres replaces them');

    /* -------------------------------------------------------------- filters */

    t_group('the watched filters');

    t_reset();
    $a = movie_save(array('title' => 'Five Star', 'status' => 'watched', 'rating' => 5,
        'date_watched' => '2026-05-01', 'is_favorite' => 1), null, $today);
    $b = movie_save(array('title' => 'Two Star', 'status' => 'watched', 'rating' => 2,
        'date_watched' => '2026-05-02', 'is_rewatchable' => 1), null, $today);
    $c = movie_save(array('title' => 'Unrated', 'status' => 'watched',
        'date_watched' => '2026-05-03'), null, $today);

    t_is(count(movies_watched(array('stars-5'))), 1, 'five stars matches one');
    t_is(count(movies_watched(array('favorites'))), 1, 'favorites matches one');
    t_is(count(movies_watched(array('rewatchable'))), 1, 'rewatchable matches one');
    t_is(count(movies_watched(array('unrated'))), 1, 'unrated matches one');

    /* THE ONE THAT MATTERS: an unrated movie is not "3 stars or fewer". NULL
     * means no opinion was recorded and sweeping it into the lowest bucket
     * would invent one. */
    $low = movies_watched(array('stars-low'));
    t_is(count($low), 1, '"3 or fewer" matches only the rated low movie');
    t_is($low[0]['title'], 'Two Star', 'and it is the two-star one, not the unrated one');

    // An unknown filter shows everything rather than erroring.
    t_is(count(movies_watched(array('nonsense-filter'))), 3,
        'an unknown filter from a stale URL shows the whole collection');

    // Filters AND together.
    t_is(count(movies_watched(array('favorites', 'stars-5'))), 1, 'two filters AND');
    t_is(count(movies_watched(array('favorites', 'rewatchable'))), 0,
        'and can legitimately match nothing');

    /* ---------------------------------------------------------- sort order */

    t_group('sort order');

    t_reset();
    movie_save(array('title' => 'Older', 'status' => 'watched', 'date_watched' => '2026-01-01'), null, $today);
    movie_save(array('title' => 'Newer', 'status' => 'watched', 'date_watched' => '2026-05-01'), null, $today);
    movie_save(array('title' => 'Undated', 'status' => 'watched'), null, $today);

    $order = array_column(movies_watched(), 'title');
    t_is($order, array('Newer', 'Older', 'Undated'),
        'watched is newest first, with undated movies last rather than missing');

    t_reset();
    movie_save(array('title' => 'Later', 'status' => 'coming_soon', 'release_date' => '2026-12-01'), null, $today);
    movie_save(array('title' => 'Sooner', 'status' => 'coming_soon', 'release_date' => '2026-07-01'), null, $today);
    movie_save(array('title' => 'TBA', 'status' => 'coming_soon'), null, $today);

    $order = array_column(movies_coming_soon(), 'title');
    t_is($order, array('Sooner', 'Later', 'TBA'),
        'coming soon is soonest first, with undated films last');

    /* ============================================================== THE ONE
     * THAT MATTERS MOST. The public surface must never carry a movie that is
     * not watched, and must never carry a private field. */

    t_group('the public whitelist');

    t_reset();

    movie_save(array(
        'title' => 'Public Movie', 'year' => 2024, 'status' => 'watched',
        'rating' => 4, 'date_watched' => '2026-05-20', 'notes' => 'lovely',
        'poster_path' => 'posters/pub.jpg', 'is_favorite' => 1, 'is_rewatchable' => 1,
        'tmdb_id' => 42,
    ), null, $today);

    $secret1 = movie_save(array(
        'title' => 'SECRET COMING SOON', 'status' => 'coming_soon',
        'release_date' => '2026-12-18', 'notes' => 'SECRET NOTE',
    ), null, $today);

    $secret2 = movie_save(array(
        'title' => 'SECRET TO WATCH', 'status' => 'to_watch', 'notes' => 'SECRET NOTE',
    ), null, $today);

    $public = movies_public();

    t_is(count($public), 1, 'the public list contains only the watched movie');

    $json = json_encode($public);
    t_ok(!str_contains($json, 'SECRET COMING SOON'), 'no coming-soon title reaches the public list');
    t_ok(!str_contains($json, 'SECRET TO WATCH'), 'no to-watch title reaches the public list');
    t_ok(!str_contains($json, 'SECRET NOTE'), 'no private note reaches the public list');

    /* The whitelist is built from an explicit list, so a column added to
     * `movies` later is private by default. Asserting the exact key set is what
     * makes that promise testable — this fails the day somebody switches it to
     * unset()-ing fields from the row. */
    $keys = array_keys($public[0]);
    sort($keys);
    t_is($keys, array(
        'genres', 'id', 'is_favorite', 'notes', 'poster_path', 'rating', 'title', 'year',
    ), 'the public shape is exactly the whitelisted keys and nothing else');

    foreach (array('status', 'tmdb_id', 'date_watched', 'release_date', 'is_rewatchable',
                   'day_of_reminder', 'heads_up_eligible') as $private) {
        t_ok(!array_key_exists($private, $public[0]), $private . ' is not public');
    }

    /* --------------------------------------------------- render fail-soft */

    t_group('rendering degrades rather than breaking');

    $card = render_movie_card(array('id' => 1, 'title' => 'No Poster', 'poster_path' => null));
    t_ok(str_contains($card, 'poster-none'), 'a movie with no poster renders a placeholder');
    t_ok(str_contains($card, 'No Poster'), 'and the placeholder carries the title');

    /* An unlinked tile must NOT claim to be a button. The public page has no
     * JS, so role="button" there would announce an interaction that does not
     * exist. */
    t_ok(!str_contains($card, 'role="button"'), 'an unlinked tile is inert, not a fake button');

    t_is(render_stars(null), '', 'an unrated movie renders no stars at all');
    t_is(render_stars(0), '', 'and neither does a stray zero');
    t_ok(str_contains(render_stars(3), 'aria-label="3 out of 5 stars"'), 'a rating is labelled');

    // Titles are escaped on the way out.
    $xss = render_movie_card(array('id' => 1, 'title' => '<script>alert(1)</script>'));
    t_ok(!str_contains($xss, '<script>'), 'a title carrying markup is escaped');

    t_is(render_providers(array(), 'https://image.tmdb.org/t/p/'),
        '<p class="hint">Not on any subscription service right now.</p>',
        'no streaming services renders a sentence, not an empty box');
}
