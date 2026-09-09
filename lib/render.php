<?php
/* Movie components, rendered server-side.
 *
 * The three list screens, the detail screen and the public page all render a
 * poster tile, a rating and a genre chip. Five copies of that markup is five
 * different-looking tiles — and five places to remember that a missing poster
 * still has to render and that the favourite marker is a heart.
 *
 * These RETURN strings rather than echoing, so callers can put them in
 * attributes, JSON or a template. All of them escape their own output. */

declare(strict_types=1);

/**
 * A rating as stars, or '' when unrated.
 *
 * The scale is 1-5; NULL means unrated and renders NOTHING — not five hollow
 * stars, not a zero. That distinction is the load-bearing one: logging a movie
 * now and rating it later is a normal thing to do, and an unrated movie drawn
 * as zero stars would misrepresent it as one you disliked.
 *
 * A 0 shouldn't reach here (the schema's CHECK rejects it), but a legacy or
 * hand-edited row carrying one is treated as unrated rather than drawn — fail
 * soft, and don't invent a rating nobody gave.
 */
function render_stars(?int $rating): string
{
    if ($rating === null || $rating < 1) {
        return '';
    }
    $rating = min(5, $rating);

    $out = '<span class="stars" role="img" aria-label="' . $rating . ' out of 5 stars">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<span class="star' . ($i <= $rating ? ' is-on' : '') . '" aria-hidden="true">'
             . ($i <= $rating ? '★' : '☆') . '</span>';
    }
    return $out . '</span>';
}

/**
 * The favourite marker — a HEART, never a star.
 *
 * The rating right beside it is already five stars; a sixth star means nothing
 * legible at phone size. Decided once, here, instead of per screen.
 *
 * Per the brief this appears on the detail and edit screens only, not on the
 * grid tile — so no caller passes it into render_movie_card().
 */
function render_favorite(bool $isFavorite): string
{
    if (!$isFavorite) {
        return '';
    }
    return '<span class="fav" role="img" aria-label="Favorite">♥</span>';
}

/**
 * Genre chips, plus the Rewatchable chip when it applies.
 *
 * The brief asks for "Rewatchable" to live alongside the genre tags in the same
 * tag area, and this is where that happens — visually it is one row of chips.
 * Underneath, it is a column on `movies` and the genres are rows in a table;
 * see schema.sql for why. `.is-static` marks it as not-a-genre so it can be
 * styled apart later without re-deciding where it lives.
 */
function render_genre_chips(array $genreNames, bool $rewatchable = false): string
{
    if (!$genreNames && !$rewatchable) {
        return '';
    }

    $out = '<span class="chips">';
    foreach ($genreNames as $g) {
        $out .= '<span class="chip">' . h((string) $g) . '</span>';
    }
    if ($rewatchable) {
        $out .= '<span class="chip is-static">Rewatchable</span>';
    }
    return $out . '</span>';
}

/**
 * One poster tile.
 *
 * $opts:
 *   'href'      string  wrap the tile in a link. A tile with NO href renders as
 *                       a plain <div> with no role and no tabindex — see below.
 *   'sub'       string  the line under the title (already-escaped HTML)
 *   'countdown' string  the Coming Soon countdown text
 *   'countdown_out' bool render the countdown as already-released
 *
 * FAIL SOFT: a movie with no poster still renders, as a titled placeholder.
 * That is a normal end state, not an error — TMDB doesn't have art for
 * everything, and a movie missing its poster must never be missing from the
 * grid.
 *
 * ON THE NO-HREF CASE. Book Tracker renders a card with no href as
 * `<div role="button" tabindex="0">`, which is correct there because JS
 * attaches a handler. Its own CONTRACTS.md flags this as a bug waiting to
 * happen on any surface without JS: every tile announces itself as a button
 * and does nothing. This version renders an inert <div> instead, so the one
 * screen that lists movies without linking them — the public page, which has
 * no JS at all — cannot promise a interaction it does not have.
 */
function render_movie_card(array $movie, array $opts = array()): string
{
    $title  = (string) ($movie['title'] ?? '');
    $poster = $movie['poster_path'] ?? null;

    $art = $poster
        ? '<img class="poster" src="' . h($poster) . '" alt="" loading="lazy" decoding="async">'
        // The placeholder carries the title: at grid size an untitled coloured
        // box is unidentifiable, and these are the movies most likely to need
        // a fix.
        : '<span class="poster poster-none"><span>' . h($title) . '</span></span>';

    $inner = '<span class="poster-art">' . $art . '</span>'
           . '<span class="poster-title">' . h($title) . '</span>';

    if (!empty($opts['sub'])) {
        $inner .= '<span class="poster-sub">' . $opts['sub'] . '</span>';
    }
    if (!empty($opts['countdown'])) {
        $inner .= '<span class="poster-countdown' . (!empty($opts['countdown_out']) ? ' is-out' : '') . '">'
               . h((string) $opts['countdown']) . '</span>';
    }
    if (isset($movie['rating']) && $movie['rating'] !== null) {
        $inner .= render_stars((int) $movie['rating']);
    }

    if (!empty($opts['href'])) {
        return '<a class="poster-card" href="' . h((string) $opts['href']) . '">' . $inner . '</a>';
    }
    return '<div class="poster-card">' . $inner . '</div>';
}

/**
 * A whole grid of tiles, or the empty state.
 *
 * NO YEAR DIVIDERS. Book Tracker's equivalent takes a flag for them and the
 * collection view uses it; the brief for this app rules them out explicitly for
 * the watched grid. Not building the flag is the point — an unused option is a
 * thing every future caller has to decide about.
 *
 * @param callable|null $hrefFn  Given a movie row, returns its link, or null
 *                               for an unlinked (public) grid.
 * @param callable|null $optsFn  Given a movie row, returns extra card opts.
 */
function render_movie_grid(
    array $movies,
    ?callable $hrefFn = null,
    ?callable $optsFn = null,
    string $emptyText = 'Nothing here yet.'
): string {
    if (!$movies) {
        return '<p class="empty">' . h($emptyText) . '</p>';
    }

    $out = '<div class="poster-grid">';
    foreach ($movies as $m) {
        $opts = $optsFn === null ? array() : $optsFn($m);
        if ($hrefFn !== null) {
            $opts['href'] = $hrefFn($m);
        }
        $out .= render_movie_card($m, $opts);
    }
    return $out . "</div>\n";
}

/**
 * The subtitle under a watched tile: the year, or nothing.
 *
 * Returns pre-escaped HTML because render_movie_card() takes 'sub' as markup —
 * the Coming Soon screen puts a date in the same slot and needs it formatted.
 */
function render_year_sub(?int $year): string
{
    return $year === null ? '' : h((string) $year);
}

/**
 * The filter chip row on the watched grid.
 *
 * Lives here rather than in public/index.php because that file is a TEMPLATE,
 * and a template that declares functions is a template that cannot be included
 * twice — which is not a problem in a request, where it is included once, and
 * is exactly the problem in tools/smoke-screens.php, which renders every
 * screen in one process. Shirewatch keeps its render_filters() here for the
 * same reason.
 *
 * Chips are LINKS, so filter state lives in the URL and a filtered view is
 * bookmarkable and shareable with yourself. Tapping the active one clears it,
 * which is what makes a single row work as a toggle without a second control.
 *
 * Genres are generated from the data and only the ones actually used appear —
 * a filter that can only ever match nothing is a dead control.
 */
function render_filter_chips(array $active, array $genres, array $filters): string
{
    $url = static function (string $filter): string {
        return $filter === ''
            ? 'index.php'
            : 'index.php?' . http_build_query(array('f' => $filter));
    };

    $chip = static function (string $id, string $label) use ($active, $url): string {
        $on = in_array($id, $active, true);
        return '<a class="chip' . ($on ? ' is-on' : '') . '" href="'
            . h($url($on ? '' : $id)) . '">' . h($label) . '</a>';
    };

    $out = '<div class="filterbar">'
        . '<a class="chip' . ($active === array() ? ' is-on' : '') . '" href="'
        . h($url('')) . '">All</a>';

    foreach ($filters as $id => $label) {
        $out .= $chip((string) $id, (string) $label);
    }

    foreach ($genres as $g) {
        if ((int) $g['count'] === 0) {
            continue;
        }
        $out .= $chip('genre-' . (int) $g['id'], ucwords((string) $g['name']));
    }

    return $out . '</div>';
}

/**
 * Streaming availability, or the sentence that says there is none.
 *
 * SUBSCRIPTION SERVICES ONLY — rent and buy never reach the database (see
 * lib/tmdb.php), so there is nothing to filter here.
 *
 * A movie streaming NOWHERE renders a sentence rather than an empty box. "No
 * subscription streaming" is a real answer and the most common one for a film
 * still in theatres; an empty area looks like the feature is broken.
 */
function render_providers(array $providers, string $imageBase): string
{
    if (!$providers) {
        return '<p class="hint">Not on any subscription service right now.</p>';
    }

    $out = '<ul class="providers">';
    foreach ($providers as $p) {
        $out .= '<li class="provider">';
        if (!empty($p['logo_path'])) {
            /* Hotlinked from TMDB's CDN rather than cached locally: these are a
             * handful of shared logos that change when a service rebrands, and
             * a stale cached logo is worse than a current remote one. */
            $out .= '<img src="' . h($imageBase . 'w45' . $p['logo_path']) . '" alt="" '
                 . 'loading="lazy" decoding="async" width="26" height="26">';
        }
        $out .= '<span>' . h((string) $p['provider_name']) . '</span></li>';
    }
    return $out . '</ul>';
}
