<?php
/* =============================================================================
 *  inc/admin/chat.php — the Messages tab: moderate the chat and the comments.
 * -----------------------------------------------------------------------------
 *  Runs INSIDE admin.php's scope, so $flash / $tab_body are already declared
 *  and CSRF has already been checked centrally.
 *
 *  TWO SUB-TABS, because these are two different conversations an admin
 *  moderates and there is no reason to make them hunt in different places for
 *  the same job:
 *    ?sub=chat      — the shoutbox (the original contents of this tab)
 *    ?sub=comments  — everything written under a game or a poll
 *
 *  Both list newest-first with a delete button each. Newest first because
 *  moderation is almost always about something just posted; the chat panel on
 *  the site itself reads oldest-first, which is the opposite need.
 * ============================================================================= */

/* Which sub-tab. Chat is the default because it is what this tab used to be,
 * so an existing bookmark still lands where it did. */
$sub = ($_GET['sub'] ?? '') === 'comments' ? 'comments' : 'chat';

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

/* ---- COMMENTS ------------------------------------------------------------
 * Everything written under a game or a poll, in one list. The two live in
 * separate tables and are brought together with UNION ALL rather than by
 * reading both and sorting in PHP: an event that has run for a while holds
 * thousands of these, and sorting them in memory to show fifty is exactly the
 * kind of thing that is fine on the developer's laptop and slow on a club's
 * shared host.
 *
 * 'kind' rides along in the result so the delete button knows which table the
 * row came from — the two id sequences overlap, so an id alone is ambiguous.  */
if ($sub === 'comments') {
    $cPerPage = 50;   // fixed, and not the admin_per_page used elsewhere: this
                      // is a moderation feed, scanned rather than paged through.
    $cTotal = (int)db_val('SELECT (SELECT COUNT(*) FROM comments) + (SELECT COUNT(*) FROM poll_comments)');
    $cPages = max(1, (int)ceil($cTotal / $cPerPage));
    $cPage  = max(1, min($cPages, (int)($_GET['page'] ?? 1)));

    /* One query, ordered and limited by the database. The joins reach the event
     * and the parent's name so the list can say WHERE a comment was written —
     * "somebody said this somewhere" is not moderatable. */
    $comments = db_all(
        "SELECT * FROM (
             SELECT c.id, c.name, c.comment, c.created_at,
                    'game' AS kind, g.id AS parent_id, g.name AS parent_name,
                    e.id AS event_id, e.name AS event_name
               FROM comments c
               JOIN games  g ON g.id = c.game_id
               LEFT JOIN events e ON e.id = g.event_id
             UNION ALL
             SELECT pc.id, pc.name, pc.comment, pc.created_at,
                    'poll' AS kind, p.id AS parent_id, p.proposer_name AS parent_name,
                    e2.id AS event_id, e2.name AS event_name
               FROM poll_comments pc
               JOIN polls p ON p.id = pc.poll_id
               LEFT JOIN events e2 ON e2.id = p.event_id
         )
         ORDER BY created_at DESC, id DESC
         LIMIT ? OFFSET ?",
        [$cPerPage, ($cPage - 1) * $cPerPage]);

    $tab_body = tpl_capture('admin_comments', [
        'csrf'     => csrf_field(),
        'comments' => $comments,
        'total'    => $cTotal,
        'page'     => $cPage,
        'pages'    => $cPages,
        'sub'      => $sub,
    ]);
    return;   // the chat list below is not needed on this sub-tab
}

$tab_body = tpl_capture('admin_chat', [
    'csrf'     => csrf_field(),
    'messages' => $messages,
    'total'    => $total,
    'page'     => $page,
    'pages'    => $pages,
    'sub'      => $sub,
]);
