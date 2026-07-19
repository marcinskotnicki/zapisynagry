<?php
/* =============================================================================
 *  sign_up.php — sign up for a game (or its reserve list).
 * -----------------------------------------------------------------------------
 *  GET renders the signup form; POST records the signup. Whether it's CONFIRMED
 *  or RESERVE is decided at submit time from the current free seats, so two
 *  people racing for the last seat resolve sanely (the one whose write lands
 *  while a seat is free is confirmed; the other becomes reserve).
 *  On a confirmed signup the game's bringer gets a notification email.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/notify.php';

// The game can arrive via query (link) or body (form post).
$gameId = (int)($_GET['game'] ?? $_POST['game'] ?? 0);
$game   = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
if (!$game) { http_response_code(404); exit('Unknown game.'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$game['event_id']]);
$day   = db_one('SELECT day_index FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);   // for redirecting back to the right day tab

// Signups only on the live event, and only if permitted by the access rules.
if (!$event || (int)$event['is_archived'] === 1 || !can_signup()) {
    redirect('index.php');
}

// Prefill name/email for a logged-in user; default "knows rules" to 0 ("yes").
$u = current_user();
$form = [
    'name'  => $_POST['name']  ?? ($u['display_name'] ?? ''),
    'email' => $_POST['email'] ?? ($u['email'] ?? ''),
    'knows' => isset($_POST['knows']) ? (int)$_POST['knows'] : 0,
];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['name']  = trim((string)$form['name']);
    $form['email'] = trim((string)$form['email']);
    $form['knows'] = min(2, max(0, (int)$form['knows']));   // clamp to the 0..2 codes

    if ($form['name'] === '') {
        $error = t('error_signup_name');
    } elseif (email_required_for_game($game) && $form['email'] === '') {
        // Required globally (mode 1) or because THIS game's proposer demands it.
        $error = t('error_email_required');
    } elseif ($form['email'] !== '' && !email_valid($form['email'])) {
        $error = t('error_email_invalid');   // non-empty but not X@Y.Z-shaped
    } else {
        // Confirmed unless the game is already full RIGHT NOW (re-checked here,
        // not from a value computed earlier, so the race resolves correctly).
        $isReserve = game_is_full($gameId, $game['max_players']) ? 1 : 0;
        db_run(
            'INSERT INTO players (game_id, name, email, knows_rules, is_reserve, user_id)
             VALUES (?,?,?,?,?,?)',
            [
                $gameId, $form['name'],
                $form['email'] !== '' ? $form['email'] : null,   // store NULL, not ''
                $form['knows'], $isReserve,
                $u['id'] ?? null,                                // link to account if logged in
            ]
        );
        log_action('signup', $form['name'] . ' -> ' . $game['name'] . ($isReserve ? ' (reserve)' : ''));
        notify_signup($game, $form['name']);                     // no-op unless notifications on
        redirect('index.php?day=' . $activeDay . '#game-' . $gameId);   // PRG + jump to the card
    }
}

// For the form: is the game full (so we can label the button "join reserve")?
$full = game_is_full($gameId, $game['max_players']);

tpl_render('header', ['page_title' => t('signup_title')]);
tpl_render('sign_up', [
    'game'  => $game,
    'form'  => $form,
    'full'  => $full,
    'error' => $error,
    'csrf'  => csrf_field(),
]);
tpl_render('footer');
