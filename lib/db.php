<?php
/* PDO connection, created once per request on first use.
 *
 * Ported from the Personal CRM, which ports it from Grocery, Book Tracker and
 * the Workout Generator. Fixes here should land in the siblings too.
 *
 * The time-zone pin at connect time comes from the CRM rather than from Book
 * Tracker, and it is not optional in this app: a reminder cron whose PHP and
 * MySQL disagree about the date sends on the wrong day, and nothing on any
 * screen would ever say so. */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    /* THE ONE DEVIATION FROM THE SIBLINGS' db.php.
     *
     * tools/test-harness.php builds an in-memory SQLite database from
     * schema.sql and puts it here, so that tests exercise the REAL repo
     * functions through the real q() rather than a parallel query path that
     * could drift from the shipped one. There is no MySQL in the build
     * environment, so the alternative is testing nothing.
     *
     * CHECKED BEFORE THE STATIC, AND NEVER CACHED INTO IT. The test suite
     * creates a FRESH in-memory database between test groups so that one
     * group's rows cannot leak into the next one's counts. If the static were
     * consulted first, every reset after the first would be silently ignored
     * and the suite would quietly be asserting against accumulated data —
     * which is a test suite that passes for the wrong reasons, the worst kind
     * to own.
     *
     * Gated on CLI so this is not reachable over HTTP under any circumstances
     * — not even with variable injection, because $GLOBALS is not populated
     * from the request. On the web path this is one PHP_SAPI comparison per
     * call, which short-circuits before the isset. */
    if (PHP_SAPI === 'cli'
        && isset($GLOBALS['movies_pdo_override'])
        && $GLOBALS['movies_pdo_override'] instanceof PDO
    ) {
        return $GLOBALS['movies_pdo_override'];
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        cfg('db.host', 'localhost'),
        cfg('db.name'),
        cfg('db.charset', 'utf8mb4')
    );

    /* ONE CLOCK. PHP's default zone is set in lib/bootstrap.php; this is the
     * other half, so MySQL's NOW() and PHP's time() cannot land on different
     * days at midnight.
     *
     * Nothing in this app compares the two directly — every reminder query is
     * `WHERE release_date = ?` with the date computed by movies_today() — but
     * login_attempts throttling does its arithmetic entirely inside SQL, and
     * movie_reminder_sends.sent_at is stamped from the run's own $today. A skew
     * there is a release-day email that arrives the day after the release.
     *
     * A NUMERIC OFFSET, NOT A NAME. Hostinger's MySQL commonly ships with the
     * named time-zone tables (mysql.time_zone_name) unloaded, and
     * `SET time_zone = 'America/Chicago'` then fails at connect time with an
     * unhelpful error — which, as an init command, means the connection itself
     * fails and the whole app 500s. The offset always works.
     *
     * The offset is computed fresh per request, so DST is handled by the fact
     * that a connection never outlives the request that opened it. */
    $offset = (new DateTime('now', new DateTimeZone((string) cfg('timezone', 'UTC'))))->format('P');

    try {
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.pass'), array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . $offset . "'",
        ));
    } catch (PDOException $e) {
        // The real reason goes to the log, never to a WEB response — a
        // connection error message can carry the host, database name and user.
        error_log('DB connect failed: ' . $e->getMessage());

        /* On the command line, say it out loud. There is nobody to hide it
         * from: you are already logged into the account, standing in the
         * directory, with config.php open in the next window.
         *
         * Hostinger is the specific reason this matters. It prefixes both the
         * database name and the username with the account id, so the values in
         * config.example.php are wrong on every real deploy, and the resulting
         * failure is the FIRST thing that happens when you try to load the
         * schema or run the cron by hand. */
        if (PHP_SAPI === 'cli') {
            fatal_error(
                'db_unavailable',
                'Could not connect to the database: ' . $e->getMessage()
                    . "\n\nCheck the db block in config.php. On Hostinger the database name and"
                    . "\nuser are both prefixed with your account id (u123456_movies), not the"
                    . "\nbare name in config.example.php.",
                500
            );
        }

        fatal_error('db_unavailable', 'The database is unavailable right now.', 500);
    }

    return $pdo;
}

/** Run a query with bound params and return the statement. */
function q(string $sql, array $params = array()): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
