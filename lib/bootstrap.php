<?php
/* Shared bootstrap: config, requires, JSON helpers.
 * Every entry point (public/*.php, public/api/*.php, tools/*.php) starts by
 * requiring this file.
 *
 * Ported from the Personal CRM, which ports it from Grocery, Book Tracker and
 * the Workout Generator. Fixes to what is shared should land in the siblings
 * too.
 *
 * The date handling is the CRM's, not Book Tracker's, and that is deliberate:
 * this app sends email on a schedule. Everything about the clock below exists
 * because "seven days before release" and "on release day" are the product. */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    exit("config.php is missing. Copy config.example.php to config.php and fill it in.\n");
}
$GLOBALS['config'] = require $configFile;

/* ONE CLOCK, ESTABLISHED HERE, BEFORE ANYTHING ELSE RUNS.
 *
 * Deliberately the very next statement after the config load and before a
 * single require below it, because everything downstream is allowed to ask what
 * day it is and none of it may get a different answer. lib/db.php pins the
 * MySQL connection to the same zone at connect time; between the two, PHP's
 * time() and MySQL's NOW() cannot land on different days at midnight.
 *
 * Explicit rather than inherited from the server: Hostinger's PHP default and
 * its MySQL default are configured independently and neither is guaranteed to
 * be the zone the person using this app lives in.
 *
 * The consequence here is specific. A movie releasing on the 14th has its
 * heads-up reminder on the 7th. If PHP thinks it is the 7th and MySQL thinks it
 * is the 6th, the email either goes twice or never — and "never" is invisible,
 * because nothing on any screen says an email failed to be sent.
 *
 * The fallback is UTC rather than the server default, so a config.php missing
 * the key produces one wrong-but-consistent answer instead of a clock that
 * shifts when the host is reconfigured. (cfg() is declared further down this
 * file and is callable here: PHP binds top-level function declarations before
 * the file's statements run.) */
date_default_timezone_set((string) cfg('timezone', 'UTC'));

/* The web root's directory NAME differs between here and the server: this
 * checkout serves public/, while some Hostinger setups serve public_html/.
 * Kept from the siblings because the failure is silent rather than loud —
 * asset() stats a file under PUBLIC_DIR to build its cache-busting stamp, so a
 * wrong value doesn't error, it just quietly stops busting the cache and you
 * spend an afternoon wondering why a CSS change didn't deploy. */
$publicDirName = (string) ($GLOBALS['config']['public_dir'] ?? '');
if ($publicDirName === '') {
    $publicDirName = is_dir(APP_ROOT . '/public') ? 'public' : 'public_html';
}
define('PUBLIC_DIR', APP_ROOT . '/' . $publicDirName);

/* Where downloaded and uploaded poster art lives. Relative paths stored in
 * movies.poster_path are relative to PUBLIC_DIR, so they work unchanged as
 * both a filesystem path and a URL. */
define('POSTER_DIR', PUBLIC_DIR . '/posters');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* Loaded here rather than left to each caller, deliberately. movies_today()
 * reads PHP's default zone, which the line above set from config — a screen
 * that required dates.php on its own without going through bootstrap would
 * still work, and would silently be on the server's clock. Making it impossible
 * to get that wrong costs one require of a file with no side effects. */
require_once __DIR__ . '/dates.php';

/** Read a config value with dot notation: cfg('db.host'). */
function cfg(string $path, $default = null)
{
    $node = $GLOBALS['config'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

/* ---------------------------------------------------------------- JSON I/O */

function json_out($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $code, int $status = 400, ?string $detail = null): void
{
    $body = array('error' => $code);
    if ($detail !== null) {
        $body['detail'] = $detail;
    }
    json_out($body, $status);
}

/** Decode a JSON request body into an array. */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return array();
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        json_error('invalid_json', 400);
    }
    return $parsed;
}

/** Restrict an endpoint to specific HTTP verbs. */
function require_method(string ...$allowed): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error('method_not_allowed', 405);
    }
    return $method;
}

/* ------------------------------------------------------------ fatal errors */

/**
 * Bail out with an error rendered in the format the caller can actually use.
 *
 *   CLI          -> STDERR + exit 1, so tools/ scripts fail loudly
 *   /api/*       -> JSON, because that's what the fetch() on the other end
 *                   is parsing
 *   everything   -> a minimal HTML page using the real stylesheet
 *   else
 *
 * Routing off the /api/ path segment is safe because docs/CONTRACTS.md fixes
 * the file layout: JSON endpoints live in public/api/ and nowhere else.
 */
function fatal_error(string $code, string $human, int $status = 500): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $code . ': ' . $human . PHP_EOL);
        exit(1);
    }

    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        json_error($code, $status);
    }

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $safe = htmlspecialchars($human, ENT_QUOTES, 'UTF-8');
    /* Cache-busted like everywhere else, but NOT via asset(): this can fire
     * before PUBLIC_DIR is defined (a config.php that returns something that
     * isn't an array, say), and a fatal handler that itself fatals shows the
     * user a blank page. */
    $mtime   = @filemtime(__DIR__ . '/../public/assets/styles.css')
        ?: @filemtime(__DIR__ . '/../public_html/assets/styles.css');
    $cssHref = 'assets/styles.css' . ($mtime ? '?v=' . $mtime : '');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Movies</title>
<link rel="stylesheet" href="/{$cssHref}">
</head>
<body class="login-body">
  <main class="login-card">
    <h1 class="login-title">Movies</h1>
    <p>{$safe}</p>
    <p class="hint">This is a server problem, not something you did.</p>
  </main>
</body>
</html>

HTML;
    exit;
}

/* ---------------------------------------------------------------- misc */

/**
 * Cache-busted URL for a file under the web root.
 *
 *   <link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
 *   -> assets/styles.css?v=1751049600
 *
 * Without this, a browser holding yesterday's stylesheet keeps using it after a
 * deploy and there is nothing on the page to tell you: the app renders, mostly
 * correctly, with whichever rules happen to be missing simply not applying.
 *
 * Keyed to the file's own mtime, so a changed file busts itself and an
 * unchanged one stays cached. Falls back to no version if the file can't be
 * stat'd, which only costs the cache benefit rather than breaking the link.
 */
function asset(string $relative): string
{
    $relative = ltrim($relative, '/');
    $stamp    = @filemtime(PUBLIC_DIR . '/' . $relative);

    return h($stamp === false ? $relative : $relative . '?v=' . $stamp);
}

/** Escape for HTML output. Shorthand because templates are full of it. */
function h(?string $raw): string
{
    return htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8');
}

/**
 * Turn a free-text genre or tag name into its canonical stored form.
 *
 * Lowercased so 'Science Fiction' and 'science fiction' are one genre, not two
 * — the UNIQUE key on genres.name depends on this having already run. TMDB's
 * own genre list is seeded through here for the same reason, so a custom tag
 * typed by hand can collide with a TMDB genre and be recognised as the same
 * thing rather than sitting beside it as a near-duplicate.
 *
 * This applies ONLY to genre names. Notes and titles are stored exactly as
 * typed and never pass through here.
 */
function normalize_tag(string $raw): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $raw));
    $t = mb_strtolower($t, 'UTF-8');
    return mb_substr($t, 0, 64, 'UTF-8');
}

/**
 * Render a stored date for a human. NOT date arithmetic — that is lib/dates.php,
 * where it is pure and tested. This only chooses letters.
 *
 *   fmt_date('2026-04-15')              -> 'April 15, 2026'
 *   fmt_date('2026-04-15', 'M j')       -> 'Apr 15'
 *   fmt_date(null)                      -> ''
 *
 * Having exactly one of these is the point — six templates each calling
 * date('M j, Y', strtotime($row['x'])) is six places for a NULL to render as
 * "Jan 1, 1970", which is the specific failure this replaces. A movie with no
 * announced release date is normal here, not an error, so the empty string is
 * a expected return value and every caller has to handle it.
 */
function fmt_date(?string $date, string $format = 'F j, Y'): string
{
    if ($date === null || $date === '' || str_starts_with($date, '0000-')) {
        return '';
    }

    /* No timezone conversion happens here — the string carries no zone, and
     * DateTimeImmutable reads it in the app's zone (set at the top of this
     * file) and formats it back out in the same one. Nothing shifts. */
    try {
        return (new DateTimeImmutable($date))->format($format);
    } catch (Exception $e) {
        return '';
    }
}
