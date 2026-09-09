<?php
/* Build the zip you upload to Hostinger.
 *
 *   php tools/build-deploy.php
 *
 * Writes movies-deploy-YYYY-MM-DD.zip in the project root (gitignored).
 *
 * ---------------------------------------------------------------------------
 * THIS EXISTS BECAUSE TWO THINGS GO WRONG WHEN A HUMAN ZIPS THE FOLDER.
 * ---------------------------------------------------------------------------
 *
 *   1. config.php GETS INCLUDED. It carries the database password, the admin
 *      password hash, the TMDB token and the cron token. A zip is a thing that
 *      ends up in a Downloads folder, an email, a support ticket. This script
 *      refuses to add it and ABORTS if it somehow matched — it is not a filter
 *      you can quietly defeat by editing a glob.
 *
 *   2. THE DOTFILES GET DROPPED. Five .htaccess files carry the entire
 *      protection of this deploy: the document-root rewrite, the deny-alls over
 *      lib/, tools/ and docs/, and the no-PHP-execution rule in the one
 *      directory that accepts uploaded bytes. GUI zip tools and file managers
 *      routinely skip dotfiles, and the failure is SILENT — the site comes up,
 *      looks perfect, and serves config.php as plain text to anyone who asks.
 *      So this script asserts all five are in the finished archive and fails
 *      the build if any is missing.
 *
 * It also leaves public/posters/ in place but EMPTY of images: cached posters
 * are fetched data, and a manually uploaded one is the only exception (see
 * .gitignore). Nothing here overwrites what is already on the server, because
 * you upload INTO the folder rather than replacing it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

/* Everything that must reach the server, and nothing else. An allow-list
 * rather than a deny-list: a file added to the repo next year is excluded
 * until somebody deliberately includes it, which is the safe direction for a
 * bundle that carries an app's whole protection story. */
const DEPLOY_INCLUDE = array(
    '.htaccess',
    'config.example.php',
    'schema.sql',
    'README.md',
    'CLAUDE.md',
    'DEPLOY.txt',
    'lib',
    'public',
    'tools',
    'docs',
);

/* Never, under any circumstances. */
const DEPLOY_NEVER = array(
    'config.php',        // every secret this app has
    '.git',
    '.gitignore',
    '.DS_Store',
);

/* The files whose absence is silent and catastrophic. Asserted in the finished
 * archive, not just intended. */
const DEPLOY_REQUIRED = array(
    '.htaccess',
    'lib/.htaccess',
    'tools/.htaccess',
    'docs/.htaccess',
    'public/posters/.htaccess',
    'public/index.php',
    'public/collection.php',
    'lib/bootstrap.php',
    'schema.sql',
    'config.example.php',
);

/** Should this path go in? $rel is relative to the project root. */
function deploy_wanted(string $rel): bool
{
    $base = basename($rel);

    foreach (DEPLOY_NEVER as $never) {
        if ($rel === $never || $base === $never || str_starts_with($rel, $never . '/')) {
            return false;
        }
    }

    // Built bundles, editor litter, logs.
    if (str_ends_with($base, '.zip') || str_ends_with($base, '.log') || str_ends_with($base, '~')) {
        return false;
    }

    /* Cached poster images are FETCHED DATA, not source — they re-fetch on
     * demand, and shipping them would overwrite nothing useful while making
     * the zip large. The directory itself, its .gitkeep and its .htaccess do
     * ship: the directory has to exist and be protected before the first
     * poster is written into it. */
    if (str_starts_with($rel, 'public/posters/')
        && $base !== '.gitkeep' && $base !== '.htaccess'
    ) {
        return false;
    }

    return true;
}

/** Every file under $dir, relative to $root, depth-first. */
function deploy_walk(string $root, string $dir): array
{
    $out  = array();
    $path = $root . '/' . $dir;

    if (is_file($path)) {
        return deploy_wanted($dir) ? array($dir) : array();
    }
    if (!is_dir($path)) {
        return array();
    }

    /* scandir rather than glob: glob() does not match dotfiles without a flag,
     * and dotfiles are precisely what must not be dropped here. */
    foreach (scandir($path) ?: array() as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $rel = $dir . '/' . $entry;
        if (!deploy_wanted($rel)) {
            continue;
        }
        if (is_dir($root . '/' . $rel)) {
            $out = array_merge($out, deploy_walk($root, $rel));
        } else {
            $out[] = $rel;
        }
    }
    return $out;
}

/* ------------------------------------------------------------------- build */

$files = array();
foreach (DEPLOY_INCLUDE as $entry) {
    if (!file_exists($root . '/' . $entry)) {
        fwrite(STDERR, "Missing from the project: {$entry}\n");
        exit(1);
    }
    $files = array_merge($files, deploy_walk($root, $entry));
}
sort($files);

/* THE GUARD. Belt and braces over deploy_wanted(): if config.php ever reaches
 * this list, the build stops rather than producing a zip that looks fine. */
foreach ($files as $rel) {
    if (basename($rel) === 'config.php') {
        fwrite(STDERR, "REFUSING TO BUILD: config.php matched the include list.\n"
            . "That file carries the database password, the admin password hash,\n"
            . "the TMDB token and the cron token. Fix deploy_wanted() before\n"
            . "building again.\n");
        exit(1);
    }
}

$name = 'movies-deploy-' . date('Y-m-d') . '.zip';
$out  = $root . '/' . $name;
@unlink($out);

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create {$out}\n");
    exit(1);
}

foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, $rel);
}
$zip->close();

/* ------------------------------------------------------------------ verify */

/* Re-open and read the archive back. Asserting what we INTENDED to add proves
 * nothing about what is in the file — and the whole point of this script is
 * the five dotfiles, whose absence is silent. */
$check = new ZipArchive();
if ($check->open($out) !== true) {
    fwrite(STDERR, "Built {$name} but could not reopen it to verify.\n");
    exit(1);
}

$inside = array();
for ($i = 0; $i < $check->numFiles; $i++) {
    $inside[] = $check->getNameIndex($i);
}
$check->close();

$missing = array();
foreach (DEPLOY_REQUIRED as $req) {
    if (!in_array($req, $inside, true)) {
        $missing[] = $req;
    }
}

if ($missing !== array()) {
    fwrite(STDERR, "\nBUILD FAILED — these are missing from the archive:\n");
    foreach ($missing as $m) {
        fwrite(STDERR, '  ' . $m . "\n");
    }
    fwrite(STDERR,
        "\nIf the .htaccess files are the ones missing, the site would come up,\n"
        . "look perfect, and serve config.php as plain text. Not shipping this.\n");
    @unlink($out);
    exit(1);
}

if (in_array('config.php', $inside, true)) {
    fwrite(STDERR, "BUILD FAILED: config.php is inside the archive. Deleting it.\n");
    @unlink($out);
    exit(1);
}

/* -------------------------------------------------------------------- done */

printf("%s\n", $name);
printf("  %d files, %s\n", count($inside), number_format(filesize($out) / 1024, 0) . ' KB');
printf("  %d .htaccess files, all present\n",
    count(array_filter($inside, static fn($f) => basename($f) === '.htaccess')));
printf("  config.php: NOT included (correct)\n\n");

echo "Upload the CONTENTS of this zip into the folder your subdomain points at,\n";
echo "so that .htaccess, lib/, public/ and config.example.php sit at the top of\n";
echo "it. Then follow DEPLOY.txt — it is inside the zip.\n\n";
echo "Turn on \"show hidden files\" in the file manager first. There are\n";
echo "5 .htaccess files in here and they are the whole protection of the deploy.\n";
