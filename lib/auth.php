<?php
/* Single-password auth on top of a long-lived PHP session.
 *
 * Ported from Book Tracker, which ports it from the Workout Generator. The
 * session handling, the throttling and the CSRF header are unchanged and
 * fixes to them should land in the siblings too.
 *
 * ---------------------------------------------------------------------------
 * ONE DELIBERATE DIVERGENCE FROM EVERY SIBLING: THE GATE FAILS CLOSED.
 * ---------------------------------------------------------------------------
 *
 * Book Tracker's require_admin() returns early — letting you straight through —
 * when no password hash is configured. That is right for THAT app: it shipped
 * with no login at all, its collection was designed to be embedded publicly,
 * and failing open only exposed screens that were already reachable by anyone
 * who knew the URL. Locking its owner out would have been the worse failure.
 *
 * This app inverts both halves of that reasoning:
 *
 *   1. THE DATA IS NOT PUBLIC. The brief is explicit that the Coming Soon and
 *      To Watch lists must never be visible to anyone else — that is the stated
 *      reason the public view shows only the watched collection. A gate that
 *      fails open publishes exactly the two lists that were never meant to be
 *      published, at a guessable URL, silently.
 *   2. FAILING CLOSED CANNOT LOCK ANYBODY OUT HERE. There is no state to lose
 *      and no way in that gets taken away: config.php is a file you already
 *      have to edit to deploy at all, and auth_config_message() below tells you
 *      the one command to run. Book Tracker's owner could have been locked out
 *      of live reading history; this app's owner is locked out of an empty
 *      database until they finish a deploy step they are already mid-way
 *      through.
 *
 * So: no hash configured means private screens refuse and say why. The public
 * collection page is unaffected — it never calls require_admin() and shows only
 * watched movies through the whitelist in lib/repo.php.
 *
 * IF YOU PORT THIS FILE BACK TO A SIBLING, PORT THE FAIL-OPEN BACK WITH IT.
 * The difference is a judgement about that app's data, not a bug fix. */

declare(strict_types=1);

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $days    = (int) cfg('session_days', 90);
    $seconds = $days * 86400;
    $https   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_set_cookie_params(array(
        'lifetime' => $seconds,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ));
    ini_set('session.gc_maxlifetime', (string) $seconds);

    /* Its own cookie name, like every app in the suite. Signing in or out here
     * must never disturb the Book Tracker, the CRM or Grocery on the same
     * host — they are separate apps that happen to share a domain's cookie
     * space. */
    session_name('movies');
    session_start();
}

function auth_is_logged_in(): bool
{
    auth_start_session();
    return !empty($_SESSION['authed']);
}

/** True once a password hash is configured — i.e. the gate is usable at all. */
function auth_is_configured(): bool
{
    $hash = (string) cfg('password_hash', '');
    return $hash !== '' && $hash !== 'CHANGE_ME';
}

/** What to tell somebody who reached a private screen with no hash set. */
function auth_config_message(): string
{
    return 'This app has no password set yet, so the private screens are closed.'
        . ' Run  php tools/make-hash.php  and paste the result into config.php as'
        . " 'password_hash'.";
}

/* ------------------------------------------------------- login throttling */

/* A single password on the public internet needs more than a fixed delay, or
 * an attacker gets unlimited guesses at whatever rate the server allows.
 *
 * Counting is keyed to the client address in the database, not the session —
 * an attacker just drops the cookie, so session counters protect nothing. */

const AUTH_WINDOW_MINUTES = 15;   // how far back failures are counted
const AUTH_LOCK_AFTER     = 10;   // failures in that window before refusing
const AUTH_SLOW_AFTER     = 3;    // failures before delays start escalating
const AUTH_MAX_DELAY      = 4;    // seconds — cap so a request can't hang

/**
 * REMOTE_ADDR only, deliberately.
 *
 * X-Forwarded-For is trivially spoofed, and trusting it would let an attacker
 * present a new address per request and bypass this entirely. The cost is that
 * behind a proxy every visitor may share one address — which is why there is
 * no permanent lockout below, only a window that always expires.
 */
function auth_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : 'unknown';
}

/**
 * Throttle state for this client: recent failure count and, if locked out,
 * how many seconds remain.
 *
 * Fails OPEN if the table is missing — deploying this code before running the
 * schema should not lock you out of your own app. It logs instead. (Note this
 * is the THROTTLE failing open, not the gate: a missing table means no
 * rate limiting, never a free pass past the password.)
 *
 * @return array{failures:int, blocked_for:int}
 */
function auth_throttle_state(): array
{
    $none = array('failures' => 0, 'blocked_for' => 0);

    /* Every timestamp comparison happens inside SQL, on MySQL's clock.
     *
     * Doing the arithmetic in PHP silently disabled this in a sibling app:
     * PHP ran in UTC while MySQL ran in CDT, so strtotime() read MySQL's
     * local-time string as UTC and produced an unlock time five hours in the
     * past. blocked_for was always 0 and the lockout never fired, while the
     * escalating delays kept making it look like throttling worked.
     * One clock, no conversions. */
    try {
        $row = q(
            'SELECT COUNT(*) AS failures,
                    GREATEST(0, COALESCE(
                      TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL ? MINUTE), 0
                    )) AS blocked_for
               FROM login_attempts
              WHERE ip = ?
                AND succeeded = 0
                AND attempted_at > NOW() - INTERVAL ? MINUTE',
            array(AUTH_WINDOW_MINUTES, auth_client_ip(), AUTH_WINDOW_MINUTES)
        )->fetch();
    } catch (Throwable $e) {
        error_log('auth: throttle unavailable (run schema.sql): ' . $e->getMessage());
        return $none;
    }

    $failures = (int) ($row['failures'] ?? 0);

    // The window runs from the most recent failure, so hammering the lock
    // keeps it shut rather than letting attempts leak through as it ages out.
    return array(
        'failures'    => $failures,
        'blocked_for' => $failures >= AUTH_LOCK_AFTER ? (int) $row['blocked_for'] : 0,
    );
}

/** Seconds this client must wait, or 0 when it may try. */
function auth_blocked_for(): int
{
    return auth_throttle_state()['blocked_for'];
}

function auth_record_attempt(bool $succeeded): void
{
    try {
        q(
            'INSERT INTO login_attempts (ip, succeeded) VALUES (?, ?)',
            array(auth_client_ip(), $succeeded ? 1 : 0)
        );

        // Clear this address's failures on success so one good login resets
        // the counter, and prune old rows so the table can't grow unbounded.
        if ($succeeded) {
            q('DELETE FROM login_attempts WHERE ip = ? AND succeeded = 0', array(auth_client_ip()));
        }
        if (random_int(1, 20) === 1) {
            q('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
        }
    } catch (Throwable $e) {
        error_log('auth: could not record attempt: ' . $e->getMessage());
    }
}

/**
 * Verify a password attempt and start the session. Returns success.
 *
 * Callers must check auth_blocked_for() first — this records the attempt and
 * escalates its own delay, but does not enforce the lockout itself.
 */
function auth_attempt_login(string $password): bool
{
    auth_start_session();

    if (!auth_is_configured()) {
        return false;
    }
    $hash = (string) cfg('password_hash', '');

    $failures = auth_throttle_state()['failures'];

    // Baseline delay so a wrong password can't be timed, then escalate once
    // this address starts looking like a guessing loop: 0.5s, 1s, 2s, 4s...
    // capped, so an attacker's throughput collapses while one honest typo
    // still costs nothing noticeable.
    $delay = 0.25;
    if ($failures >= AUTH_SLOW_AFTER) {
        $delay = min(0.25 * (2 ** ($failures - AUTH_SLOW_AFTER + 1)), AUTH_MAX_DELAY);
    }
    usleep((int) round($delay * 1_000_000));

    if (!password_verify($password, $hash)) {
        auth_record_attempt(false);
        return false;
    }

    auth_record_attempt(true);

    session_regenerate_id(true);
    $_SESSION['authed']   = true;
    $_SESSION['login_at'] = time();
    return true;
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', array(
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'httponly' => true,
            'secure'   => $p['secure'],
            'samesite' => 'Lax',
        ));
    }
    session_destroy();
}

/* ------------------------------------------------------------- THE GATE */

/**
 * Gate a private screen. Called by EVERY private entry point.
 *
 * FAILS CLOSED when no password hash is configured — see the file header for
 * why this app diverges from the siblings here. The refusal names the fix,
 * because the only person who can hit it is the person deploying it.
 */
function require_admin(): void
{
    noindex();

    if (!auth_is_configured()) {
        fatal_error('no_password_set', auth_config_message(), 503);
    }
    if (!auth_is_logged_in()) {
        // Come back here after signing in, rather than dumping you on the
        // collection and making you navigate to what you were already doing.
        $next = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        $qs   = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            $next .= '?' . $qs;
        }
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }
}

/**
 * The same gate for JSON endpoints. 401s instead of redirecting.
 *
 * Kept separate from require_admin() rather than branching inside it: a
 * fetch() that follows a 302 to an HTML login page produces a JSON parse
 * error at the caller, which is a genuinely confusing way to learn you're
 * signed out.
 */
function require_admin_api(): void
{
    noindex();

    if (!auth_is_configured()) {
        json_error('no_password_set', 503, auth_config_message());
    }
    if (!auth_is_logged_in()) {
        json_error('unauthorized', 401);
    }
}

/**
 * Keep private screens out of search results.
 *
 * Called from inside both gates above rather than by each screen, so a new
 * private screen cannot forget it. It is not redundant with the gate: a
 * redirect to login.php is still a 302 a crawler will record the URL of, and
 * this app deliberately publishes one page from the same domain, so there IS
 * a crawlable surface that could lead a bot here.
 */
function noindex(): void
{
    header('X-Robots-Tag: noindex, nofollow');
}

/* CSRF: mutating endpoints require a custom header. A cross-origin form post
 * cannot set one without passing a CORS preflight we never answer, so this
 * plus SameSite=Lax is sufficient for a single-user app. */
const CSRF_HEADER_VALUE = 'Movies';

function require_same_origin(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, array('GET', 'HEAD', 'OPTIONS'), true)) {
        return;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== CSRF_HEADER_VALUE) {
        json_error('csrf_check_failed', 403);
    }
}
