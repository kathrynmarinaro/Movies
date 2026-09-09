<?php
/* The Watched grid — the private home screen.
 *
 * NOT the public page. public/collection.php is that, it is a separate file,
 * and it shares no chrome with this one. See docs/CONTRACTS.md §6: a public
 * page that shares no code with the private chrome cannot leak the private
 * chrome.
 *
 * NO YEAR DIVIDERS, per the brief. Book Tracker's collection groups by year;
 * this one is a plain reverse-chronological grid. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/layout.php';

require_admin();

/* Filters arrive as ?f=id, parsed as a comma-separated LIST even though the UI
 * offers one at a time. The query layer already ANDs a set (see
 * watched_filter_sql), so going multi-select later is a change to this screen
 * and not to the URL scheme — and old single-filter links keep working. */
$active = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($_GET['f'] ?? ''))
)));

$movies = movies_watched($active);
$genres = genres_all();

page_head('Watched', 'watched');
screen_head('Watched', page_menu());
?>

<?= render_filter_chips($active, $genres, WATCHED_FILTERS) ?>

<?= render_movie_grid(
    $movies,
    static fn(array $m): string => 'movie.php?id=' . (int) $m['id'],
    static fn(array $m): array  => array('sub' => render_year_sub(
        $m['year'] === null ? null : (int) $m['year']
    )),
    $active === array()
        ? 'Nothing watched yet. Tap + to add the first one.'
        : 'No movies match that filter.'
) ?>

<a href="add.php?to=watched" class="fab" aria-label="Add a movie">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</a>

<?php page_foot('watched'); ?>
