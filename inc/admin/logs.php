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

// Requested scope: an event id, or 0 for the SYSTEM log (site-wide entries,
// stored with event_id NULL — settings, accounts, updates, uploads).
$viewId = (int)($_GET['event_id'] ?? ($current['id'] ?? 0));
// A hand-edited ?event_id= pointing at nothing falls back to the system log
// rather than to an arbitrary event: 0 is now a real, always-valid scope.
$valid = ($viewId === 0);
foreach ($events as $ev) { if ((int)$ev['id'] === $viewId) { $valid = true; break; } }
if (!$valid) $viewId = 0;

// Most recent 500 entries for the chosen event (newest first). The cap keeps a
// long-running event's log page bounded.
// account_name shows which ACCOUNT (if any) performed the action — the
// difference between "typed the name Czarek" and "was logged in as Czarek".
$sql = 'SELECT l.created_at, l.action, l.detail, l.actor_name, l.ip,
               u.display_name AS account_name
        FROM logs l LEFT JOIN users u ON u.id = l.actor_user_id
        WHERE ' . ($viewId ? 'l.event_id = ?' : 'l.event_id IS NULL') . '
        ORDER BY l.id DESC LIMIT 500';
$logs = db_all($sql, $viewId ? [$viewId] : []);

$tab_body = tpl_capture('admin_logs', [
    'events'  => $events,
    'view_id' => $viewId,
    'logs'    => $logs,
]);
