<?php
/* Outgoing email: the one thing this app does that you are not looking at.
 *
 * ---------------------------------------------------------------------------
 * ONE FUNCTION IS THE CONTRACT.
 * ---------------------------------------------------------------------------
 *
 *   send_release_email(array $movie, string $kind, string $today): bool
 *
 * Everything else in this file exists to build that message or to explain why
 * it did not go. Nothing outside this file knows that PHPMailer exists, which
 * is the point: swapping the transport later — for a hand-rolled SMTP client,
 * for an API — touches this file and nothing else.
 *
 * Ported from the Personal CRM. The transport half is unchanged; the phrasing
 * half is this app's.
 *
 * ---------------------------------------------------------------------------
 * WHY SMTP AND NOT mail().
 * ---------------------------------------------------------------------------
 *
 * A message pushed through mail() from a shared host arrives in spam often
 * enough that you would stop trusting the reminders, and a reminder you do not
 * trust is worse than no reminder: you still get the mail, you just stop
 * reading it. The brief asks for SMTP for exactly this reason.
 *
 * PHPMailer is VENDORED AS THREE PLAIN FILES in lib/vendor/PHPMailer/,
 * required directly below. No Composer, no autoloader, no build step — they
 * FTP up with everything else, which is what the suite's no-build-step rule is
 * actually about (not needing a toolchain, rather than never using anyone
 * else's code). Those three files are third-party and are kept byte-identical
 * to the upstream release; do not restyle them to match the house rules, and
 * do not "fix" them.
 *
 * ---------------------------------------------------------------------------
 * THE FAILURE THIS FILE IS MOST AFRAID OF.
 * ---------------------------------------------------------------------------
 *
 * FROM_EMAIL THAT DOES NOT MATCH USER. Gmail rewrites or outright rejects a
 * From address it has not authorized, and the bounce is silent from this app's
 * point of view: the send "succeeds", the ledger records a delivery, and the
 * mail never arrives. Nothing on any screen would ever say so. It is checked
 * before the connection is opened (mailer_config_problem()), it is asserted by
 * tools/send-test-email.php, and it is in DEPLOY.txt in capitals.
 *
 * ---------------------------------------------------------------------------
 * THE CLOCK.
 * ---------------------------------------------------------------------------
 *
 * Every function here takes $today, exactly as lib/dates.php does and for the
 * same reason — "next Tuesday" and "in 6 days" are what the subject line SAYS,
 * so a one-day skew is a lie printed on a lock screen. The cron passes its own
 * $today, and the tests pass a fixed one. */

declare(strict_types=1);

/* Order matters and there is no autoloader: PHPMailer.php and SMTP.php both
 * refer to Exception, and SMTP.php is instantiated from inside PHPMailer.php. */
require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';

/* The two reminder kinds, spelled once. They are an ENUM in the schema, so a
 * typo is a database error rather than a silent no-op — but a database error
 * inside an unattended cron is still an email that didn't arrive, so the
 * strings live here and callers use the constants. */
const REMINDER_HEADS_UP = 'heads_up';
const REMINDER_DAY_OF   = 'day_of';

/* How many genres go in the email. It is a nudge, not the screen — three is
 * enough to remember what kind of film this is. */
const MAILER_GENRE_LIMIT = 3;

/* Placeholders config.example.php ships with. Sending with any of these still
 * in place is a guaranteed authentication failure, and catching it here turns
 * a mystery SMTP error into a sentence naming the key. */
const MAILER_PLACEHOLDER = 'CHANGE_ME';

/**
 * Why the last send failed, for the caller's ledger row. '' when nothing has.
 *
 * A module-level static rather than an exception or a richer return type,
 * because the contracted signature is `: bool` and the cron needs the reason
 * for the ledger. Read it immediately after a false; it is overwritten by the
 * next send.
 */
function mailer_last_error(?string $set = null): string
{
    static $last = '';
    if ($set !== null) {
        $last = $set;
    }
    return $last;
}

/* ================================================================== config ==*/

/**
 * The first thing wrong with the smtp config block, or null if nothing is.
 *
 * Checked BEFORE a connection is opened, so the common misconfigurations
 * report themselves as a sentence instead of as a timeout or a 535. Every one
 * of these has exactly one cause and exactly one fix, so each message names
 * both.
 */
function mailer_config_problem(): ?string
{
    $user = trim((string) cfg('smtp.user', ''));
    $from = trim((string) cfg('smtp.from_email', ''));
    $to   = trim((string) cfg('smtp.to', ''));
    $pass = (string) cfg('smtp.pass', '');
    $host = trim((string) cfg('smtp.host', ''));

    if ($host === '') {
        return 'smtp.host is empty in config.php';
    }
    if ($user === '' || $user === MAILER_PLACEHOLDER) {
        return 'smtp.user is not set in config.php';
    }
    if ($pass === '' || $pass === MAILER_PLACEHOLDER) {
        return 'smtp.pass is not set in config.php — it must be a Gmail APP PASSWORD,'
            . ' not the account password';
    }
    if ($to === '' || $to === MAILER_PLACEHOLDER) {
        return 'smtp.to is not set in config.php — there is nobody to send reminders to';
    }

    /* THE ONE THAT FAILS SILENTLY. Everything above produces a visible error;
     * this produces a successful-looking send and no email. */
    if (strcasecmp($from, $user) !== 0) {
        return 'smtp.from_email (' . $from . ') does not match smtp.user (' . $user . ').'
            . ' Gmail rewrites or rejects a From it has not authorized and the bounce is'
            . ' silent — the send appears to succeed and the mail never arrives.';
    }

    return null;
}

/**
 * Where a movie lives on the public internet, for the link in the email.
 *
 * Three ways, degrading rather than refusing to send:
 *
 *   1. cfg('app_url') — the only reliable answer for a COMMAND cron, which has
 *      no request and therefore no host name.
 *   2. The current request's own scheme and host, which is exactly right for
 *      the public/cron.php path: the URL the cron fetched IS the app's URL.
 *   3. A bare relative path, so the email still names the screen even when it
 *      cannot link to it. An email with no link is worth more than no email.
 *
 * DEPLOY.txt asks for (1), because a command cron silently lands on (3).
 */
function mailer_movie_url(int $movieId): string
{
    $path = 'movie.php?id=' . $movieId;

    $base = trim((string) cfg('app_url', ''));
    if ($base !== '') {
        return rtrim($base, '/') . '/' . $path;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && PHP_SAPI !== 'cli') {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            ? 'https'
            : 'http';
        $dir = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $scheme . '://' . $host . $dir . '/' . $path;
    }

    return $path;
}

/* ================================================================ phrasing ==*/

/**
 * When a release falls, said the way a person says it, for a SUBJECT LINE.
 *
 *   "today" · "tomorrow" · "next Wednesday" · "in 12 days"
 *
 * Deliberately NOT fmt_countdown(), which is the on-screen phrasing and
 * capitalises for a card ("In 6 days", "Out today"). A subject line reads as a
 * sentence and lands mid-phrase — "Out today" inside "Dune: Part Three is Out
 * today" is wrong in a way that is hard to unsee. This is the one place in the
 * app that needs a different register, so it gets its own short function
 * rather than a flag on the shared one.
 */
function mailer_when_phrase(?string $date, string $today): string
{
    $days = days_until($date, $today);
    if ($days === null) {
        return 'soon';
    }

    if ($days === 0) {
        return 'today';
    }
    if ($days === 1) {
        return 'tomorrow';
    }
    if ($days === -1) {
        return 'yesterday';
    }
    if ($days < 0) {
        return abs($days) . ' days ago';
    }
    if ($days <= 7) {
        $parsed = movies_parse_date($date);
        return $parsed === null ? 'in ' . $days . ' days' : 'next ' . $parsed->format('l');
    }

    return 'in ' . $days . ' days';
}

/**
 * THE SUBJECT LINE, which is the whole user experience here.
 *
 * This arrives on a phone lock screen and is very often the only part that is
 * ever read, so it carries the TITLE FIRST — a truncated subject must still
 * say which movie it is about. The date and the when follow.
 *
 *   In theatres next Friday: Dune: Part Three — March 20
 *   Out today: Dune: Part Three
 *
 * The day-of subject is deliberately shorter. "Out today" needs no date beside
 * it, and the shorter it is the more of the title survives truncation.
 */
function mailer_subject(array $movie, string $kind, string $today): string
{
    $title   = (string) ($movie['title'] ?? 'A movie');
    $release = $movie['release_date'] ?? null;

    if ($kind === REMINDER_DAY_OF) {
        return 'Out today: ' . $title;
    }

    $when = mailer_when_phrase($release, $today);
    $date = fmt_date($release, 'F j');

    return 'In theatres ' . $when . ': ' . $title
        . ($date === '' ? '' : ' — ' . $date);
}

/* ================================================================== bodies ==*/

/**
 * The pieces of the body, assembled once and rendered twice.
 *
 * The release date, your own note, the genres and a link — and NOTHING ELSE.
 * The email is a nudge with enough context to act on without opening anything;
 * everything else is one tap away and stays there.
 *
 * @return array{headline: string, notes: string, genres: string, url: string}
 */
function mailer_body_parts(array $movie, string $kind, string $today): array
{
    $movieId = (int) ($movie['id'] ?? 0);
    $release = $movie['release_date'] ?? null;
    $date    = fmt_date($release, 'F j, Y');

    $headline = $kind === REMINDER_DAY_OF
        ? 'Out in theatres today.'
        : 'In theatres ' . mailer_when_phrase($release, $today)
            . ($date === '' ? '' : ' — ' . $date) . '.';

    $genres = array();
    foreach (($movie['genres'] ?? array()) as $g) {
        $genres[] = ucwords((string) ($g['name'] ?? $g));
    }
    $genres = array_slice($genres, 0, MAILER_GENRE_LIMIT);

    return array(
        'headline' => $headline,
        'notes'    => trim((string) ($movie['notes'] ?? '')),
        'genres'   => implode(' · ', $genres),
        'url'      => mailer_movie_url($movieId),
    );
}

/** The plain-text part. The one that actually gets read on a watch. */
function mailer_body_text(array $movie, string $kind, string $today): string
{
    $parts = mailer_body_parts($movie, $kind, $today);
    $title = (string) ($movie['title'] ?? 'A movie');

    $lines = array($title, $parts['headline'], '');

    if ($parts['genres'] !== '') {
        $lines[] = $parts['genres'];
        $lines[] = '';
    }

    if ($parts['notes'] !== '') {
        $lines[] = 'Your note';
        $lines[] = $parts['notes'];
        $lines[] = '';
    }

    $lines[] = $parts['url'];

    return implode("\n", $lines) . "\n";
}

/**
 * The HTML part: the same words, in a readable size, and nothing else.
 *
 * No layout, no images, no tracking, no web fonts. An email client is the one
 * rendering engine nobody can test against, and this only has to be legible.
 * The inline styles here are the exception the no-inline-style rule allows —
 * there is no stylesheet in an email, and styles.css is not involved.
 *
 * Everything from the database goes through h(), same as every template.
 */
function mailer_body_html(array $movie, string $kind, string $today): string
{
    $parts = mailer_body_parts($movie, $kind, $today);
    $title = (string) ($movie['title'] ?? 'A movie');

    $html = '<div style="font:16px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#222">'
        . '<h1 style="font-size:20px;margin:0 0 4px">' . h($title) . '</h1>'
        . '<p style="margin:0 0 16px;color:#555">' . h($parts['headline']) . '</p>';

    if ($parts['genres'] !== '') {
        $html .= '<p style="margin:0 0 16px;color:#777;font-size:13px">' . h($parts['genres']) . '</p>';
    }

    if ($parts['notes'] !== '') {
        $html .= '<h2 style="font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#777;margin:16px 0 4px">Your note</h2>'
            /* nl2br over an ESCAPED string, never the other way round. */
            . '<p style="margin:0">' . nl2br(h($parts['notes'])) . '</p>';
    }

    $html .= '<p style="margin:20px 0 0"><a href="' . h($parts['url']) . '">Open ' . h($title) . '</a></p>'
        . '</div>';

    return $html;
}

/* ================================================================ delivery ==*/

/**
 * Hand one message to the SMTP server. The only place PHPMailer is touched.
 *
 * Returns false and records mailer_last_error() rather than throwing: a cron
 * run sends several emails and one refused connection must not cost the rest
 * of them. The caller writes the reason beside the ledger row, so tomorrow's
 * retry has something to read.
 *
 * SMTPDebug stays OFF. Its output goes to stdout, which for the URL-fetch cron
 * is the HTTP response body — the whole SMTP conversation, app password
 * included, served to whoever fetched the URL.
 */
function mailer_send(string $subject, string $textBody, string $htmlBody): bool
{
    /* Test seam, matching lib/tmdb.php's. tools/tests-reminders.php installs a
     * callable here so the whole cron can be exercised — claim, send, ledger,
     * retry — without an SMTP server and without sending anything. Nothing in
     * the app sets it.
     *
     * CHECKED BEFORE THE CONFIG, so the seam replaces the WHOLE transport
     * rather than only its last step. A test that has supplied its own
     * transport should not also have to supply credentials for a server it
     * will never contact — and requiring it would mean the reminder tests
     * silently stopped exercising anything the moment a config key was
     * renamed, reporting a refused send as a mail failure.
     *
     * mailer_config_problem() is not skipped, it is tested directly:
     * tests_mailer_config() in tools/tests-reminders.php covers each branch,
     * including the from_email/user mismatch that fails silently in
     * production. */
    $hook = $GLOBALS['mailer_send_hook'] ?? null;
    if (is_callable($hook)) {
        $ok = (bool) $hook($subject, $textBody, $htmlBody);
        mailer_last_error($ok ? '' : 'test hook refused the message');
        return $ok;
    }

    $problem = mailer_config_problem();
    if ($problem !== null) {
        mailer_last_error($problem);
        error_log('mailer: ' . $problem);
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host     = (string) cfg('smtp.host', 'smtp.gmail.com');
        $mail->Port     = (int) cfg('smtp.port', 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) cfg('smtp.user', '');
        $mail->Password = (string) cfg('smtp.pass', '');

        /* 'tls' is STARTTLS on 587; 'ssl' is implicit TLS on 465. Anything else
         * in config becomes STARTTLS rather than plaintext — a typo in this key
         * must not quietly send the app password in the clear. */
        $mail->SMTPSecure = ((string) cfg('smtp.secure', 'tls')) === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        /* A cron that hangs on a dead SMTP host holds the run open until PHP's
         * own limit kills it, which on the URL-fetch path means a request that
         * never returns and a "did it run?" nobody can answer. Fail in a
         * quarter of a minute and let tomorrow retry — the ledger row is what
         * makes that safe. */
        $mail->Timeout = 15;

        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;

        $mail->setFrom((string) cfg('smtp.from_email', ''), (string) cfg('smtp.from_name', 'Movies'));
        $mail->addAddress((string) cfg('smtp.to', ''));

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        mailer_last_error('');
        return true;
    } catch (Throwable $e) {
        /* PHPMailer's ErrorInfo is the useful half — it carries the server's
         * own refusal ("Username and Password not accepted") where the
         * exception message is often just "SMTP Error: Could not authenticate". */
        $why = trim($mail->ErrorInfo) !== '' ? $mail->ErrorInfo : $e->getMessage();
        mailer_last_error($why);
        error_log('mailer: send failed: ' . $why);
        return false;
    }
}

/**
 * ===================== THE FUNCTION THE CRON CALLS ==========================
 *
 *   send_release_email(array $movie, string $kind, string $today): bool
 *
 * $movie is a movies row as movie_get() returns it — id, title, release_date,
 * notes and genres are the parts used.
 * $kind is REMINDER_HEADS_UP or REMINDER_DAY_OF.
 * ============================================================================
 *
 * True means the SMTP server accepted the message. It does not mean it was
 * delivered, and nothing in this app can know that — which is why
 * mailer_config_problem() refuses the one misconfiguration that produces an
 * accepted message nobody receives.
 */
function send_release_email(array $movie, string $kind, ?string $today = null): bool
{
    $today = $today ?? movies_today();

    return mailer_send(
        mailer_subject($movie, $kind, $today),
        mailer_body_text($movie, $kind, $today),
        mailer_body_html($movie, $kind, $today)
    );
}
