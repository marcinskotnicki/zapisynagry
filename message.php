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
$pwid = (int)($_GET['poll_owner'] ?? $_POST['poll_owner'] ?? 0);   // message the poll's proposer
$plid = (int)($_GET['poll']       ?? $_POST['poll']       ?? 0);   // message everyone who voted

// Resolve the target: a single player, a whole game's players, a poll's
// proposer, or a poll's voters. Exactly one of the four ids is expected.
$recipients = [];
$targetLabel = '';
$game = null;
$poll = null;

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
} elseif ($pwid) {
    // The poll's proposer — only if they left an email.
    $poll = db_one('SELECT * FROM polls WHERE id = ?', [$pwid]);
    if (!$poll || empty($poll['proposer_email'])) redirect('index.php');
    $recipients = [$poll['proposer_email']];
    $targetLabel = t('msg_to_poll_owner', $poll['proposer_name'] ?: '?');
} elseif ($plid) {
    // Everyone who voted in the poll, on any candidate (distinct, non-empty).
    $poll = db_one('SELECT * FROM polls WHERE id = ?', [$plid]);
    if ($poll) {
        $rows = db_all('SELECT DISTINCT email FROM poll_votes WHERE poll_id = ? AND email IS NOT NULL AND email <> ""', [$plid]);
        $recipients = array_column($rows, 'email');
        $targetLabel = t('msg_to_poll');
    }
}

// Must have a live parent (game or poll) and at least one recipient.
$parentEventId = $game['event_id'] ?? $poll['event_id'] ?? 0;
$event = $parentEventId ? db_one('SELECT is_archived FROM events WHERE id = ?', [$parentEventId]) : null;
if ((!$game && !$poll) || !$event || (int)$event['is_archived'] === 1 || empty($recipients)) {
    redirect('index.php');
}
$parentDayId = $game['day_id'] ?? $poll['day_id'];
$day = db_one('SELECT day_index FROM event_days WHERE id = ?', [$parentDayId]);
$activeDay = (int)($day['day_index'] ?? 1);
// Where "back" leads: the game card or the poll card.
$backAnchor = $game ? ('#game-' . (int)$game['id']) : ('#poll-' . (int)$poll['id']);

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $bodyText = trim($_POST['body'] ?? '');
    if ($bodyText === '') {
        $error = t('msg_empty');
    } else {
        // From = venue (set in send_mail); Reply-To = the sender, so a reply
        // goes to them. One send per recipient.
        // Subject: "[venue: ]Message from <sender> regarding: <game / poll>".
        // Game targets name the game; poll targets (owner or voters) use the
        // poll label + its start time (a poll has no single game name yet).
        // The venue prefix is added only when a venue name is actually set,
        // so an unconfigured install doesn't produce a subject like ": ...".
        $re = $game ? $game['name'] : (t('poll_label') . ' ' . $poll['start_time']);
        $subject = t('msg_subject', $me['display_name'], $re);
        $venue = opt('venue_name');
        if ($venue !== '') {
            $subject = $venue . ': ' . $subject;
        }
        $replyTo = $me['email'];
        foreach ($recipients as $to) {
            send_mail($to, $subject, $bodyText, $replyTo);
        }
        log_action('message_sent', $targetLabel);
        flash_set(t('msg_sent'));
        redirect('index.php?day=' . $activeDay . $backAnchor);
    }
}

tpl_render('header', ['page_title' => t('msg_title')]);
tpl_render('message_form', [
    'target_label' => $targetLabel,
    // Exactly one of these four is non-zero; the template emits the matching
    // hidden field so the POST lands back in the same mode.
    'player'       => $pid,
    'game_id'      => $gid ? (int)$game['id'] : 0,
    'poll_owner'   => $pwid,
    'poll_id'      => $plid,
    'recipients'   => count($recipients),
    'error'        => $error,
    'csrf'         => csrf_field(),
]);
tpl_render('footer');
