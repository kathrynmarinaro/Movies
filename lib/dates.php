<?php
/* Every piece of date arithmetic in this app, in one file, as pure functions.
 *
 * ---------------------------------------------------------------------------
 * THE RULE: movies_today() IS THE ONLY FUNCTION HERE THAT ASKS WHAT DAY IT IS.
 * ---------------------------------------------------------------------------
 *
 * Everything else takes a $today parameter. Nothing else in the app anywhere
 * may call date(), time(), 'now' or NOW() to decide whether a reminder is due.
 *
 * Why it is worth being this strict. lib/auth.php carries a comment about a
 * sibling app whose login lockout silently never fired: PHP ran in UTC while
 * MySQL ran in CDT, strtotime() read MySQL's local-time string as UTC, and the
 * unlock time came out five hours in the past. The lockout never triggered and
 * the escalating delays kept making it look like throttling worked.
 *
 * This app cannot contain that lesson inside one query. "Seven days before
 * release" and "on release day" ARE the product. A one-day skew is not subtle
 * here — it is an email about a movie that came out yesterday, with nothing on
 * screen to explain it.
 *
 * One reading of "today", taken once per request or cron run, passed down, and
 * compared against DATE columns that carry no time component at all. That is
 * what makes the reminder queries plain equality against an indexed DATE
 * column — no DATE_ADD, no INTERVAL, portable to the SQLite the test harness
 * runs on. It is also what makes this file testable: every function but the
 * first needs no clock, no database and no config.
 *
 * ---------------------------------------------------------------------------
 * ALL ARITHMETIC HAPPENS IN UTC, DELIBERATELY, AND THAT IS NOT A CONTRADICTION.
 * ---------------------------------------------------------------------------
 *
 * The strings these functions handle are plain calendar dates. They carry no
 * time of day and no zone, so there is nothing to convert — but doing the
 * arithmetic in a zone that observes DST breaks it anyway:
 *
 *     (new DateTimeImmutable('2026-03-08'))->diff(new DateTimeImmutable('2026-03-09'))->days
 *
 * is 0, not 1, in America/Chicago, because those two midnights are 23 hours
 * apart and ->days floors. A March release would then read "6 days away" for
 * two days running, and the heads-up email would fire twice or not at all.
 * Parsing in UTC makes every day exactly 86400 seconds long and the counting
 * exact.
 *
 * movies_today() is where the configured zone is applied, and it is the only
 * place it needs to be. */

declare(strict_types=1);

/**
 * Today, as Y-m-d, in the app's configured timezone.
 *
 * THE ONLY IMPURE FUNCTION IN THIS FILE. Call it once at the top of a request
 * or a cron run and pass the string down; calling it twice in one run is how a
 * cron started at 23:59:59.9 decides a movie is due against one date and writes
 * the send ledger against another — and the ledger is keyed on that date, so a
 * mismatch means the same email again tomorrow.
 *
 * It reads PHP's default timezone rather than cfg('timezone') again, on
 * purpose. lib/bootstrap.php sets that default from the config value as its
 * very first act, and lib/db.php pins MySQL's connection to the same zone.
 * Re-reading the config here would be a second, independent interpretation of
 * the same setting — one more place for the app's idea of "today" to fork.
 */
function movies_today(): string
{
    return date('Y-m-d');
}

/**
 * Parse a Y-m-d date into a UTC midnight, or null. Internal to this file.
 *
 * Tolerates a trailing time, so a DATETIME straight out of the database can be
 * handed to days_until() without every caller remembering to slice it.
 *
 * Returns null for anything it cannot read, so callers degrade one card rather
 * than throwing a screen away. That path is reached in normal use: TMDB serves
 * an empty release_date string for a film that has been announced but not
 * dated, and those movies are exactly the ones somebody wants on a Coming Soon
 * list.
 */
function movies_parse_date(?string $date): ?DateTimeImmutable
{
    if ($date === null || strlen($date) < 10) {
        return null;
    }

    /* '!' resets the time to 00:00:00 — without it, createFromFormat fills the
     * unspecified time from the CURRENT clock, which makes a "pure" function
     * return different answers depending on when you run it. */
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), new DateTimeZone('UTC'));

    /* createFromFormat happily rolls 2026-02-30 forward into March rather than
     * failing, so the round trip is the actual validity check. */
    if ($parsed === false || $parsed->format('Y-m-d') !== substr($date, 0, 10)) {
        return null;
    }

    return $parsed;
}

/**
 * Whole days from $today to $date. Positive = in the future, negative = past.
 * Null when either side is unparseable.
 *
 * Both sides are UTC midnights, so this is exact integer division and no DST
 * transition can round it to the wrong side. See the file header.
 *
 * This is the number the Coming Soon countdown renders and the number the
 * reminder logic reasons about, and it is one function so those two can never
 * disagree about whether a movie is out.
 */
function days_until(?string $date, string $today): ?int
{
    $target = movies_parse_date($date);
    $from   = movies_parse_date($today);
    if ($target === null || $from === null) {
        return null;
    }

    return (int) (($target->getTimestamp() - $from->getTimestamp()) / 86400);
}

/**
 * The date a heads-up reminder for $releaseDate falls on. Null if undated.
 *
 * THE ONLY FUNCTION ALLOWED TO COMPUTE A REMINDER DATE. The cron's due query,
 * the add/edit screen's eligibility check and the movie detail screen all call
 * this. A second implementation anywhere would eventually produce a date the
 * cron never actually fires on, and you would find out on the day the email
 * didn't arrive.
 *
 * $lead comes from config (reminders.heads_up_days, default 7) and is read in
 * one place, so the screen and the cron cannot disagree about it.
 */
function heads_up_date(?string $releaseDate, int $lead): ?string
{
    $release = movies_parse_date($releaseDate);
    if ($release === null) {
        return null;
    }
    $lead = max(0, $lead);

    return $release->modify('-' . $lead . ' days')->format('Y-m-d');
}

/**
 * The countdown on a Coming Soon card, phrased for a person.
 *
 *   "Out today" · "Tomorrow" · "In 12 days" · "Out 3 days ago" · "Date TBA"
 *
 * Capitalised and standalone, because it renders as a line on a card rather
 * than mid-sentence. lib/mailer.php has its own lowercase phrasing for subject
 * lines, which read as a sentence — that is a genuinely different register and
 * a flag on this function would produce a worse version of both.
 *
 * A PAST DATE STILL RENDERS, rather than being treated as an error. A movie
 * that came out last week and hasn't been marked watched yet is a normal state
 * — it is precisely the pile the Coming Soon list is meant to surface — and
 * hiding the fact that it is already out would be the screen lying.
 */
function fmt_countdown(?string $releaseDate, string $today): string
{
    $days = days_until($releaseDate, $today);
    if ($days === null) {
        return 'Date TBA';
    }

    if ($days === 0)  { return 'Out today'; }
    if ($days === 1)  { return 'Tomorrow'; }
    if ($days === -1) { return 'Out yesterday'; }
    if ($days < 0)    { return 'Out ' . abs($days) . ' days ago'; }

    return 'In ' . $days . ' days';
}

/**
 * The year of a stored date as an int, or null.
 *
 * movies.year is stored separately and is what the grid renders, because a
 * manually-added movie may have a year and no full release date. This is for
 * deriving that year from a TMDB release date at save time, and nothing else.
 */
function date_year(?string $date): ?int
{
    $parsed = movies_parse_date($date);
    return $parsed === null ? null : (int) $parsed->format('Y');
}
