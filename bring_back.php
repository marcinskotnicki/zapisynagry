<?php
/* =============================================================================
 *  bring_back.php — restore a soft-deleted ("keep archived") game.
 * -----------------------------------------------------------------------------
 *  Anyone may do this (no verification challenge): they supply a name (+ email),
 *  the game is reactivated (is_archived=0), and they become its new owner /
 *  bringer. Players still attached to the game are notified it's back.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/notify.php';

$gameId = (int)($_GET['game'] ?? $_POST['game'] ?? 0);
$game   = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
if (!$game) { redirect('index.php'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$game['event_id']]);
$day   = db_one('SELECT day_index FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Only meaningful for an archived game on the live event.
if (!$event || (int)$event['is_archived'] === 1 || (int)$game['is_archived'] !== 1) {
    redirect('index.php?day=' . $activeDay);
}

// Prefill from the logged-in user, if any (a guest types their own details).
$u = current_user();
$form = [
    'name'  => $_POST['name']  ?? ($u['display_name'] ?? ''),
    'email' => $_POST['email'] ?? ($u['email'] ?? ''),
];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['name']  = trim((string)$form['name']);
    $form['email'] = trim((string)$form['email']);

    if ($form['name'] === '') {
        $error = t('error_name_required');
    } elseif (email_required_for_game($game) && $form['email'] === '') {
        // Restoring re-owns the game, so the bringer rule applies: required
        // globally (mode 1) or when this game's flag demands emails (mode 2).
        $error = t('error_email_required');
    } elseif ($form['email'] !== '' && !email_valid($form['email'])) {
        $error = t('error_email_invalid');   // non-empty but not X@Y.Z-shaped
    } else {
        // Reactivate AND re-own: the restorer becomes bringer + added_by owner.
        db_run(
            'UPDATE games SET is_archived = 0, brings_name = ?, brings_email = ?, brings_user_id = ?, added_by_user_id = ? WHERE id = ?',
            [$form['name'], $form['email'] !== '' ? $form['email'] : null,
             $u['id'] ?? null, $u['id'] ?? null, $gameId]
        );
        log_action('game_bringback', $game['name'] . ' -> ' . $form['name']);
        notify_game_undeleted($game);   // tell players still attached it's back
        redirect('index.php?day=' . $activeDay . '#game-' . $gameId);
    }
}

tpl_render('header', ['page_title' => t('bringback_title')]);
tpl_render('bring_back', [
    'game'  => $game,
    'form'  => $form,
    'error' => $error,
    'csrf'  => csrf_field(),
]);
tpl_render('footer');
