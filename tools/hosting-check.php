<?php
/* What this Hostinger plan can actually do.
 *
 *   php tools/hosting-check.php          (over SSH, if you have it)
 *   or upload it and fetch it once, then DELETE IT
 *
 * ---------------------------------------------------------------------------
 * RUN THIS BEFORE TRUSTING THE APP, NOT AFTER.
 * ---------------------------------------------------------------------------
 *
 * Book Tracker's equivalent is what cut Google Books from that build: the
 * hosting turned out not to be able to reach googleapis.com, and finding that
 * out during integration would have meant rewriting a finished module. Three
 * things here can change this app's shape the same way, and all three are
 * cheaper to learn now:
 *
 *   1. TMDB UNREACHABLE. Search, posters, genres and streaming availability
 *      all go through api.themoviedb.org, and the poster files come from
 *      image.tmdb.org — which is a different host and can fail independently.
 *      Without them every movie has to be entered by hand through the "Create
 *      new" path. That path is built and works, but it is a different app to
 *      use, and you would want to know before relying on this one.
 *   2. OUTBOUND SMTP BLOCKED. Some shared plans refuse port 587 outright. The
 *      whole reminder feature assumes it is open, and a blocked port shows up
 *      as reminders that silently never arrive.
 *   3. NO COMMAND CRON, only a URL fetch. That decides whether
 *      tools/cron-reminders.php or public/cron.php is the entry point, which
 *      changes what you paste into hPanel and whether cron.token has to be set.
 *
 * It is READ-ONLY except for one temp file it writes and deletes, and it
 * prints no credentials — the SMTP check reports whether the socket opened,
 * never what was sent over it, and the TMDB check prints the first characters
 * of a response, never the token.
 *
 * DELETE IT FROM THE SERVER AFTERWARDS. It reports PHP limits and extension
 * versions, which is reconnaissance you have no reason to publish.
 */

declare(strict_types=1);

$viaHttp = PHP_SAPI !== 'cli';
if ($viaHttp) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
}

$root = dirname(__DIR__);

$GLOBALS['problems'] = 0;

function line(string $label, string $value, ?bool $ok = null): void
{
    $mark = $ok === null ? '   ' : ($ok ? ' ok' : ' !!');
    if ($ok === false) {
        $GLOBALS['problems']++;
    }
    printf("%s  %-26s %s\n", $mark, $label, $value);
}

function heading(string $text): void
{
    echo "\n" . $text . "\n" . str_repeat('-', strlen($text)) . "\n";
}

echo "Movies — hosting check\n";
echo "Run this before deploying. Delete it from the server afterwards.\n";

/* ------------------------------------------------------------------- PHP */

heading('PHP');

line('version', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>='));
line('SAPI', PHP_SAPI);

foreach (array('pdo_mysql', 'curl', 'gd', 'mbstring', 'openssl') as $ext) {
    $have = extension_loaded($ext);
    /* GD is the only optional one: it is used by tools/seed.php to draw
     * placeholder posters and by nothing the app itself does. Everything else
     * is load-bearing — no openssl means no STARTTLS means no email. */
    line('ext: ' . $ext, $have ? 'loaded' : 'MISSING', $ext === 'gd' ? null : $have);
}

line('allow_url_fopen', ini_get('allow_url_fopen') ? 'on' : 'off');
line('upload_max_filesize', (string) ini_get('upload_max_filesize'));
line('post_max_size', (string) ini_get('post_max_size'));
line('max_execution_time', (string) ini_get('max_execution_time'));
line('default timezone', date_default_timezone_get());

/* ------------------------------------------------------------ the config */

heading('Config and files');

$hasConfig = is_file($root . '/config.php');
line('config.php present', $hasConfig ? 'yes' : 'no — copy config.example.php', $hasConfig);

$config = $hasConfig ? require $root . '/config.php' : array();
if (!is_array($config)) {
    $config = array();
}

/** Read a config value without needing the app booted. */
function conf(array $config, string $path, $default = null)
{
    $node = $config;
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

$posterDir = $root . '/public/posters';
if (!is_dir($posterDir)) {
    $posterDir = $root . '/public_html/posters';
}
$writable = is_dir($posterDir) && is_writable($posterDir);
line('posters/ writable', $writable ? $posterDir : 'NO — ' . $posterDir, $writable);

if ($writable) {
    /* The one write. A directory can be is_writable() and still refuse under
     * open_basedir or a full quota, and the poster cache failing is the kind
     * of thing that shows up as "some movies have no art" months later. */
    $probe = $posterDir . '/.hosting-check-probe';
    $wrote = @file_put_contents($probe, 'probe') !== false;
    @unlink($probe);
    line('posters/ write test', $wrote ? 'wrote and removed a file' : 'WRITE FAILED', $wrote);
}

/* -------------------------------------------------------------- database */

heading('Database');

if (!$hasConfig) {
    line('connection', 'skipped — no config.php');
} else {
    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            conf($config, 'db.host', 'localhost'),
            conf($config, 'db.name', ''),
            conf($config, 'db.charset', 'utf8mb4')
        );
        $pdo = new PDO($dsn, (string) conf($config, 'db.user'), (string) conf($config, 'db.pass'), array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ));
        line('connection', 'connected', true);
        line('server version', (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        line('tables', $tables ? implode(', ', $tables) : 'none — load schema.sql', (bool) $tables);
    } catch (Throwable $e) {
        /* On Hostinger both the database name and the user are prefixed with
         * the account id, so the values in config.example.php are wrong on
         * every real deploy. This is the first thing that fails. */
        line('connection', 'FAILED: ' . $e->getMessage(), false);
        line('', 'On Hostinger the DB name and user are both prefixed');
        line('', 'with your account id, e.g. u123456_movies.');
    }
}

/* ------------------------------------------------------------------ TMDB */

heading('TMDB — the one that can change the plan');

$token = trim((string) conf($config, 'tmdb.token', ''));
$apiBase = rtrim((string) conf($config, 'tmdb.api_base', 'https://api.themoviedb.org/3'), '/');
$imgBase = rtrim((string) conf($config, 'tmdb.image_base', 'https://image.tmdb.org/t/p/'), '/');

/**
 * One GET, reporting reachability separately from authorisation — they have
 * completely different fixes and confusing them wastes an afternoon.
 *
 * @return array{reached: bool, status: int, body: string, error: string}
 */
function probe(string $url, array $headers = array(), int $timeout = 15): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Movies/1.0 hosting-check',
        ));
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = (string) curl_error($ch);
        curl_close($ch);

        return array(
            'reached' => is_string($body),
            'status'  => $status,
            'body'    => is_string($body) ? $body : '',
            'error'   => $error,
        );
    }

    if (!ini_get('allow_url_fopen')) {
        return array('reached' => false, 'status' => 0, 'body' => '', 'error' => 'no curl and allow_url_fopen is off');
    }

    $ctx  = stream_context_create(array('http' => array(
        'timeout' => $timeout, 'header' => implode("\r\n", $headers), 'ignore_errors' => true,
    )));
    $body = @file_get_contents($url, false, $ctx);

    return array(
        'reached' => $body !== false,
        'status'  => 200,
        'body'    => $body === false ? '' : $body,
        'error'   => $body === false ? 'file_get_contents failed' : '',
    );
}

/* Reachability first, WITHOUT the token. This separates "the host cannot get
 * there at all" — the answer that changes the plan — from "the token is
 * wrong", which is a five-minute fix. An unauthenticated request to the API
 * returns 401, and a 401 still proves the host can reach it. */
$reach = probe($apiBase . '/movie/550');
if (!$reach['reached']) {
    line('api.themoviedb.org', 'UNREACHABLE: ' . $reach['error'], false);
    line('', 'This is the Google Books situation from Book Tracker.');
    line('', 'Search, posters and streaming cannot work on this plan;');
    line('', 'every movie would have to be entered by hand.');
} else {
    line('api.themoviedb.org', 'reachable (HTTP ' . $reach['status'] . ')', true);

    if ($token === '' || $token === 'CHANGE_ME') {
        line('tmdb.token', 'not set — cannot test authentication');
    } else {
        /* v4 read access token, in an Authorization header. A v3 API key
         * pasted here produces a 401 whose body says "Invalid API key", which
         * reads like a wrong key rather than the wrong KIND of key — so say so
         * explicitly. */
        $auth = probe($apiBase . '/movie/550', array(
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ));

        if ($auth['status'] === 200) {
            $parsed = json_decode($auth['body'], true);
            line('tmdb.token', 'accepted — read "' . (string) ($parsed['title'] ?? '?') . '"', true);
        } elseif ($auth['status'] === 401) {
            line('tmdb.token', 'REFUSED (401)', false);
            line('', 'Use the API READ ACCESS TOKEN (the long "eyJ..." one),');
            line('', 'not the 32-character v3 API key.');
        } else {
            line('tmdb.token', 'unexpected HTTP ' . $auth['status'], false);
        }
    }

    /* The image CDN is a DIFFERENT HOST and can fail on its own. Posters come
     * from here; metadata does not. A plan that reaches one and not the other
     * gives you a working search and a grid of placeholders. */
    $img = probe($imgBase . '/w92/kqjL17yufvn9OVLyXYpvtyrFfak.jpg');
    if (!$img['reached'] || $img['status'] < 200 || $img['status'] > 299) {
        line('image.tmdb.org', 'UNREACHABLE — posters cannot be fetched', false);
    } else {
        line('image.tmdb.org', 'reachable (' . strlen($img['body']) . ' bytes)', true);
    }
}

/* ------------------------------------------------------------------ SMTP */

heading('Outgoing email');

$smtpHost = (string) conf($config, 'smtp.host', 'smtp.gmail.com');
$smtpPort = (int) conf($config, 'smtp.port', 587);

/* THE SOCKET ONLY. Nothing is sent, nothing is authenticated, and no
 * credential is read — this answers "is the port open from here", which is the
 * question a shared host can answer with no. Use tools/send-test-email.php for
 * the rest. */
$errNo  = 0;
$errStr = '';
$sock = @fsockopen($smtpHost, $smtpPort, $errNo, $errStr, 8);

if ($sock === false) {
    line($smtpHost . ':' . $smtpPort, 'BLOCKED: ' . $errStr, false);
    line('', 'Reminder emails cannot be sent from this plan.');
    line('', 'Try port 465 with smtp.secure = ssl before giving up.');
} else {
    $greeting = (string) @fgets($sock, 512);
    @fclose($sock);
    line($smtpHost . ':' . $smtpPort, 'open — ' . trim(substr($greeting, 0, 60)), true);
}

$user = trim((string) conf($config, 'smtp.user', ''));
$from = trim((string) conf($config, 'smtp.from_email', ''));
if ($user !== '' && $from !== '' && $user !== 'CHANGE_ME') {
    /* The failure that produces a successful-looking send and no email. */
    $match = strcasecmp($user, $from) === 0;
    line('from_email matches user', $match ? 'yes' : 'NO — mail will vanish silently', $match);
}

/* ------------------------------------------------------------------ cron */

heading('Cron');

/* There is no way to detect from inside PHP whether hPanel offers a command
 * cron, so this reports what each path NEEDS and lets you match it against
 * what the panel shows. */
if (PHP_SAPI === 'cli') {
    line('command cron', 'this ran from the CLI, so a command cron will work', true);
    line('', 'php ' . $root . '/tools/cron-reminders.php');
} else {
    line('command cron', 'unknown — this ran over HTTP. Try SSH.');
}

$cronToken = (string) conf($config, 'cron.token', '');
if ($cronToken === '') {
    line('cron.token', 'empty — public/cron.php will refuse every request');
    line('', 'Fine if you have a command cron. If you do not, set it:');
    line('', "php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'");
} else {
    line('cron.token', 'set (' . strlen($cronToken) . ' chars)', strlen($cronToken) >= 32);
}

/* --------------------------------------------------------------- verdict */

heading('Verdict');

if ($GLOBALS['problems'] === 0) {
    echo "Everything this can check looks right.\n";
    echo "Next: php tools/send-test-email.php, then point the cron at it.\n";
} else {
    echo $GLOBALS['problems'] . " problem(s) above, marked !!\n";
    echo "The TMDB and SMTP ones change what this app can do. Read those first.\n";
}

echo "\nNow delete this file from the server.\n";

exit($GLOBALS['problems'] > 0 ? 1 : 0);
