<?php
/* The handful of lib/bootstrap.php helpers the test suite needs, without
 * bootstrap.php itself.
 *
 * WHY THIS EXISTS RATHER THAN JUST REQUIRING bootstrap.php. That file does
 * three things a test run must not do: it refuses to start without a real
 * config.php, it defines APP_ROOT / PUBLIC_DIR / POSTER_DIR (which run-tests.php
 * has already defined with test values), and it sets the timezone from a config
 * key that isn't there yet.
 *
 * The alternative — writing a temporary config.php into the repo — is a side
 * effect a test run should not have, and one interrupted run would leave a
 * file behind that the real app would then happily boot from.
 *
 * THE RULE FOR THIS FILE: every function below is a VERBATIM copy of the one in
 * lib/bootstrap.php. If you change one there, change it here, and if you find
 * yourself wanting to change only this copy, that is the signal that the test
 * is wrong rather than the app. Nothing app-specific may be invented here —
 * this file exists to remove bootstrap's SIDE EFFECTS, not to reimplement its
 * behaviour.
 *
 * tools/run-tests.php asserts that these stay in sync (tests_schema()). */

declare(strict_types=1);

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

function h(?string $raw): string
{
    return htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8');
}

function normalize_tag(string $raw): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $raw));
    $t = mb_strtolower($t, 'UTF-8');
    return mb_substr($t, 0, 64, 'UTF-8');
}

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

function asset(string $relative): string
{
    $relative = ltrim($relative, '/');
    $stamp    = @filemtime(PUBLIC_DIR . '/' . $relative);

    return h($stamp === false ? $relative : $relative . '?v=' . $stamp);
}

/* fatal_error() is the one that is NOT copied verbatim: the real one exits, and
 * a test that trips it should fail loudly with a stack trace rather than
 * silently ending the run at statement 40 of 300 with a zero exit code. */
function fatal_error(string $code, string $human, int $status = 500): void
{
    throw new RuntimeException('fatal_error(' . $code . '): ' . $human);
}

function json_out($payload, int $status = 200): void
{
    throw new RuntimeException('json_out() called in a test');
}

function json_error(string $code, int $status = 400, ?string $detail = null): void
{
    throw new RuntimeException('json_error(' . $code . ')');
}
