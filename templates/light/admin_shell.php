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
    'options'    => 'tab_options',
    'new_event'  => 'tab_new_event',
    'thumbnails' => 'tab_thumbnails',
    'users'      => 'tab_users',
    'logs'       => 'tab_logs',
    'archive'    => 'tab_archive',
    'update'     => 'tab_update',
];
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
