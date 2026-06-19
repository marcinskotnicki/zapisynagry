<?php
/* =============================================================================
 *  edit_game.php — edit a game.
 * -----------------------------------------------------------------------------
 *  Owners and admins edit directly. For unregistered-added games, the
 *  verification tree gates access: the user passes a challenge ONCE, its result
 *  is remembered in the session for this game ($_SESSION['edit_ok'][gameId]),
 *  and then they see the pre-filled form (the add-game form reused in edit mode).
 *
 *  WHY A SESSION UNLOCK (vs the single-step delete): editing shows a big form
 *  the user fills in, so we verify FIRST (a small challenge), remember it, then
 *  let the save go through — rather than making them fill the whole form only to
 *  fail a challenge at submit time.
 *
 *  REQUEST ROUTING (all POST unless noted):
 *    action=verify  -> check the challenge, set the session unlock
 *    mode=save      -> (if unlocked) write the update
 *    otherwise      -> render the challenge gate (if locked) or the edit form
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
$table = db_one('SELECT * FROM game_tables WHERE id = ?', [$game['table_id']]);
$day   = db_one('SELECT * FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Only active games on the live event are editable (also re-checks the button rule).
if (!$event || (int)$event['is_archived'] === 1 || (int)$game['is_archived'] === 1
    || !verify_can_show_buttons($game['added_by_user_id'])) {
    redirect('index.php?day=' . $activeDay);
}

$decision = verify_decision($game['added_by_user_id'], $game['brings_email']);
if ($decision === 'deny') { redirect('index.php?day=' . $activeDay); }

// "Unlocked" = no challenge needed (owner/admin) OR already passed this session.
$unlocked = ($decision === 'allow') || !empty($_SESSION['edit_ok'][$gameId]);
$error = null;

/* ---- Challenge submission (gate) ----------------------------------------- */
if (($_POST['action'] ?? '') === 'verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (verify_passes($decision, 'game', $gameId, $game['brings_email'], $_POST)) {
        $_SESSION['edit_ok'][$gameId] = true;   // remember success for this game
        $unlocked = true;
    } else {
        $error = t('verify_failed');
    }
}

/* ---- Save ---------------------------------------------------------------- */
if (($_POST['mode'] ?? '') === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$unlocked) { redirect('index.php?day=' . $activeDay); }   // never save while locked

    $start = trim($_POST['start_time'] ?? '');
    if (!is_valid_time($start)) $start = $game['start_time'];      // keep old time if invalid
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $error = t('error_name_required');
    } elseif (opt_bool('require_email') && trim($_POST['brings_email'] ?? '') === '') {
        $error = t('error_email_required');
    } else {
        $startChanged = ($start !== $game['start_time']);   // for the notification
        // A BGG game's image is locked: keep the stored thumbnail; manual games
        // can change it via the picker.
        $thumbnail = (int)$game['bgg_id'] ? $game['thumbnail'] : trim($_POST['thumbnail'] ?? '');
        db_run(
            'UPDATE games SET name=?, length_minutes=?, weight=?, max_players=?, start_time=?,
                    thumbnail=?, brings_name=?, brings_email=?, explain_rules=?, comment=? WHERE id=?',
            [
                $name,
                max(0, (int)($_POST['length_minutes'] ?? 0)),
                min(5, max(1, (float)($_POST['weight'] ?? 1))),
                max(1, (int)($_POST['max_players'] ?? 1)),
                $start,
                $thumbnail !== '' ? $thumbnail : null,
                trim($_POST['brings_name'] ?? '') ?: null,
                trim($_POST['brings_email'] ?? '') ?: null,
                min(2, max(0, (int)($_POST['explain_rules'] ?? 0))),
                trim($_POST['comment'] ?? '') ?: null,
                $gameId,
            ]
        );
        unset($_SESSION['edit_ok'][$gameId]);          // consume the unlock after a successful save
        log_action('game_edit', $name);
        if ($startChanged) notify_starttime_changed($game, $start);   // tell signed-up players
        redirect('index.php?day=' . $activeDay . '#game-' . $gameId);
    }
}

/* ---- Render -------------------------------------------------------------- */
// Needs a challenge and not yet unlocked -> show the challenge gate.
if (!$unlocked) {
    if ($decision === 'email_code' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        verify_send_code('game', $gameId, $game['brings_email']);   // email the code on first view
    }
    tpl_render('header', ['page_title' => t('editgame_title')]);
    tpl_render('verify_challenge', [
        'decision' => $decision,
        'action'   => 'edit_game.php?game=' . $gameId,   // the gate posts back here
        'title'    => t('editgame_title'),
        'error'    => $error,
        'csrf'     => csrf_field(),
    ]);
    tpl_render('footer');
    exit;
}

// Unlocked: show the pre-filled form (reusing the add-game form in edit mode).
$form = $game;
$form['add_self'] = 0;                              // editing never re-adds the bringer
$source = (int)$game['bgg_id'] ? 'bgg' : 'manual'; // BGG games keep the locked image

tpl_render('header', ['page_title' => t('editgame_title')]);
tpl_render('add_game_form', [
    'table'   => $table,
    'game'    => $form,
    'source'  => $source,
    'thumbs'  => $source === 'bgg' ? [] : db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
    'captcha' => '',                       // owner/admin/verified — no captcha on edit
    'error'   => $error,
    'csrf'    => csrf_field(),
    'action'  => 'edit_game.php?game=' . $gameId,   // form posts mode=save back here
    'is_edit' => true,
    'title'   => t('editgame_title'),
]);
tpl_render('footer');
