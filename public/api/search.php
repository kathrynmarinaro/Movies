<?php
/* Live TMDB search for the add flow.
 *
 * GET /api/search.php?q=dune  ->  { "results": [ ...normalized... ] }
 *
 * PRIVATE. Search costs a TMDB request, and TMDB rate-limits per key — an
 * ungated search endpoint is somebody else's free API proxy running on this
 * key until it gets suspended.
 *
 * The normalized shape is lib/tmdb.php's and is documented there. This file is
 * a thin wrapper on purpose: it validates one parameter and hands back what
 * tmdb_search() returns.
 *
 * AN EMPTY RESULT LIST IS A 200, NOT AN ERROR. "TMDB has nothing for this" and
 * "TMDB is unreachable" both land here as an empty array — assets/addflow.js
 * renders "Create new" either way, which is the right answer to both. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/tmdb.php';

require_method('GET');
require_admin_api();

$query = trim((string) ($_GET['q'] ?? ''));
if ($query === '') {
    json_out(array('results' => array(), 'reached' => true));
}

/* Bounded before it reaches the network. A megabyte of query string would be
 * forwarded to TMDB verbatim otherwise, and no real title is this long. */
$query = mb_substr($query, 0, 200, 'UTF-8');

$results = tmdb_search($query);

/* `reached` is the difference between "TMDB has nothing called that" and "this
 * host cannot reach TMDB" — both of which are an empty list, and which need
 * completely different things said to the person typing. The add flow shows a
 * different message for each; on a first deploy the second one is the answer
 * to "why does search do nothing", and it is the thing tools/hosting-check.php
 * exists to confirm. */
json_out(array('results' => $results, 'reached' => tmdb_reached()));
