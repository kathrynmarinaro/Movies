<?php
/* Poster downloads and uploads.
 *
 * A poster is fetched from TMDB once, written into public/posters/ under a
 * random name, and served from disk forever after — rendering a grid makes no
 * network call at all.
 *
 * Ported from Book Tracker's lib/covers.php. Two things here are load-bearing
 * and both cost real work to get wrong, because these files are cached
 * permanently and fixing them later means re-running the API over the whole
 * collection:
 *
 *   1. SIZE COMES FROM CONFIG AND DEFAULTS TO w500. TMDB's smaller sizes are
 *      visibly soft at retina density on the phone this app is built for.
 *   2. VALIDATION IS BY CONTENT, NOT BY HTTP STATUS OR FILENAME.
 *      getimagesizefromstring() reads the actual header, so a URL that returns
 *      an HTML error page, a truncated download, or an upload named .jpg that
 *      is really something else all fail here.
 *
 * Fail soft: every failure path returns null and the movie saves with
 * poster_path NULL, which is a valid end state. A movie with no poster art
 * still belongs in the grid — render_movie_card() draws a titled placeholder. */

declare(strict_types=1);

require_once __DIR__ . '/tmdb.php';

/* Formats TMDB actually serves, plus the extension each is stored under. This
 * list is also the WHITELIST: a file whose real bytes aren't one of these is
 * not written, whatever the URL or the upload said it was. */
const POSTER_TYPES = array(
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
);

/* A real w500 poster is 500px wide. The byte floor from config catches a
 * one-pixel placeholder on its own, but a dimension floor also catches a
 * padded or corrupt image that happens to weigh enough — cheap, and it is the
 * check that actually expresses "this is a poster". */
const POSTER_MIN_PX = 100;

/**
 * Download a TMDB poster and return its poster_path, or null.
 *
 * The return value is what goes in movies.poster_path: relative to the web
 * root, e.g. "posters/a1b2c3d4e5f6a7b8.jpg". This function does NOT write to
 * the database — the caller owns the row and saves the path alongside
 * everything else, so a poster fetch can never half-update a movie.
 *
 * $movieId is for the log only. It is deliberately NOT part of the filename:
 * names are random so a poster URL can't be guessed from a movie id, and so
 * re-fetching never overwrites a file some other row still points at.
 */
function poster_fetch(string $tmdbPosterPath, int $movieId = 0): ?string
{
    try {
        /* TMDB poster paths look like "/9A1JSVmSxsyaBK4SUFsYVqbAYfW.jpg" and
         * go straight into a URL path. Anything else is refused rather than
         * concatenated — this is the one string in the module that comes from
         * a remote response and becomes part of a request we make. */
        if (preg_match('#^/?[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$#i', $tmdbPosterPath) !== 1) {
            error_log('posters: refusing malformed TMDB path for movie ' . $movieId);
            return null;
        }

        $url = tmdb_image_url($tmdbPosterPath, (string) cfg('tmdb.poster_size', 'w500'));
        $res = tmdb_http($url, (int) cfg('posters.timeout', 20), false);

        if ($res === null) {
            error_log('posters: no response for ' . $tmdbPosterPath . ' (movie ' . $movieId . ')');
            return null;
        }
        if ($res['status'] < 200 || $res['status'] > 299) {
            error_log('posters: HTTP ' . $res['status'] . ' for ' . $tmdbPosterPath
                . ' (movie ' . $movieId . ')');
            return null;
        }

        $bytes = $res['body'];
        if (!poster_is_real_image($bytes) || !poster_looks_complete($bytes)) {
            error_log('posters: rejected ' . strlen($bytes) . ' bytes for ' . $tmdbPosterPath
                . ' (movie ' . $movieId . ') — not a usable image');
            return null;
        }

        return poster_store($bytes, $movieId);
    } catch (Throwable $e) {
        error_log('posters: fetch failed for ' . $tmdbPosterPath . ' (movie ' . $movieId
            . '): ' . $e->getMessage());
        return null;
    }
}

/**
 * Is this blob really an image, at a size worth keeping?
 *
 * Three gates, in increasing cost: the byte floor from config, then a real
 * image-header parse, then dimensions. getimagesizefromstring() is what makes
 * this a CONTENT check — it reads the actual header, so a .jpg URL that
 * returns an HTML error page fails here, and so does an upload whose name and
 * client-supplied MIME type both lie.
 *
 * Public because the manual poster upload needs exactly this test on bytes it
 * didn't download. Validating an upload by filename or by $_FILES['type'] —
 * both of which are supplied by the client — is the classic way a PHP file
 * ends up in an uploads directory.
 */
function poster_is_real_image(string $bytes): bool
{
    if (strlen($bytes) < (int) cfg('posters.min_bytes', 2000)) {
        return false;
    }

    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || !isset($info[0], $info[1], $info[2])) {
        return false;
    }
    if (!isset(POSTER_TYPES[$info[2]])) {
        return false;
    }

    return $info[0] >= POSTER_MIN_PX && $info[1] >= POSTER_MIN_PX;
}

/**
 * Does this download end where the format says it should?
 *
 * An image header parses fine on a HALF-DOWNLOADED file — getimagesizefromstring()
 * reads the first few bytes and reports the dimensions the header claims — so a
 * dropped connection can otherwise produce a file that validates, gets cached
 * forever, and renders as a poster that fades into grey halfway down. cURL
 * catches a short read when Content-Length is known; this is the cheap
 * belt-and-braces on the one file we are about to keep permanently.
 *
 * Deliberately NOT part of poster_is_real_image(): that one is shared with the
 * manual upload path, where a photo straight off a phone may legitimately
 * carry trailing metadata after the end marker. Only formats with an
 * unambiguous terminator are judged; anything else passes.
 */
function poster_looks_complete(string $bytes): bool
{
    $info = @getimagesizefromstring($bytes);
    // Searched in the TAIL rather than matched at the exact end, so a few bytes
    // of trailing padding don't fail an otherwise complete image.
    $tail = substr($bytes, -32);

    return match ($info[2] ?? -1) {
        IMAGETYPE_JPEG => str_contains($tail, "\xFF\xD9"),   // EOI
        IMAGETYPE_PNG  => str_contains($tail, 'IEND'),
        default        => true,
    };
}

/**
 * Write validated image bytes into public/posters/ and return the poster_path.
 *
 * Shared with the manual-upload path so both routes produce the same kind of
 * filename and the same kind of failure.
 */
function poster_store(string $bytes, int $movieId = 0): ?string
{
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || !isset(POSTER_TYPES[$info[2] ?? -1])) {
        return null;
    }
    // Extension from the REAL header, never from the URL or the upload name.
    $ext = POSTER_TYPES[$info[2]];

    if (!is_dir(POSTER_DIR) && !@mkdir(POSTER_DIR, 0755, true) && !is_dir(POSTER_DIR)) {
        error_log('posters: cannot create ' . POSTER_DIR);
        return null;
    }

    /* Random name, 16 hex characters. Never derived from the title: titles
     * contain characters a filesystem or a URL will mangle, two movies can
     * share one, and a guessable poster URL would leak the collection of an
     * app whose whole point is that two of its three lists are private. */
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $path = POSTER_DIR . '/' . $name;

    $written = @file_put_contents($path, $bytes, LOCK_EX);
    if ($written !== strlen($bytes)) {
        // A partial write must not become a poster_path pointing at a
        // truncated file — the grid would render a broken image forever.
        error_log('posters: write failed for movie ' . $movieId . ' at ' . $path);
        @unlink($path);
        return null;
    }
    // The FTP upload and the web server run as different users on Hostinger,
    // so a file written by PHP needs explicit read permission for the server.
    @chmod($path, 0644);

    return poster_dir_name() . '/' . $name;
}

/**
 * Store an uploaded poster. Returns the poster_path, or null with a reason.
 *
 * $file is one entry from $_FILES. Everything about it that the client
 * supplied — the name, the type, even the claimed size — is treated as a hint
 * and nothing more; the bytes are what get validated.
 *
 * @return array{path: ?string, error: ?string}
 */
function poster_store_upload(array $file, int $movieId = 0): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        /* UPLOAD_ERR_INI_SIZE deserves its own sentence: PHP silently discards
         * anything over upload_max_filesize before this code runs, so "nothing
         * happened" is what it looks like from the form. */
        $why = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'That image is larger than the server accepts.',
            UPLOAD_ERR_NO_FILE => 'No file was chosen.',
            default            => 'The upload did not complete.',
        };
        return array('path' => null, 'error' => $why);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        // is_uploaded_file() is what stops a crafted request naming a path on
        // the server's own disk and having it copied into the web root.
        return array('path' => null, 'error' => 'That upload could not be read.');
    }

    $maxBytes = max(1, (int) cfg('posters.max_upload_mb', 8)) * 1024 * 1024;
    if (filesize($tmp) > $maxBytes) {
        return array('path' => null, 'error' => 'That image is too large.');
    }

    $bytes = @file_get_contents($tmp);
    if ($bytes === false) {
        return array('path' => null, 'error' => 'That upload could not be read.');
    }

    if (!poster_is_real_image($bytes)) {
        return array(
            'path'  => null,
            'error' => 'That file is not an image the app can use. JPEG, PNG, GIF or WebP, at least 100px.',
        );
    }

    $path = poster_store($bytes, $movieId);
    return $path === null
        ? array('path' => null, 'error' => 'The image could not be saved.')
        : array('path' => $path, 'error' => null);
}

/** The posters directory name as it appears in poster_path, without slashes. */
function poster_dir_name(): string
{
    return trim((string) cfg('posters.dir', 'posters'), '/');
}
