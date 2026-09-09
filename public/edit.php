<?php
/* The one form. It serves five arrivals, and the difference between them is
 * entirely in how $movie gets populated:
 *
 *   edit.php?tmdb_id=N&to=X   a TMDB result, prefilled, not yet saved
 *   edit.php?new=1&to=X       a blank manual entry
 *   edit.php?id=N             editing something already saved
 *   edit.php?id=N&watched=1   "Mark as watched" — an existing movie, with the
 *                             watched fields shown and the status about to change
 *   (a POST of any of the above)
 *
 * ONE FORM RATHER THAN THREE SCREENS, because the fields are the same fields.
 * Three templates would be three places to add a column, and the third one
 * would be forgotten.
 *
 * The section decides which fields are SHOWN, not which exist: a coming-soon
 * movie has no rating input, because a rating for a film nobody has seen is
 * not a thing. Promotion reveals them.
 *
 * NO JAVASCRIPT IS REQUIRED HERE. This is a plain form that posts to itself.
 * The poster upload is a file input, the genres are a text field. That is
 * deliberate — this is the screen where data is entered, and it must not be
 * possible to lose a filled-in form to a module that failed to load. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/tmdb.php';
require_once __DIR__ . '/../lib/posters.php';
require_once __DIR__ . '/../lib/layout.php';

require_admin();

$today  = movies_today();
$errors = array();
$notice = '';

/* ---------------------------------------------------------------- arrival */

$id      = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$isNew   = $id === 0;
$movie   = null;
$genres  = array();

if (!$isNew) {
    $movie = movie_get($id);
    if ($movie === null) {
        http_response_code(404);
        page_head('Not found');
        screen_head('Not found');
        echo '<p class="empty">That movie is not here.</p>';
        page_foot();
        exit;
    }
    $genres = genre_names($movie['genres']);
}

/* Which section this ends up in. On an existing movie it defaults to the one
 * it is already in; ?watched=1 is the promotion. */
$to = (string) ($_POST['status'] ?? $_GET['to'] ?? ($movie['status'] ?? 'watched'));
if (($_GET['watched'] ?? '') === '1') {
    $to = 'watched';
}
if (!in_array($to, MOVIE_STATUSES, true)) {
    $to = 'watched';
}

/* A TMDB result being added for the first time: fetch it once and prefill.
 *
 * Nothing is written to the database here. The movie is not saved until the
 * form is submitted, so backing out of this screen leaves nothing behind —
 * which is what makes tapping a wrong search result harmless. */
$tmdbId    = (int) ($_POST['tmdb_id'] ?? $_GET['tmdb_id'] ?? 0);
$tmdbFetch = null;

if ($isNew && $tmdbId > 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    /* Already in the collection? Go to it rather than creating a second row.
     * The UNIQUE key on tmdb_id would refuse the insert anyway; this turns
     * that into a useful screen instead of an error. */
    $existing = movie_by_tmdb_id($tmdbId);
    if ($existing !== null) {
        header('Location: movie.php?id=' . (int) $existing['id']);
        exit;
    }

    $tmdbFetch = tmdb_get($tmdbId);
    if ($tmdbFetch === null) {
        /* TMDB unreachable, or it no longer has this movie. Fall through to a
         * blank-but-usable form rather than a dead end — everything TMDB would
         * have filled in can be typed. */
        $errors[] = 'Could not reach TMDB for that movie. You can still fill this in by hand.';
    } else {
        $genreMap = tmdb_genres();
        foreach ($tmdbFetch['genre_ids'] as $gid) {
            if (isset($genreMap[$gid])) {
                $genres[] = $genreMap[$gid];
            }
        }
    }
}

/* ------------------------------------------------------------------- save */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        $errors[] = 'A title is required.';
    }

    /* Rating: '' means unrated (NULL), 1-5 is a rating. Zero is not offered
     * and would be rejected by the schema's CHECK — see schema.sql. */
    $ratingRaw = (string) ($_POST['rating'] ?? '');
    $rating    = $ratingRaw === '' ? null : (int) $ratingRaw;
    if ($rating !== null && ($rating < 1 || $rating > 5)) {
        $errors[] = 'A rating must be between 1 and 5, or left blank.';
    }

    $releaseRaw = trim((string) ($_POST['release_date'] ?? ''));
    $release    = $releaseRaw === '' ? null : $releaseRaw;
    if ($release !== null && movies_parse_date($release) === null) {
        $errors[] = 'That release date is not a real date.';
        $release  = null;
    }

    $watchedRaw = trim((string) ($_POST['date_watched'] ?? ''));
    $watchedOn  = $watchedRaw === '' ? null : $watchedRaw;
    if ($watchedOn !== null && movies_parse_date($watchedOn) === null) {
        $errors[] = 'That watched date is not a real date.';
        $watchedOn = null;
    }

    $yearRaw = trim((string) ($_POST['year'] ?? ''));
    $year    = $yearRaw === '' ? null : (int) $yearRaw;

    /* Genres arrive as one comma-separated field. genres_set() normalises and
     * deduplicates, so 'Sci-Fi, sci-fi' collapses to one. */
    $genres = array_filter(array_map('trim', explode(',', (string) ($_POST['genres'] ?? ''))));

    $fields = array(
        'title'           => $title,
        'year'            => $year,
        'status'          => $to,
        'notes'           => trim((string) ($_POST['notes'] ?? '')),
        'is_favorite'     => isset($_POST['is_favorite']) ? 1 : 0,
        'is_rewatchable'  => isset($_POST['is_rewatchable']) ? 1 : 0,
        'day_of_reminder' => isset($_POST['day_of_reminder']) ? 1 : 0,
        'release_date'    => $release,
        'rating'          => $to === 'watched' ? $rating : null,
        'date_watched'    => $to === 'watched' ? $watchedOn : null,
    );

    if ($isNew) {
        $fields['tmdb_id'] = $tmdbId > 0 ? $tmdbId : null;
        $fields['source']  = $tmdbId > 0 ? 'tmdb' : 'manual';
    }

    if (!$errors) {
        $savedId = movie_save($fields, $isNew ? null : $id, $today);
        genres_set($savedId, $genres);

        /* The poster, after the row exists — poster_store() logs against the
         * movie id, and a failed poster must never cost the save. Both paths
         * fail soft to poster_path staying NULL, which renders as a titled
         * placeholder. */
        $posterPath = null;

        if (!empty($_FILES['poster']['name'])) {
            $up = poster_store_upload($_FILES['poster'], $savedId);
            if ($up['error'] !== null) {
                $errors[] = $up['error'];
            } else {
                $posterPath = $up['path'];
            }
        } elseif ($isNew && $tmdbId > 0) {
            $remote = trim((string) ($_POST['tmdb_poster_path'] ?? ''));
            if ($remote !== '') {
                $posterPath = poster_fetch($remote, $savedId);
            }
        }

        if ($posterPath !== null) {
            movie_save(array('poster_path' => $posterPath), $savedId, $today);
        }

        header('Location: movie.php?id=' . $savedId);
        exit;
    }

    /* Failed validation: re-render with what was typed, not with what was
     * saved. Losing a filled-in form to a typo in one field is the fastest way
     * to make somebody stop using an app. */
    $movie = array_merge($movie ?? array(), $fields, array('id' => $id));
}

/* ----------------------------------------------------------------- render */

/** Current value for a field: the posted one, then the saved one, then TMDB. */
function val(?array $movie, ?array $tmdb, string $key, $default = '')
{
    if ($movie !== null && array_key_exists($key, $movie) && $movie[$key] !== null) {
        return $movie[$key];
    }
    if ($tmdb !== null && array_key_exists($key, $tmdb) && $tmdb[$key] !== null) {
        return $tmdb[$key];
    }
    return $default;
}

$title = (string) val($movie, $tmdbFetch, 'title', (string) ($_GET['title'] ?? ''));
$heading = $isNew ? 'Add a movie' : 'Edit';

$tab = match ($to) {
    'coming_soon' => 'coming-soon',
    'to_watch'    => 'watchlist',
    default       => 'watched',
};

page_head($heading, $tab);
screen_head($heading, page_menu());
?>

<?php foreach ($errors as $e): ?>
  <p class="field-err" role="alert"><?= h($e) ?></p>
<?php endforeach; ?>

<?php /* enctype because of the poster upload. Without it the file arrives as
         a filename string and $_FILES is empty, which looks like the upload
         silently doing nothing. */ ?>
<form method="post" enctype="multipart/form-data" class="stack">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <input type="hidden" name="status" value="<?= h($to) ?>">
<?php if ($isNew && $tmdbId > 0): ?>
  <input type="hidden" name="tmdb_id" value="<?= (int) $tmdbId ?>">
  <?php /* The TMDB poster path, carried through the form so the fetch happens
           once, after the row exists, rather than on the way in. */ ?>
  <input type="hidden" name="tmdb_poster_path"
         value="<?= h((string) val(null, $tmdbFetch, 'poster_path')) ?>">
<?php endif; ?>

  <label class="field">
    <span>Title</span>
    <input class="input" type="text" name="title" required value="<?= h($title) ?>">
  </label>

  <label class="field">
    <span>Year</span>
    <input class="input" type="number" name="year" inputmode="numeric"
           min="1888" max="2100"
           value="<?= h((string) val($movie, $tmdbFetch, 'year')) ?>">
  </label>

  <label class="field">
    <span>Genres <span class="hint">comma separated</span></span>
    <input class="input" type="text" name="genres" value="<?= h(implode(', ', $genres)) ?>">
  </label>

<?php if ($to === 'watched'): ?>
  <label class="field">
    <span>Rating <span class="hint">leave blank if you'd rather not rate it</span></span>
    <select class="input" name="rating">
      <?php $current = (string) val($movie, null, 'rating'); ?>
      <option value=""<?= $current === '' ? ' selected' : '' ?>>Unrated</option>
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <option value="<?= $i ?>"<?= $current === (string) $i ? ' selected' : '' ?>>
          <?= str_repeat('★', $i) ?>
        </option>
      <?php endfor; ?>
    </select>
  </label>

  <label class="field">
    <span>Date watched</span>
    <?php /* Defaults to today on a new entry, per the brief — logging a movie
             right after seeing it is the common case, and a date picker you
             have to open every time is friction on the one screen that gets
             used most. */ ?>
    <input class="input" type="date" name="date_watched"
           value="<?= h((string) val($movie, null, 'date_watched', $today)) ?>">
  </label>

  <label class="row">
    <input type="checkbox" name="is_favorite" value="1"
           <?= (int) val($movie, null, 'is_favorite', 0) ? 'checked' : '' ?>>
    <span>Favorite</span>
  </label>

  <label class="row">
    <input type="checkbox" name="is_rewatchable" value="1"
           <?= (int) val($movie, null, 'is_rewatchable', 0) ? 'checked' : '' ?>>
    <span>Rewatchable</span>
  </label>
<?php endif; ?>

<?php if ($to === 'coming_soon'): ?>
  <label class="field">
    <span>Theatrical release date</span>
    <input class="input" type="date" name="release_date"
           value="<?= h((string) val($movie, $tmdbFetch, 'release_date')) ?>">
  </label>

  <label class="row">
    <input type="checkbox" name="day_of_reminder" value="1"
           <?= (int) val($movie, null, 'day_of_reminder', 0) ? 'checked' : '' ?>>
    <span>Also email me on release day</span>
  </label>
  <?php
  /* Say what will happen, before it happens. The week-ahead email is decided
   * at save time (schema.sql, heads_up_eligible), so somebody adding a movie
   * that opens on Friday should learn HERE that no week-ahead email is coming
   * — not by wondering later why one never arrived. */
  $preview = heads_up_date((string) val($movie, $tmdbFetch, 'release_date'), reminders_lead_days());
  ?>
  <p class="hint">
    <?php if ($preview === null): ?>
      Add a release date and you'll get an email a week before it opens.
    <?php elseif ($preview < $today): ?>
      No week-ahead email — that's less than <?= (int) reminders_lead_days() ?>
      days away. Tick the box above to be told on the day.
    <?php else: ?>
      You'll get an email on <?= h(fmt_date($preview)) ?>, a week before it opens.
    <?php endif; ?>
  </p>
<?php endif; ?>

  <label class="field">
    <span>Notes</span>
    <textarea class="input" name="notes" rows="4"><?= h((string) val($movie, null, 'notes')) ?></textarea>
  </label>

  <label class="field">
    <span>Poster <span class="hint">
      <?= $isNew && $tmdbId > 0 ? 'optional — TMDB\'s will be used otherwise' : 'optional' ?>
    </span></span>
    <input class="input" type="file" name="poster" accept="image/*">
  </label>

  <button class="btn-primary" type="submit">Save</button>
</form>

<?php page_foot($tab); ?>
