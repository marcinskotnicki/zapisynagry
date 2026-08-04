<?php
/* =============================================================================
 *  inc/admin/archive.php — Archive tab controller.
 * -----------------------------------------------------------------------------
 *  Lists every event with a shareable link. Archived events are viewable by
 *  anyone holding the link (index.php?e=<access_token>) — index.php renders that
 *  read-only view. This tab is just the directory of those links.
 *
 *  Using the per-event access_token (not the numeric id) means the link is
 *  unguessable and can be shared publicly without exposing the id space.
 *
 *  Runs in admin.php's scope: sets $tab_body.
 * ============================================================================= */

require_once __DIR__ . '/../events.php';   // events_page(), events_count()

// Archive / un-archive a single event. Only offered when public archives are
// on: with the feature off, archiving is automatic on new-event creation and a
// manual button here would fight that behaviour rather than complement it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && public_archives_enabled()) {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['event'] ?? 0);
    if ($id > 0 && ($act === 'archive' || $act === 'unarchive')) {
        if ($act === 'archive') {
            db_run('UPDATE events SET is_archived = 1, archived_at = ? WHERE id = ?',
                   [gmdate('Y-m-d H:i:s'), $id]);
        } else {
            // archived_at cleared too, so "archived on" never shows a date for
            // an event that is currently open.
            db_run('UPDATE events SET is_archived = 0, archived_at = NULL WHERE id = ?', [$id]);
        }
        log_action('event_' . $act, 'event #' . $id);
        $flash = t('saved_ok');
    }
}

// Paginated: an established club accumulates events indefinitely, and this tab
// was fetching every one of them on every view.
$perPage = max(1, min(500, opt_int('admin_per_page')));
$total   = events_count();
$pages   = max(1, (int)ceil($total / $perPage));
$page    = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$events  = events_page($perPage, ($page - 1) * $perPage);

// Build an absolute base URL so the links are copy-pasteable as-is. We derive
// scheme/host/dir from the current request rather than hard-coding a domain.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');   // the folder admin.php sits in
$base   = $scheme . '://' . $host . $dir . '/index.php?e=';      // template appends the token

$tab_body = tpl_capture('admin_archive', [
    'events'      => $events,
    'base'        => $base,
    'csrf'        => csrf_field(),
    'can_toggle'  => public_archives_enabled(),
    'page'        => $page,
    'pages'       => $pages,
]);
