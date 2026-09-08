<?php
/* =============================================================================
 *  delete_comment.php — remove one comment from a discussion.
 * -----------------------------------------------------------------------------
 *  WHO: comment_can_manage() — an admin on any comment, and a member on one they
 *  wrote while logged in. That second half is why this is no longer admin-only:
 *  fixing your own remark should not need finding an admin.
 *
 *  It stops there, though. A GUEST comment records no owner, only the typed
 *  name, and a name proves nothing — anyone could sign one "Marek". So guests
 *  can delete nothing, including comments they really did write. The people who
 *  would most want a comment gone are often the ones it was aimed at, and
 *  matching on a name would hand it to them.
 *
 *  Mirrors add_comment.php's shape on purpose — one endpoint for both kinds of
 *  discussion, POST only, no page of its own, always redirects back to the card
 *  it came from. The pair is much easier to keep in step read side by side.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
// No require_admin() here: the rule depends on WHICH comment, so it can only be
// asked once the row is loaded — see comment_can_manage() below.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();

/* Which discussion. A comment belongs either to a game or to a poll, and the
 * two live in separate tables — same split add_comment.php makes. */
$commentId = (int)($_POST['comment'] ?? 0);
$kind      = ($_POST['kind'] ?? '') === 'poll' ? 'poll' : 'game';
if ($commentId <= 0) redirect('index.php');

if ($kind === 'poll') {
    $row = db_one('SELECT c.*, p.day_id, p.event_id, p.id AS parent_id
                     FROM poll_comments c
                     JOIN polls p ON p.id = c.poll_id
                    WHERE c.id = ?', [$commentId]);
} else {
    $row = db_one('SELECT c.*, g.day_id, g.event_id, g.id AS parent_id
                     FROM comments c
                     JOIN games g ON g.id = c.game_id
                    WHERE c.id = ?', [$commentId]);
}
// Already gone, or an id that never existed: nothing to say, nothing to do.
if (!$row) redirect('index.php');

// Now the row is known, so the question can be asked.
if (!comment_can_manage($row)) redirect('index.php');

db_run($kind === 'poll'
        ? 'DELETE FROM poll_comments WHERE id = ?'
        : 'DELETE FROM comments WHERE id = ?', [$commentId]);

/* Logged with the author and the text. A deletion an admin cannot later explain
 * is worse than the comment was — and "who removed what, and when" is exactly
 * what somebody will ask afterwards. Truncated because a comment can be long
 * and the log is meant to be skimmed. */
log_action('comment_delete', $kind . ' #' . (int)$row['parent_id'] . ' — '
         . $row['name'] . ': ' . mb_substr((string)$row['comment'], 0, 80));

/* Back where the deletion was asked for. From a game card that is the event
 * page; from the admin list it is that list, and sending an admin who is
 * working through a page of comments out to the front page after each one would
 * make the job unusable.
 *
 * The value is not trusted: only a known internal destination is honoured, so a
 * crafted form cannot turn this endpoint into an open redirect. */
if (($_POST['back'] ?? '') === 'admin.php?tab=chat&sub=comments') {
    redirect('admin.php?tab=chat&sub=comments');
}

$day = db_one('SELECT day_index, event_id FROM event_days WHERE id = ?', [$row['day_id']]);
redirect(front_url((int)($day['day_index'] ?? 1), (int)($day['event_id'] ?? 0))
         . ($kind === 'poll' ? '#poll-' : '#game-') . (int)$row['parent_id']);
