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
/* Two different POSTs land here, and they must not be confused: 'run' performs
 * the actual update, 'check' only asks GitHub what the newest commit is. */
$checked = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    $results = update_run($APP_ROOT);     // $APP_ROOT set by admin.php; returns result lines
    log_action('system_update', 'Admin ran system update');
    options_load();                       // pick up the last_update_at just written
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['check'])) {
    $checked = true;
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

/* ONLY the button reaches the network. Every other render reads what a previous
 * check left behind, so opening this tab never waits on GitHub — on a host with
 * no outbound route that wait was up to ten seconds, twice five, for a page an
 * admin opens to press a different button entirely.
 *
 * On failure a short reason is shown rather than swallowed: a missing line is
 * indistinguishable between "no outbound network", "GitHub is rate-limiting us"
 * and "the code is wrong", which is exactly the guessing game it caused once. */
$remoteWhy = '';
$remote = update_remote_commit($remoteWhy, $checked);
$checkedAt = update_remote_checked_at();

$tab_body = tpl_capture('admin_update', [
    'csrf'      => csrf_field(),
    'results'   => $results,              // null until the updater has run
    'last_run'  => update_when($lastRaw), // '' when it has never been run
    'remote'    => $remote === null ? '' : update_when($remote['date']),
    'remote_sha'=> $remote['sha'] ?? '',
    'remote_why'=> $remote === null ? $remoteWhy : '',
    // '' until someone has pressed the button at least once.
    'checked_at'=> $checkedAt > 0 ? update_when(gmdate('Y-m-d H:i:s', $checkedAt)) : '',
]);
