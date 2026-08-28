<?php
/* =============================================================================
 *  templates/light/admin_shell.php — admin panel frame (tab nav + body slot).
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. The chrome around every admin tab: the heading, an
 *  optional flash banner, the tab navigation, and a slot into which the active
 *  tab's already-rendered HTML is dropped. admin.php builds $tab_body by running
 *  the tab controller, then renders this.
 *
 *  RENDER VARS:
 *    $active_tab — current tab key (highlights its nav link).
 *    $tab_body   — pre-rendered HTML for the active tab (a string).
 *    $flash      — optional success/info message, or null.
 *    $flash_kind — 'ok' (green), 'warn' (amber) or 'error' (red): a caveat on an action
 *                  that DID succeed but is about to be undone by something
 *                  else. Green would bury exactly the part worth reading.
 * ============================================================================= */

// Tab key => language key for the label. The array ORDER defines the nav order.
// (Keep in sync with admin.php's $TABS whitelist.)
$tabs = [
    'archive'    => 'tab_archive',
    // 'new_event' is deliberately NOT here. It is still a real tab — the
    // screen, its controller and its URL are unchanged — but with this many
    // tabs the nav had become the crowded part, and creating an event is an
    // action taken FROM the events list rather than a place to browse to. The
    // button at the top of that list links here.
    'thumbnails' => 'tab_thumbnails',
    'options'    => 'tab_options',
    'logs'       => 'tab_logs',
    'texts'      => 'tab_texts',
    'users'      => 'tab_users',
    // Help and Update at the end together: both are about the system itself
    // rather than the club's events. Help is unconditional — a guide to
    // switching features on has to be reachable while they are all still off.
    'help'       => 'tab_help',
    'update'     => 'tab_update',
];
// Same condition as admin.php's whitelist: no tab for a feature that is off.
// mailing used to sit in the array above unconditionally, with the screen
// itself saying "not enabled" when an admin clicked it — the same pattern
// chat and club_shelf are deliberately moved away from below, so it now
// follows their lead instead of being the exception.
if (mailing_enabled()) {
    // Inserted before Update, which stays last.
    $pos  = array_search('update', array_keys($tabs), true);
    $tabs = array_slice($tabs, 0, $pos, true)
          + ['mailing' => 'tab_mailing']
          + array_slice($tabs, $pos, null, true);
}
if (chat_enabled()) {
    // Inserted before Update, which stays last.
    $pos  = array_search('update', array_keys($tabs), true);
    $tabs = array_slice($tabs, 0, $pos, true)
          + ['chat' => 'tab_chat']
          + array_slice($tabs, $pos, null, true);
}
if (club_shelf_enabled()) {
    $pos  = array_search('update', array_keys($tabs), true);
    $tabs = array_slice($tabs, 0, $pos, true)
          + ['club_shelf' => 'tab_club_shelf']
          + array_slice($tabs, $pos, null, true);
}
?>
<div class="admin">
    <h1><?= e(t('admin_panel')) ?></h1>

    <?php /* THE DATABASE IS DOWNLOADABLE. Shown on every admin screen and not
           * dismissable, because it means every password hash and email address
           * on the site can be fetched by anyone who guesses the URL. The
           * .htaccess that normally prevents this is an Apache file; Nginx,
           * Caddy and IIS ignore it without a word, so a wrongly-served install
           * looked identical to a correct one until now. */ ?>
    <?php if (!empty($db_exposed)): ?>
        <p class="msg msg-error admin-dbwarn"><?= e(t('admin_db_exposed')) ?></p>
    <?php endif; ?>

    <?php if (!empty($flash)): ?>
        <?php // 'error' as well as 'warn': anything not explicitly listed used to
              // fold to green, so a refusal read as a success. Same bug the
              // member-facing flashes had. ?>
        <?php $fk = in_array($flash_kind ?? 'ok', ['ok', 'warn', 'error'], true) ? ($flash_kind ?? 'ok') : 'ok'; ?>
        <p class="msg msg-<?= e($fk) ?>"><?= e($flash) ?></p>
    <?php endif; ?>

    <?php // Same wrapper the event and day strips use, so initTabScroll() picks
          // this up too: it widens the strip past the content column when the
          // tabs outgrow it, scrolls when even the window is not enough, and
          // brings the CURRENT tab into view on load. The panel has enough tabs
          // to need all three on a phone. ?>
    <div class="tab-scroll">
    <nav class="tabs">
        <?php foreach ($tabs as $key => $labelKey): ?>
            <a class="tab<?= $key === ($active_tab ?? '') ? ' tab-active' : '' ?>"
               href="admin.php?tab=<?= e($key) ?>"><?= e(t($labelKey)) ?></a>
        <?php endforeach; ?>
    </nav>
    </div>

    <section class="tab-body">
        <?= $tab_body ?? '' /* trusted: built by a tab controller via tpl_capture */ ?>
    </section>
</div>

<?php // Trix, only where a rich-text field is actually on screen — the tab
      // controller sets $needs_editor. That is narrower than "the Pages tab":
      // the bare page LIST has no editor on it, and this is ~200KB of JS.
      // Vendored locally (see vendor/trix) rather than pulled from a CDN: the
      // app is FTP-deployed onto shared hosting and a club's admin panel
      // should not depend on a third party being reachable. ?>
<?php if (!empty($needs_editor)): ?>
    <link rel="stylesheet" href="vendor/trix/trix.css">
    <script src="vendor/trix/trix.min.js"></script>
<?php endif; ?>
