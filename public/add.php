<?php
/* The add flow: one search field, live TMDB results, "Create new" pinned last.
 *
 * Ported in shape from Book Tracker's add.php. The whole screen is one input;
 * assets/addflow.js does the searching and renders the list.
 *
 * ?to= carries which section this movie is being added to, so the same flow
 * serves all three. It is validated here rather than trusted — it ends up in a
 * link to edit.php, and an unvalidated value would put whatever was in the URL
 * into the next screen's query string.
 *
 * WHY THE SEARCH IS NOT DONE HERE. It is a fetch from the browser to
 * api/search.php, not a form post, because the results have to update as you
 * type — a round trip per keystroke through a page reload would be unusable,
 * and the debounce and in-flight cancellation in addflow.js are what make it
 * feel instant on a phone. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/layout.php';

require_admin();

$to = (string) ($_GET['to'] ?? 'watched');
if (!in_array($to, MOVIE_STATUSES, true)) {
    $to = 'watched';
}

/* No "Add to Coming Soon" any more: which of the two unwatched sections a
 * movie lands in is decided from its release date by movie_section(), not
 * chosen here. One heading for both. */
$label = $to === 'watched' ? 'Add a watched movie' : 'Add to your list';

$tab = match ($to) {
    /* Both unwatched sections live on watchlist.php now, so they light the
     * same tab. */
    'coming_soon' => 'watchlist',
    'to_watch'    => 'watchlist',
    default       => 'watched',
};

page_head($label, $tab);
screen_head($label, page_menu());
?>

<label class="field">
  <span>Title</span>
  <?php /* autofocus so the keyboard is already up: this screen is one field
           and there is nothing else to tap. type="search" rather than "text"
           for the clear button iOS draws on it. */ ?>
  <input class="input" type="search" id="q" name="q"
         autocomplete="off" autocapitalize="words" autocorrect="off"
         spellcheck="false" autofocus
         placeholder="Search for a movie">
</label>

<p class="field-err" id="search-error" role="status"></p>

<?php /* The results list, filled by addflow.js. Empty in the markup rather
         than pre-rendered, because there is nothing to show until something is
         typed — and a spinner in the initial HTML would flash on every load. */ ?>
<ul class="results" id="results" aria-live="polite"></ul>

<?php
/* The flow's parameters, handed to the module as JSON rather than through a
 * shared global. type="application/json" so the browser does not execute it,
 * and the JSON_HEX_* flags escape anything that could close the tag. */
printf(
    '<script type="application/json" id="addflow-config">%s</script>' . "\n",
    json_encode(
        array('to' => $to),
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
    )
);
printf('<script type="module" src="%s"></script>' . "\n", asset('assets/addflow.js'));

page_foot($tab);
