<?php
/* =============================================================================
 *  inc/admin/mailing.php — the Mailing tab: write to one of four audiences.
 * -----------------------------------------------------------------------------
 *  Sets $tab_body (and optionally $flash) for admin.php, like every tab
 *  controller. CSRF is already checked centrally by admin.php.
 *
 *  THE FOUR AUDIENCES:
 *    subscribers     — signed up to THIS event's list.
 *    all_subscribers — ever signed up to any event's list.
 *    participants    — left an address on this event (added a game, proposed a
 *                      poll, signed up to play, voted). Never asked for mail —
 *                      choosing this is the admin's call.
 *    event_everyone  — the two event-scoped lists merged and de-duplicated.
 *
 *  Every message carries the event's own unsubscribe link when the recipient is
 *  a subscriber, so even an admin broadcast stays one click from opting out.
 * ============================================================================= */
require_once __DIR__ . '/../mail.php';
require_once __DIR__ . '/../mailing.php';
require_once __DIR__ . '/../events.php';

/* WHICH event's lists are we writing to?
 *
 * "Current event" stopped being an answer once clubs could run several at
 * once: current_event() picks the soonest one still open, which is a fine
 * default for the front page but a poor thing to guess at when the action is
 * "send mail to these people".
 *
 * Only ACTIVE events are offered — events_active() already excludes archived
 * and deleted ones. Mailing an archived event's subscribers is not a thing an
 * admin does by design, and offering it invites doing it by accident.
 *
 * The id arrives by GET (the selector reloads the tab so the audience counts
 * below match what is actually selected) and again as a hidden field on the
 * send form. Either way it is validated against the list: a hand-typed
 * ?event_id= must not reach an archived event, someone else's, or a deleted
 * one — this is the id that decides who gets the mail. */
$activeEvents = events_active();
$currentEvent = current_event();

$eventId = (int)($_POST['event_id'] ?? $_GET['event_id'] ?? ($currentEvent['id'] ?? 0));
$event   = null;
foreach ($activeEvents as $ev) {
    if ((int)$ev['id'] === $eventId) { $event = $ev; break; }
}
// Unknown, archived, deleted or absent: fall back to the first active event
// rather than to whatever was asked for. With no active events at all this
// stays null and $eventId becomes 0 — the event-scoped audiences are then
// empty, while 'all_subscribers' still works, which is the sensible reading.
if ($event === null) {
    $event   = $activeEvents[0] ?? null;
    $eventId = (int)($event['id'] ?? 0);
}

$AUDIENCES = ['subscribers', 'all_subscribers', 'participants', 'event_everyone'];

$draft = ['audience' => 'subscribers', 'subject' => '', 'body' => ''];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $draft['audience'] = in_array($_POST['audience'] ?? '', $AUDIENCES, true)
        ? $_POST['audience'] : 'subscribers';
    $draft['subject'] = trim($_POST['subject'] ?? '');
    $draft['body']    = trim($_POST['body'] ?? '');

    if (!mailing_enabled()) {
        $error = t('ml_admin_disabled');
    } elseif ($draft['subject'] === '' || $draft['body'] === '') {
        $error = t('ml_admin_empty');
    } elseif (!opt_bool('send_emails')) {   // the email master switch
        // No point queueing mail the app can't actually send.
        $error = t('ml_admin_nomail');
    } else {
        $recipients = mailing_audience($draft['audience'], $eventId);
        // Look up subscriber tokens once, so each message can carry that
        // person's own unsubscribe link. Participants who never subscribed
        // have no token and simply get no footer — there is nothing to leave.
        $tokens = [];
        foreach (db_all('SELECT email, token FROM mail_subscribers WHERE event_id = ?', [$eventId]) as $r) {
            $tokens[strtolower($r['email'])] = $r['token'];
        }
        // Counts recipients written to, not deliveries confirmed — see
        // mailing_notify_new_item()'s note on why.
        $sent = 0;
        foreach ($recipients as $to) {
            $body = $draft['body'];
            if (isset($tokens[$to])) $body .= mailing_unsub_footer($tokens[$to]);
            send_mail($to, $draft['subject'], $body);
            $sent++;
        }
        // Name the event in the log line: with several running at once, "who
        // was mailed" is not answerable from the audience key alone.
        log_action('mailing_broadcast', $draft['audience'] . ' -> ' . $sent . ' recipient(s)'
            . ($event ? ' [' . $event['name'] . ']' : ''));
        $flash = t('ml_admin_sent', $sent);
        $draft = ['audience' => $draft['audience'], 'subject' => '', 'body' => ''];
    }
}

// Live counts so the admin can see the size of each audience before sending.
$counts = [];
foreach ($AUDIENCES as $a) {
    $counts[$a] = count(mailing_audience($a, $eventId));
}

$tab_body = tpl_capture('admin_mailing', [
    'draft'     => $draft,
    'counts'    => $counts,
    'audiences' => $AUDIENCES,
    'enabled'   => mailing_enabled(),
    'error'     => $error,
    'event'     => $event,
    // The selector is skipped entirely when there is nothing to choose between.
    'events'    => $activeEvents,
    'event_id'  => $eventId,
    'csrf'      => csrf_field(),
]);
