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

$event   = current_event();
$eventId = (int)($event['id'] ?? 0);

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
        log_action('mailing_broadcast', $draft['audience'] . ' -> ' . $sent . ' recipient(s)');
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
    'csrf'      => csrf_field(),
]);
