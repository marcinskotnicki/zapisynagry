<?php
/* =============================================================================
 *  inc/admin/logs.php — Logs tab controller.
 * -----------------------------------------------------------------------------
 *  Shows the audit trail (the logs table, written by log_action()) for the
 *  current event by default; a selector lets the admin view any past event's
 *  logs too. Read-only — this tab never writes.
 *
 *  Runs in admin.php's scope: sets $tab_body.
 * ============================================================================= */

// Event list for the selector (newest first), and which one we're viewing.
$events  = db_all('SELECT id, name, is_archived, created_at FROM events ORDER BY id DESC');
$current = current_event();

// Requested event id (from the selector) or the current event by default.
$viewId = (int)($_GET['event_id'] ?? ($current['id'] ?? 0));
// Make sure the requested id is a real event; otherwise fall back to current.
// (Guards against a hand-edited ?event_id= pointing at nothing.)
$valid = false;
foreach ($events as $ev) { if ((int)$ev['id'] === $viewId) { $valid = true; break; } }
if (!$valid) $viewId = (int)($current['id'] ?? 0);

// Most recent 500 entries for the chosen event (newest first). The cap keeps a
// long-running event's log page bounded.
$logs = $viewId
    ? db_all('SELECT created_at, action, detail, actor_name, ip
              FROM logs WHERE event_id = ? ORDER BY id DESC LIMIT 500', [$viewId])
    : [];

$tab_body = tpl_capture('admin_logs', [
    'events'  => $events,
    'view_id' => $viewId,
    'logs'    => $logs,
]);
