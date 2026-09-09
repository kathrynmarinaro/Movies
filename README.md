# Movies

A personal, mobile-first movie log. Two screens: what you've **watched** (with
ratings), and what you haven't — **Coming Soon** and **Out now**, two sections
of one list, with email reminders before a film opens. Plus a public,
read-only page showing only the watched collection.

Single user. PHP 8.4 + MySQL on Hostinger. No build step — the deployed app is
plain files.

Part of the same suite as Book Tracker, Personal CRM, Shirewatch, Grocery and
the rest, and shares their design system, auth and file layout.

## Setup

```bash
# 1. Config
cp config.example.php config.php
#    fill in the DB credentials from hPanel, the TMDB token, and SMTP

# 2. A password (the gate fails CLOSED without one)
php tools/make-hash.php
#    paste the result into config.php as 'password_hash'

# 3. Database
mysql -u root movies < schema.sql

# 4. Check the host can actually do what this app needs
php tools/hosting-check.php

# 5. Sample data, for development only
php tools/seed.php --reset
```

**Do step 4 before trusting any of this.** See "The TMDB bet" below.

## Dev

```bash
php -S 127.0.0.1:8790 -t public     # dev server
php tools/seed.php --reset          # 20 movies, chosen to break layouts
php tools/run-tests.php             # 140 assertions, offline
php tools/smoke-screens.php         # 57 assertions, renders every screen
php tools/cron-reminders.php --dry-run --today=2026-09-16
```

The seed makes **no network calls** — posters are drawn locally with GD. It
deliberately includes the cases that break things: a movie with no poster, an
unrated one beside a one-star one, a very long title and a one-word title, a
title containing an apostrophe and one containing `<script>`, a watched movie
with no date, a film out tomorrow, one already out and still sitting on the
Coming Soon list, one with no release date at all, and a "late add" whose
week-ahead reminder must never fire.

## The TMDB bet

Everything except manual entry goes through
[TMDB](https://www.themoviedb.org/settings/api): search, posters, genres and
streaming availability.

**Book Tracker had to cut Google Books entirely** after its hosting check found
`googleapis.com` unreachable from Hostinger. `api.themoviedb.org` is the same
class of bet, and `image.tmdb.org` is a separate host that can fail
independently — a plan that reaches one but not the other gives you a working
search and a grid of placeholders.

`tools/hosting-check.php` tests both, plus outbound SMTP and the database, and
it has to be run **on the server**. If TMDB is unreachable, the "Create new"
path still works and every movie is entered by hand.

Use the **API Read Access Token** (the long `eyJ...` one), not the 32-character
v3 API key. The v3 key produces a 401 whose body says "Invalid API key", which
reads like a wrong key rather than the wrong *kind* of key.

## Coming Soon and Out now

The To Watch tab has two sections, and **which one a film is in is not a
choice** — it is a fact about the calendar:

| | |
|---|---|
| not out yet, or still in theatres | **Coming Soon**, soonest first |
| out of theatres | **Out now** |

`movie_section()` in `lib/repo.php` is the only thing allowed to decide it, and
it runs both on save and as a daily sweep — a film changes section because a
day passed, and nothing in a database notices that on its own. The add flow
therefore never asks which list you meant.

**"Out of theatres" is an assumption.** TMDB has no end-of-run date, only a
release date, so it is approximated as release + `theatrical_window_days`
(config, default 45 — roughly the current studio window before streaming).
Change it and everything re-settles on the next sweep. Two things are never
moved by a date: a **watched** film, and one with **no release date** —
unknown is not the same as out.

A film that came out last week stays in Coming Soon and says "Out 6 days ago"
in red. It is still in theatres, and that is the row most worth acting on.

## Reminders

A daily cron emails you about films on the Coming Soon list:

- **a week before release** — automatic, but only if the film was added while
  that week was still ahead. Add something three days before it opens and you
  get no week-ahead email; the movie screen says so, rather than leaving you to
  wonder later.
- **on release day** — a per-movie toggle, off by default.

One email per reminder, no digest batching. Reminders stop when a movie is
marked watched, because the due query filters on status.

Release dates are **frozen** when saved. The cron re-checks against TMDB
immediately before sending and **does not send** if the date has moved — it
corrects the stored date instead, and you hear about the film on the right
week. If TMDB is unreachable at that moment it sends anyway, on the frozen
date: an API outage must never silently cancel a reminder.

`movie_reminder_sends`, keyed `(movie_id, kind, trigger_date)`, is what makes a
double-send impossible. A failed send leaves `sent_at` NULL and is retried; a
delivered one never sends again.

Set the schedule to run once a day, early:

```
# a command cron
php /home/uXXXX/domains/movies.example.com/tools/cron-reminders.php

# or, on a plan with only a URL fetch (set cron.token in config.php first)
https://movies.example.com/cron.php?token=...
```

Both run the identical function in `lib/cron.php`.

## Architecture

| | |
|---|---|
| `lib/` | Shared server code — config, DB, auth, dates, repo, render, TMDB, posters, mailer, cron |
| `public/` | Web root: screens, `api/`, `assets/`, `posters/` |
| `tools/` | CLI: hosting check, seed, tests, cron, hash, test email |
| `docs/CONTRACTS.md` | **Start here.** Interfaces and the CSS vocabulary |
| `CLAUDE.md` | The decisions, and what looks like a bug but isn't |
| `schema.sql` | Database, heavily commented |

## Deploying to Hostinger

```bash
php tools/build-deploy.php     # -> movies-deploy-YYYY-MM-DD.zip
```

Upload the zip's **contents** by FTP into the folder the subdomain points at.
Nothing to compile — the build step only packages files and checks two things
a human zipping the folder gets wrong: it excludes `config.php` (which holds
every secret this app has) and it fails the build if any of the five
`.htaccess` files is missing, because that failure is silent and the site
would come up looking perfect while serving `config.php` as plain text. Full instructions in `DEPLOY.txt` — read it, there
are four things that are easy to get wrong and silent when you do.

`public/posters/` must be writable; that's where fetched and uploaded poster
art is cached.

### Verify after uploading

```bash
curl -I https://movies.example.com/                # expect 200 (login)
curl -I https://movies.example.com/collection.php  # expect 200 (public)
curl -I https://movies.example.com/config.php      # expect 403 or 404
curl -I https://movies.example.com/schema.sql      # expect 403 or 404
curl -I https://movies.example.com/lib/db.php      # expect 403 or 404
```

**403 and 404 are both passes — only 200 is a failure.** Which one you get
depends on the server: Hostinger runs LiteSpeed, which resolves the rewrite
before the file-level deny, so top-level files tend to 404 while anything under
`lib/` hits that directory's deny file and 403s.

## The public page

`public/collection.php` is the only file a stranger should ever reach. It shows
poster, title, year, rating, favorite, notes and genres for **watched movies
only** — never the Coming Soon list, never the To Watch list, never the exact
date you watched something.

Three independent things enforce that, and each is enough on its own:

1. The status filter is in the **query**, not a template.
2. Every row goes through `movie_public()`, which builds a new array from an
   explicit whitelist rather than removing fields — so a column added to
   `movies` next year is private by default.
3. That file has no way to ask for another status; `movies_public()` takes no
   parameter.

`tools/smoke-screens.php` renders the actual page and greps the bytes for every
private title in the seed.

## Signing in

Everything except `collection.php` and the token-gated `cron.php` is behind a
single password.

**The gate fails closed.** With no `password_hash` in `config.php` the private
screens refuse and tell you to run `tools/make-hash.php`. This differs from
Book Tracker, deliberately — two of the three lists here must never be public,
and failing closed locks nobody out of anything.

Login attempts are throttled per IP: escalating delays after 3 failures,
refusal after 10 in 15 minutes.
