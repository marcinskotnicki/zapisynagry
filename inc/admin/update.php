<?php
/* =============================================================================
 *  inc/admin/update.php — Update tab controller.
 * -----------------------------------------------------------------------------
 *  Thin UI wrapper around the updater library (inc/update.php). On a POST with
 *  the "run" flag it pulls the latest files and reconciles the schema, then
 *  shows the library's result lines. A plain GET just renders the button.
 *
 *  Runs in admin.php's scope: sets $tab_body, uses $APP_ROOT (the app root,
 *  defined by admin.php) and the already-passed csrf_check().
 * ============================================================================= */
require_once __DIR__ . '/../update.php';

$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    $results = update_run($APP_ROOT);     // $APP_ROOT set by admin.php; returns result lines
    log_action('system_update', 'Admin ran system update');
}

$tab_body = tpl_capture('admin_update', [
    'csrf'    => csrf_field(),
    'results' => $results,                // null until the updater has run
]);
