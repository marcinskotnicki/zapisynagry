<?php
/* =============================================================================
 *  message.php — send a message to a player or to everyone at a game.
 * -----------------------------------------------------------------------------
 *  Logged-in only, and only when messaging is enabled. The recipients' email
 *  addresses are never shown to the sender; the sender's address goes in
 *  Reply-To so replies reach them directly (the From stays the venue address).
 *
 *  Target is either ?player=<id> (one player who left an email) or ?game=<id>
 *  (every player on the game with an email).
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/mail.php';
require_login();

if (!opt_bool('allow_messaging')) redirect('index.php');

$me  = current_user();
$pid = (int)($_GET['player'] ?? $_POST['player'] ?? 0);
$gid = (int)($_GET['game']   ?? $_POST['game']   ?? 0);

// Resolve the target: a single player, or a whole game's players.
$recipients = [];
$targetLabel = '';
$game = null;

if ($pid) {
    // One player — only if they actually left an email (else nothing to send to).
    $player = db_one('SELECT * FROM players WHERE id = ?', [$pid]);
    if (!$player || empty($player['email'])) redirect('index.php');
    $game = db_one('SELECT * FROM games WHERE id = ?', [$player['game_id']]);
    $recipients = [$player['email']];
    $targetLabel = t('msg_to_player', $player['name']);
} elseif ($gid) {
    // Everyone on the game with an email (distinct, non-empty).
    $game = db_one('SELECT * FROM games WHERE id = ?', [$gid]);
    if ($game) {
        $rows = db_all('SELECT DISTINCT email FROM players WHERE game_id = ? AND email IS NOT NULL AND email <> ""', [$gid]);
        $recipients = array_column($rows, 'email');
        $targetLabel = t('msg_to_game', $game['name']);
    }
}

// Must have a live game and at least one recipient, else bail to the front page.
$event = $game ? db_one('SELECT is_archived FROM events WHERE id = ?', [$game['event_id']]) : null;
if (!$game || !$event || (int)$event['is_archived'] === 1 || empty($recipients)) {
    redirect('index.php');
}
$day = db_one('SELECT day_index FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $bodyText = trim($_POST['body'] ?? '');
    if ($bodyText === '') {
        $error = t('msg_empty');
    } else {
        // From = venue (set in send_mail); Reply-To = the sender, so a reply
        // goes to them. One send per recipient.
        $subject = t('msg_subject', $me['display_name']);
        $replyTo = $me['email'];
        foreach ($recipients as $to) {
            send_mail($to, $subject, $bodyText, $replyTo);
        }
        log_action('message_sent', $targetLabel);
        flash_set(t('msg_sent'));
        redirect('index.php?day=' . $activeDay . '#game-' . (int)$game['id']);
    }
}

tpl_render('header', ['page_title' => t('msg_title')]);
tpl_render('message_form', [
    'target_label' => $targetLabel,
    'player'       => $pid,                       // non-zero => single-player mode
    'game_id'      => $pid ? 0 : (int)$game['id'], // else the whole-game mode
    'recipients'   => count($recipients),
    'error'        => $error,
    'csrf'         => csrf_field(),
]);
tpl_render('footer');
