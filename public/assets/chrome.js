/* Page chrome: wires the hamburger on every screen.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE EXISTS BECAUSE THE MENU SHIPPED DEAD.
 * ---------------------------------------------------------------------------
 *
 * In Shirewatch, lib/layout.php's page_menu() rendered the button and
 * assets/menu.js exported attachMenu(), and nothing ever called it. Every
 * screen had a hamburger that did nothing, and the tests did not catch it
 * because they asserted menu_items() returned items — not that anything
 * consumed them. This app is built with the wiring already in place.
 *
 * The fix is structural rather than a line added to each screen's script:
 * page_foot() loads this file itself, so a new screen cannot forget it, and
 * there is no per-screen wiring to leave out.
 *
 * THE ITEMS COME FROM PHP, as JSON in the page. menu_items() in lib/layout.php
 * stays the single source of truth — hardcoding them here would be a second
 * list that drifts the first time one changes.
 */

import { attachMenu } from './menu.js';

const source = document.getElementById('menu-items');
const trigger = document.getElementById('app-menu');

if (source && trigger) {
  let items = [];
  try {
    items = JSON.parse(source.textContent || '[]');
  } catch (error) {
    /* Fail soft: a broken payload costs you the menu, not the screen. Every
       destination in it is a real URL reachable another way. */
    items = [];
  }

  if (Array.isArray(items) && items.length > 0) {
    attachMenu(trigger, { items, label: 'Menu' });
  }
}
