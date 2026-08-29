<?php
/* =============================================================================
 *  delete_comment.php — remove one comment from a discussion.
 * -----------------------------------------------------------------------------
 *  ADMINS ONLY, and deliberately so. Everything else on a card can be undone by
 *  the person who did it — a seat is resigned, a game is edited by whoever
 *  brought it. A comment is different: the people who would most want one gone
 *  are the ones it was aimed at, and letting them delete it turns a discussion
 *  into something anyone can quietly rewrite. So this is moderation, not
 *  self-service: the club's admins, nobody else.
 *
 *  Mirrors add_comment.php's shape on purpose — one endpoint for both kinds of
 *  discussion, POST only, no page of its own, always redirects back to the card
 *  it came from. The pair is much easier to keep in step read side by side.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require_admin();                       // moderation is an admin action

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

db_run($kind === 'poll'
        ? 'DELETE FROM poll_comments WHERE id = ?'
        : 'DELETE FROM comments WHERE id = ?', [$commentId]);

/* Logged with the author and the text. A deletion an admin cannot later explain
 * is worse than the comment was — and "who removed what, and when" is exactly
 * what somebody will ask afterwards. Truncated because a comment can be long
 * and the log is meant to be skimmed. */
log_action('comment_delete', $kind . ' #' . (int)$row['parent_id'] . ' — '
         . $row['name'] . ': ' . mb_substr((string)$row['comment'], 0, 80));

$day = db_one('SELECT day_index, event_id FROM event_days WHERE id = ?', [$row['day_id']]);
redirect(front_url((int)($day['day_index'] ?? 1), (int)($day['event_id'] ?? 0))
         . ($kind === 'poll' ? '#poll-' : '#game-') . (int)$row['parent_id']);
