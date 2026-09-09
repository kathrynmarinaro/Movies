<?php
/* One movie: everything known about it, plus streaming availability.
 *
 * Reached by tapping a poster on any of the three list screens. What it shows
 * depends on the section the movie is in, because the fields differ — a
 * watched movie has a rating and a date, a coming-soon one has a countdown and
 * reminder toggles, a to-watch one has neither. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/tmdb.php';
require_once __DIR__ . '/../lib/reminders.php';
require_once __DIR__ . '/../lib/layout.php';

require_admin();

$id    = (int) ($_GET['id'] ?? 0);
$movie = $id > 0 ? movie_get($id) : null;

if ($movie === null) {
    http_response_code(404);
    page_head('Not found');
    screen_head('Not found');
    echo '<p class="empty">That movie is not here. It may have been deleted.</p>';
    page_foot();
    exit;
}

$today  = movies_today();
$status = (string) $movie['status'];

/* Streaming availability, from the cache, refreshed only when stale. This is
 * the one screen that may make a network call, and only when the cached answer
 * has aged past tmdb.providers_ttl_days — so opening a movie you looked at
 * yesterday costs nothing. See providers_for_movie(). */
$providers = providers_for_movie(
    $id,
    $movie['tmdb_id'] === null ? null : (int) $movie['tmdb_id']
);

/* Which tab to leave lit, so the bar reflects where you came from. */
$tab = match ($status) {
    /* Both unwatched sections live on watchlist.php now, so they light the
     * same tab. */
    'coming_soon' => 'watchlist',
    'to_watch'    => 'watchlist',
    default       => 'watched',
};

/* The answer from a "Check release date" round trip, as a flash. Whitelisted
 * against a match rather than echoed, so nothing from the URL reaches the page
 * — this is the only user-supplied value rendered on this screen. */
$checkedFlash = match ((string) ($_GET['checked'] ?? '')) {
    'moved'       => 'TMDB has moved this release date. It has been updated, and the '
                   . 'reminder was rescheduled to match.',
    'unchanged'   => 'TMDB still has the same release date.',
    'unreachable' => 'Could not reach TMDB just now. The date is unchanged.',
    default       => '',
};

page_head((string) $movie['title'], $tab);
screen_head((string) $movie['title'], page_menu());
?>

<?php if ($checkedFlash !== ''): ?>
  <p class="hint" role="status"><?= h($checkedFlash) ?></p>
<?php endif; ?>

<div class="row" style="gap:16px; align-items:flex-start">
  <div style="flex:0 0 120px">
    <span class="poster-art" style="display:block">
    <?php if (!empty($movie['poster_path'])): ?>
      <img class="poster" src="<?= h((string) $movie['poster_path']) ?>" alt="" decoding="async">
    <?php else: ?>
      <span class="poster poster-none"><span><?= h((string) $movie['title']) ?></span></span>
    <?php endif; ?>
    </span>
  </div>

  <div class="stack" style="flex:1; min-width:0; gap:8px">
    <?php if ($movie['year'] !== null): ?>
      <p class="muted" style="margin:0"><?= (int) $movie['year'] ?></p>
    <?php endif; ?>

    <?php if ($status === 'watched'): ?>
      <?php /* An unrated movie renders NOTHING here — not five hollow stars.
               See render_stars(): logging a movie now and rating it later is a
               normal thing to do. */ ?>
      <?= render_stars($movie['rating'] === null ? null : (int) $movie['rating']) ?>
      <?= render_favorite((bool) $movie['is_favorite']) ?>
      <?php $watched = fmt_date($movie['date_watched'] ?? null); ?>
      <?php if ($watched !== ''): ?>
        <p class="hint" style="margin:0">Watched <?= h($watched) ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($status === 'coming_soon'): ?>
      <?php $days = days_until($movie['release_date'] ?? null, $today); ?>
      <p class="poster-countdown<?= $days !== null && $days < 0 ? ' is-out' : '' ?>" style="margin:0">
        <?= h(fmt_countdown($movie['release_date'] ?? null, $today)) ?>
      </p>
      <?php $release = fmt_date($movie['release_date'] ?? null); ?>
      <p class="hint" style="margin:0">
        <?= $release === '' ? 'No release date announced yet.' : h($release) ?>
      </p>
    <?php endif; ?>

    <?= render_genre_chips(genre_names($movie['genres']), (bool) $movie['is_rewatchable']) ?>
  </div>
</div>

<?php if (trim((string) ($movie['notes'] ?? '')) !== ''): ?>
  <h2 class="section-title">Notes</h2>
  <?php /* nl2br over an ESCAPED string, never the other way round. */ ?>
  <p><?= nl2br(h((string) $movie['notes'])) ?></p>
<?php endif; ?>

<?php if ($status === 'coming_soon'): ?>
  <h2 class="section-title">Reminders</h2>
  <?php
  /* What HAPPENED, not what the rule is. reminder_status_line() reads the send
   * ledger, so a past date reads as history — "sent on August 27" — rather
   * than as a promise about a day that has already gone. It also says plainly
   * when a due date passed with no email at all, which is the one failure
   * nothing else in this app can tell you about. */
  $sends = reminder_sends_for_movie($id);
  ?>
  <p class="hint"><?= h(reminder_status_line($movie, REMINDER_HEADS_UP, $today, $sends)) ?></p>
  <p class="hint"><?= h(reminder_status_line($movie, REMINDER_DAY_OF, $today, $sends)) ?></p>

  <?php /* The manual re-check. Release dates are frozen (schema.sql), so this
           is the deliberate way to move one — plus the automatic check the
           cron runs immediately before sending. */ ?>
  <form method="post" action="api/release-check.php" class="row" style="margin-top:12px">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <button class="btn-secondary" type="submit">Check release date</button>
  </form>
  <?php $checked = fmt_date($movie['release_date_checked_at'] ?? null, 'M j, Y'); ?>
  <p class="hint"><?= $checked === ''
      ? 'Not checked against TMDB since it was added.'
      : 'Last checked against TMDB on ' . h($checked) . '.' ?></p>
<?php endif; ?>

<h2 class="section-title">Streaming</h2>
<?php /* Subscription services only. Rent and buy never enter the database —
         they are filtered out in lib/tmdb.php at parse time, so there is
         nothing here that could show them. */ ?>
<?= render_providers($providers, (string) cfg('tmdb.image_base', 'https://image.tmdb.org/t/p/')) ?>

<div class="row" style="margin-top:26px; gap:10px; flex-wrap:wrap">
  <a class="btn-secondary" href="edit.php?id=<?= (int) $id ?>">Edit</a>

<?php if ($status !== 'watched'): ?>
  <?php /* THE PROMOTION. One UPDATE, not a row copy — the movie keeps its id,
           its genres and its poster. edit.php then collects the rating, the
           date and the tags. */ ?>
  <a class="btn-primary" href="edit.php?id=<?= (int) $id ?>&amp;watched=1">Mark as watched</a>
<?php endif; ?>
</div>

<?php page_foot($tab); ?>
