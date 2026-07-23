<?php
/* =============================================================================
 *  add_comment.php — post a comment to a game's discussion.
 * -----------------------------------------------------------------------------
 *  Available when discussions are enabled. Anyone may comment; the name is
 *  prefilled for logged-in users. POST-only (no form page of its own — the form
 *  lives in the game card); always redirects back to the game.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';

$gameId = (int)($_POST['game'] ?? 0);
$game   = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
if (!$game) { redirect('index.php'); }

$event = db_one('SELECT is_archived FROM events WHERE id = ?', [$game['event_id']]);
$day   = db_one('SELECT day_index FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Only when discussions are on and the event is live.
if (!opt_bool('allow_discussions') || !$event || (int)$event['is_archived'] === 1) {
    redirect('index.php?day=' . $activeDay);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
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
        && !text_too_long($name, TEXT_NAME_MAX)
        && !text_too_long($comment, TEXT_BODY_MAX)) {
        db_run('INSERT INTO comments (game_id, name, user_id, comment) VALUES (?,?,?,?)',
               [$gameId, $name, $u['id'] ?? null, $comment]);
        log_action('comment_add', $game['name']);
    }
}
redirect('index.php?day=' . $activeDay . '#game-' . $gameId);
