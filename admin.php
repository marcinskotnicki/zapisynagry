<?php
/* =============================================================================
 *  admin.php — admin panel dispatcher.
 * -----------------------------------------------------------------------------
 *  Thin router: validates the requested tab, checks CSRF on POST, then includes
 *  the matching controller from inc/admin/. Each controller runs in THIS scope
 *  (so it can see $APP_ROOT, $flash, etc.) and is expected to set $tab_body
 *  (an HTML string) and optionally $flash, and may redirect. The result is
 *  wrapped in the admin shell template.
 *
 *  WHY A WHITELIST: $tab comes straight off the query string and is used to
 *  build an include path. The in_array() check means only known tab names can
 *  ever be included — a stray "../../etc" can't reach the require below.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require_admin();                       // everything here is admin-only

$APP_ROOT = __DIR__;   // controllers (in inc/admin/) use this for file paths (uploads, updater)

// Whitelist of tabs => controller file. The whitelist also blocks path tricks.
// Order is the order they appear, and the FIRST is the default landing tab —
// events, because that is what an admin opens the panel to look at. Update is
// deliberately last: it is the one that changes code rather than content.
$TABS = ['archive', 'new_event', 'thumbnails', 'options', 'logs',
         'texts', 'users', 'mailing'];
// The chat tab exists only while the feature does. Removed from the whitelist
// rather than merely hidden in the nav, so ?tab=chat on a site with the chat
// switched off falls through to the default rather than rendering a moderation
// screen for a feature that is not running.
if (chat_enabled()) $TABS[] = 'chat';
$TABS[] = 'update';   // appended after the optional tabs so it stays last

$tab = $_GET['tab'] ?? $TABS[0];
if (!in_array($tab, $TABS, true)) $tab = $TABS[0];   // unknown tab -> the default one

$flash    = null;   // success/info banner (a tab controller may set this)
$tab_body = '';     // rendered HTML for the active tab (the controller MUST set this)

// One central CSRF gate for every state-changing request, so each tab
// controller doesn't have to repeat csrf_check().
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

// Hand off to the tab's controller, which fills $tab_body / $flash.
require __DIR__ . '/inc/admin/' . $tab . '.php';

// Frame it in the admin shell (tab nav + flash + body).
tpl_render('header', ['page_title' => t('admin_panel')]);
tpl_render('admin_shell', [
    'active_tab' => $tab,
    'tab_body'   => $tab_body,
    'flash'      => $flash,
    // A tab controller sets $needs_editor when its screen contains a rich-text
    // field, so the editor's assets load only where they are used.
    'needs_editor' => !empty($needs_editor),
]);
tpl_render('footer');
