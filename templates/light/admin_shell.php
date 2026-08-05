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
 * ============================================================================= */

// Tab key => language key for the label. The array ORDER defines the nav order.
// (Keep in sync with admin.php's $TABS whitelist.)
$tabs = [
    'archive'    => 'tab_archive',
    'new_event'  => 'tab_new_event',
    'thumbnails' => 'tab_thumbnails',
    'options'    => 'tab_options',
    'logs'       => 'tab_logs',
    'texts'      => 'tab_texts',
    'users'      => 'tab_users',
    'mailing'    => 'tab_mailing',
    'update'     => 'tab_update',
];
// Same condition as admin.php's whitelist: no tab for a feature that is off.
if (chat_enabled()) {
    // Inserted before Update, which stays last.
    $pos  = array_search('update', array_keys($tabs), true);
    $tabs = array_slice($tabs, 0, $pos, true)
          + ['chat' => 'tab_chat']
          + array_slice($tabs, $pos, null, true);
}
?>
<div class="admin">
    <h1><?= e(t('admin_panel')) ?></h1>

    <?php if (!empty($flash)): ?>
        <p class="msg msg-ok"><?= e($flash) ?></p>
    <?php endif; ?>

    <nav class="tabs">
        <?php foreach ($tabs as $key => $labelKey): ?>
            <a class="tab<?= $key === ($active_tab ?? '') ? ' tab-active' : '' ?>"
               href="admin.php?tab=<?= e($key) ?>"><?= e(t($labelKey)) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="tab-body">
        <?= $tab_body ?? '' /* trusted: built by a tab controller via tpl_capture */ ?>
    </section>
</div>

<?php // Trix, only on the Pages tab — no other admin screen needs a rich-text
      // editor, and this is ~200KB of JS. Vendored locally (see vendor/trix)
      // rather than pulled from a CDN: the app is FTP-deployed onto shared
      // hosting and a club's admin panel should not depend on a third party
      // being reachable. ?>
<?php if (($active_tab ?? '') === 'texts'): ?>
    <link rel="stylesheet" href="vendor/trix/trix.css">
    <script src="vendor/trix/trix.min.js"></script>
<?php endif; ?>
