<?php
/* =============================================================================
 *  edit_comment.php — change the text of one comment.
 * -----------------------------------------------------------------------------
 *  Mirrors delete_comment.php exactly: same permission rule, same two kinds,
 *  same return handling. The pair is far easier to keep honest read side by
 *  side than two endpoints that drifted apart.
 *
 *  WHO: comment_can_manage() — an admin on anything, a member on what they
 *  wrote while logged in. A guest comment has no owner recorded, so nobody but
 *  an admin can touch it; see that function for why matching on the name would
 *  be worse than useless.
 *
 *  ONLY THE TEXT CHANGES. Not the author, not the timestamp, not which game it
 *  belongs to — an edit is a correction, and letting one move a comment to a
 *  different game or re-sign it under another name would be something else
 *  entirely.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();

$commentId = (int)($_POST['comment'] ?? 0);
$kind      = ($_POST['kind'] ?? '') === 'poll' ? 'poll' : 'game';
$text      = trim((string)($_POST['comment_text'] ?? ''));
if ($commentId <= 0) redirect('index.php');

/* The parent is joined in for the redirect at the end, exactly as the delete
 * endpoint does — a comment on its own does not know which page shows it. */
if ($kind === 'poll') {
    $row = db_one('SELECT c.*, p.day_id, p.id AS parent_id
                     FROM poll_comments c
                     JOIN polls p ON p.id = c.poll_id
                    WHERE c.id = ?', [$commentId]);
} else {
    $row = db_one('SELECT c.*, g.day_id, g.id AS parent_id
                     FROM comments c
                     JOIN games g ON g.id = c.game_id
                    WHERE c.id = ?', [$commentId]);
}
if (!$row) redirect('index.php');

// The row must be loaded before this can be asked — the rule depends on who
// wrote it, which only the row knows.
if (!comment_can_manage($row)) redirect('index.php');

/* Same content rules as writing one in the first place. An edit that emptied a
 * comment would be a deletion with no log line and no confirmation, so a blank
 * one is refused rather than treated as "remove it". */
if ($text === '' || !text_has_content($text) || text_too_long($text, TEXT_BODY_MAX)) {
    flash_set(t('error_comment_required'), 'error');
} else {
    db_run($kind === 'poll'
            ? 'UPDATE poll_comments SET comment = ? WHERE id = ?'
            : 'UPDATE comments SET comment = ? WHERE id = ?',
           [$text, $commentId]);
    /* Logged with the author, because an admin editing somebody else's words is
     * a thing people will want explained afterwards. The old text is not kept:
     * this is a sign-up board, not a wiki, and storing every previous version of
     * every comment is a bigger promise than the feature is worth. */
    log_action('comment_edit', $kind . ' #' . (int)$row['parent_id'] . ' — ' . $row['name']);
}

// Back where the edit was asked for; only a known internal destination is
// honoured, so this cannot be turned into an open redirect.
if (($_POST['back'] ?? '') === 'admin.php?tab=chat&sub=comments') {
    redirect('admin.php?tab=chat&sub=comments');
}

$day = db_one('SELECT day_index, event_id FROM event_days WHERE id = ?', [$row['day_id']]);
redirect(front_url((int)($day['day_index'] ?? 1), (int)($day['event_id'] ?? 0))
         . ($kind === 'poll' ? '#poll-' : '#game-') . (int)$row['parent_id']);
