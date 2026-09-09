# Interface Contracts

What each layer promises the others. Read this before writing code; the
reasoning behind the decisions is in `CLAUDE.md`.

---

## 0. Ground rules

- **No build step.** ES modules (`<script type="module">`), no bundler, no npm
  in `public/`.
- **PHP 8.4+**, `declare(strict_types=1)`, PDO with prepared statements only.
  Never interpolate a variable into SQL.
- `array()` long syntax, matching the sibling apps.
- **Mobile-first.** Minimum tap target is `--tap` (48px). Inputs are 16px or
  iOS zooms on focus and never zooms back.
- **Use the design system.** `public/assets/styles.css` is complete (§5). Write
  markup against those classes. Don't add a `<style>` block, don't add inline
  `style=` for anything structural.
- **Fail soft.** A movie with no poster still renders. A failed TMDB call still
  saves the movie. A malformed row degrades one card, never a screen.
- Every entry point starts with `require_once __DIR__ . '/../lib/bootstrap.php';`
- Escape on output with `h()`.

---

## 1. File map

| Owner | Files |
|---|---|
| Foundation | `lib/bootstrap.php`, `db.php`, `auth.php`, `dates.php`, `layout.php`, `render.php`, `public/assets/styles.css`, `schema.sql`, `config.example.php` |
| Data | `lib/repo.php` |
| Metadata | `lib/tmdb.php`, `lib/posters.php`, `public/api/search.php` |
| Reminders | `lib/reminders.php`, `lib/mailer.php`, `lib/cron.php`, `tools/cron-reminders.php`, `public/cron.php` |
| Screens | `public/index.php`, `watchlist.php` (Coming Soon + Out now), `movie.php`, `add.php`, `edit.php`, `login.php`, `logout.php`. `coming-soon.php` is a 301 to `watchlist.php` |
| **Public** | `public/collection.php` — see §6 |
| Browser | `public/assets/{api,menu,tagfield,chrome,addflow}.js` |

---

## 2. The database

`schema.sql` is the contract — read it, it's commented. Six tables: `movies`,
`genres`, `movie_genres`, `movie_reminder_sends`, `movie_providers`,
`movie_provider_fetches`, plus `login_attempts`.

**Ten things that will bite you:**

1. **One table, three statuses, two screens.** `watched`, `coming_soon`,
   `to_watch`. Every transition is one `UPDATE`. There are no separate entry
   tables — see `CLAUDE.md`. `coming_soon` and `to_watch` are two SECTIONS of
   `watchlist.php`, and **`movie_section()` is the only thing allowed to decide
   which** — it is a function of `release_date`, not a stored choice.
   `movies_resettle()` applies it daily.
2. **The rating scale is 1–5, and `NULL` means UNRATED.** Zero is not legal;
   the `CHECK` rejects it. An unrated movie renders *nothing* — no stars.
   Every form must express "no rating" distinctly from any starred value.
3. **`tmdb_id` is UNIQUE, but NULL is exempt.** One row per TMDB movie; any
   number of manual entries. That asymmetry is deliberate.
4. **There is no `reminders` table.** Trigger dates are derived from
   `release_date`. Only `movie_reminder_sends` — the ledger — is stored.
5. **`movie_reminder_sends` PK is `(movie_id, kind, trigger_date)`.** That key
   *is* the double-send guarantee. `sent_at IS NULL` means attempted, not
   delivered, and is retried.
6. **`heads_up_eligible` is stored, computed once at save time.** Never derive
   it at send time — you get the opposite behaviour.
7. **`release_date` is frozen.** Two things move it, both explicit: the
   "Check release date" button and the cron's verify-before-send.
8. **`is_rewatchable` is a column, not a genre.** It renders as a chip
   alongside them anyway.
9. **`movie_provider_fetches` exists so an EMPTY provider list can be cached.**
   A movie streaming nowhere has no rows in `movie_providers`, so without it
   every view would look like a cache miss forever.
10. **`notes` is free text and nothing parses it.** No tag inference, no
    normalization, ever.

---

## 3. Shared helpers

| Helper | From | Does |
|---|---|---|
| `cfg('db.host')` | `bootstrap.php` | dot-notation config read |
| `json_out()` / `json_error()` / `json_body()` / `require_method()` | `bootstrap.php` | JSON endpoint plumbing |
| `h($s)` | `bootstrap.php` | `htmlspecialchars` shorthand |
| `asset('assets/x.css')` | `bootstrap.php` | cache-busted URL |
| `fmt_date($d, $fmt)` | `bootstrap.php` | display date; `''` for NULL |
| `normalize_tag($s)` | `bootstrap.php` | canonical genre name (lowercases) |
| `fatal_error($code, $human)` | `bootstrap.php` | JSON under `/api/`, HTML elsewhere, STDERR on CLI |
| `db()` / `q($sql, $params)` | `db.php` | PDO handle / bound query |
| **`movies_today()`** | `dates.php` | **the only function that asks what day it is** |
| `days_until($date, $today)` | `dates.php` | signed day count |
| `heads_up_date($release, $lead)` | `dates.php` | **the only thing allowed to compute a reminder date** |
| `movie_section($status, $release, $today)` | `repo.php` | **the only thing allowed to decide Coming Soon vs To Watch** |
| `movies_resettle($today)` | `repo.php` | applies it to every stored row; returns how many moved |
| `theatrical_window_days()` | `repo.php` | the config read, in one place |
| `fmt_countdown($release, $today)` | `dates.php` | "In 6 days" / "Out today" / "Date TBA" |
| `movies_watched($filters)` / `movies_coming_soon()` / `movies_to_watch()` / `movie_get($id)` / `movie_by_tmdb_id($id)` | `repo.php` | reads, genres attached |
| `movie_save($fields, $id, $today)` / `movie_set_status()` / `movie_delete()` | `repo.php` | writes |
| `genres_all()` / `genres_set($id, $names)` / `genre_names()` | `repo.php` | genres |
| **`movie_public($row)` / `movies_public()`** | `repo.php` | **the public whitelist — §6** |
| `render_movie_card()` / `render_movie_grid()` / `render_stars()` / `render_favorite()` / `render_genre_chips()` / `render_filter_chips()` / `render_providers()` | `render.php` | components |
| `page_head()` / `screen_head()` / `page_foot()` / `page_menu()` | `layout.php` | private chrome only |
| `require_admin()` / `require_admin_api()` / `noindex()` / `require_same_origin()` | `auth.php` | gates (§6) |

**Every date function takes `$today` as a parameter.** `movies_today()` is
called once per request or cron run and passed down. Nothing else may call
`date()`, `time()`, `'now'` or `NOW()` to decide whether a reminder is due.

Mutating `fetch()` calls **must** send `X-Requested-With: Movies` or
`require_same_origin()` will 403 them. `assets/api.js` does this for you.

---

## 4. Shapes

**A movie row** is all columns from `schema.sql` plus a `genres` array of
`{id, name}`.

**The normalized TMDB result** — what `tmdb_search()`, `tmdb_get()` and
`/api/search.php` return:

```jsonc
{ "tmdb_id": 693134, "title": "Dune: Part Two", "year": 2024,
  "release_date": "2024-02-27",           // string | null
  "poster_path": "/abc.jpg",              // string | null — TMDB's path
  "thumb": "https://image.tmdb.org/t/p/w185/abc.jpg",  // string | null
  "genre_ids": [878, 12] }
```

When TMDB has no art, `poster_path` and `thumb` are **both null** — not a
placeholder URL. Consumers render `.poster-none`, which carries the title.

**The public shape** — what `movie_public()` returns and the ONLY thing
`collection.php` may render:

```jsonc
{ "id": 12, "title": "Arrival", "year": 2016,
  "rating": 5,              // int 1-5 | null (null = unrated)
  "is_favorite": true,
  "notes": "...",           // string | null
  "poster_path": "posters/a1b2.jpg",   // string | null
  "genres": ["drama", "science fiction"] }
```

Note what is **absent**: `status`, `tmdb_id`, `date_watched`, `release_date`,
`is_rewatchable`, and both reminder columns.

---

## 5. Component vocabulary

Ported from Shirewatch — `.wrap`, `.screen-head` + `.head-actions`, `.tabbar`,
`.card`, `.pill`, `.chip` / `.chips` / `.chip.is-on` / `.chip.is-static`,
`.stars` / `.star.is-on`, `.field` / `.input` / `.field-err`, `.btn-primary` /
`.btn-secondary` / `.btn-ghost` / `.btn-danger`, `.icon-btn`, `.sheet` /
`.sheet-panel` / `.sheet-cancel`, `.list` / `.list-row`, `.empty`, `.hint`,
`.muted`, `.sr-only`, `.filterbar`, `.fab`, `.section-title`, `.login-*`.

New in this app:

| Class | Use |
|---|---|
| `.poster-grid` | the poster grid, `auto-fill` — 3 across on a phone |
| `.poster-card` | one movie tile |
| `.poster-art` / `.poster` | the 2:3 art box and the image itself |
| `.poster-none` | no-poster placeholder; **carries the title** |
| `.poster-title` / `.poster-sub` | title and the line under it |
| `.poster-countdown` (+ `.is-out`) | the Coming Soon countdown |
| `.fav` | the favourite heart — `--heart`, deliberately NOT the accent |
| `.wrap-public` / `.public-head` / `.public-sub` | the public page only |
| `.providers` / `.provider` | streaming availability |
| `.results` / `.result` / `.result-new` | the add flow's search list |

**Two chips, one name.** `.chips .chip` is Book Tracker's filled tag — a
read-only label, small, no border. `.filterbar .chip` is Shirewatch's bordered
32px filter — a control, and it earns the size. Don't merge them.

**Stars are glyphs.** `render_stars()` emits `★`/`☆`; `.star` is coloured text,
not a sized box. This app has no half-stars.

**`.poster-title` clamps to two lines** and `.poster-sub` to one. Without the
clamp a long title drags its whole grid row out of rhythm.

**`.poster-*`, not `.card-*`, deliberately.** Shirewatch's `.card` is a
bordered content block that three screens' worth of ported markup depends on.
Book Tracker's `.card` is a poster tile — a different component with the same
name. Redefining it would silently restyle every ported `.card`.

Use `render_*()` from `lib/render.php` rather than hand-writing tile markup.
**Never declare a function in a screen file** — templates get included twice by
`tools/smoke-screens.php`, and a helper belongs in `render.php` anyway.

---

## 6. Public vs private — the hard line

| Surface | Files | Rule |
|---|---|---|
| **Public** | `public/collection.php` | Read-only, `watched` only. No edit affordances, no links to private screens, no JS. Indexable. |
| **Public, token-gated** | `public/cron.php` | `hash_equals()`, 404 on mismatch, empty token refuses |
| **Private** | everything else | Must call `require_admin()` or `require_admin_api()` |

Four rules, all load-bearing:

1. **Every public response goes through `movie_public()`**, which builds a new
   array from an explicit whitelist rather than removing fields from the row. A
   column added to `movies` next year is private by default.
2. **Status filtering happens in the QUERY, not the template.** A
   template-level filter is one edit away from publishing the Coming Soon list.
3. **The public page does not use `lib/layout.php`.** `page_foot()` renders the
   tab bar, and the tab bar is a list of the private URLs. It renders its own
   `<head>` and has no chrome at all.
4. **An unlinked tile is an inert `<div>`, never `role="button"`.** There is no
   JS on the public page; a tile announcing itself as a button would promise an
   interaction that does not exist.

`noindex()` is called from inside both gates rather than by each screen, so a
new private screen cannot forget it.

**The gate fails CLOSED** when no `password_hash` is configured. This differs
from Book Tracker on purpose — see `CLAUDE.md`.

---

## 7. Endpoints

| Method | Path | Body / query | Returns | Surface |
|---|---|---|---|---|
| GET | `/api/search.php` | `?q=` | `{ results: [normalized] }` | private |
| POST | `/api/release-check.php` | `id` | 302 back to `movie.php` | private (form) |
| GET | `/cron.php` | `?token=` | text/plain summary | token-gated |

`edit.php` posts to itself — it is a plain form, deliberately, so a filled-in
entry can never be lost to a JS module that failed to load.

`api/release-check.php` uses `require_admin()`, not `require_admin_api()`,
even though it lives under `api/`: its caller is a `<form>`, and a JSON 401
renders as a page of braces. **The caller decides which gate is right, not the
directory.**

---

## 8. The reminder run

`lib/cron.php` owns `cron_reminders_run($today, $dryRun)`. Both entry points —
`tools/cron-reminders.php` and `public/cron.php` — call it, so they cannot
drift.

The sequence, and the order matters:

1. `reminders_due($today)` — computed, not stored. **Equality on the date, not
   `<=`.**
2. `reminder_claim()` — the ledger insert, **before any network call**. If the
   process dies later, the row exists with `sent_at` NULL and tomorrow retries.
   Claim last would mean a crash after sending sends again.
3. `reminder_verify_release_date()` — three outcomes: `unchanged` → send;
   `changed` → correct the stored date and **do not send**; `unreachable` →
   **send anyway** on the frozen date.
4. `send_release_email()` → `reminder_mark_sent()` or `reminder_mark_failed()`.

Non-zero exit / HTTP 500 if anything failed. A cron nobody watches has its exit
status as its only signal.

---

## 9. Testing

```bash
php tools/run-tests.php        # schema, repo, whitelist, reminders, mailer config
php tools/smoke-screens.php    # renders every screen; greps the public page
```

Both offline, against SQLite built from `schema.sql` by
`tools/test-harness.php`. Two entry points because `run-tests.php` avoids
`bootstrap.php` (so it needs no `config.php`) while `smoke-screens.php`
exercises it (so it does). Each file's header explains why.

**Test seams:** `$GLOBALS['tmdb_http_hook']` and `$GLOBALS['mailer_send_hook']`
replace the network and the transport. Nothing in the app sets them. The mailer
hook is checked *before* `mailer_config_problem()`, so a fake transport needs
no credentials — that check is tested directly instead.

Neither suite proves anything about MySQL, TMDB reachability or SMTP from the
deployment host. `tools/hosting-check.php` answers the last two, **on the
server**.
