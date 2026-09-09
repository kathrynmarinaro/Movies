/* The add flow: type a title, get TMDB results, tap one to fill in a form.
 *
 * ---------------------------------------------------------------------------
 * THREE THINGS HERE ARE LOAD-BEARING, NOT POLISH.
 * ---------------------------------------------------------------------------
 *
 *   1. DEBOUNCE. A request per keystroke is a request per keystroke — on a
 *      phone keyboard that is a dozen in-flight searches for one title, all
 *      but the last of them wasted, and TMDB rate-limits.
 *
 *   2. CANCEL THE IN-FLIGHT REQUEST. Without this the results list belongs to
 *      whichever response happens to arrive last, not to what is currently in
 *      the box — so a slow search for "du" can land after a fast one for
 *      "dune" and replace the right answer with a stale one. AbortController
 *      makes the ordering irrelevant instead of hoping it works out.
 *
 *   3. "CREATE NEW" IS RENDERED IMMEDIATELY AND NEVER WAITS ON THE NETWORK.
 *      It is the escape hatch for movies TMDB does not have — obscure titles,
 *      festival films — and it is useless if it appears a second after the
 *      results. It is also what the screen falls back to when TMDB is
 *      unreachable, which per lib/tmdb.php is a real possibility on this host.
 *
 * Ported in shape from Book Tracker's addflow.js.
 */

import { apiGet, ApiError } from './api.js';

const DEBOUNCE_MS = 350;
const MIN_CHARS = 2;

const input = document.getElementById('q');
const list = document.getElementById('results');
const errorBox = document.getElementById('search-error');
const configEl = document.getElementById('addflow-config');

let config = { to: 'watched' };
try {
  config = JSON.parse(configEl?.textContent || '{}');
} catch (error) {
  /* Fail soft: a broken payload costs the section, not the screen. 'watched'
     is the default the server would have picked anyway. */
  config = { to: 'watched' };
}

/* Per-query result cache. Backspacing through a title re-issues searches you
   already ran a moment ago, and those answers cannot have changed. Keyed on
   the trimmed lowercase query, cleared never — this is a page-lifetime cache
   on a screen you leave as soon as you have found what you want. */
const cache = new Map();

let timer = null;
let inFlight = null;

/**
 * The one message slot under the search box.
 *
 * `tone` matters: "No matches on TMDB" is a neutral fact about your spelling,
 * and rendering it in error red — which .field-err is — would make an ordinary
 * empty search look like the app broke. Only an actual failure gets that
 * treatment.
 */
function setMessage(message, tone = 'error') {
  if (!errorBox) { return; }
  errorBox.textContent = message;
  errorBox.className = message === '' ? 'field-err'
    : (tone === 'error' ? 'field-err' : 'hint');
}

/** Where tapping a result goes: the edit form, prefilled from TMDB. */
function editUrl(params) {
  const qs = new URLSearchParams({ to: config.to, ...params });
  return `edit.php?${qs.toString()}`;
}

/**
 * The "Create new" row — a blank entry form, carrying whatever has been typed
 * so far as the title. Always present, always last.
 */
function buildCreateNew() {
  const item = document.createElement('li');
  const link = document.createElement('a');
  link.className = 'result result-new';
  link.href = editUrl({ new: '1', title: (input?.value || '').trim() });
  link.textContent = 'Create new — enter it by hand';
  item.appendChild(link);
  return item;
}

function buildResult(movie) {
  const item = document.createElement('li');

  const link = document.createElement('a');
  link.className = 'result';
  link.href = editUrl({ tmdb_id: String(movie.tmdb_id) });

  if (movie.thumb) {
    const img = document.createElement('img');
    img.src = movie.thumb;
    img.alt = '';
    img.loading = 'lazy';
    img.decoding = 'async';
    link.appendChild(img);
  } else {
    /* A movie with no poster still gets a slot of the same size, so the rows
       do not jump about as results with and without art come back. */
    const blank = document.createElement('span');
    blank.className = 'result-thumb';
    link.appendChild(blank);
  }

  const text = document.createElement('span');
  text.className = 'result-text';

  const title = document.createElement('span');
  title.className = 'result-title';
  /* textContent, never innerHTML. This string came off the network. */
  title.textContent = movie.title;
  text.appendChild(title);

  if (movie.year) {
    const year = document.createElement('span');
    year.className = 'result-year';
    year.textContent = String(movie.year);
    text.appendChild(year);
  }

  link.appendChild(text);
  item.appendChild(link);
  return item;
}

function render(results) {
  if (!list) { return; }
  list.replaceChildren();
  results.forEach((movie) => list.appendChild(buildResult(movie)));
  list.appendChild(buildCreateNew());
}

async function search(query) {
  const key = query.trim().toLowerCase();

  if (cache.has(key)) {
    render(cache.get(key));
    return;
  }

  /* Abort whatever is still running. See (2) in the header — this is about
     correctness of the rendered list, not about saving bandwidth. */
  if (inFlight) { inFlight.abort(); }
  inFlight = new AbortController();

  try {
    const data = await apiGet('api/search.php', { q: query }, inFlight.signal);
    const results = Array.isArray(data.results) ? data.results : [];
    cache.set(key, results);

    /* An empty list has two very different causes and they need different
       things said. `reached` is the server telling us which — "no such film"
       means try another spelling, "cannot reach TMDB" means this host can't do
       search at all and everything has to be typed. On a first deploy the
       second message is the answer to "why does search do nothing", and
       silence here is what would send somebody hunting through logs. */
    if (results.length === 0) {
      if (data.reached === false) {
        setMessage('Could not reach TMDB. You can still add this movie by hand.', 'error');
      } else {
        setMessage('No matches on TMDB.', 'neutral');
      }
    } else {
      setMessage('');
    }

    render(results);
  } catch (error) {
    /* An abort is this code's own doing, not a failure to report. */
    if (error?.name === 'AbortError') { return; }

    /* Branch on the CODE, never the message. */
    if (error instanceof ApiError && error.code === 'unauthorized') {
      window.location.href = 'login.php';
      return;
    }

    /* Everything else — TMDB down, no API token, the host unable to reach it
       at all — degrades to the manual path rather than an empty screen. The
       message says what to do next, not what went wrong internally. */
    setMessage('Could not search TMDB. You can still add this movie by hand.', 'error');
    render([]);
  } finally {
    inFlight = null;
  }
}

if (input && list) {
  input.addEventListener('input', () => {
    const query = input.value.trim();

    if (timer) { clearTimeout(timer); }

    if (query.length < MIN_CHARS) {
      /* Below the threshold, still show Create new — typing one character and
         seeing an empty box reads as "this is broken". */
      if (inFlight) { inFlight.abort(); }
      setMessage('');
      render([]);
      if (query.length === 0) { list.replaceChildren(); }
      return;
    }

    timer = setTimeout(() => search(query), DEBOUNCE_MS);
  });

  /* Enter should not submit anything — there is no form, and the results are
     the only way forward. Without this, Enter on some mobile keyboards
     navigates or reloads and loses what was typed. */
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') { event.preventDefault(); }
  });
}
