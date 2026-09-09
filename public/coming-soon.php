<?php
/* Coming Soon is now a SECTION of watchlist.php, not a screen of its own.
 *
 * This file stays behind as a redirect rather than being deleted. The two
 * lists answer one question and were merged, but the old URL may sit in a
 * bookmark or a browser's autocomplete, and a 404 there is a worse answer than
 * the screen the person actually wanted.
 *
 * 301, not 302: the move is permanent, so a browser that remembers it stops
 * asking. Nothing links here any more.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

noindex();
header('Location: watchlist.php', true, 301);
exit;
