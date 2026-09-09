<?php
/* Development data.
 *
 *   php tools/seed.php --reset
 *
 * NO NETWORK CALLS. Posters are drawn locally, so seeding works offline, costs
 * no TMDB quota, and produces the same collection every time.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS IN HERE IS CHOSEN TO BREAK LAYOUTS.
 * ---------------------------------------------------------------------------
 *
 * A seed of twenty pleasant rows proves nothing. This one deliberately
 * includes every case that has broken a grid in a sibling app or that this
 * app's own logic branches on:
 *
 *   - a movie with NO poster (renders the titled placeholder)
 *   - an UNRATED watched movie beside a one-star one (must look different)
 *   - a very long title and a one-word title in the same grid
 *   - a title carrying an apostrophe and one carrying markup
 *   - a watched movie with NO date (must sort last, not vanish)
 *   - a Coming Soon movie releasing TOMORROW, in a WEEK (its heads-up is due
 *     today), and one whose date has already PASSED
 *   - a Coming Soon movie with NO release date at all
 *   - a LATE ADD: released in three days, added today, so heads_up_eligible
 *     is 0 and no week-ahead email should ever fire for it
 *   - one movie with day_of_reminder on and one with it off
 *
 * The three reminder cases are why this file passes $today explicitly rather
 * than letting movie_save() default it: heads_up_eligible is computed at save
 * time, so a seed that used the real clock would produce a different database
 * depending on the day you ran it. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';

/**
 * Draw a poster locally and return its stored path, or null.
 *
 * A flat colour block with the title's initials, written as a real PNG through
 * GD — so it is a genuine image file that poster_is_real_image() would accept,
 * not a stub. Without GD the seed still works and the movie simply has no
 * poster, which is itself one of the cases worth seeing.
 */
function seed_poster(string $title, int $index): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $w = 300;
    $h = 450;
    $im = imagecreatetruecolor($w, $h);

    /* Spread the hues so a grid of seeded posters is visually distinguishable
     * at a glance — which is the point of having art on the cards at all. */
    $hue = ($index * 47) % 360;
    list($r, $g, $b) = seed_hsl_to_rgb($hue / 360, 0.45, 0.42);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, $r, $g, $b));

    $white = imagecolorallocate($im, 255, 255, 255);
    $initials = '';
    foreach (preg_split('/\s+/', $title) as $word) {
        if ($word !== '' && preg_match('/^[A-Za-z0-9]/', $word)) {
            $initials .= strtoupper($word[0]);
        }
        if (strlen($initials) >= 3) {
            break;
        }
    }
    if ($initials === '') {
        $initials = '?';
    }
    imagestring($im, 5, (int) (($w - strlen($initials) * 9) / 2), (int) ($h / 2) - 8, $initials, $white);

    ob_start();
    imagepng($im);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    if (!is_dir(POSTER_DIR) && !@mkdir(POSTER_DIR, 0755, true) && !is_dir(POSTER_DIR)) {
        return null;
    }
    $name = 'seed-' . $index . '.png';
    if (@file_put_contents(POSTER_DIR . '/' . $name, $bytes) === false) {
        return null;
    }

    return trim((string) cfg('posters.dir', 'posters'), '/') . '/' . $name;
}

/** HSL to RGB, 0-1 in, 0-255 out. Only used to spread the seed poster hues. */
function seed_hsl_to_rgb(float $h, float $s, float $l): array
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h * 6, 2) - 1));
    $m = $l - $c / 2;

    $seg = (int) floor($h * 6) % 6;
    list($r, $g, $b) = match ($seg) {
        0 => array($c, $x, 0.0),
        1 => array($x, $c, 0.0),
        2 => array(0.0, $c, $x),
        3 => array(0.0, $x, $c),
        4 => array($x, 0.0, $c),
        default => array($c, 0.0, $x),
    };

    return array(
        (int) round(($r + $m) * 255),
        (int) round(($g + $m) * 255),
        (int) round(($b + $m) * 255),
    );
}

/** Y-m-d, $days from $today. */
function seed_date(string $today, int $days): string
{
    return (new DateTimeImmutable($today, new DateTimeZone('UTC')))
        ->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/**
 * Fill an empty database. Returns how many movies were written.
 *
 * Declared as a function so tools/smoke-screens.php can call it against the
 * in-memory database without this file running itself. Same reasoning as
 * lib/cron.php's: one definition of the seed, two callers.
 */
function seed_run(string $today, bool $withPosters = true): int
{
    /* ------------------------------------------------------------ watched */

    $watched = array(
        array('Arrival', 2016, 5, 12, array('Science Fiction', 'Drama'), 1, 1,
            'Still thinking about the ending.'),
        array('Paddington 2', 2017, 5, 40, array('Comedy', 'Family'), 1, 1,
            'Perfect. No notes.'),
        array('The Lighthouse', 2019, 3, 95, array('Horror', 'Drama'), 0, 0, ''),
        array('Tenet', 2020, 2, 200, array('Action', 'Science Fiction'), 0, 0,
            'Could not hear a word of it.'),
        // A one-star film beside an unrated one: they must not look the same.
        array('The Room', 2003, 1, 320, array('Drama'), 0, 1, 'Watched as a joke. Stayed for the joke.'),
        // UNRATED — renders no stars at all, which is the point.
        array('Aftersun', 2022, null, 60, array('Drama'), 0, 0, 'Need to sit with this before rating it.'),
        // A very long title, in a 96px grid column.
        array('Everything Everywhere All at Once and Then Some More Besides', 2022, 5, 150,
            array('Science Fiction', 'Comedy'), 1, 1, ''),
        // One word.
        array('Us', 2019, 4, 220, array('Horror'), 0, 0, ''),
        // An apostrophe, which is what breaks a naive SQL escape.
        array("Ocean's Eleven", 2001, 4, 400, array('Comedy', 'Crime'), 0, 1, ''),
        // Markup in a title, which is what breaks a template missing an h().
        array('<script>alert(1)</script>', 2024, 2, 410, array('Horror'), 0, 0,
            'Not a real film. It is here so a missing h() shows up on screen.'),
    );

    $index = 0;
    foreach ($watched as list($title, $year, $rating, $daysAgo, $genres, $fav, $rewatch, $notes)) {
        $id = movie_save(array(
            'title'          => $title,
            'year'           => $year,
            'status'         => 'watched',
            'rating'         => $rating,
            'date_watched'   => seed_date($today, -$daysAgo),
            'is_favorite'    => $fav,
            'is_rewatchable' => $rewatch,
            'notes'          => $notes,
            'source'         => 'manual',
            'poster_path'    => $withPosters ? seed_poster($title, $index) : null,
        ), null, $today);
        genres_set($id, $genres);
        $index++;
    }

    /* A watched movie with NO poster — TMDB doesn't have art for everything,
     * and the titled placeholder is a normal end state. */
    $id = movie_save(array(
        'title' => 'A Festival Film Nobody Has Heard Of', 'year' => 2025,
        'status' => 'watched', 'rating' => 4, 'date_watched' => seed_date($today, -20),
        'source' => 'manual', 'poster_path' => null,
    ), null, $today);
    genres_set($id, array('Documentary'));

    /* A watched movie with NO DATE. It must sort LAST rather than vanishing —
     * dropping it from the only screen that lists watched movies would hide it
     * forever. */
    movie_save(array(
        'title' => 'Something I Forgot To Date', 'year' => 2018,
        'status' => 'watched', 'rating' => 3, 'date_watched' => null,
        'source' => 'manual', 'poster_path' => $withPosters ? seed_poster('Forgot', 90) : null,
    ), null, $today);

    /* -------------------------------------------------------- coming soon */

    /* Releasing in exactly the lead window, added long ago: its heads-up is
     * DUE TODAY. This is the row that makes `php tools/cron-reminders.php
     * --dry-run` show something. */
    $id = movie_save(array(
        'title' => 'Dune: Part Three', 'year' => null, 'status' => 'coming_soon',
        'release_date' => seed_date($today, reminders_lead_days()),
        'tmdb_id' => 900001, 'source' => 'tmdb',
        'notes' => 'Obviously.', 'day_of_reminder' => 1,
        'poster_path' => $withPosters ? seed_poster('Dune Three', 20) : null,
    ), null, seed_date($today, -120));
    genres_set($id, array('Science Fiction', 'Adventure'));

    // Tomorrow.
    $id = movie_save(array(
        'title' => 'The Next One', 'status' => 'coming_soon',
        'release_date' => seed_date($today, 1),
        'source' => 'manual', 'day_of_reminder' => 1,
        'poster_path' => $withPosters ? seed_poster('Next One', 21) : null,
    ), null, seed_date($today, -60));
    genres_set($id, array('Thriller'));

    /* A LATE ADD: out in three days, added today. heads_up_eligible must be 0
     * and no week-ahead email may ever fire for it. */
    $id = movie_save(array(
        'title' => 'Added Too Late', 'status' => 'coming_soon',
        'release_date' => seed_date($today, 3),
        'source' => 'manual', 'day_of_reminder' => 0,
        'poster_path' => $withPosters ? seed_poster('Too Late', 22) : null,
    ), null, $today);
    genres_set($id, array('Comedy'));

    /* Already out and still sitting here. NOT an error state — this is the
     * pile the Coming Soon screen exists to surface. */
    $id = movie_save(array(
        'title' => 'Out Last Week', 'status' => 'coming_soon',
        'release_date' => seed_date($today, -6),
        'source' => 'manual',
        'poster_path' => $withPosters ? seed_poster('Out Last Week', 23) : null,
    ), null, seed_date($today, -40));
    genres_set($id, array('Drama'));

    /* Announced, undated. TMDB serves an empty string for these; the app
     * stores NULL. Must sort LAST on the coming-soon screen and render
     * "Date TBA" rather than a fake date. */
    $id = movie_save(array(
        'title' => 'Untitled Sequel', 'status' => 'coming_soon',
        'release_date' => null, 'source' => 'manual',
        'notes' => 'No date announced yet.',
        'poster_path' => null,
    ), null, $today);
    genres_set($id, array('Action'));

    /* ----------------------------------------------------------- to watch */

    $toWatch = array(
        array('Past Lives', 2023, array('Drama', 'Romance'), 'Alex keeps bringing it up.'),
        array('Perfect Days', 2023, array('Drama'), ''),
        array('The Zone of Interest', 2023, array('Drama', 'History'), 'Heard it is hard going.'),
    );

    $index = 40;
    foreach ($toWatch as list($title, $year, $genres, $notes)) {
        $id = movie_save(array(
            'title' => $title, 'year' => $year, 'status' => 'to_watch',
            'notes' => $notes, 'source' => 'manual',
            'poster_path' => $withPosters ? seed_poster($title, $index) : null,
        ), null, $today);
        genres_set($id, $genres);
        $index++;
    }

    return (int) q('SELECT COUNT(*) FROM movies')->fetchColumn();
}

/** Empty every table. Only ever called behind --reset. */
function seed_reset(): void
{
    /* movies first: the genre links, the ledger and the provider cache all
     * cascade off it, so this is one statement rather than four. */
    q('DELETE FROM movies');
    q('DELETE FROM genres');
}

/* ------------------------------------------------------------- entry point */

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (!in_array('--reset', $argv, true)) {
        fwrite(STDERR, "Refusing to seed on top of existing data.\n"
            . "Run with --reset to empty the database first:\n\n"
            . "    php tools/seed.php --reset\n\n");
        exit(1);
    }

    $seedToday = movies_today();
    seed_reset();
    $count = seed_run($seedToday);

    fwrite(STDOUT, sprintf(
        "Seeded %d movies against today = %s.\n"
        . "One Coming Soon film is %d days out, so its week-ahead reminder is due today —\n"
        . "try:  php tools/cron-reminders.php --dry-run\n",
        $count,
        $seedToday,
        reminders_lead_days()
    ));
}
