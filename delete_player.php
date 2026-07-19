<?php
/* =============================================================================
 *  delete_player.php — remove a player's signup.
 * -----------------------------------------------------------------------------
 *  Permission + challenge come from inc/verify.php:
 *    - admins and the player's own account: remove directly (a simple confirm).
 *    - unregistered signups: everyone sees the button; the admin's verification
 *      method decides whether a code / matching email is required first.
 *  When a CONFIRMED player leaves, the earliest reserve is promoted (and that
 *  promoted player is notified). The game's bringer is notified of the resign.
 *
 *  Single-step verification: the confirm screen carries the challenge inputs and
 *  verify_passes() is checked on submit (no separate "unlock" step needed for a
 *  one-shot delete, unlike the multi-field edit form).
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/verify.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/notify.php';

$playerId = (int)($_GET['player'] ?? $_POST['player'] ?? 0);
$player   = $playerId ? db_one('SELECT * FROM players WHERE id = ?', [$playerId]) : null;
if (!$player) { redirect('index.php'); }

// Load the game/event/day around this player (for permission + redirect target).
$game  = db_one('SELECT * FROM games WHERE id = ?', [$player['game_id']]);
$event = $game ? db_one('SELECT * FROM events WHERE id = ?', [$game['event_id']]) : null;
$day   = $game ? db_one('SELECT day_index FROM event_days WHERE id = ?', [$game['day_id']]) : null;
$activeDay = (int)($day['day_index'] ?? 1);

// No edits on archived events.
if (!$game || !$event || (int)$event['is_archived'] === 1) {
    redirect('index.php');
}

// Buttons shouldn't have been shown if this is true, but enforce server-side
// (never trust that the UI hid the link).
if (!verify_can_show_buttons($player['user_id'])) {
    redirect('index.php?day=' . $activeDay);
}

// What (if anything) must the actor prove? 'deny' shouldn't reach the form.
$decision = verify_decision($player['user_id'], $player['email']);
if ($decision === 'deny') {
    redirect('index.php?day=' . $activeDay);
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!verify_passes($decision, 'player', $playerId, $player['email'], $_POST)) {
        $error = t('verify_failed');             // wrong/missing challenge -> re-show the form
    } else {
        $wasConfirmed = ((int)$player['is_reserve'] === 0);
        db_run('DELETE FROM players WHERE id = ?', [$playerId]);
        notify_resign($game, $player['name']);
        if ($wasConfirmed) {
            // A confirmed seat just freed up — pull the earliest reserve(s) in,
            // and email anyone who got promoted.
            $promoted = promote_reserves($player['game_id']);   // fill the freed seat from reserve
            foreach ($promoted as $pid) {
                $pe = db_val('SELECT email FROM players WHERE id = ?', [$pid]);
                notify_promoted($pe, $game['name']);
            }
        }
        log_action('player_delete', $player['name'] . ' <- ' . $game['name']);
        redirect('index.php?day=' . $activeDay . '#game-' . $player['game_id']);
    }
}

// GET = the remove-player button was clicked: leave a trace right away, so
// even an attempt that's abandoned (or fails the challenge later) shows in
// the logs. The success path still adds its own player_delete row.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_action('player_delete_attempt', $player['name'] . ' <- ' . $game['name']);
}
// GET (or failed POST): for email_code, (re)issue a code now so it's waiting
// in the user's inbox when the confirm screen renders.
if ($decision === 'email_code' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    verify_send_code('player', $playerId, $player['email']);
}

tpl_render('header', ['page_title' => t('delplayer_title')]);
tpl_render('player_confirm', [
    'player'   => $player,
    'game'     => $game,
    'decision' => $decision,
    'error'    => $error,
    'csrf'     => csrf_field(),
]);
tpl_render('footer');
