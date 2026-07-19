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
 *
 *  PURGE MODE: an ALREADY soft-deleted game may also land here — that's the
 *  admin-only "delete permanently" button on the greyed card. The confirm
 *  screen then offers just back / delete everything (no re-archive, no
 *  challenge, no duplicate deletion email).
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

// PURGE MODE: a soft-deleted game may be removed PERMANENTLY, but only by an
// admin (the greyed card shows them the button). Regular flow (active game)
// keeps the usual live-event + button-rule + verification checks.
$purge = ((int)$game['is_archived'] === 1);

if ($purge) {
    // Archived game: admin-only, no verification challenge (admins always pass).
    if (!$event || (int)$event['is_archived'] === 1 || !is_admin()) {
        redirect('index.php?day=' . $activeDay);
    }
    $decision = 'allow';
} else {
    // Live event + active game only, and re-check the button rule server-side.
    if (!$event || (int)$event['is_archived'] === 1
        || !verify_can_show_buttons($game['added_by_user_id'])) {
        redirect('index.php?day=' . $activeDay);
    }
    $decision = verify_decision($game['added_by_user_id'], $game['brings_email']);
    if ($decision === 'deny') { redirect('index.php?day=' . $activeDay); }
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $choice = $_POST['choice'] ?? 'back';

    if ($choice === 'back') {
        redirect('index.php?day=' . $activeDay);          // bail out, no challenge needed
    }
    if (!verify_passes($decision, 'game', $gameId, $game['brings_email'], $_POST)) {
        $error = t('verify_failed');                       // failed challenge -> re-show confirm
    } elseif ($choice === 'archive' && !$purge) {   // meaningless when already archived
        notify_game_deleted($game);                        // notify while players still exist
        db_run('UPDATE games SET is_archived = 1 WHERE id = ?', [$gameId]);   // soft-delete
        log_action('game_archive', $game['name']);
        redirect('index.php?day=' . $activeDay);
    } elseif ($choice === 'everything') {
        // Players were already notified when the game was soft-deleted, so a
        // purge of an archived game skips the (duplicate) deletion email.
        if (!$purge) { notify_game_deleted($game); }   // gather + notify before the cascade removes players
        db_run('DELETE FROM games WHERE id = ?', [$gameId]);   // cascades players + comments
        log_action('game_delete', $game['name']);
        redirect('index.php?day=' . $activeDay);
    }
}

// GET = the delete button was clicked: leave a trace right away, so even an
// attempt that's abandoned (or fails the challenge later) shows in the logs.
// The success path still adds its own game_archive / game_delete row.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_action('game_delete_attempt', $game['name']);
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
    'purge'    => $purge,      // archived game -> only back / delete-everything buttons
    'csrf'     => csrf_field(),
]);
tpl_render('footer');
