<?php
/* =============================================================================
 *  help.php — the players' guide, for everyone.
 * -----------------------------------------------------------------------------
 *  The public counterpart of the admin panel's Help tab: same idea, different
 *  document. This one renders help/DLA-GRACZY.md or FOR-PLAYERS.md — the guide
 *  written for somebody signing up for a game, not for somebody configuring the
 *  site.
 *
 *  OPEN TO EVERYONE, deliberately: the people who most need "how do I sign up"
 *  are exactly the ones without an account. It is reachable only when an admin
 *  has switched on the top-bar button, since a page nobody links to is a page
 *  nobody finds — but the check below is on the OPTION, not on who is asking.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/markdown.php';

/* Off means off: with the button hidden this page is not part of the site, so
 * a stale link or a guess lands on the front page rather than on a document the
 * club chose not to offer. */
if (!opt_bool('show_help_front')) redirect('index.php');

/* Which language. Polish is the fallback for the same reason as the admin
 * guide: it is the language of the clubs this is written for. */
$playerDocs = ['pl' => 'DLA-GRACZY.md', 'en' => 'FOR-PLAYERS.md'];
$playerFile = __DIR__ . '/help/' . ($playerDocs[lang_current()] ?? $playerDocs['pl']);
$playerBody = is_file($playerFile) ? (string)file_get_contents($playerFile) : null;

/* Show the club's own address rather than the example one — same helper the
 * admin guide uses, and the players' guide names the site too. */
if ($playerBody !== null) {
    $playerBody = help_localise_urls($playerBody, opt('site_url'));
}

tpl_render('header', ['page_title' => t('nav_help')]);
tpl_render('player_help', [
    'html'    => $playerBody === null ? null : md_render($playerBody),
    'missing' => $playerBody === null ? 'help/' . basename($playerFile) : null,
]);
tpl_render('footer');
