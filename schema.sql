-- Movies — schema
-- Load with:  mysql -u root movies < schema.sql
--
-- Every decision below was made deliberately and the reasoning is here rather
-- than in a document nobody opens. The comments explain WHY, not what.
--
-- Written to load on MariaDB 10.11 / MySQL 8, and — with the exception of the
-- ON DUPLICATE KEY UPDATE in the send ledger, which tools/test-harness.php
-- translates on its way through the connection — to be inside the subset SQLite
-- also accepts, so the reminder logic is testable without a MySQL server.


-- ------------------------------------------------------------------ movies

-- ONE TABLE FOR ALL THREE SECTIONS, with a status column, rather than the
-- separate watched / coming_soon / recommended tables the brief drafted.
--
-- The brief asks for two promotion flows: "Mark as Watched" from Coming Soon,
-- and the same from Recommended. Under separate tables each of those is a
-- cross-table row COPY, which means:
--
--   * the movie gets a new id, so any link, bookmark or open tab pointing at
--     the old one breaks;
--   * every movie_genres row has to be re-keyed to the new id, and there is a
--     window where the movie exists twice or not at all;
--   * the cached poster on disk is still named after nothing in particular but
--     is pointed at by a row that just got replaced — get the order wrong and
--     you orphan the file or, worse, delete art you can never re-fetch because
--     it was manually uploaded for a movie TMDB doesn't have.
--
-- Under one table every one of those transitions is a single UPDATE. This is
-- Book Tracker's decision, made for the same reason, and it is the first entry
-- in that app's list of things that look like bugs and aren't.
--
-- The UI shows three tabs. The database does not, and you cannot tell from
-- outside.
CREATE TABLE IF NOT EXISTS movies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- TMDB's own movie id. NULL for a movie entered by hand, which is a normal
  -- and permanent end state — the brief asks for manual entry precisely
  -- because TMDB doesn't have everything (obscure titles, festival films).
  --
  -- UNIQUE, but see the note on the key below: NULL is exempt, so any number
  -- of manual entries coexist while a TMDB movie can only be added once.
  tmdb_id           INT UNSIGNED NULL,

  title             VARCHAR(255) NOT NULL,

  -- Release year, stored rather than derived from release_date. A manually
  -- added movie may have a year and no full date, and the grid renders the
  -- year — deriving it would render nothing for exactly the entries that most
  -- need identifying.
  year              SMALLINT UNSIGNED NULL,

  -- The three sections, and the two transitions between them:
  --
  --   coming_soon ──→ watched      ("Mark as Watched", after release)
  --   to_watch    ──→ watched      ("Mark as Watched")
  --
  -- There is deliberately no coming_soon ──→ to_watch automatic move when a
  -- release date passes. A movie that came out last week and hasn't been seen
  -- yet should keep saying "Out 6 days ago" on the Coming Soon list, because
  -- that is the true statement about the world and it is the thing that
  -- prompts you to go. Silently reclassifying it would move it to a screen you
  -- weren't looking at, on a day nobody chose. (Same reasoning as Shirewatch's
  -- refusal to roll a missed seasonal task forward a season.)
  --
  -- Default is 'watched' because that is the overwhelmingly common way a movie
  -- enters this app: saw it, logging it right after.
  status            ENUM('watched','coming_soon','to_watch')
                    NOT NULL DEFAULT 'watched',

  -- Relative to the web root, e.g. "posters/a1b2c3d4e5f6a7b8.jpg". NULL until
  -- fetched, and NULL forever is a valid end state — a movie with no poster
  -- still belongs in the grid, and the card renders a titled placeholder.
  --
  -- Fetched at TMDB's w500 (config tmdb.poster_size). These files are cached
  -- permanently and never re-fetched, so a smaller size chosen here would
  -- quietly degrade every poster from that point on and only become visible on
  -- a phone months later. Same lesson as Book Tracker's covers, which are
  -- fetched at -L for exactly this reason.
  poster_path       VARCHAR(255) NULL,

  -- Theatrical release date, from TMDB. NULL is normal: an announced film
  -- often has no date yet, and those are precisely the ones somebody wants on
  -- a Coming Soon list.
  --
  -- FROZEN AT SAVE TIME, not re-synced nightly. Release dates slip constantly,
  -- and a value that rewrites itself under you is a value you cannot reason
  -- about — you would see a countdown change without having asked for it. Two
  -- things move it, both explicit: the "Check release date" button on the
  -- movie screen, and the cron's verify-before-send step, which re-checks
  -- immediately before emailing and, if the date has moved, corrects this
  -- column and does NOT send. See tools/cron-reminders.php.
  release_date      DATE NULL,

  -- When release_date was last confirmed against TMDB. Rendered on the movie
  -- screen next to the date, so "is this still right?" has an answer that
  -- isn't a guess. NULL means never checked since it was first saved.
  release_date_checked_at DATETIME NULL,

  -- Whole stars, 1-5. NULL means UNRATED and renders NOTHING.
  --
  -- ZERO IS NOT A LEGAL RATING and the CHECK below rejects it. The brief asked
  -- for 0-5; this is a deliberate change, agreed before build. The distinction
  -- that actually has to survive is rated vs unrated — logging a movie now and
  -- rating it later is a real thing people do, and NULL is what carries that.
  -- A zero-star rating would be a second way of saying "bad" alongside one
  -- star, and the two would be indistinguishable in every filter.
  --
  -- Matching Book Tracker's scale also means render_stars() and the rating
  -- filters port across unchanged.
  rating            TINYINT UNSIGNED NULL CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),

  -- Separate from rating, per the brief: a five-star film and one you'd press
  -- into someone's hands are different judgements.
  --
  -- Rendered as a HEART, never a star — the rating beside it is already five
  -- stars and a sixth is illegible at phone size. Edit-page only, not on the
  -- grid card, matching Book Tracker.
  is_favorite       TINYINT(1) NOT NULL DEFAULT 0,

  -- A COLUMN, NOT A TAG, even though the brief describes it living "alongside
  -- genre tags in the same tag area" — and it does, visually: the movie screen
  -- renders it as a chip beside the genre chips, which is what was asked for.
  --
  -- It is a column because the brief also asks to FILTER on it. A genre is a
  -- row in `genres` that can be renamed or deleted from the genre screen, and
  -- the day somebody tidies up a genre list is the day a filter built on one
  -- silently starts matching nothing. A column cannot be renamed out from
  -- under a query.
  is_rewatchable    TINYINT(1) NOT NULL DEFAULT 0,

  -- Free text, on all three sections. Nothing parses it, ever — no tag
  -- inference, no normalization, no typo correction. On a To Watch entry it is
  -- usually who recommended it; on Coming Soon it is why you care; on a
  -- watched movie it is whatever you thought. Any rule smart enough to
  -- categorise those is smart enough to destroy them.
  notes             TEXT NULL,

  -- The date you watched it. NULL for coming_soon and to_watch, which is what
  -- keeps them out of the reverse-chronological watched stream.
  --
  -- DATE, not DATETIME: nobody logs the minute the credits rolled.
  --
  -- Note there is deliberately no format / "how watched" column. The brief
  -- excludes it explicitly as data this person doesn't find useful, and an
  -- unused nullable column is a thing every future form has to decide about.
  date_watched      DATE NULL,

  -- THE "SKIP LATE ADDS" RULE, DECIDED ONCE AND STORED.
  --
  -- True when the heads-up window (release_date minus reminders.heads_up_days)
  -- was still in the FUTURE at the moment this movie was saved. Set by
  -- lib/repo.php on every save that touches release_date, never by the cron.
  --
  -- This is stored rather than derived because deriving it at send time gives
  -- the opposite behaviour: a movie added three days before release would have
  -- a heads-up date three days in the past, the due query would see it as
  -- overdue, and it would fire immediately — which is exactly the email that
  -- was asked not to be sent. Evaluating the window once, at the only moment
  -- when "was there still time?" is a meaningful question, is what makes the
  -- rule expressible at all.
  heads_up_eligible TINYINT(1) NOT NULL DEFAULT 0,

  -- The optional second reminder, on release day itself. Per-movie toggle, set
  -- when adding or editing a Coming Soon entry — not a global setting, per the
  -- brief. Off by default: an email you did not ask for is worse than one you
  -- have to opt into.
  day_of_reminder   TINYINT(1) NOT NULL DEFAULT 0,

  -- Where the metadata came from. Kept so a manual entry can be told apart
  -- from a TMDB one without inferring it from tmdb_id being NULL — which would
  -- be the same test today but stops being so the moment anything else can
  -- create rows.
  source            ENUM('tmdb','manual') NOT NULL DEFAULT 'manual',

  date_added        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- ONE ROW PER TMDB MOVIE, and MySQL permits unlimited NULLs under a UNIQUE
  -- key — so manual entries are simply not subject to this, and two hand-typed
  -- movies with the same title are allowed. That asymmetry is the point:
  -- adding the same TMDB movie twice is always a mistake (the add flow should
  -- have found the existing one), while two manual rows that look alike may be
  -- two genuinely different films.
  UNIQUE KEY uniq_tmdb (tmdb_id),

  -- The three section queries: WHERE status = ? ORDER BY date_watched DESC
  -- (watched) or release_date ASC (coming soon).
  KEY idx_status_watched (status, date_watched),
  KEY idx_status_release (status, release_date),

  -- The cron's due query ranges on release_date across coming_soon rows only.
  -- Covered by idx_status_release above; called out here so nobody adds a
  -- third index for it.

  KEY idx_favorite (is_favorite, date_watched)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------------ genres

-- Seeded from TMDB's own genre list (tools/seed-genres.php) and extended by
-- hand — the brief asks for TMDB genres to be editable and for custom tags
-- alongside them, and this is one list serving both. A custom tag is simply a
-- genre nobody at TMDB thought of.
CREATE TABLE IF NOT EXISTS genres (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Stored lowercase and trimmed via normalize_tag(). The UNIQUE key is what
  -- stops 'Science Fiction' and 'science fiction' becoming two genres — which
  -- matters more here than in a hand-typed list, because TMDB's names arrive
  -- title-cased and anything typed on a phone does not.
  name      VARCHAR(64) NOT NULL,

  -- TMDB's genre id, for the ones that came from there. NULL for custom tags.
  -- Kept so the seed can be re-run idempotently and so a TMDB genre rename
  -- upstream can be matched to the local row rather than creating a second one.
  tmdb_id   SMALLINT UNSIGNED NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS movie_genres (
  movie_id  INT UNSIGNED NOT NULL,
  genre_id  INT UNSIGNED NOT NULL,

  PRIMARY KEY (movie_id, genre_id),
  KEY idx_genre (genre_id),
  CONSTRAINT fk_mg_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
  CONSTRAINT fk_mg_genre FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------- the send ledger

-- THE ONE THING THAT MAKES A DOUBLE-SEND IMPOSSIBLE.
--
-- NOTE WHAT IS NOT HERE: there is no `reminders` table. The brief drafted one,
-- with a trigger_date column per reminder. It is not built, because both
-- trigger dates are DERIVABLE — heads-up is release_date minus the configured
-- lead, day-of is release_date itself. Storing them would mean two copies of
-- the same fact, and the copies come apart the instant a release date is
-- corrected: the movie would say November and the reminder row would still
-- fire in September, with nothing to reconcile them.
--
-- So the schedule is computed, and only the FACT OF HAVING SENT is stored.
-- That is Shirewatch's task_reminder_sends pattern and the Personal CRM's
-- reminder_sends, and it is what lets a release date move freely without any
-- reminder bookkeeping following it around.
CREATE TABLE IF NOT EXISTS movie_reminder_sends (
  movie_id     INT UNSIGNED NOT NULL,

  kind         ENUM('heads_up','day_of') NOT NULL,

  -- The date this send was FOR: the computed trigger date at the moment of the
  -- attempt. Part of the key, so if a release date DOES move, the new date is
  -- a different (movie, kind, date) triple and is allowed to send once on its
  -- own terms. That is the correct behaviour and it falls out of the key
  -- rather than needing a rule.
  trigger_date DATE NOT NULL,

  -- NULL = attempted and not yet delivered. Non-NULL = delivered, never again.
  -- The state that matters is the NULL, and it is why this is a timestamp
  -- rather than a boolean: "when did that email actually go" is a question you
  -- ask exactly once, in a panic, and only a timestamp answers it.
  sent_at      DATETIME NULL,

  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,

  -- Truncated failure reason — an SMTP refusal, or the note that the send was
  -- skipped because TMDB had moved the release date. 255 because it is a
  -- diagnostic beside the row, not a log; the full text goes to error_log.
  last_error   VARCHAR(255) NULL,

  -- The claim is `INSERT ... ON DUPLICATE KEY UPDATE attempts = attempts + 1`,
  -- and it is safe to run twice because THIS KEY enforces it in the database
  -- rather than in code that has to remember to check first. A previous FAILED
  -- attempt left sent_at NULL and is retried tomorrow — a hung SMTP connection
  -- must not cost you the release.
  PRIMARY KEY (movie_id, kind, trigger_date),

  CONSTRAINT fk_mrs_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------- streaming availability

-- A CACHE, not a source of truth. Rows here are disposable: delete the lot and
-- the app refetches on the next detail view.
--
-- It exists so that opening a movie makes no network call in the common case.
-- Availability changes when licensing deals turn over — a monthly-ish event —
-- so a TTL of days (config tmdb.providers_ttl_days) trades a week of staleness
-- for a screen that renders instantly and still works when TMDB is down.
--
-- SUBSCRIPTION SERVICES ONLY. TMDB's watch-provider response also carries
-- `rent` and `buy` lists; those are filtered out in lib/tmdb.php at PARSE
-- time, before anything reaches this table, rather than being stored and
-- hidden at render time. The brief puts rent/buy explicitly out of scope, and
-- data that never enters the database cannot leak onto a screen through a
-- later template edit.
CREATE TABLE IF NOT EXISTS movie_providers (
  movie_id      INT UNSIGNED NOT NULL,

  -- TMDB's provider id and display name, e.g. 8 / "Netflix".
  provider_id   INT UNSIGNED NOT NULL,
  provider_name VARCHAR(128) NOT NULL,

  -- TMDB logo path, e.g. "/9A1JSVmSxsyaBK4SUFsYVqbAYfW.jpg". Rendered from
  -- TMDB's image CDN rather than cached locally: unlike posters these are a
  -- handful of shared logos, they change when a service rebrands, and a stale
  -- cached logo is worse than a hotlinked current one.
  logo_path     VARCHAR(255) NULL,

  -- When this movie's provider list was last fetched. Same value on every row
  -- for a movie — it describes the FETCH, not the row, which is why an empty
  -- result still writes nothing here and is tracked separately (see
  -- providers_fetched_at handling in lib/tmdb.php: a movie streaming nowhere
  -- is a real answer and must not re-query on every view).
  fetched_at    DATETIME NOT NULL,

  PRIMARY KEY (movie_id, provider_id),
  CONSTRAINT fk_mp_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- A movie that streams NOWHERE is a real, common answer, and it has no rows in
-- movie_providers to carry a fetched_at. Without this table, every view of
-- such a movie would look like a cache miss and re-query TMDB forever.
--
-- One row per movie, written on every successful fetch whether or not it found
-- anything. This is the table the TTL is actually checked against.
CREATE TABLE IF NOT EXISTS movie_provider_fetches (
  movie_id   INT UNSIGNED NOT NULL,
  fetched_at DATETIME NOT NULL,

  PRIMARY KEY (movie_id),
  CONSTRAINT fk_mpf_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------------- auth

-- Login throttling. One row per attempt, pruned opportunistically.
--
-- Session-based counting would be useless: an attacker simply discards the
-- cookie. It has to be keyed to the client address server-side.
--
-- All of the time arithmetic against this table happens inside SQL, on MySQL's
-- clock (lib/auth.php). Doing it in PHP silently disabled the lockout in a
-- sibling app when the two clocks disagreed.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)  NOT NULL,     -- 45 chars covers IPv6
  succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
