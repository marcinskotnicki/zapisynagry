<?php
/* =============================================================================
 *  add_comment.php — post a comment to a game's discussion.
 * -----------------------------------------------------------------------------
 *  Available when discussions are enabled. Anyone may comment; the name is
 *  prefilled for logged-in users. POST-only (no form page of its own — the form
 *  lives in the game card); always redirects back to the game.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/captcha.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/notify.php';   // tell the others a comment arrived

// One endpoint serves both discussions: a comment belongs either to a game or
// to a poll. $target is whichever row we found; $kind says which.
$gameId = (int)($_POST['game'] ?? 0);
$pollId = (int)($_POST['poll'] ?? 0);
if ($pollId) {
    $kind   = 'poll';
    $target = db_one('SELECT * FROM polls WHERE id = ?', [$pollId]);
} else {
    $kind   = 'game';
    $target = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
}
if (!$target) { redirect('index.php'); }
$game = $target;   // legacy name used further down for the log line

$event = db_one('SELECT is_archived FROM events WHERE id = ?', [$target['event_id']]);
$day   = db_one('SELECT day_index, event_id FROM event_days WHERE id = ?', [$target['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Only when discussions are on and the event is live.
if (!opt_bool('allow_discussions') || !$event || (int)$event['is_archived'] === 1) {
    redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    antibot_check('form');
    if (!captcha_verify('comment')) { flash_set(t('error_captcha'), 'error'); redirect('index.php'); }
    $u       = current_user();
    // Use the typed name, or fall back to the logged-in display name.
    $name    = trim($_POST['name'] ?? '') ?: ($u['display_name'] ?? '');
    $comment = trim($_POST['comment'] ?? '');

    // Silently ignore empty submissions (no error page for a blank comment).
    // Junk (nothing but punctuation) and oversized text are dropped the same
    // way: this endpoint redirects rather than rendering, so there's nowhere to
    // show an error, and a silently-skipped junk comment is the right outcome.
    if ($name !== '' && $comment !== ''
        && text_has_content($name) && text_has_content($comment)
        && !text_too_long($name, TEXT_PERSON_MAX)
        && !text_too_long($comment, TEXT_BODY_MAX)) {
        /* The author's own address, so the notification below can skip them.
         * A logged-in commenter has one on their account; a guest does not, and
         * '' simply means "cannot tell" — then everyone is told, which is the
         * safe direction to err in. */
        $authorEmail = (string)($u['email'] ?? '');

        if ($kind === 'poll') {
            db_run('INSERT INTO poll_comments (poll_id, name, user_id, comment) VALUES (?,?,?,?)',
                   [$pollId, $name, $u['id'] ?? null, $comment]);
            log_action('comment_add', 'poll #' . $pollId);
            /* Everyone with a stake in that poll hears about it. Sent AFTER the
             * insert, so a mail never announces a comment that failed to save. */
            notify_poll_comment_added($target, $name, $authorEmail);
        } else {
            db_run('INSERT INTO comments (game_id, name, user_id, comment) VALUES (?,?,?,?)',
                   [$gameId, $name, $u['id'] ?? null, $comment]);
            log_action('comment_add', $game['name']);
            notify_comment_added($game, $name, $authorEmail);
        }
    }
}
redirect(front_url($activeDay, (int)($day['event_id'] ?? 0))
         . ($kind === 'poll' ? '#poll-' . $pollId : '#game-' . $gameId));
