<?php
/* =============================================================================
 *  inc/admin/chat.php — moderate the shoutbox.
 * -----------------------------------------------------------------------------
 *  Runs INSIDE admin.php's scope, so $flash / $tab_body are already declared
 *  and CSRF has already been checked centrally.
 *
 *  Lists messages newest-first with a delete button each. Newest first because
 *  moderation is almost always about something just posted; the chat panel
 *  itself reads oldest-first, which is the opposite need.
 * ============================================================================= */

// Deleting one line. The chat can be switched off between a page load and this
// POST, so the action is guarded on its own rather than trusting the tab to be
// unreachable.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && chat_enabled()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = db_one('SELECT name FROM chat_messages WHERE id = ?', [$id]);
            if ($row) {
                db_run('DELETE FROM chat_messages WHERE id = ?', [$id]);
                log_action('chat_delete', 'message #' . $id . ' by ' . $row['name']);
                $flash = t('chat_admin_deleted');
            }
        }
    } elseif ($act === 'purge') {
        // Clearing the whole log. Separate action, and the button is a
        // btn-danger with its own confirm, because it is not undoable.
        $n = (int)db_val('SELECT COUNT(*) FROM chat_messages');
        chat_wipe();
        log_action('chat_purge', $n . ' messages');
        $flash = t('chat_admin_purged');
    }
}

// Paginated on the same setting as the other admin lists — a busy chat holds
// up to chat_max_messages rows, which is far too many for one page.
$perPage = max(1, min(500, opt_int('admin_per_page')));
$total   = (int)db_val('SELECT COUNT(*) FROM chat_messages');
$pages   = max(1, (int)ceil($total / $perPage));
$page    = max(1, min($pages, (int)($_GET['page'] ?? 1)));

$messages = db_all(
    'SELECT * FROM chat_messages ORDER BY id DESC LIMIT ? OFFSET ?',
    [$perPage, ($page - 1) * $perPage]);

$tab_body = tpl_capture('admin_chat', [
    'csrf'     => csrf_field(),
    'messages' => $messages,
    'total'    => $total,
    'page'     => $page,
    'pages'    => $pages,
]);
