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
    options_load();                       // pick up the last_update_at just written
}

/**
 * Render a stored UTC timestamp on the venue clock.
 *
 * Stored UTC, shown local: options.php has already applied the venue timezone
 * via date_default_timezone_set(), so date() does the conversion. Returns ''
 * for anything unparseable rather than a misleading "1970-01-01".
 *
 * @param string $utc  'Y-m-d H:i:s' in UTC, or ''.
 * @return string
 */
function update_when($utc) {
    $utc = trim((string)$utc);
    if ($utc === '') return '';
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) return '';
    return t('update_when', date('Y-m-d', $ts), date('H:i', $ts));
}

/* Never run since this feature shipped? Fall back to the newest audit entry,
 * so an install that HAS been updated does not claim it never was. The option
 * takes over from the next run onwards, and outlives log pruning. */
$lastRaw = (string)opt('last_update_at');
if ($lastRaw === '') {
    $row = db_one("SELECT created_at FROM logs WHERE action = 'system_update' ORDER BY id DESC LIMIT 1");
    $lastRaw = (string)($row['created_at'] ?? '');
}

/* Best-effort and cached for an hour. On failure it reports a short reason,
 * shown beside the line rather than swallowed: a missing line is
 * indistinguishable between "this host has no outbound network", "GitHub is
 * rate-limiting us" and "the code is wrong", and telling them apart from a
 * live site is otherwise guesswork. */
$remoteWhy = '';
$remote = update_remote_commit($remoteWhy);

$tab_body = tpl_capture('admin_update', [
    'csrf'      => csrf_field(),
    'results'   => $results,              // null until the updater has run
    'last_run'  => update_when($lastRaw), // '' when it has never been run
    'remote'    => $remote === null ? '' : update_when($remote['date']),
    'remote_sha'=> $remote['sha'] ?? '',
    'remote_why'=> $remote === null ? $remoteWhy : '',
]);
