<?php
/* The test suite.
 *
 *   php tools/run-tests.php
 *
 * No framework, no dependencies, no network, no MySQL. tools/test-harness.php
 * translates schema.sql into an in-memory SQLite database and installs it as
 * db()'s connection, so every test calls the REAL q() and the REAL repo
 * functions rather than a parallel query path that could drift from what
 * ships.
 *
 * WHAT THIS PROVES: the schema parses and its constraints bite, the repo
 * functions execute end to end, the public whitelist withholds what it should,
 * and the reminder cron does the right thing on every boundary day.
 *
 * WHAT IT DOES NOT PROVE: anything about MySQL specifically, or that SMTP or
 * TMDB work from the deployment host. tools/hosting-check.php answers the
 * second; loading schema.sql into a real MariaDB once before deploying answers
 * the first. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* A config good enough to boot, with no real credentials in it. This is why
 * the suite runs on a fresh checkout with no config.php: bootstrap.php refuses
 * to load without one, and writing a temporary file to the repo would be a
 * side effect the tests shouldn't have. */
$GLOBALS['config'] = array(
    'timezone'   => 'America/Chicago',
    'public_dir' => 'public',
    'db'         => array('host' => 'localhost', 'name' => 'movies_test'),
    'app_url'    => 'https://movies.example.com',
    'tmdb'       => array(
        'token' => 'test-token', 'region' => 'US', 'results' => 4,
        'api_base' => 'https://api.themoviedb.org/3',
        'image_base' => 'https://image.tmdb.org/t/p/',
        'poster_size' => 'w500', 'thumb_size' => 'w185',
        'providers_ttl_days' => 7,
    ),
    'posters'    => array('dir' => 'posters', 'min_bytes' => 2000),
    'reminders'  => array('heads_up_days' => 7, 'max_per_run' => 20, 'verify_before_send' => true),
    'smtp'       => array(
        'host' => 'smtp.example.com', 'user' => 'a@example.com', 'pass' => 'x',
        'from_email' => 'a@example.com', 'to' => 'a@example.com',
    ),
);

define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_DIR', APP_ROOT . '/public');
define('POSTER_DIR', PUBLIC_DIR . '/posters');
date_default_timezone_set('America/Chicago');

/* bootstrap.php is not required here — it would re-define the constants above
 * and demand a config.php. Its functions are pulled in individually instead,
 * which also documents exactly what the tested code depends on. */
require_once __DIR__ . '/bootstrap-shim.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/dates.php';
require_once __DIR__ . '/../lib/repo.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/tmdb.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/reminders.php';
require_once __DIR__ . '/../lib/cron.php';
require_once __DIR__ . '/test-harness.php';
require_once __DIR__ . '/tests-reminders.php';
require_once __DIR__ . '/tests-repo.php';

/* ------------------------------------------------------------- assertions */

$GLOBALS['t_pass']   = 0;
$GLOBALS['t_fail']   = 0;
$GLOBALS['t_failures'] = array();
$GLOBALS['t_group']  = '';

function t_group(string $name): void
{
    $GLOBALS['t_group'] = $name;
    echo "\n  " . $name . "\n";
}

function t_ok(bool $condition, string $label): void
{
    if ($condition) {
        $GLOBALS['t_pass']++;
        echo "    ok   " . $label . "\n";
        return;
    }
    $GLOBALS['t_fail']++;
    $GLOBALS['t_failures'][] = $GLOBALS['t_group'] . ' — ' . $label;
    echo "    FAIL " . $label . "\n";
}

function t_is($actual, $expected, string $label): void
{
    /* Loose on numeric strings, because PDO returns integers as strings on
     * some drivers and the tests are about behaviour, not about which driver
     * boxed the value. Everything else is compared strictly. */
    $same = (is_int($expected) && is_numeric($actual))
        ? (int) $actual === $expected
        : $actual === $expected;

    if ($same) {
        $GLOBALS['t_pass']++;
        echo "    ok   " . $label . "\n";
        return;
    }

    $GLOBALS['t_fail']++;
    $detail = sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true));
    $GLOBALS['t_failures'][] = $GLOBALS['t_group'] . ' — ' . $label . ' (' . $detail . ')';
    echo "    FAIL " . $label . "\n         " . $detail . "\n";
}

/** A fresh, empty database. Called at the top of anything that writes rows. */
function t_reset(): void
{
    harness_pdo(dirname(__DIR__) . '/schema.sql');
}

/* ------------------------------------------------------------------- run */

echo "Movies — test suite\n";

t_reset();

tests_schema();
tests_repo();
tests_reminders();

echo "\n";
echo $GLOBALS['t_pass'] . ' passed, ' . $GLOBALS['t_fail'] . " failed\n";

if ($GLOBALS['t_fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($GLOBALS['t_failures'] as $f) {
        echo '  - ' . $f . "\n";
    }
    exit(1);
}
exit(0);
