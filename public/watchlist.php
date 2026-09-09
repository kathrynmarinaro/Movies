<?php
/* To Watch — everything not yet seen, in two sections.
 *
 * ---------------------------------------------------------------------------
 * ONE SCREEN, TWO SECTIONS, AND THE SECTION IS NOT A CHOICE.
 * ---------------------------------------------------------------------------
 *
 * Coming Soon and To Watch were two tabs. They are one screen because they are
 * one question — "what am I going to watch?" — and the difference between them
 * is a fact about the calendar rather than a decision anybody makes:
 *
 *   still in theatres, or not out yet  ->  Coming Soon
 *   out of theatres                    ->  To Watch
 *
 * movie_section() in lib/repo.php owns that rule and is the only thing allowed
 * to decide it. A film crosses from one section to the other because a DAY
 * PASSED, so something has to notice — see the movies_resettle() call below.
 *
 * COMING SOON IS FIRST, and within it the soonest release is first. That is
 * the half with a deadline attached: a film opening on Friday is a thing you
 * can miss, and the list you can get to whenever is not.
 *
 * public/coming-soon.php still exists and redirects here, so an old bookmark
 * or a link in a sent reminder email does not 404. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/layout.php';

require_admin();

/* ONE movies_today() for the whole render, so every countdown on the screen
 * agrees with every other one. A grid computing its own per card could
 * straddle midnight and show two different answers for two films opening on
 * the same day. */
$today = movies_today();

/* Re-settle before reading, so the page is right the moment you open it.
 *
 * The daily cron does this too, and that is the real mechanism — but a page
 * that is up to a day stale would show a film in Coming Soon that left
 * theatres last night, and on a plan where the cron was never set up it would
 * be wrong forever. A single UPDATE affecting zero rows on almost every call
 * is a cheap way to never have to explain either. */
movies_resettle($today);

$comingSoon = movies_coming_soon();
$toWatch    = movies_to_watch();

page_head('To Watch', 'watchlist');
screen_head('To Watch', page_menu());
?>

<?php if ($comingSoon !== array()): ?>
  <h2 class="section-title">Coming Soon</h2>
  <?= render_movie_grid(
      $comingSoon,
      static fn(array $m): string => 'movie.php?id=' . (int) $m['id'],
      static function (array $m) use ($today): array {
          $days = days_until($m['release_date'] ?? null, $today);
          return array(
              // The date answers "can I plan around this"; the countdown
              // answers "is it soon". Different questions, both worth a line.
              'sub'           => h(fmt_date($m['release_date'] ?? null, 'M j, Y')),
              'countdown'     => fmt_countdown($m['release_date'] ?? null, $today),
              'countdown_out' => $days !== null && $days < 0,
          );
      }
  ) ?>
<?php endif; ?>

<?php /* The second heading is shown only when the first one was, so a screen
         with nothing coming up isn't a lone "To Watch" heading under a title
         that already says To Watch. */ ?>
<?php if ($comingSoon !== array() && $toWatch !== array()): ?>
  <h2 class="section-title">Out now</h2>
<?php endif; ?>

<?= render_movie_grid(
    $toWatch,
    static fn(array $m): string => 'movie.php?id=' . (int) $m['id'],
    static fn(array $m): array  => array('sub' => render_year_sub(
        $m['year'] === null ? null : (int) $m['year']
    )),
    $comingSoon === array()
        ? 'Nothing on the list. Tap + to add something you have been meaning to see.'
        : ''
) ?>

<?php /* The add button does NOT ask which section — movie_section() decides
         from the release date on save. Adding is one tap and one decision
         fewer, which is the point of merging the two screens. */ ?>
<a href="add.php?to=to_watch" class="fab" aria-label="Add a movie">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
</a>

<?php page_foot('watchlist'); ?>
