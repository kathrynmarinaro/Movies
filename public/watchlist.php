<?php
/* To Watch — the manually curated list of released films not yet seen.
 *
 * MANUALLY CURATED, per the brief. This is not a recommendation engine and
 * there is nothing algorithmic here: things arrive by being added, the same
 * way they do on the other two screens.
 *
 * Newest first, because the most recent recommendation is the one you are most
 * likely to be looking for. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/layout.php';

require_admin();

$movies = movies_to_watch();

page_head('To Watch', 'watchlist');
screen_head('To Watch', page_menu());
?>

<?= render_movie_grid(
    $movies,
    static fn(array $m): string => 'movie.php?id=' . (int) $m['id'],
    static fn(array $m): array  => array('sub' => render_year_sub(
        $m['year'] === null ? null : (int) $m['year']
    )),
    'Nothing on the list. Tap + to add something you have been meaning to see.'
) ?>

<a href="add.php?to=to_watch" class="fab" aria-label="Add a movie">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</a>

<?php page_foot('watchlist'); ?>
