<?php
/* =============================================================================
 *  edit_player.php — correct a player's sign-up (name, email, rules knowledge).
 * -----------------------------------------------------------------------------
 *  Behind the 'allow_player_edit' option (off by default).
 *
 *  WHO MAY: exactly the people who may REMOVE the same sign-up — the rules
 *  live in inc/verify.php and are shared with delete_player.php:
 *    - admins: anyone's,
 *    - a sign-up made while logged in: only that account (and admins),
 *    - a guest sign-up: the admin's verification method (code / matching
 *      email / ...), with the no-email rule where it applies.
 *
 *  Two steps for a guest who must prove themselves, like edit_game.php: pass
 *  the challenge once (remembered in the session for this sign-up), then the
 *  form. The same code a delete would use unlocks it — both use 'player:N'.
 *
 *  What does NOT change here: the seat (confirmed vs reserve), the game, the
 *  account link and "signed up by". Moving between games is resign + sign up.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require_once __DIR__ . '/inc/verify.php';
require_once __DIR__ . '/inc/captcha.php';

$playerId = (int)($_GET['player'] ?? $_POST['player'] ?? 0);
$player   = $playerId ? db_one('SELECT * FROM players WHERE id = ?', [$playerId]) : null;
if (!$player) { redirect('index.php'); }

$game  = db_one('SELECT * FROM games WHERE id = ?', [$player['game_id']]);
$event = $game ? db_one('SELECT * FROM events WHERE id = ?', [$game['event_id']]) : null;
$day   = $game ? db_one('SELECT day_index, event_id FROM event_days WHERE id = ?', [$game['day_id']]) : null;
$activeDay = (int)($day['day_index'] ?? 1);
$back = $game ? front_url($activeDay, (int)($day['event_id'] ?? 0), '#game-') . (int)$game['id'] : 'index.php';

// The feature itself, a live event, and the same button rule the card uses.
if (!opt_bool('allow_player_edit') || !$game || !$event
    || (int)$event['is_archived'] === 1 || (int)($game['is_archived'] ?? 0) === 1
    || !verify_can_show_buttons($player['user_id'], $player['email'] ?? null)) {
    redirect($back);
}
$decision = verify_decision($player['user_id'], $player['email']);
if ($decision === 'deny') { redirect($back); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_action('player_edit_attempt', $player['name'] . ' <- ' . $game['name']);
}

// "Unlocked" = no challenge needed (owner/admin) or already passed this session.
$unlocked = ($decision === 'allow') || !empty($_SESSION['player_edit_ok'][$playerId]);
$error = null;

/* ---- Challenge (the gate) ------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    csrf_check();
    antibot_check('form');
    if (($_POST['choice'] ?? '') === 'send_code') {
        // Asking for a code is its own step and the only thing that sends mail.
        if (captcha_verify('verify')) {
            verify_send_code('player', $playerId, $player['email']);
            verify_mark_requested('player', $playerId);
        } else {
            $error = t('error_captcha');
        }
    } elseif (verify_passes($decision, 'player', $playerId, $player['email'], $_POST)) {
        $_SESSION['player_edit_ok'][$playerId] = true;
        $unlocked = true;
    } else {
        $error = t('verify_failed');
    }
}

/* ---- Save ---------------------------------------------------------------- */
$form = [
    'name'  => (string)$player['name'],
    'email' => (string)($player['email'] ?? ''),
    // The stored answer as an int, or null for old rows that never had one —
    // then the question must be answered, exactly as on the sign-up form.
    'knows' => ($player['knows_rules'] === null || $player['knows_rules'] === '') ? null : (int)$player['knows_rules'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_check();
    antibot_check('form');
    if (!$unlocked) { redirect($back); }   // never save while locked

    $form['name']  = trim((string)($_POST['name'] ?? ''));
    $form['email'] = trim((string)($_POST['email'] ?? ''));
    $form['knows'] = knows_from_post($_POST);

    if ($form['name'] === '') {
        $error = t('error_signup_name');
    } elseif (!text_has_content($form['name'])) {
        $error = t('error_name_meaningless');
    } elseif (text_too_long($form['name'], TEXT_PERSON_MAX)) {
        $error = t('error_too_long', TEXT_PERSON_MAX);
    } elseif ($form['knows'] === null) {
        $error = t('error_knows_required');
    } elseif (email_required_for_game($game) && $form['email'] === '') {
        $error = t('error_email_required');
    } elseif ($form['email'] !== '' && !email_valid($form['email'])) {
        $error = t('error_email_invalid');
    } else {
        db_run('UPDATE players SET name = ?, email = ?, knows_rules = ? WHERE id = ?',
               [$form['name'], $form['email'] !== '' ? $form['email'] : null, $form['knows'], $playerId]);
        log_action('player_edit', $player['name']
            . ($form['name'] !== $player['name'] ? ' -> ' . $form['name'] : '') . ' @ ' . $game['name']);
        // Done: the unlock is not left lying around for this sign-up.
        unset($_SESSION['player_edit_ok'][$playerId]);
        flash_set(t('editplayer_saved'));
        redirect($back);
    }
}

tpl_render('header', ['page_title' => t('editplayer_title')]);
if (!$unlocked) {
    tpl_render('verify_challenge', [
        'decision'  => $decision,
        'action'    => 'edit_player.php?player=' . $playerId,
        'title'     => t('editplayer_title'),
        'error'     => $error,
        'csrf'      => csrf_field(),
        'code_sent' => verify_code_requested('player', $playerId),
        'captcha'   => captcha_html('verify'),
    ]);
} else {
    tpl_render('edit_player', [
        'player' => $player,
        'game'   => $game,
        'form'   => $form,
        'error'  => $error,
        'back'   => $back,
        'csrf'   => csrf_field(),
    ]);
}
tpl_render('footer');
