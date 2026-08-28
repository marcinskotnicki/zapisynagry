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

/* mailing_enabled() is needed below, to decide whether the Mailing tab exists
 * at all — but inc/mailing.php is not bootstrapped globally (see its own
 * header: the rest of the app plain-`require`s it, and a require_once
 * upstream would make that later plain require fatal on redeclaration).
 * require_once here is safe because admin.php is never loaded in the same
 * request as a page that plain-requires it — each is its own top-level
 * script — and inc/admin/mailing.php's own require_once of the same file,
 * later in this same request, is then simply a no-op. */
require_once __DIR__ . '/inc/mailing.php';

$APP_ROOT = __DIR__;   // controllers (in inc/admin/) use this for file paths (uploads, updater)

// Whitelist of tabs => controller file. The whitelist also blocks path tricks.
// Order is the order they appear, and the FIRST is the default landing tab —
// events, because that is what an admin opens the panel to look at. Update is
// deliberately last: it is the one that changes code rather than content.
// 'new_event' stays here — it is a real screen with a real URL — but it is not
// in the nav (see admin_shell.php): it is reached by the button at the top of
// the events list, because creating an event is an action taken from that list
// rather than somewhere to browse to.
$TABS = ['archive', 'new_event', 'thumbnails', 'options', 'logs',
         'texts', 'users'];
// Same reasoning as chat and club_shelf below: removed from the whitelist
// rather than merely hidden, so ?tab=mailing on a site with the mailing list
// switched off falls through to the default instead of rendering a compose
// screen for a feature that is not running. (It used to stay in the whitelist
// and rely on the screen itself saying "not enabled" when clicked — the same
// pattern chat and club_shelf were deliberately moved away from.)
if (mailing_enabled()) $TABS[] = 'mailing';
// The chat tab exists only while the feature does. Removed from the whitelist
// rather than merely hidden in the nav, so ?tab=chat on a site with the chat
// switched off falls through to the default rather than rendering a moderation
// screen for a feature that is not running.
if (chat_enabled()) $TABS[] = 'chat';
// Same reasoning for the club's own shelf: removed from the whitelist rather
// than merely hidden, so ?tab=club_shelf on a site with it switched off falls
// through instead of rendering a manager for a feature that is not running.
if (club_shelf_enabled()) $TABS[] = 'club_shelf';
/* Help sits next to Update at the end: both are about the system itself rather
 * than about the club's events, and an admin looking for either is not in the
 * middle of a task. It is unconditional — a guide to switching features on has
 * to be reachable when they are all still off. */
$TABS[] = 'help';
$TABS[] = 'update';   // appended after the optional tabs so it stays last

$tab = $_GET['tab'] ?? $TABS[0];
if (!in_array($tab, $TABS, true)) $tab = $TABS[0];   // unknown tab -> the default one

$flash    = null;   // success/info banner (a tab controller may set this)
// 'ok' (default) or 'warn'. Some outcomes SUCCEED but need a caveat — the
// action worked, and something else is about to undo it — and a green
// "saved" banner is the wrong way to say that.
$flashKind = 'ok';
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
    'flash_kind' => $flashKind,
    /* Checked at most once a day and cached: it is an outbound HTTP request, so
     * it must not run on every admin page load. Cached as a timestamp + state
     * rather than a bare flag, so a fixed install stops warning by itself on
     * the next check rather than needing anything cleared. */
    'db_exposed' => db_exposure_is_exposed(),
    // A tab controller sets $needs_editor when its screen contains a rich-text
    // field, so the editor's assets load only where they are used.
    'needs_editor' => !empty($needs_editor),
]);
tpl_render('footer');
