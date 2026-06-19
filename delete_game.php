<?php
/* =============================================================================
 *  delete_game.php — delete a game, three ways.
 * -----------------------------------------------------------------------------
 *  Confirm screen with three buttons (a single form, distinguished by the
 *  submitted "choice"):
 *    back       : do nothing, return to the event.
 *    archive    : soft-delete (is_archived=1) — greyed out, can be brought back.
 *    everything : hard delete — cascades players + comments away.
 *  Verification (for unregistered-added games) is on the confirm screen and is
 *  checked when the action is submitted, mirroring player deletion (single-step).
 *  Signed-up players are emailed BEFORE the delete (so we can still find them).
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/verify.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/notify.php';

$gameId = (int)($_GET['game'] ?? $_POST['game'] ?? 0);
$game   = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
if (!$game) { redirect('index.php'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$game['event_id']]);
$day   = db_one('SELECT day_index FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Live event + active game only, and re-check the button rule server-side.
if (!$event || (int)$event['is_archived'] === 1 || (int)$game['is_archived'] === 1
    || !verify_can_show_buttons($game['added_by_user_id'])) {
    redirect('index.php?day=' . $activeDay);
}

$decision = verify_decision($game['added_by_user_id'], $game['brings_email']);
if ($decision === 'deny') { redirect('index.php?day=' . $activeDay); }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $choice = $_POST['choice'] ?? 'back';

    if ($choice === 'back') {
        redirect('index.php?day=' . $activeDay);          // bail out, no challenge needed
    }
    if (!verify_passes($decision, 'game', $gameId, $game['brings_email'], $_POST)) {
        $error = t('verify_failed');                       // failed challenge -> re-show confirm
    } elseif ($choice === 'archive') {
        notify_game_deleted($game);                        // notify while players still exist
        db_run('UPDATE games SET is_archived = 1 WHERE id = ?', [$gameId]);   // soft-delete
        log_action('game_archive', $game['name']);
        redirect('index.php?day=' . $activeDay);
    } elseif ($choice === 'everything') {
        notify_game_deleted($game);   // gather + notify before the cascade removes players
        db_run('DELETE FROM games WHERE id = ?', [$gameId]);   // cascades players + comments
        log_action('game_delete', $game['name']);
        redirect('index.php?day=' . $activeDay);
    }
}

// GET (or failed POST): issue a code if needed, then render the confirm screen.
if ($decision === 'email_code' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    verify_send_code('game', $gameId, $game['brings_email']);
}

tpl_render('header', ['page_title' => t('delgame_title')]);
tpl_render('game_delete_confirm', [
    'game'     => $game,
    'decision' => $decision,
    'error'    => $error,
    'csrf'     => csrf_field(),
]);
tpl_render('footer');
