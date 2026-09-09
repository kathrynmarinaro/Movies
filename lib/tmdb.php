<?php
/* Everything that talks to The Movie Database.
 *
 * Nothing outside this file knows TMDB's URL shapes, its auth header or its
 * response JSON. The rest of the app sees one normalized result array and a
 * list of provider names.
 *
 * ---------------------------------------------------------------------------
 * READ tools/hosting-check.php BEFORE TRUSTING ANY OF THIS.
 * ---------------------------------------------------------------------------
 *
 * Book Tracker cut Google Books entirely after its hosting check found
 * googleapis.com unreachable from Hostinger — a discovery that would have
 * meant rewriting a finished module if it had been made during integration
 * instead of before it. api.themoviedb.org is the same class of bet. If it is
 * unreachable from the plan this deploys to, search, posters, genres and
 * streaming availability all stop working and every movie has to be entered by
 * hand through the "Create new" path, which is built and works.
 *
 * ---------------------------------------------------------------------------
 * FAIL SOFT, EVERY PATH.
 * ---------------------------------------------------------------------------
 *
 * Every function here returns null or an empty array on any failure and logs
 * the reason. A movie must always be saveable: the add flow degrades to manual
 * entry, a poster degrades to a titled placeholder, and streaming availability
 * degrades to "not on any subscription service right now". The one thing that
 * must never happen is a TMDB outage taking a screen down. */

declare(strict_types=1);

/**
 * One HTTP GET. Returns array{status:int, body:string} or null.
 *
 * $auth adds TMDB's bearer token — true for the API, false for the image CDN,
 * which is a plain static host and rejects nothing.
 */
function tmdb_http(string $url, int $timeout, bool $auth = true): ?array
{
    /* Test seam. tools/test-harness.php installs a callable here to answer
     * from recorded fixtures instead of the network; nothing in the app sets
     * it, so in production this is one isset() per request. Ported from Book
     * Tracker, where it is what makes the metadata module testable at all. */
    $hook = $GLOBALS['tmdb_http_hook'] ?? null;
    if (is_callable($hook)) {
        $res = $hook($url, $timeout, $auth);
        return is_array($res) ? $res : null;
    }

    $timeout = $timeout > 0 ? $timeout : 15;

    /* TMDB v4 auth: an Authorization: Bearer header carrying the API Read
     * Access Token. NOT the older ?api_key= query parameter — see the note in
     * config.example.php about the two credential types, because pasting the
     * wrong one produces a 401 whose body says "Invalid API key", which reads
     * like the key is wrong rather than the wrong kind. */
    $headers = array('Accept: application/json');
    if ($auth) {
        $token = trim((string) cfg('tmdb.token', ''));
        if ($token === '' || $token === 'CHANGE_ME') {
            error_log('tmdb: no API token configured — set tmdb.token in config.php');
            return null;
        }
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                // The image CDN redirects; following is required for a binary
                // fetch to return anything at all.
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_USERAGENT      => 'Movies/1.0 (kathrynmarinaro.com)',
                CURLOPT_ACCEPT_ENCODING => '',
            ));
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error  = curl_error($ch);
            curl_close($ch);

            if (is_string($body)) {
                if ($status === 429 || $status >= 500) {
                    error_log('tmdb: HTTP ' . $status . ' from ' . $url);
                }
                return array('status' => $status, 'body' => $body);
            }
            error_log('tmdb: cURL failed for ' . $url . ': ' . $error);
        }
    }

    if (!ini_get('allow_url_fopen')) {
        return null;
    }

    $ctx = stream_context_create(array('http' => array(
        'timeout'       => $timeout,
        'header'        => implode("\r\n", $headers),
        // Read the body of a 404 or a 429 instead of throwing a warning and
        // returning false, so the caller sees a status rather than a mystery.
        'ignore_errors' => true,
    )));
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        error_log('tmdb: file_get_contents failed for ' . $url);
        return null;
    }

    /* $http_response_header is set by the stream wrapper in the calling scope.
     * Absent on some SAPIs, so a successful read with no parsable status line
     * is treated as 200 — we have a body either way. */
    $status = 200;
    foreach ((array) ($http_response_header ?? array()) as $header) {
        if (preg_match('#^HTTP/[0-9.]+\s+(\d{3})#', (string) $header, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return array('status' => $status, 'body' => $body);
}

/**
 * Did the last TMDB call reach the API at all?
 *
 * "TMDB has no film by that name" and "this host cannot reach TMDB" both come
 * back as an empty result list, and they need completely different responses
 * from the person looking at the screen: one means try another spelling, the
 * other means the plan cannot do this and everything must be entered by hand.
 *
 * A module-level static rather than a richer return type, because the search
 * contract is a plain list and every caller but one is happy with that. Read
 * it immediately after a call; the next one overwrites it.
 *
 * This is the same shape as mailer_last_error(), and for the same reason.
 */
function tmdb_reached(?bool $set = null): bool
{
    static $reached = true;
    if ($set !== null) {
        $reached = $set;
    }
    return $reached;
}

/** GET a TMDB API path and return the decoded array, or null. */
function tmdb_get_json(string $path, array $query = array()): ?array
{
    $base = rtrim((string) cfg('tmdb.api_base', 'https://api.themoviedb.org/3'), '/');
    $url  = $base . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $res = tmdb_http($url, (int) cfg('tmdb.timeout', 15));
    if ($res === null) {
        /* No response at all: DNS, a refused connection, a timeout, or no API
         * token configured. This is the one that changes what the app can do. */
        tmdb_reached(false);
        return null;
    }
    tmdb_reached(true);

    if ($res['status'] < 200 || $res['status'] > 299) {
        error_log('tmdb: HTTP ' . $res['status'] . ' for ' . $path);
        /* A 401 means the host CAN reach TMDB and the token is wrong — which
         * is a five-minute fix, not a re-plan. Reported as reached, because
         * that is the true and more useful statement. */
        return null;
    }

    $parsed = json_decode($res['body'], true);
    return is_array($parsed) ? $parsed : null;
}

/** A full image URL for a TMDB path at a given size. */
function tmdb_image_url(string $imagePath, string $size): string
{
    $base = rtrim((string) cfg('tmdb.image_base', 'https://image.tmdb.org/t/p/'), '/');
    return $base . '/' . $size . '/' . ltrim($imagePath, '/');
}

/**
 * Normalize one TMDB movie object into the shape the rest of the app uses.
 *
 * ONE SHAPE, produced here and nowhere else, so the search list, the add flow
 * and the release-date re-check all agree about what a movie looks like.
 *
 *   { tmdb_id, title, year, release_date, poster_path, thumb, genre_ids }
 *
 * poster_path and thumb are BOTH null when TMDB has no art — not a placeholder
 * URL. Consumers render the titled `.poster-none` block rather than a broken
 * image. year and release_date may also be null.
 */
function tmdb_normalize(array $movie): ?array
{
    $id    = isset($movie['id']) ? (int) $movie['id'] : 0;
    $title = trim((string) ($movie['title'] ?? $movie['original_title'] ?? ''));
    if ($id <= 0 || $title === '') {
        return null;
    }

    /* TMDB serves an EMPTY STRING, not null, for an undated film — and an
     * empty string written into a DATE column becomes '0000-00-00' on a
     * permissive MySQL, which then formats as a real date on screen. Normalise
     * it to null here, once, rather than in each caller. */
    $release = trim((string) ($movie['release_date'] ?? ''));
    $release = $release === '' ? null : $release;

    $poster = $movie['poster_path'] ?? null;
    $poster = (is_string($poster) && $poster !== '') ? $poster : null;

    /* Both endpoint shapes: /search/movie returns genre_ids (a list of ints),
     * /movie/{id} returns genres (a list of objects). Handled here so callers
     * never have to know which endpoint their row came from. */
    $genreIds = array();
    if (isset($movie['genre_ids']) && is_array($movie['genre_ids'])) {
        foreach ($movie['genre_ids'] as $g) {
            $genreIds[] = (int) $g;
        }
    } elseif (isset($movie['genres']) && is_array($movie['genres'])) {
        foreach ($movie['genres'] as $g) {
            if (isset($g['id'])) {
                $genreIds[] = (int) $g['id'];
            }
        }
    }

    return array(
        'tmdb_id'      => $id,
        'title'        => $title,
        'year'         => date_year($release),
        'release_date' => $release,
        'poster_path'  => $poster,
        'thumb'        => $poster === null
            ? null
            : tmdb_image_url($poster, (string) cfg('tmdb.thumb_size', 'w185')),
        'genre_ids'    => $genreIds,
    );
}

/**
 * Search movies by title. Returns a list of normalized results, newest first
 * within TMDB's own relevance ordering.
 *
 * TMDB's relevance ranking is genuinely good — unlike Open Library, which
 * ranks AI-generated study guides into the top three and forced Book Tracker
 * to carry a junk-pattern filter. There is no equivalent filtering here
 * because there is no equivalent problem; if one appears, this is where it
 * goes.
 */
function tmdb_search(string $query, ?int $limit = null): array
{
    $query = trim($query);
    if ($query === '') {
        return array();
    }
    $limit = $limit ?? (int) cfg('tmdb.results', 4);

    $data = tmdb_get_json('/search/movie', array(
        'query'         => $query,
        'include_adult' => 'false',
        'language'      => 'en-US',
        'page'          => 1,
    ));
    if ($data === null || !isset($data['results']) || !is_array($data['results'])) {
        return array();
    }

    $out = array();
    foreach ($data['results'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = tmdb_normalize($row);
        if ($norm !== null) {
            $out[] = $norm;
        }
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/** One movie by TMDB id, normalized. Null if TMDB doesn't have it or is down. */
function tmdb_get(int $tmdbId): ?array
{
    if ($tmdbId <= 0) {
        return null;
    }
    $data = tmdb_get_json('/movie/' . $tmdbId, array('language' => 'en-US'));

    return $data === null ? null : tmdb_normalize($data);
}

/**
 * Just the release date for one movie, as Y-m-d, or null.
 *
 * Separate from tmdb_get() because the cron's verify-before-send step wants
 * exactly this and nothing else, and because the DIFFERENCE between "TMDB says
 * there is no date" and "TMDB could not be reached" matters there — the first
 * means the movie was un-dated and the reminder should not fire, the second
 * means send anyway on the frozen date.
 *
 * @return array{ok: bool, release_date: ?string}  ok=false means unreachable.
 */
function tmdb_release_date(int $tmdbId): array
{
    if ($tmdbId <= 0) {
        return array('ok' => false, 'release_date' => null);
    }

    $data = tmdb_get_json('/movie/' . $tmdbId, array('language' => 'en-US'));
    if ($data === null) {
        return array('ok' => false, 'release_date' => null);
    }

    $release = trim((string) ($data['release_date'] ?? ''));
    return array('ok' => true, 'release_date' => $release === '' ? null : $release);
}

/** TMDB's own genre list, as [tmdb_id => name]. Used by the genre seeder. */
function tmdb_genres(): array
{
    $data = tmdb_get_json('/genre/movie/list', array('language' => 'en-US'));
    if ($data === null || !isset($data['genres']) || !is_array($data['genres'])) {
        return array();
    }

    $out = array();
    foreach ($data['genres'] as $g) {
        if (isset($g['id'], $g['name'])) {
            $out[(int) $g['id']] = (string) $g['name'];
        }
    }
    return $out;
}

/**
 * Subscription streaming providers for one movie, in the configured region.
 *
 * ---------------------------------------------------------------------------
 * SUBSCRIPTION ONLY, FILTERED AT PARSE TIME.
 * ---------------------------------------------------------------------------
 *
 * TMDB's response carries three lists per region: `flatrate` (subscription),
 * `rent` and `buy`. The brief puts rent and buy explicitly out of scope, and
 * this function reads ONLY `flatrate` — so those options never enter the
 * database and cannot reach a screen through a later template edit. Filtering
 * at render time instead would leave the data one careless loop away from
 * being displayed.
 *
 * `ads` and `free` are also not read. They are a different question
 * ("where can I watch this at no cost") from the one the brief asks ("is this
 * on something I already pay for"), and mixing them would make the list
 * untrustworthy for deciding what to watch tonight.
 *
 * @return array{ok: bool, providers: array<int, array{provider_id:int, provider_name:string, logo_path:?string}>}
 *         ok=false means TMDB was unreachable — which is NOT the same as an
 *         empty provider list, and the caller must not cache the two alike.
 */
function tmdb_providers(int $tmdbId): array
{
    if ($tmdbId <= 0) {
        return array('ok' => false, 'providers' => array());
    }

    $data = tmdb_get_json('/movie/' . $tmdbId . '/watch/providers');
    if ($data === null) {
        return array('ok' => false, 'providers' => array());
    }

    $region  = strtoupper(trim((string) cfg('tmdb.region', 'US')));
    $results = $data['results'] ?? array();

    /* A region with no entry at all is a legitimate empty answer, not a
     * failure: it means nobody streams this film there. ok stays true so the
     * caller caches the emptiness and stops asking. */
    if (!is_array($results) || !isset($results[$region]) || !is_array($results[$region])) {
        return array('ok' => true, 'providers' => array());
    }

    $flatrate = $results[$region]['flatrate'] ?? array();
    if (!is_array($flatrate)) {
        return array('ok' => true, 'providers' => array());
    }

    $out  = array();
    $seen = array();
    foreach ($flatrate as $p) {
        if (!is_array($p) || !isset($p['provider_id'], $p['provider_name'])) {
            continue;
        }
        $id = (int) $p['provider_id'];
        /* TMDB lists the same service twice when it has multiple tiers
         * (Netflix and Netflix basic with ads, say). One row per service is
         * what a person wants to see. */
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;

        $logo = $p['logo_path'] ?? null;
        $out[] = array(
            'provider_id'   => $id,
            'provider_name' => (string) $p['provider_name'],
            'logo_path'     => (is_string($logo) && $logo !== '') ? $logo : null,
        );
    }

    return array('ok' => true, 'providers' => $out);
}

/* ------------------------------------------------- the availability cache */

/**
 * Providers for a movie, from the cache, refreshing it when stale.
 *
 * The cache is what keeps a detail view free of network calls in the common
 * case. Availability changes when licensing deals turn over — monthly-ish —
 * so a TTL in days trades a week of staleness for a screen that renders
 * instantly and still works while TMDB is down.
 *
 * THE EMPTY ANSWER IS CACHED TOO, and that is the reason
 * movie_provider_fetches exists as a separate table. A movie streaming nowhere
 * has no rows in movie_providers to carry a timestamp, so without a separate
 * record of the fetch every view of it would look like a cache miss and
 * re-query TMDB forever — on precisely the movies where the answer is least
 * likely to change.
 *
 * A FAILED fetch does not touch either table. Caching an outage as "nothing
 * available" would be a wrong answer held for a week.
 */
function providers_for_movie(int $movieId, ?int $tmdbId, ?string $now = null): array
{
    $now = $now ?? date('Y-m-d H:i:s');
    $ttl = max(0, (int) cfg('tmdb.providers_ttl_days', 7));

    $fetched = q(
        'SELECT fetched_at FROM movie_provider_fetches WHERE movie_id = ?',
        array($movieId)
    )->fetchColumn();

    $fresh = false;
    if ($fetched !== false && $fetched !== null) {
        $age   = (strtotime($now) - strtotime((string) $fetched)) / 86400;
        $fresh = $age >= 0 && $age < $ttl;
    }

    if (!$fresh && $tmdbId !== null && $tmdbId > 0) {
        $res = tmdb_providers($tmdbId);
        if ($res['ok']) {
            providers_store($movieId, $res['providers'], $now);
        }
        // On !ok we fall through and serve whatever is cached, however old.
        // Stale availability beats an empty box that looks like a bug.
    }

    return q(
        'SELECT provider_id, provider_name, logo_path FROM movie_providers
          WHERE movie_id = ? ORDER BY provider_name',
        array($movieId)
    )->fetchAll();
}

/** Replace a movie's cached providers. Records the fetch even when empty. */
function providers_store(int $movieId, array $providers, string $now): void
{
    q('DELETE FROM movie_providers WHERE movie_id = ?', array($movieId));

    foreach ($providers as $p) {
        q(
            'INSERT INTO movie_providers (movie_id, provider_id, provider_name, logo_path, fetched_at)
             VALUES (?, ?, ?, ?, ?)',
            array($movieId, $p['provider_id'], $p['provider_name'], $p['logo_path'], $now)
        );
    }

    /* The fetch record, written whether or not anything was found. See the
     * note in providers_for_movie() — this row is the TTL, not the provider
     * rows' own timestamps. */
    q(
        'INSERT INTO movie_provider_fetches (movie_id, fetched_at) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at)',
        array($movieId, $now)
    );
}
