# Movies — working notes

A personal movie tracker: what you've watched and rated, what's coming out
(with email reminders), and what you mean to get to. Plus a public, read-only
page showing only the watched collection.

Single user. PHP 8.4 + MySQL on Hostinger, deployed by FTP. No build step.

**Read `docs/CONTRACTS.md` before writing any code.** It has the interfaces,
the CSS class vocabulary, and the ten things about this schema that will bite
you.

## Stack, non-negotiable

- PHP 8.4+, `declare(strict_types=1)` at the top of every file.
- MySQL/MariaDB via PDO. **Prepared statements only** — never interpolate into
  SQL. Use `q($sql, $params)` from `lib/db.php`.
- **No build step, ever.** Deployment is an FTP upload of plain files. No npm,
  no bundler, no transpiler, no CSS preprocessor. Browser JS is hand-written ES
  modules. (PHPMailer is vendored as three plain files — that's not a violation
  of the rule; the rule is about not needing a toolchain.)
- Mobile-first. The phone is the only device that matters. 48px minimum tap
  target (`--tap`), 16px minimum font on inputs — below 16px iOS zooms on focus
  and never zooms back.
- `array()` long syntax, matching the sibling apps.

## House style

- Comments explain **why**, not what. Look at `schema.sql` and `lib/dates.php`
  for the register.
- Escape on output with `h()`. Templates use `<?= h($x) ?>`, never a bare echo
  of DB data.
- Entry points start with `require_once __DIR__ . '/../lib/bootstrap.php';`
- Fail soft: a missing poster, a failed TMDB call, a malformed row — each
  degrades that one card. It never 500s a screen.

**`public/assets/styles.css` is complete.** Write markup against the existing
classes (catalogued in `docs/CONTRACTS.md` §5). Don't add `<style>` blocks,
don't add inline `style=` for anything structural. Need a new component? Say so.

## Shared with the other apps

`lib/bootstrap.php`, `lib/db.php`, `lib/auth.php`, `lib/dates.php`,
`lib/layout.php` and `lib/mailer.php` are ports from the Personal CRM and Book
Tracker. `public/assets/styles.css` and the shared JS modules (`api.js`,
`menu.js`, `tagfield.js`, `chrome.js`) come from Shirewatch.

**If you fix a bug in any of these, it should land in the siblings too — say so
in your report.** Two are outstanding right now:

- **Shirewatch's Log out is broken.** Its `menu_items()` points at
  `logout.php` with an `href`, but its `logout.php` is `require_method('POST')`
  — so the menu item does a GET, gets a JSON 405, and renders a page of braces
  instead of signing you out. Fixed here by using the `form` key instead
  (`lib/layout.php`), which is what `menu.js` already supports.
- **`lib/db.php`'s CLI PDO override was consulted after its static cache**, so
  a second `harness_pdo()` in one process was silently ignored and tests
  accumulated rows across groups. Fixed here by checking the override first
  and never caching it. The CRM and Shirewatch have the same ordering.

## Things that look like bugs but are decisions

- **One `movies` table with a `status` column, not three tables.** The brief
  drafted separate `watched_entries` / `coming_soon_entries` /
  `recommended_entries`. It asks for two promotion flows, and under separate
  tables each of those is a cross-table row copy that re-keys the genre rows,
  hands the movie a new id (breaking any saved link), and leaves the cached
  poster pointed at by a row that just got replaced. One table makes every
  transition a single `UPDATE`. Book Tracker made the same call for the same
  reason.

- **The rating scale is 1–5. Zero is not a legal rating, and `rating` NULL
  means unrated and renders *nothing*.** The brief said 0–5. Changed
  deliberately: the distinction that has to survive is rated vs unrated —
  logging a movie now and rating it later is a normal thing to do — and a
  zero-star rating would be indistinguishable from one star in every filter.

- **There is no `reminders` table.** The brief drafted one. Both trigger dates
  derive from `release_date` (minus the lead, and the date itself), so storing
  them would be a second copy of the same fact, and the copies come apart the
  moment a release date is corrected. Only the *send ledger*
  (`movie_reminder_sends`) is stored.

- **`heads_up_eligible` is stored, not derived.** It records whether the
  week-ahead window was still ahead when the movie was saved. Deriving it at
  send time gives the opposite behaviour: a movie added three days before
  release would have a heads-up date in the past, the due query would read that
  as overdue, and it would fire immediately — the exact email that was asked
  not to be sent.

- **The reminder due query uses `=`, not `<=`.** Both siblings use `<=` and let
  the ledger silence overdue rows, because an unflushed water heater is still
  true tomorrow. "This is out in a week" is not. A heads-up fires on its day or
  not at all; if the cron misses a day, that heads-up is gone.

- **Release dates are frozen at save time.** Nothing re-syncs them nightly — a
  value that rewrites itself under you is one you can't reason about. Two
  things move a date, both explicit: the "Check release date" button, and the
  cron's verify-before-send, which re-checks immediately before emailing and
  **does not send** if the date moved. If TMDB is unreachable at that moment it
  **sends anyway**, on the frozen date: an API outage must never silently
  cancel a reminder.

- **Coming Soon and To Watch are one screen with two sections, and the section
  is not a choice.** They were two tabs. They answer one question — "what am I
  going to watch?" — and the difference between them is a fact about the
  calendar, so `movie_section()` in `lib/repo.php` decides it and is the only
  thing allowed to: still in theatres (or not out yet) → Coming Soon, out of
  theatres → To Watch. Applied on save *and* by a daily sweep
  (`movies_resettle()`), because a film changes section when a day passes and
  nothing in a database notices that on its own. The add flow therefore never
  asks which list.

- **"Out of theatres" is an assumption, not a fact TMDB gives us.** There is no
  end-of-run date in the API — only the release date — so it is approximated as
  release + `theatrical_window_days` (config, default 45). Change the value and
  everything re-settles on the next sweep; the number is not baked into a query.
  Two cases are deliberately never moved: `watched` (the one status a person
  sets), and a film with no release date (unknown is not the same as out).

- **`is_rewatchable` is a column, not a genre tag** — even though it renders as
  a chip beside the genres, which is what the brief asked for. The brief also
  asks to filter on it, and a genre can be renamed or deleted out from under a
  filter. A column can't.

- **The auth gate FAILS CLOSED when no password hash is set.** Book Tracker's
  fails open, correctly for that app: it shipped with no login and its data was
  public anyway. Here two of the three lists must never be public, and failing
  closed locks nobody out of anything — `config.php` is a file you already have
  to edit to deploy.

- **The favorite marker is a heart, never a star.** The rating beside it is
  already five stars; a sixth is illegible at phone size. It is `--heart`
  (#d4576b), deliberately NOT the accent teal — it sits on poster art of every
  colour and has to stay findable. Ported from Book Tracker along with
  `--star`.

- **Stars are glyphs, not SVG.** Shirewatch's `.star` is a 15px box expecting
  an `<svg>` child, because that app draws half-stars for a vendor average.
  This app has whole stars only, `render_stars()` emits `★`/`☆`, and the CSS is
  Book Tracker's two lines. Sizing a glyph with width/height happened to look
  right and wasn't.

- **`.chips .chip` is the filled Book Tracker tag; `.filterbar .chip` is the
  bordered Shirewatch filter.** Two components that share a name. A filter is a
  48px-ish tappable control and earns its size; a genre on a detail screen is a
  label, and giving it the bordered form made it look like a button that does
  nothing.

- **`.poster-title` clamps to two lines.** Without it a long title sets its own
  height and drags the whole grid row with it — "Everything Everywhere All at
  Once and Then Some More Besides" ran to six lines and pushed the tiles beside
  it out of rhythm.

- **The public page is its own file with its own `<head>`.** It deliberately
  does not use `lib/layout.php`, because `page_foot()` renders the tab bar and
  the tab bar is a list of the private URLs.

- **An unlinked poster tile is an inert `<div>`, not `role="button"`.** Book
  Tracker's renders the latter and its own contracts flag it as the thing to
  fix before publishing a JS-free surface. The public page is exactly that
  surface.

- **Rent and buy streaming options are filtered out at PARSE time**, in
  `lib/tmdb.php`, so they never enter the database. Filtering at render time
  would leave them one careless loop away from being displayed.

## Testing

```bash
php tools/run-tests.php        # 140: schema, repo, sections, reminders, mailer
php tools/smoke-screens.php    # 57: renders every screen, checks the public page
```

Both run offline against an in-memory SQLite translation of `schema.sql`
(`tools/test-harness.php`). Neither contacts MySQL, TMDB or an SMTP server.

`run-tests.php` avoids `lib/bootstrap.php` so it runs with no `config.php`;
`smoke-screens.php` uses the real bootstrap and therefore needs one. That's why
they're two entry points — the reasoning is in each file's header.

**Neither proves anything about TMDB reachability or SMTP from the deployment
host.** `tools/hosting-check.php` answers both, and it has to be run *on
Hostinger*.

The schema itself *has* been verified against a real MariaDB 10.11: it loads
clean and its constraints bite (rating 0 and 6 rejected, unknown status
rejected, duplicate `tmdb_id` rejected while many NULLs are fine, and a
duplicate ledger key rejected — the double-send guarantee holding on the
database this deploys to).

## Out of scope for v1

Algorithmic recommendations · rent/buy streaming · "how watched" format
tracking · multi-user · multi-region streaming · importing existing history
(there is none).

Don't build these, and don't leave hooks for them.
