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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['event'] ?? 0);

    // ARCHIVE / UNARCHIVE are the only actions tied to the public-archives
    // option: with it off, archiving happens automatically when a new event is
    // created and a manual button here would fight that. Everything else —
    // renaming, managing days, deleting — is ordinary event administration and
    // has to work on every install.
    if ($id > 0 && ($act === 'archive' || $act === 'unarchive') && public_archives_enabled()) {
        if ($act === 'archive') {
            db_run('UPDATE events SET is_archived = 1, archived_at = ? WHERE id = ?',
                   [gmdate('Y-m-d H:i:s'), $id]);
        } else {
            // archived_at cleared too, so "archived on" never shows a date for
            // an event that is currently open. Un-archiving a DELETED event is
            // refused: it would be live on the front end again while still
            // flagged deleted. Restore it first.
            db_run('UPDATE events SET is_archived = 0, archived_at = NULL
                     WHERE id = ? AND is_deleted = 0', [$id]);
        }
        log_action('event_' . $act, 'event #' . $id);
        $flash = t('saved_ok');

    } elseif ($id > 0 && $act === 'delete') {
        // Hidden, not destroyed. is_archived is set at the same time so that
        // every controller's existing "refuse to edit an archived event" guard
        // covers a deleted one too, without fourteen permission checks having
        // to learn about the new flag.
        db_run('UPDATE events SET is_deleted = 1, deleted_at = ?, is_archived = 1,
                       archived_at = COALESCE(archived_at, ?)
                 WHERE id = ?', [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id]);
        log_action('event_delete', 'event #' . $id);
        $flash = t('archive_deleted_ok');

    } elseif ($id > 0 && $act === 'restore') {
        // Comes back ARCHIVED rather than live: an admin restoring a mistake
        // should decide separately whether it reopens, not have it reappear on
        // the front page unannounced.
        db_run('UPDATE events SET is_deleted = 0, deleted_at = NULL WHERE id = ?', [$id]);
        log_action('event_restore', 'event #' . $id);
        $flash = t('archive_restored_ok');

    } elseif ($id > 0 && $act === 'rename') {
        // Renaming any event by id. The old version updated
        // `WHERE is_archived = 0`, which renamed EVERY live event at once —
        // harmless while only one could be live, wrong since public archives
        // made several possible.
        $newName = trim((string)($_POST['name'] ?? ''));
        if (text_has_content($newName) && !text_too_long($newName, TEXT_NAME_MAX)) {
            db_run('UPDATE events SET name = ? WHERE id = ?', [$newName, $id]);
            log_action('event_rename', 'event #' . $id . ' -> ' . $newName);
            $flash = t('saved_ok');
        }

    } elseif ($id > 0 && $act === 'day_add') {
        if (event_day_add($id, $_POST['day_date'] ?? '', $_POST['day_start'] ?? '', $_POST['day_end'] ?? '')) {
            log_action('event_day_add', 'event #' . $id . ' ' . trim((string)($_POST['day_date'] ?? '')));
            $flash = t('days_added');
        } else {
            $flash = t('days_add_failed');
        }

    } elseif ($id > 0 && $act === 'day_update') {
        $dayId = (int)($_POST['day'] ?? 0);
        // Checked against THIS event, so a posted id cannot edit another's day.
        $own = $dayId > 0
            ? db_one('SELECT id FROM event_days WHERE id = ? AND event_id = ?', [$dayId, $id])
            : null;
        $newDate  = trim((string)($_POST['day_date'] ?? ''));
        $newStart = trim((string)($_POST['day_start'] ?? ''));
        $newEnd   = trim((string)($_POST['day_end'] ?? ''));
        $newName  = trim((string)($_POST['day_name'] ?? ''));
        // A second day on the same date would give the event two identical tabs.
        $clash = $own ? (int)db_val(
            'SELECT COUNT(*) FROM event_days WHERE event_id = ? AND day_date = ? AND id <> ?',
            [$id, $newDate, $dayId]) : 0;

        if ($own && is_valid_date($newDate) && is_valid_time($newStart)
            && is_valid_time($newEnd) && $clash === 0) {
            // Names are only writable while the feature is on, so an admin who
            // switched it off cannot blank existing labels by saving a day.
            if (day_names_enabled()) {
                db_run('UPDATE event_days SET day_date = ?, start_time = ?, end_time = ?, day_name = ?
                         WHERE id = ?',
                       [$newDate, $newStart, $newEnd, $newName === '' ? null : $newName, $dayId]);
            } else {
                db_run('UPDATE event_days SET day_date = ?, start_time = ?, end_time = ? WHERE id = ?',
                       [$newDate, $newStart, $newEnd, $dayId]);
            }
            // The date may have moved the day past its neighbours.
            event_days_renumber($id);
            log_action('event_day_edit', 'event #' . $id . ' day #' . $dayId);
            $flash = t('days_updated');
        } else {
            $flash = t('days_update_failed');
        }

    } elseif ($id > 0 && $act === 'day_delete') {
        $dayId = (int)($_POST['day'] ?? 0);
        // Checked against THIS event, so a posted id cannot reach another
        // event's day.
        $own = $dayId > 0
            ? db_one('SELECT id FROM event_days WHERE id = ? AND event_id = ?', [$dayId, $id])
            : null;
        if ($own && event_day_delete($dayId)) {
            log_action('event_day_delete', 'event #' . $id . ' day #' . $dayId);
            $flash = t('days_deleted');
        } else {
            $flash = t('days_delete_failed');
        }

    } elseif ($id > 0 && $act === 'purge') {
        // The irreversible one. Only offered for an already-deleted event, so
        // it always takes two deliberate steps. The row's foreign keys cascade
        // days, tables, games, players, comments and polls with it.
        $row = db_one('SELECT name, is_deleted FROM events WHERE id = ?', [$id]);
        if ($row && (int)$row['is_deleted'] === 1) {
            db_run('DELETE FROM events WHERE id = ?', [$id]);
            log_action('event_purge', 'event #' . $id . ' (' . $row['name'] . ')');
            $flash = t('archive_purged_ok');
        }
    }
}

// Paginated: an established club accumulates events indefinitely, and this tab
// was fetching every one of them on every view.
$perPage = max(1, min(500, opt_int('admin_per_page')));
// The only listing that includes deleted events — this tab is where they are
// restored or purged from, so hiding them here would strand them.
$total   = events_count(false, true);
$pages   = max(1, (int)ceil($total / $perPage));
$page    = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$events  = events_page($perPage, ($page - 1) * $perPage, false, true);

// Build an absolute base URL so the links are copy-pasteable as-is. We derive
// scheme/host/dir from the current request rather than hard-coding a domain.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');   // the folder admin.php sits in
$base   = $scheme . '://' . $host . $dir . '/index.php?e=';      // template appends the token

// ?event=<id> switches this tab to a single-event screen: rename, and manage
// its days. Kept in the same tab so everything about an event is in one place.
$editId = (int)($_GET['event'] ?? 0);
if ($editId > 0) {
    $editEvent = db_one('SELECT * FROM events WHERE id = ?', [$editId]);
    if ($editEvent) {
        $editDays = event_days($editId);
        $dayInfo  = [];
        foreach ($editDays as $ed) $dayInfo[(int)$ed['id']] = event_day_contents((int)$ed['id']);
        $tab_body = tpl_capture('admin_event_edit', [
            'csrf'       => csrf_field(),
            // ?day=<id> turns that one row into an edit form in place.
            'edit_day'   => (int)($_GET['day'] ?? 0),
            'event'      => $editEvent,
            'days'       => $editDays,
            'day_info'   => $dayInfo,
            'can_manage' => public_archives_enabled() || (int)$editEvent['is_archived'] === 0,
        ]);
        return;   // this tab renders the edit screen instead of the list
    }
}

$tab_body = tpl_capture('admin_archive', [
    'events'      => $events,
    'base'        => $base,
    'csrf'        => csrf_field(),
    'can_toggle'  => public_archives_enabled(),
    'page'        => $page,
    'pages'       => $pages,
]);
