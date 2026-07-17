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
require __DIR__ . '/inc/captcha.php';  // guests get the same captcha as other public forms

// One gate for the whole feature (same helper the envelope icons use):
// allow_messaging on, and — unless allow_guest_messaging — a logged-in account.
if (!messaging_allowed()) redirect('index.php');

$me  = current_user();                 // null for guests (allowed when the toggle is on)
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
// Sender identity: accounts supply it implicitly; guests must type BOTH a name
// (for the subject line) and a valid email (for Reply-To — a message nobody can
// answer is just anonymous spam). Values persist across a validation error.
$senderName  = $me ? $me['display_name'] : trim($_POST['sender_name']  ?? '');
$senderEmail = $me ? $me['email']        : trim($_POST['sender_email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $bodyText = trim($_POST['body'] ?? '');
    if ($bodyText === '') {
        $error = t('msg_empty');
    } elseif (!$me && $senderName === '') {
        $error = t('error_signup_name');
    } elseif (!$me && $senderEmail === '') {
        $error = t('error_email_required');           // guests always need a reply path
    } elseif (!$me && !email_valid($senderEmail)) {
        $error = t('error_email_invalid');
    } elseif (!$me && !captcha_verify()) {
        $error = t('error_captcha');                  // no-op when captcha is off
    } else {
        // From = venue (set in send_mail); Reply-To = the sender, so a reply
        // goes to them. One send per recipient.
        // Subject: "Message from <sender> regarding: <game / poll>" — game
        // targets name the game; poll targets (owner or voters) use the poll
        // label + its start time (a poll has no single game name yet). The
        // "<venue>: " prefix is added centrally by send_mail(), like on every
        // other outgoing email.
        $re = $game ? $game['name'] : (t('poll_label') . ' ' . $poll['start_time']);
        $subject = t('msg_subject', $senderName, $re);
        $replyTo = $senderEmail;
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
    // Guest mode: the form shows sender name/email inputs (+ captcha when on);
    // logged-in senders are identified by their account, no extra fields.
    'is_guest'     => $me === null,
    'sender_name'  => $senderName,
    'sender_email' => $senderEmail,
    'captcha'      => $me === null ? captcha_html() : '',
    'error'        => $error,
    'csrf'         => csrf_field(),
]);
tpl_render('footer');
