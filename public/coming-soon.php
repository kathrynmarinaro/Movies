<?php
/* Coming Soon — unreleased movies, with a countdown and the reminder toggles.
 *
 * Sorted soonest first, with undated films last. Movies whose release date has
 * already passed STAY HERE, marked as out, rather than being moved or hidden:
 * that is the pile this screen exists to surface, and the true statement about
 * the world. See schema.sql on movies.status. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/layout.php';

require_admin();

/* ONE movies_today() for the whole render, so every countdown on the screen
 * agrees with every other one. A grid that computed its own per card could
 * straddle midnight and show two different answers for two movies releasing on
 * the same day. */
$today  = movies_today();
$movies = movies_coming_soon();

page_head('Coming Soon', 'coming-soon');
screen_head('Coming Soon', page_menu());
?>

<?= render_movie_grid(
    $movies,
    static fn(array $m): string => 'movie.php?id=' . (int) $m['id'],
    static function (array $m) use ($today): array {
        $days = days_until($m['release_date'] ?? null, $today);
        return array(
            // The date itself under the title, the countdown under that. Both,
            // because "March 20" answers "can I plan around this" and "In 12
            // days" answers "is it soon", and they are different questions.
            'sub'           => h(fmt_date($m['release_date'] ?? null, 'M j, Y')),
            'countdown'     => fmt_countdown($m['release_date'] ?? null, $today),
            'countdown_out' => $days !== null && $days < 0,
        );
    },
    'Nothing on the horizon. Tap + to add something you are waiting for.'
) ?>

<a href="add.php?to=coming_soon" class="fab" aria-label="Add a movie">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</a>

<?php page_foot('coming-soon'); ?>
