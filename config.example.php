<?php
/* =====================================================================
 * Movies — configuration
 * ---------------------------------------------------------------------
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored and must never be committed.
 *
 * On Hostinger this file lives ONE LEVEL ABOVE your web root, next to
 * lib/ — so it can never be served over the web even if PHP is off.
 * The root .htaccess denies it a second time, belt and braces.
 *
 * FOUR SECRETS LIVE IN HERE, not one: the database password, the admin
 * password hash, the TMDB API key and the cron token. That is worth
 * knowing before you paste it into a support ticket.
 * ===================================================================== */

return array(

    /* ---- database ---------------------------------------------------
     * Create the DB in hPanel, then paste those values here.
     * Host is usually 'localhost'.
     *
     * On Hostinger BOTH the database name and the username are prefixed
     * with your account id (u123456_movies), not the bare name below.
     * This is the first thing that goes wrong on a real deploy.
     */
    'db' => array(
        'host'    => 'localhost',
        'name'    => 'movies',
        'user'    => 'CHANGE_ME',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ),

    /* ---- the clock --------------------------------------------------
     * READ THIS ONE. It is the highest-consequence value in the file.
     *
     * EXPLICIT, NOT INHERITED FROM THE SERVER. Hostinger sets its PHP
     * default and its MySQL default independently, and neither is
     * guaranteed to be the zone you live in — the usual answer is UTC
     * for one and something else for the other.
     *
     * This app emails you on a schedule. "Seven days before release"
     * and "on release day" are the whole feature, and a one-day skew
     * means the release-day email lands the day after the release,
     * with nothing on any screen to explain it.
     *
     * Set once, used twice: lib/bootstrap.php calls
     * date_default_timezone_set() with it the instant config loads, and
     * lib/db.php pins the MySQL connection to the same offset at
     * connect time. One clock, no conversions. Nothing else in the app
     * asks what day it is except movies_today() in lib/dates.php.
     *
     * A named zone here, NOT an offset: DST is handled correctly, and
     * lib/db.php converts it to the numeric offset MySQL needs.
     */
    'timezone' => 'America/Chicago',

    /* ---- the gate ---------------------------------------------------
     * Single user, password only. Store the HASH, never the password.
     *
     *   php tools/make-hash.php
     *
     * THE GATE FAILS CLOSED. Unlike Book Tracker, leaving this empty or
     * as CHANGE_ME does NOT make the app reachable by anyone who finds
     * the URL — the private screens refuse with a message naming this
     * key. See the header of lib/auth.php for why this app diverges.
     *
     * The one page that stays reachable without it is the public
     * collection view, which shows watched movies only.
     */
    'password_hash' => 'CHANGE_ME',

    // How long a login lasts on a device, in days.
    'session_days'  => 90,

    /* ---- web root ---------------------------------------------------
     * Name of the directory this app's public files live in, relative to
     * the folder holding lib/ and this file. Leave empty to auto-detect
     * "public" (a local checkout) or "public_html" (some Hostinger
     * setups).
     *
     * Only set it if your host uses some other name. Getting it wrong
     * doesn't error — it silently stops asset() cache-busting the CSS
     * and JS, so a deploy appears not to have taken effect.
     */
    'public_dir' => '',

    /* ---- public address ----------------------------------------------
     * Where this app lives, e.g. 'https://movies.kathrynmarinaro.com'.
     * Used only to build the "open this movie" link inside a reminder
     * email.
     *
     * SET IT. It degrades in three steps rather than breaking — this
     * value, then the scheme and host of the current request, then a
     * bare movie.php?id=N — but the middle step is unavailable to a
     * COMMAND cron, which has no request to read a host from. So a
     * command cron with this unset sends an email naming the right
     * screen with a link you cannot tap, and a URL-fetch cron happens
     * to work without it. That is exactly the kind of difference that
     * shows up months later on the wrong plan.
     */
    'app_url' => '',

    /* ---- TMDB -------------------------------------------------------
     * Metadata, posters, genres and streaming availability all come
     * from The Movie Database. Get a key at:
     *   https://www.themoviedb.org/settings/api
     *
     * TWO KINDS OF CREDENTIAL EXIST AND THEY ARE NOT INTERCHANGEABLE.
     * Use the API READ ACCESS TOKEN (a long JWT starting "eyJ"), which
     * goes in an Authorization: Bearer header. The older "API Key (v3
     * auth)" is a 32-character hex string passed as ?api_key= and this
     * app does not use it. Pasting the v3 key here produces a 401 on
     * every request with a body that says "Invalid API key", which
     * reads like the key is wrong rather than the wrong kind.
     *
     * RUN tools/hosting-check.php BEFORE TRUSTING ANY OF THIS. Book
     * Tracker had to cut Google Books entirely after its own hosting
     * check found googleapis.com unreachable from Hostinger. If
     * api.themoviedb.org is unreachable from your plan, search and
     * poster fetching cannot work and everything has to be entered by
     * hand.
     */
    'tmdb' => array(
        'token' => 'CHANGE_ME',   // API Read Access Token (v4), the "eyJ..." one

        'api_base'   => 'https://api.themoviedb.org/3',
        'image_base' => 'https://image.tmdb.org/t/p/',

        /* Poster size. TMDB serves w92 / w154 / w185 / w342 / w500 /
         * w780 / original.
         *
         * 'w500' for the stored poster, matching Book Tracker's "-L not
         * -M" lesson: the smaller sizes are visibly soft at retina
         * density on a phone, and these files are cached permanently,
         * so choosing wrong means re-running the API over the whole
         * collection later to fix it.
         *
         * 'w185' for the thumbnails in the search results, which are
         * transient and never stored.
         */
        'poster_size' => 'w500',
        'thumb_size'  => 'w185',

        /* Streaming availability region. TMDB returns providers per
         * country and this app displays one. Flagged as config per the
         * brief in case it ever needs to change.
         */
        'region' => 'US',

        /* How long a cached streaming-availability answer is trusted,
         * in days. Availability changes when licensing deals turn over,
         * which is a monthly-ish event, not a daily one — and fetching
         * it live on every detail view would put a network round trip
         * in front of a screen that otherwise makes none.
         */
        'providers_ttl_days' => 7,

        /* How many search results the add flow shows. Four fits a phone
         * without scrolling, and the "Create new" row has to stay
         * visible below them.
         */
        'results' => 4,

        // Seconds. A slow response must cost a spinner, never a wedged
        // form — the add flow debounces and cancels in flight.
        'timeout' => 15,
    ),

    /* ---- posters ------------------------------------------------------ */
    'posters' => array(
        // Downloaded once into public/posters/ and served from disk
        // forever after. The app makes no network call to render a grid.
        'dir' => 'posters',

        // A real w500 poster is ~500x750 and tens of kilobytes. Anything
        // under this is not a poster — validation is by CONTENT as well
        // (see lib/posters.php), but the byte floor is the cheap first
        // gate.
        'min_bytes' => 2000,

        // Manual poster upload, for movies TMDB doesn't have. Also
        // checked against PHP's own upload_max_filesize at boot.
        'max_upload_mb' => 8,

        'timeout' => 20,
    ),

    /* ---- how long a film stays in theatres ---------------------------
     * Coming Soon and To Watch are two SECTIONS of one screen, and which
     * one a film is in is decided from its release date rather than
     * chosen: still in theatres (or not out yet) puts it in Coming Soon,
     * out of theatres moves it to To Watch. The daily cron re-settles
     * every film, and the screen does too so it is never stale.
     *
     * THIS IS AN ASSUMPTION, NOT A FACT TMDB GIVES US. There is no
     * end-of-run date in the API — only the release date — so "out of
     * theatres" is approximated as release date + this many days. Around
     * 45 is the current studio window before a film reaches streaming or
     * PVOD, but it varies a lot: a small release can be gone in two
     * weeks, a blockbuster can run for three months.
     *
     * Change it and every film re-settles on the next sweep. Nothing is
     * baked into a query, and nothing is lost either way — a film in the
     * wrong section is still one tap from the other one.
     */
    'theatrical_window_days' => 45,

    /* ---- reminders --------------------------------------------------
     * Read in ONE place each, so the cron and the screens cannot
     * disagree about them.
     */
    'reminders' => array(
        /* How many days BEFORE release the heads-up email fires.
         *
         * Applied when a movie is SAVED, not when the cron reads it:
         * movies.heads_up_eligible records whether this window was still
         * ahead at the time the movie was added. A movie added five days
         * before release gets no heads-up at all — that is the deliberate
         * "skip late adds" behaviour, and evaluating the window at send
         * time instead would fire it immediately as overdue.
         */
        'heads_up_days' => 7,

        /* Cap on emails per run. A guard against a data accident (a bulk
         * import with the same release date, say) turning into a hundred
         * messages from your own address in one morning. Anything over
         * the cap waits for tomorrow's run; the send ledger means
         * nothing is lost.
         */
        'max_per_run' => 20,

        /* Re-check the release date against TMDB immediately before
         * sending, and DON'T SEND if it has moved.
         *
         * Release dates slip constantly. Frozen dates are what this app
         * stores (so a date never changes under you without being
         * asked), but sending a "this is out in a week" email about a
         * date TMDB has since moved is worse than sending nothing: the
         * stored date is corrected, the reminder is recomputed for the
         * new date, and you hear about the movie on the right week
         * instead.
         *
         * If TMDB is unreachable at that moment the email is sent
         * anyway, on the frozen date. An API outage must not silently
         * cancel a reminder.
         */
        'verify_before_send' => true,
    ),

    /* ---- outgoing email ---------------------------------------------
     * SMTP rather than mail(), for deliverability: a message sent
     * through mail() from a shared host arrives in spam often enough
     * that you would stop trusting the reminders — and a reminder you
     * do not trust is worse than no reminder, because you still get the
     * mail, you just stop reading it.
     *
     * PHPMailer, vendored as three plain files in lib/vendor/PHPMailer/
     * and required directly — no Composer, no autoloader, no build
     * step, so they FTP up with everything else. lib/mailer.php wraps
     * them so nothing else in the app knows which choice was made.
     *
     * FROM_EMAIL MUST MATCH USER. Gmail rewrites or outright rejects a
     * From address it has not authorized, and the bounce is silent from
     * this app's point of view — the send "succeeds", the ledger
     * records a delivery, and the mail never arrives. This is the
     * single most common way this setup fails, and it is checked before
     * the connection is opened.
     *
     * 'pass' is a Gmail APP PASSWORD, not the account password. Regular
     * passwords have not worked for SMTP since 2022.
     *
     * Verify with `php tools/send-test-email.php` BEFORE trusting a
     * cron you cannot watch run.
     */
    'smtp' => array(
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'secure'     => 'tls',        // 'tls' (587, STARTTLS) or 'ssl' (465)
        'user'       => 'CHANGE_ME',
        'pass'       => 'CHANGE_ME',  // Gmail APP PASSWORD
        'from_email' => 'CHANGE_ME',  // MUST equal 'user'
        'from_name'  => 'Movies',

        /* Single user: every reminder goes to this one address. Make it
         * the one you actually read on your phone — a reminder in an
         * inbox you check weekly is not a reminder.
         */
        'to'         => 'CHANGE_ME',
    ),

    /* ---- cron -------------------------------------------------------
     * Hostinger plans differ. Some give a real command cron:
     *
     *   php /home/uXXXX/domains/movies.../tools/cron-reminders.php
     *
     * Others only offer a URL fetch, and tools/ is denied over HTTP —
     * so public/cron.php exists as a thin wrapper around the same
     * function, gated by this token compared with hash_equals().
     *
     * Generate one and paste it in:
     *
     *   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
     *
     * Leave it empty if you have a command cron; public/cron.php then
     * refuses every request rather than running unauthenticated. That
     * is the opposite of the login gate's behaviour on purpose —
     * failing closed here locks nobody out of anything, because the
     * command cron and the app both still work.
     *
     * Run it once a day, early morning in the timezone above.
     */
    'cron' => array(
        'token' => '',
    ),
);
