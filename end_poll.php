<?php
/* =============================================================================
 *  end_poll.php — end a poll early (resolve to the current leader NOW).
 * -----------------------------------------------------------------------------
 *  Offered to the poll's PROPOSER (their account) and to admins — logged-in
 *  accounts only, which sidesteps the whole verification tree: a guest-created
 *  poll simply waits for its threshold or deadline instead.
 *
 *  GET shows a small confirm card; POST resolves via poll_force_resolve() (same
 *  winner rule as an expired deadline: best fill ratio, earlier candidate wins
 *  ties) and jumps to the resulting game card.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/polls.php';
require __DIR__ . '/inc/notify.php';   // resolution sends the conclusion email
require_login();                       // proposer/admin are accounts by definition

$pollId = (int)($_GET['poll'] ?? $_POST['poll'] ?? 0);
$poll   = $pollId ? db_one('SELECT * FROM polls WHERE id = ?', [$pollId]) : null;
if (!$poll) { redirect('index.php'); }

$event = db_one('SELECT is_archived FROM events WHERE id = ?', [$poll['event_id']]);
$day   = db_one('SELECT day_index FROM event_days WHERE id = ?', [$poll['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Live event only, and only the proposer's own account or an admin.
$u = current_user();
$isOwner = ((int)$poll['proposer_user_id'] !== 0
            && (int)$poll['proposer_user_id'] === (int)$u['id']);
if (!$event || (int)$event['is_archived'] === 1 || (!$isOwner && !is_admin())) {
    redirect('index.php?day=' . $activeDay);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $newGameId = poll_force_resolve($pollId);
    log_action('poll_ended_early', 'Poll #' . $pollId . ($newGameId ? ' -> game #' . $newGameId : ' (no winner)'));
    if ($newGameId) {
        redirect('index.php?day=' . $activeDay . '#game-' . $newGameId);
    }
    redirect('index.php?day=' . $activeDay);       // pathological: poll had no candidates
}

tpl_render('header', ['page_title' => t('poll_end_title')]);
tpl_render('end_poll_confirm', [
    'poll'  => $poll,
    'csrf'  => csrf_field(),
]);
tpl_render('footer');
