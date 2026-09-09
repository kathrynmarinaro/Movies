<?php
/* Shared page chrome. Every private screen in this app is:
 *
 *   require_once __DIR__ . '/../lib/bootstrap.php';
 *   require_once __DIR__ . '/../lib/layout.php';
 *   require_admin();
 *   page_head('Watched', 'watched');
 *   screen_head('Watched', page_menu());
 *   ... markup ...
 *   page_foot('watched');
 *
 * Ported from the Personal CRM, which ports it from Grocery.
 *
 * ---------------------------------------------------------------------------
 * THE PUBLIC PAGE DOES NOT USE THESE FUNCTIONS.
 * ---------------------------------------------------------------------------
 *
 * public/collection.php renders its own minimal document. That is deliberate
 * and it is the whole reason this file has no $public flag: Book Tracker's
 * layout takes one, and the result is that every future edit to page_head() has
 * to be reasoned about twice — once for the surface that may link to add.php
 * and once for the surface that must never mention it. Here the tab bar and the
 * menu are unconditional, because the only thing that renders them is a screen
 * that has already been through require_admin().
 *
 * A public page that shares no code with the private chrome cannot leak the
 * private chrome. See docs/CONTRACTS.md §6. */

declare(strict_types=1);

const APP_NAME = 'Movies';

/**
 * The bottom tab bar. Keys are the $tab values page_head()/page_foot() accept.
 *
 * THREE TABS, AND THE WHOLE STRUCTURE IS THIS ONE FUNCTION. Changing it later
 * is deliberately cheap — the tab bar is the only thing that knows how many
 * there are, and nothing else in the app enumerates them.
 *
 * Watched is leftmost because it is the tab opened most and the thumb reaches
 * that corner. Coming Soon is the one with a clock on it. To Watch is the
 * reference list you go to on purpose, when choosing something for tonight.
 *
 * movie.php, add.php and edit.php are NOT here. They are detail and action
 * screens pushed from a tab and mark whichever tab they came from active —
 * adding is a floating button (.fab), not a destination, following the
 * Inspiration Gallery and Book Tracker.
 */
function nav_tabs(): array
{
    return array(
        'watched'     => array('label' => 'Watched',     'href' => 'index.php'),
        'coming-soon' => array('label' => 'Coming Soon', 'href' => 'coming-soon.php'),
        'watchlist'   => array('label' => 'To Watch',    'href' => 'watchlist.php'),
    );
}

/* The shared ES modules, imported by every screen's entry script as './api.js'
 * and friends. Kept here rather than derived from a glob so that adding one is
 * a deliberate act — tools/run-tests.php checks this list against what the
 * feature modules actually import. */
const SHARED_MODULES = array('api.js', 'menu.js', 'tagfield.js');

/**
 * Cache-bust the shared modules, which asset() cannot reach.
 *
 * <script src> goes through asset() and gets ?v=<mtime>, so a changed
 * addflow.js is fetched fresh. But addflow.js then does
 *
 *     import { apiPost } from './api.js';
 *
 * and that specifier is a literal inside a .js file — no PHP runs on it, so
 * there is no version on it, so the browser serves whatever copy it already
 * has. FOREVER. The entry point updates and the modules underneath it do not.
 *
 * THIS SHIPPED IN A SIBLING APP. A trash button was added to every row, the
 * stylesheet updated (asset() covers CSS), the button appeared — wired to a
 * cached module that had never heard of it. A control that is visibly there
 * and does nothing, with nothing in any log, and "it works on my machine" being
 * literally true because the developer's browser had no old copy to keep.
 *
 * An import map remaps the resolved URLs before any module loads. Keys are
 * document-relative, matching what './api.js' resolves to from
 * /assets/<screen>.js, so this keeps working if the app ever moves into a
 * subdirectory.
 *
 * Fails safe twice over: a browser with no import-map support ignores it and
 * behaves exactly as before, and a module that can't be stat'd is left
 * unmapped rather than mapped to an unversioned URL, which would look handled
 * and not be.
 */
function shared_module_map(): void
{
    $map = array();
    foreach (SHARED_MODULES as $module) {
        $path  = 'assets/' . $module;
        $stamp = @filemtime(PUBLIC_DIR . '/' . $path);
        if ($stamp === false) {
            continue;
        }
        $map['./' . $path] = './' . $path . '?v=' . $stamp;
    }

    if ($map === array()) {
        return;
    }

    /* JSON_UNESCAPED_SLASHES so the paths read as paths in view-source. The
     * values are filenames from a hardcoded list and integer mtimes, so there
     * is nothing here that could carry a </script>. */
    echo '<script type="importmap">' . "\n";
    echo json_encode(array('imports' => $map), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    echo '</script>' . "\n";
}

/**
 * Open a page: doctype through the opening of the content container.
 *
 * @param string      $title Screen title, for <title>.
 * @param string|null $tab   Which tab to mark active, or null for none.
 */
function page_head(string $title, ?string $tab = null): void
{
    /* Pinch-zoom stays ON, following Book Tracker rather than the Workout
     * Generator. That app pins maximum-scale=1 because a stray pinch mid-set
     * throws the countdown off-screen and you're holding a dumbbell. This one
     * is a grid of poster art, and pinching in to read the title off a poster
     * is a completely reasonable thing to want.
     *
     * viewport-fit=cover is what makes the --safe-* insets in styles.css do
     * anything; without it the fixed tab bar sits above the home indicator
     * with a white band under it. */
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#41b7ab">
<?php /* No " · Movies" suffix when the screen IS the app name — the doubled
         title helps nobody. */ ?>
<title><?= $title === APP_NAME ? h(APP_NAME) : h($title) . ' · ' . h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
<?php shared_module_map(); ?>
</head>
<body>
<main class="wrap">
<?php
}

/**
 * The hamburger, for screen_head()'s $asideHtml slot.
 *
 *   screen_head('Watched', page_menu());
 *
 * WHY THIS IS IN LAYOUT AND NOT IN EACH SCREEN. Every screen gets the same
 * menu, and the moment it is copied into three files it is three files that
 * have to be edited to add an item — which is exactly how one of them ends up
 * with a menu one item shorter than the others and nobody notices for a month.
 *
 * The menu is where APP-LEVEL actions live: things you do once rather than
 * daily, which is why they are not tabs. Signing out today, and whatever comes
 * later.
 *
 * Renders only the BUTTON. The sheet itself is built on demand by
 * assets/menu.js: an empty fixed-position element sitting in every page is one
 * z-index mistake away from swallowing every tap on the tab bar underneath it,
 * and that failure looks like "the app stopped responding" rather than like a
 * menu bug.
 */
function page_menu(string $id = 'app-menu'): string
{
    return '<button class="icon-btn" type="button" id="' . h($id) . '" aria-label="Menu" aria-haspopup="dialog">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>'
        . '</svg></button>';
}

/** The h1 with its accent rule, plus optional right-hand content. */
function screen_head(string $title, string $asideHtml = ''): void
{
    ?>
  <header class="screen-head">
    <h1><?= h($title) ?></h1>
    <?php if ($asideHtml !== ''): ?>
    <div class="head-actions"><?= $asideHtml ?></div>
    <?php endif; ?>
  </header>
<?php
}

/** Close a page. Pass the same $tab you gave page_head(). */
function page_foot(?string $tab = null): void
{
    echo "</main>\n";
    ?>
<nav class="tabbar" aria-label="Sections">
<?php foreach (nav_tabs() as $key => $t): ?>
  <a href="<?= h($t['href']) ?>"<?= $key === $tab ? ' class="is-active" aria-current="page"' : '' ?>><?= h($t['label']) ?></a>
<?php endforeach; ?>
</nav>
<?php
    echo "</body>\n</html>\n";
}
