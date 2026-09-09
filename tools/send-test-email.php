<?php
/* Send one real email, to prove the reminder path works.
 *
 *   php tools/send-test-email.php
 *
 * ---------------------------------------------------------------------------
 * RUN THIS BEFORE POINTING A CRON AT THIS APP.
 * ---------------------------------------------------------------------------
 *
 * The reminder job runs once a day, unattended, and the only way you find out
 * it is broken is that an email you were expecting does not arrive — by which
 * time the film is out. This is the one chance to see the failure with your
 * own eyes and a message that names the fix.
 *
 * It uses the SAME mailer_send() the cron uses, so a success here means the
 * transport works, not that a similar-looking one does.
 *
 * It writes nothing: no database row, no ledger entry. Running it twice is
 * two emails and no other consequence.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/mailer.php';

/* The config check first, and on its own, because every one of its answers is
 * a sentence naming a key and a fix — while the same misconfiguration reaching
 * PHPMailer produces a timeout or a bare "535". */
$problem = mailer_config_problem();
if ($problem !== null) {
    fwrite(STDERR, "Not sending. " . $problem . "\n");
    exit(1);
}

$to = (string) cfg('smtp.to', '');
fwrite(STDOUT, 'Sending a test message to ' . $to . ' via '
    . (string) cfg('smtp.host', '') . ':' . (int) cfg('smtp.port', 587) . " ...\n");

/* Built through the real phrasing functions on a fake movie, so what lands in
 * the inbox looks like what a real reminder will look like — including the
 * subject line, which is the part that actually gets read. */
$movie = array(
    'id'           => 0,
    'title'        => 'A Test Of The Emergency Movie System',
    'release_date' => (new DateTimeImmutable('+7 days'))->format('Y-m-d'),
    'notes'        => 'If you are reading this, reminders work. Nothing was saved.',
    'genres'       => array(array('name' => 'documentary')),
);

$ok = send_release_email($movie, REMINDER_HEADS_UP, movies_today());

if (!$ok) {
    fwrite(STDERR, "\nFAILED: " . mailer_last_error() . "\n\n");
    fwrite(STDERR,
        "Things worth checking, in the order they usually go wrong:\n"
        . "  - smtp.pass must be a Gmail APP PASSWORD, not the account password.\n"
        . "  - smtp.from_email must equal smtp.user, or Gmail rewrites or rejects it.\n"
        . "  - port 587 may be blocked outbound; try 465 with smtp.secure = 'ssl'.\n"
        . "  - run tools/hosting-check.php, which tests the socket on its own.\n");
    exit(1);
}

fwrite(STDOUT, "\nSent. Check " . $to . ".\n\n");

/* THE PART PEOPLE SKIP. "The server accepted it" and "it arrived" are
 * different claims, and this script can only make the first — the specific
 * failure mode the from_email check exists for is a message that is accepted
 * and silently never delivered. */
fwrite(STDOUT,
    "Note: this means the SMTP server ACCEPTED the message, which is not the\n"
    . "same as it arriving. If nothing shows up within a few minutes, check the\n"
    . "spam folder, then check that smtp.from_email is an address Gmail has\n"
    . "authorised this account to send as.\n");
