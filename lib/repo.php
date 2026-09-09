<?php
/* All movie and genre data access.
 *
 * Nothing outside this file writes SQL against movies, genres or movie_genres.
 * Every statement is prepared, via q().
 *
 * One shared home rather than a query per screen, because five screens read
 * movies and three of them write. Without this the public field whitelist at
 * the bottom would exist in three places — which is exactly how a private
 * field ends up on the public page.
 *
 * Nothing here escapes for output. Templates do that with h(). */

declare(strict_types=1);

/* The three sections, and the two transitions between them:
 *
 *   coming_soon ──→ watched      ("Mark as Watched", after release)
 *   to_watch    ──→ watched      ("Mark as Watched")
 *
 * One table, one status column, so every transition is a single UPDATE via
 * movie_set_status(). See schema.sql for why the brief's three-table draft was
 * not built. */
const MOVIE_STATUSES = array('watched', 'coming_soon', 'to_watch');

/* The columns movie_save() will write. A caller passing straight from a decoded
 * request body cannot set source, tmdb_id or heads_up_eligible by adding a
 * field — those are decided here, not submitted. */
const MOVIE_WRITABLE = array(
    'tmdb_id', 'title', 'year', 'status', 'poster_path', 'release_date',
    'release_date_checked_at', 'rating', 'is_favorite', 'is_rewatchable',
    'notes', 'date_watched', 'heads_up_eligible', 'day_of_reminder', 'source',
);

/* ------------------------------------------------------------------ reads */

/* The filters the watched grid offers, as id => label.
 *
 * A LIST, and the plumbing below takes a SET of ids and ANDs them, even though
 * the UI offers one at a time today. Allowing two later is a change to the
 * screen, not to the query layer — the expensive half of "make this
 * multi-select" is already done. Ported from Book Tracker, which made the same
 * call for the same reason.
 *
 * 'genre-N' is generated per genre present in the data, so it isn't listed. */
const WATCHED_FILTERS = array(
    'favorites'   => 'Favorites',
    'rewatchable' => 'Rewatchable',
    'stars-5'     => '5 stars',
    'stars-4'     => '4 stars',
    'stars-low'   => '3 stars or fewer',
    'unrated'     => 'Unrated',
);

/**
 * Turn filter ids into SQL fragments and params.
 *
 * Unknown ids are DROPPED rather than erroring: filters arrive in the URL, and
 * a stale bookmark or a hand-typed link should show the whole collection, not
 * a failure. Every fragment is parameterised — nothing from the URL is
 * interpolated into SQL.
 *
 * @return array{0: string[], 1: array}
 */
function watched_filter_sql(array $filters): array
{
    $where  = array();
    $params = array();

    foreach ($filters as $f) {
        if (preg_match('/^genre-(\d+)$/', (string) $f, $m)) {
            $where[]  = 'EXISTS (SELECT 1 FROM movie_genres mg
                                  WHERE mg.movie_id = movies.id AND mg.genre_id = ?)';
            $params[] = (int) $m[1];
            continue;
        }
        switch ((string) $f) {
            case 'favorites':
                $where[] = 'is_favorite = 1';
                break;
            case 'rewatchable':
                $where[] = 'is_rewatchable = 1';
                break;
            case 'stars-5':
                $where[] = 'rating = 5';
                break;
            case 'stars-4':
                $where[] = 'rating = 4';
                break;
            case 'stars-low':
                /* Unrated movies are NOT "3 or fewer". NULL means no opinion
                 * was recorded, and sweeping those into the lowest bucket
                 * would invent one. They have their own filter below. */
                $where[] = 'rating IS NOT NULL AND rating <= 3';
                break;
            case 'unrated':
                $where[] = 'rating IS NULL';
                break;
        }
    }

    return array($where, $params);
}

/**
 * The watched grid: newest first.
 *
 * Movies with no date sort LAST rather than vanishing. A watched movie with no
 * date is a data gap, and dropping it from the only screen that lists watched
 * movies would hide it forever.
 *
 * @param array $filters Filter ids from WATCHED_FILTERS, plus 'genre-N'.
 */
function movies_watched(array $filters = array()): array
{
    $sql    = 'SELECT * FROM movies WHERE status = ?';
    $params = array('watched');

    list($where, $extra) = watched_filter_sql($filters);
    foreach ($where as $w) {
        $sql .= ' AND ' . $w;
    }
    $params = array_merge($params, $extra);

    /* `date_watched IS NULL` first in the sort key pushes undated rows to the
     * bottom; without it they would sort above everything, since NULL sorts
     * low and the order is DESC. */
    $sql .= ' ORDER BY date_watched IS NULL, date_watched DESC, id DESC';

    return movies_attach_genres(q($sql, $params)->fetchAll());
}

/**
 * The Coming Soon list, soonest first.
 *
 * Undated movies sort LAST, not first — same NULL-ordering trap as above, and
 * here it matters more: an announced-but-undated film sorting to the top would
 * push the thing actually coming out this week off the first screen.
 *
 * Already-released movies still on this list are NOT filtered out. That is the
 * pile the screen exists to surface. See schema.sql on movies.status.
 */
function movies_coming_soon(): array
{
    return movies_attach_genres(q(
        'SELECT * FROM movies WHERE status = ?
          ORDER BY release_date IS NULL, release_date ASC, id ASC',
        array('coming_soon')
    )->fetchAll());
}

/** The To Watch list. Most recently added first — the newest recommendation. */
function movies_to_watch(): array
{
    return movies_attach_genres(q(
        'SELECT * FROM movies WHERE status = ? ORDER BY date_added DESC, id DESC',
        array('to_watch')
    )->fetchAll());
}

function movie_get(int $id): ?array
{
    $row = q('SELECT * FROM movies WHERE id = ?', array($id))->fetch();
    if (!$row) {
        return null;
    }
    $withGenres = movies_attach_genres(array($row));
    return $withGenres[0];
}

/** The movie carrying this TMDB id, or null. What the add flow checks first. */
function movie_by_tmdb_id(int $tmdbId): ?array
{
    $row = q('SELECT * FROM movies WHERE tmdb_id = ?', array($tmdbId))->fetch();
    return $row === false ? null : movies_attach_genres(array($row))[0];
}

/**
 * Attach a 'genres' array to each row in ONE extra query, not one per movie.
 *
 * A few hundred movies each firing their own genre lookup is the classic N+1,
 * and it is invisible on a seeded database and painful on a real one.
 */
function movies_attach_genres(array $rows): array
{
    if (!$rows) {
        return array();
    }

    $ids = array();
    foreach ($rows as $r) {
        $ids[] = (int) $r['id'];
    }

    /* Placeholders are generated from a COUNT, never from the values. The ids
     * are already ints, but building SQL out of data is the habit worth not
     * having. */
    $in    = implode(',', array_fill(0, count($ids), '?'));
    $pairs = q(
        "SELECT mg.movie_id, g.id, g.name
           FROM movie_genres mg
           JOIN genres g ON g.id = mg.genre_id
          WHERE mg.movie_id IN ($in)
          ORDER BY g.name",
        $ids
    )->fetchAll();

    $byMovie = array();
    foreach ($pairs as $p) {
        $byMovie[(int) $p['movie_id']][] = array('id' => (int) $p['id'], 'name' => $p['name']);
    }

    foreach ($rows as &$r) {
        $r['genres'] = $byMovie[(int) $r['id']] ?? array();
    }
    unset($r);

    return $rows;
}

/* ----------------------------------------------------------------- writes */

/**
 * Insert or update a movie. Returns its id.
 *
 * TWO THINGS ARE DECIDED HERE RATHER THAN BY THE CALLER, because both are easy
 * to get wrong from a form and impossible to notice afterwards:
 *
 *   1. `year` is derived from release_date when the caller didn't supply one.
 *   2. `heads_up_eligible` is recomputed whenever release_date is written.
 *      See below — this is the whole "skip late adds" rule.
 */
function movie_save(array $fields, ?int $id = null, ?string $today = null): int
{
    $today = $today ?? movies_today();

    $data = array();
    foreach (MOVIE_WRITABLE as $col) {
        if (array_key_exists($col, $fields)) {
            $data[$col] = $fields[$col];
        }
    }
    if (!$data) {
        throw new InvalidArgumentException('movie_save: nothing to write');
    }

    // A year the caller didn't give us, from a release date they did.
    if (!array_key_exists('year', $data) && !empty($data['release_date'])) {
        $data['year'] = date_year($data['release_date']);
    }

    /* THE "SKIP LATE ADDS" RULE, APPLIED AT THE ONLY MOMENT IT MEANS ANYTHING.
     *
     * A heads-up email is worth sending only if there was still time to send
     * it when the movie was added. Recomputed on any save that touches
     * release_date — including the cron's own correction when TMDB moves a
     * date, which is right: a date that slips FORWARD re-opens the window, and
     * a movie whose new date is next week should get its heads-up.
     *
     * Deriving this in the cron instead would invert the behaviour. A movie
     * added three days before release has a heads-up date three days in the
     * past, the due query would read that as overdue, and it would fire
     * immediately — the exact email that was asked not to be sent. */
    if (array_key_exists('release_date', $data) && !array_key_exists('heads_up_eligible', $data)) {
        $headsUp = heads_up_date($data['release_date'], reminders_lead_days());
        $data['heads_up_eligible'] = ($headsUp !== null && $headsUp >= $today) ? 1 : 0;
    }

    if ($id === null) {
        $cols = implode(', ', array_keys($data));
        $ph   = implode(', ', array_fill(0, count($data), '?'));
        q("INSERT INTO movies ($cols) VALUES ($ph)", array_values($data));
        return (int) db()->lastInsertId();
    }

    $set = array();
    foreach (array_keys($data) as $col) {
        $set[] = "$col = ?";
    }
    $params   = array_values($data);
    $params[] = $id;
    q('UPDATE movies SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
    return $id;
}

/**
 * Move a movie to a new section, optionally filling in the fields that only
 * make sense at that moment.
 *
 * This one function covers both transitions in the lifecycle. That it is a
 * single UPDATE rather than a cross-table move is the entire payoff of the
 * one-table decision — the movie keeps its id, its genre rows and its poster
 * file, and there is no window in which it exists twice or not at all.
 */
function movie_set_status(int $id, string $status, array $extra = array(), ?string $today = null): void
{
    if (!in_array($status, MOVIE_STATUSES, true)) {
        throw new InvalidArgumentException("movie_set_status: unknown status '$status'");
    }
    $extra['status'] = $status;
    movie_save($extra, $id, $today);
}

function movie_delete(int $id): void
{
    /* movie_genres, the send ledger and the provider cache all go with it via
     * ON DELETE CASCADE; the genres themselves stay.
     *
     * The poster file on disk is deliberately NOT unlinked. A manually
     * uploaded poster exists nowhere else and cannot be re-fetched, and one
     * mistaken delete should not also destroy the only copy of the art. The
     * orphan costs a few kilobytes. */
    q('DELETE FROM movies WHERE id = ?', array($id));
}

/** The configured heads-up lead, read in exactly one place. */
function reminders_lead_days(): int
{
    return max(0, (int) cfg('reminders.heads_up_days', 7));
}

/* ----------------------------------------------------------------- genres */

/** Every genre with how many movies carry it. Powers the filter list. */
function genres_all(): array
{
    return q(
        'SELECT g.id, g.name, COUNT(mg.movie_id) AS count
           FROM genres g
           LEFT JOIN movie_genres mg ON mg.genre_id = g.id
          GROUP BY g.id, g.name
          ORDER BY count DESC, g.name'
    )->fetchAll();
}

/**
 * Find or create a genre by name, returning its id.
 *
 * INSERT IGNORE then SELECT, rather than SELECT then INSERT: two requests
 * saving the same new genre at once would otherwise both see it missing and
 * one would hit the UNIQUE key.
 */
function genre_upsert(string $name, ?int $tmdbId = null): ?int
{
    $clean = normalize_tag($name);
    if ($clean === '') {
        return null;
    }

    q('INSERT IGNORE INTO genres (name, tmdb_id) VALUES (?, ?)', array($clean, $tmdbId));
    $id = q('SELECT id FROM genres WHERE name = ?', array($clean))->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * Replace a movie's genres with exactly this list of NAMES. Creates any that
 * don't exist yet.
 *
 * Names rather than ids, because the tag field on the edit screen accepts
 * free text — the brief asks for TMDB genres to be editable and for custom
 * tags alongside them, so "a genre that doesn't exist yet" is a normal input
 * here, not an error. normalize_tag() lowercases, so the UNIQUE key on
 * genres.name does the deduplication rather than this code guessing at it.
 */
function genres_set(int $movieId, array $names): void
{
    $clean = array();
    foreach ($names as $n) {
        if (!is_string($n)) {
            continue;
        }
        $t = normalize_tag($n);
        if ($t !== '') {
            $clean[$t] = true;   // keyed, so duplicates in one submission collapse
        }
    }

    q('DELETE FROM movie_genres WHERE movie_id = ?', array($movieId));

    foreach (array_keys($clean) as $name) {
        $genreId = genre_upsert($name);
        if ($genreId !== null) {
            q(
                'INSERT IGNORE INTO movie_genres (movie_id, genre_id) VALUES (?, ?)',
                array($movieId, $genreId)
            );
        }
    }
}

/** Just the names, for rendering chips. */
function genre_names(array $genres): array
{
    $out = array();
    foreach ($genres as $g) {
        $out[] = (string) $g['name'];
    }
    return $out;
}

/* -------------------------------------------------------- PUBLIC WHITELIST */

/**
 * Reduce a movie row to what a stranger may see.
 *
 * PUBLIC: poster, title, year, rating, favorite, notes, genres.
 * PRIVATE: everything else — and specifically the exact date watched, the
 * status, the TMDB id, the reminder settings and the rewatchable mark.
 *
 * THIS BUILDS A NEW ARRAY FROM AN EXPLICIT LIST rather than unsetting fields
 * from the row. The difference is the whole point: a column added to `movies`
 * next year is private by default under this version, and would silently leak
 * under the other. Every public response must go through here.
 *
 * `status` is deliberately absent even though it is always 'watched' in a
 * public response — publishing a field whose only possible value is a constant
 * tells a reader there are other values, and invites the next person to widen
 * the query that guarantees it.
 */
function movie_public(array $row): array
{
    return array(
        'id'          => (int) $row['id'],
        'title'       => $row['title'],
        'year'        => $row['year'] === null ? null : (int) $row['year'],
        'rating'      => $row['rating'] === null ? null : (int) $row['rating'],
        'is_favorite' => (bool) $row['is_favorite'],
        'notes'       => $row['notes'],
        'poster_path' => $row['poster_path'],
        'genres'      => genre_names($row['genres'] ?? array()),
    );
}

/**
 * The public list, already filtered and whitelisted.
 *
 * STATUS FILTERING HAPPENS IN THE QUERY — movies_watched() selects
 * `status = 'watched'` — never in a template. A template-level filter is one
 * edit away from publishing the Coming Soon list, which is the specific thing
 * the brief says must never happen.
 *
 * The public page calls this and nothing else. It has no way to ask for
 * another status, because there is no parameter to pass one through.
 */
function movies_public(): array
{
    return array_map('movie_public', movies_watched());
}
