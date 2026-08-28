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
require_once __DIR__ . '/inc/library.php';   // library_can_contact(); _once — bootstrap has it too

// One gate for the whole feature (same helper the envelope icons use):
// allow_messaging on, and — unless allow_guest_messaging — a logged-in account.
if (!messaging_allowed()) redirect('index.php');

$me  = current_user();                 // null for guests (allowed when the toggle is on)
$pid = (int)($_GET['player'] ?? $_POST['player'] ?? 0);
$gid = (int)($_GET['game']   ?? $_POST['game']   ?? 0);
$pwid = (int)($_GET['poll_owner'] ?? $_POST['poll_owner'] ?? 0);   // message the poll's proposer
$plid = (int)($_GET['poll']       ?? $_POST['poll']       ?? 0);   // message everyone who voted
$lmid = (int)($_GET['library_member'] ?? $_POST['library_member'] ?? 0);   // a library owner
// Optional: WHICH of their games prompted the message, so the subject can say.
$lgid = (int)($_GET['library_game'] ?? $_POST['library_game'] ?? 0);
// The club itself, as an owner on the library page. Not a user id — the club
// is not a person — so it is its own flag with its own address.
$lclub = !empty($_GET['library_club']) || !empty($_POST['library_club']);

// Resolve the target: a single player, a whole game's players, a poll's
// proposer, or a poll's voters. Exactly one of the four ids is expected.
$recipients = [];
$targetLabel = '';
$game = null;
$poll = null;

$libraryMember = null;
$libraryClub   = false;
$libraryGame   = null;

if ($lclub) {
    /* THE CLUB'S OWN GAMES. Like the member target this has no parent event, so
     * it skips the live-parent check below.
     *
     * The gate is library_club_can_contact(): the shelf is on, an admin has
     * actually entered an address, and the ordinary messaging rules allow this
     * visitor to send anything. There is no per-member opt-in here because
     * there is no member — the address being set IS the opt-in. */
    if (!library_club_can_contact()) redirect('library.php');
    $recipients  = [library_club_email()];
    $libraryClub = true;

    // Which of the club's games prompted it, looked up in the club's own table.
    $libraryGame = $lgid ? club_shelf_entry($lgid) : null;
    $targetLabel = $libraryGame
        ? t('lib_contact_title_game', t('lib_club_owner'), $libraryGame['name'])
        : t('lib_contact_title', t('lib_club_owner'));
} elseif ($lmid) {
    /* A library owner. Unlike the other four targets this one has no parent
     * event — it is about somebody's shelf, not about a game night — so the
     * "live parent" requirement below is skipped for it.
     *
     * EVERY gate still applies, and they live in library_can_contact():
     * messaging enabled, guests allowed (or the sender signed in), the library
     * feature on, the admin's contact option on, the member's own checkbox
     * ticked, and the member not blocked. Checked here rather than trusting
     * that the button was only rendered when allowed. */
    $libraryMember = db_one('SELECT * FROM users WHERE id = ?', [$lmid]);
    if (!$libraryMember || !library_can_contact($libraryMember) || empty($libraryMember['email'])) {
        redirect('library.php');
    }
    $recipients  = [$libraryMember['email']];

    /* Naming the game turns "about their games" into "about Catan", which is
     * the whole reason someone clicked the envelope next to that title. Looked
     * up scoped to this member, so a row id from the URL cannot name a game
     * they do not own. */
    $libraryGame = $lgid ? library_entry_for_user($lmid, $lgid) : null;
    $targetLabel = $libraryGame
        ? t('lib_contact_title_game', $libraryMember['display_name'], $libraryGame['name'])
        : t('lib_contact_title', $libraryMember['display_name']);
} elseif ($pid) {
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

/* A library message has no parent event, so it skips the live-parent check
 * entirely — its own gate above already decided whether it may be sent. */
if ($libraryMember || $libraryClub) {
    $activeDay  = 0;
    $backAnchor = '';
} else {
    // Must have a live parent (game or poll) and at least one recipient.
    $parentEventId = $game['event_id'] ?? $poll['event_id'] ?? 0;
    $event = $parentEventId ? db_one('SELECT is_archived FROM events WHERE id = ?', [$parentEventId]) : null;
    if ((!$game && !$poll) || !$event || (int)$event['is_archived'] === 1 || empty($recipients)) {
        redirect('index.php');
    }
    $parentDayId = $game['day_id'] ?? $poll['day_id'];
    $day = db_one('SELECT day_index, event_id FROM event_days WHERE id = ?', [$parentDayId]);
    $activeDay = (int)($day['day_index'] ?? 1);
    // Where "back" leads: the game card or the poll card.
    $backAnchor = $game ? ('#game-' . (int)$game['id']) : ('#poll-' . (int)$poll['id']);
}

$error = null;
// Sender identity: accounts supply it implicitly; guests must type BOTH a name
// (for the subject line) and a valid email (for Reply-To — a message nobody can
// answer is just anonymous spam). Values persist across a validation error.
$senderName  = $me ? $me['display_name'] : trim($_POST['sender_name']  ?? '');
$senderEmail = $me ? $me['email']        : trim($_POST['sender_email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    antibot_check('form');
    $bodyText = trim($_POST['body'] ?? '');
    // Data-protection consent, when the admin configured wording and the
    // visitor is not signed in. Checked here rather than trusting the
    // checkbox's `required` attribute, which a client can simply not send.
    if (!consent_ok()) {
        $error = t('gdpr_required');
    } elseif ($bodyText === '') {
        $error = t('msg_empty');
    } elseif (!text_has_content($bodyText)) {
        $error = t('error_name_meaningless');
    } elseif (text_too_long($bodyText, TEXT_BODY_MAX)) {
        $error = t('error_too_long', TEXT_BODY_MAX);
    } elseif (!$me && $senderName === '') {
        $error = t('error_signup_name');
    } elseif (!$me && (!text_has_content($senderName) || text_too_long($senderName, TEXT_PERSON_MAX))) {
        $error = t('error_name_meaningless');
    } elseif (!$me && $senderEmail === '') {
        $error = t('error_email_required');           // guests always need a reply path
    } elseif (!$me && !email_valid($senderEmail)) {
        $error = t('error_email_invalid');
    } elseif (!$me && !captcha_verify('message')) {
        $error = t('error_captcha');                  // no-op when captcha is off
    } else {
        // From = venue (set in send_mail); Reply-To = the sender, so a reply
        // goes to them. One send per recipient.
        // Subject: "Message from <sender> regarding: <game / poll>" — game
        // targets name the game; poll targets (owner or voters) use the poll
        // label + its start time (a poll has no single game name yet). The
        // "<venue>: " prefix is added centrally by send_mail(), like on every
        // other outgoing email.
        $re = ($libraryMember || $libraryClub)
            ? ($libraryGame ? $libraryGame['name'] : t('lib_contact_subject'))
            : ($game ? $game['name'] : (t('poll_label') . ' ' . $poll['start_time']));
        $subject = t('msg_subject', $senderName, $re);
        $replyTo = $senderEmail;
        /* A library enquiry has no event to belong to, so — when the admin
         * leaves the option on — its subject is prefixed with the VENUE name
         * even on a site that normally prefixes with the current event's.
         * Attaching it to whichever event happens to be live would file it
         * under something unrelated. */
        $prefixMode = (($libraryMember || $libraryClub) && opt_bool('library_mail_venue')) ? 'venue' : null;
        foreach ($recipients as $to) {
            send_mail($to, $subject, $bodyText, $replyTo, $prefixMode);
        }
        log_action('message_sent', $targetLabel);
        // Remember the agreement so the next form starts ticked. Only after a
        // successful submission, so the cookie can never record consent for
        // something that was refused.
        consent_remember();
        flash_set(t('msg_sent'));
        // Back where the message was started from: the library, or the event.
        redirect(($libraryMember || $libraryClub)
            ? ($libraryClub ? 'library.php?tab=club' : 'library.php')
            : (front_url($activeDay, (int)($day['event_id'] ?? 0)) . $backAnchor));
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
    'library_member' => $lmid,
    'library_club'   => $libraryClub ? 1 : 0,
    'library_game'   => $libraryGame ? (int)$libraryGame['id'] : 0,
    'recipients'   => count($recipients),
    // Guest mode: the form shows sender name/email inputs (+ captcha when on);
    // logged-in senders are identified by their account, no extra fields.
    'is_guest'     => $me === null,
    'sender_name'  => $senderName,
    'sender_email' => $senderEmail,
    'captcha'      => $me === null ? captcha_html('message') : '',
    'error'        => $error,
    'csrf'         => csrf_field(),
]);
tpl_render('footer');
