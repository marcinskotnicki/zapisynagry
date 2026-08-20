<?php
/* =============================================================================
 *  subscribe.php — join the current event's mailing list.
 * -----------------------------------------------------------------------------
 *  POST target for the box under the timeline. Validates, records, and bounces
 *  straight back to the event page with a flash — there is no separate
 *  "subscribed!" screen to click through.
 *
 *  A PUBLIC, unauthenticated form that stores an address, so it goes through the
 *  same captcha gate as the other public forms.
 *
 *  Deliberately NOT idempotent-by-error: re-submitting an address that's already
 *  on the list reports success rather than "you are already subscribed". The
 *  difference between the two replies would tell any passer-by whether a given
 *  address is on the list, which isn't theirs to know.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/mailing.php';
require __DIR__ . '/inc/captcha.php';

$event = current_event();
if (!$event || (int)$event['is_archived'] === 1 || !mailing_enabled()) {
    redirect('index.php');
}

/* ---- Confirming an address (GET, from the link in the email) ---------------
 *
 * A GET on purpose: it is a link somebody clicks in their mail client, and
 * those cannot POST. Safe to be a GET because the token is the whole
 * authorisation — it is unguessable, one-shot, and confirms only the one
 * subscription it belongs to.
 *
 * Handled before the POST guard below, which would otherwise bounce it. */
if (isset($_GET['confirm'])) {
    $sub = mailing_confirm((string)$_GET['confirm']);
    // A used or unknown token says the same thing as a good one. There is
    // nothing useful to tell a stranger holding a bad link, and a distinct
    // reply would confirm whether an address is on the list.
    flash_set($sub ? t('ml_confirmed') : t('ml_confirm_bad'), $sub ? 'ok' : 'error');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();
/* THE ONE PUBLIC FORM THAT HAD NO BOT GATE, and the one where its absence is
 * worst: it writes a third party's address into a list the venue will later
 * broadcast to. Nobody has to prove they own the address, so without this the
 * form could be scripted to sign strangers up and the venue's own newsletter
 * became the delivery mechanism for it.
 *
 * The captcha below is not a substitute: it is off unless an admin switched it
 * on, and it defaults to off, so a stock install had nothing at all here. */
antibot_check('form');

$email   = trim($_POST['ml_email'] ?? '');
$consent = mailing_gdpr_text();
$day     = (int)($_POST['day'] ?? 1);

if (!email_valid($email)) {
    flash_set(t('ml_err_email'));
} elseif ($consent !== '' && empty($_POST['ml_consent'])) {
    // Only demanded when the admin actually configured wording to consent to.
    flash_set(t('ml_err_consent'));
} elseif (captcha_required('mailing') && !captcha_verify('mailing')) {
    flash_set(t('error_captcha'), 'error');
} else {
    // The consent text is snapshotted with the row, so a later edit to the
    // admin's wording can't rewrite what this person agreed to.
    $sub = mailing_subscribe((int)$event['id'], $email, $consent);
    if ($sub === null) {
        flash_set(t('ml_err_email'));
    } elseif (!empty($sub['confirm_token'])) {
        /* Awaiting confirmation: send the link and say so. The address gets
         * nothing else until it is confirmed — see MAILING_CONFIRMED. */
        $link = rtrim(site_base_url(), '/') . '/subscribe.php?confirm=' . rawurlencode($sub['confirm_token']);
        send_mail($email, t('ml_confirm_subject'), t('ml_confirm_body', $link));
        log_action('mailing_subscribe', 'Event #' . (int)$event['id'] . ' (awaiting confirmation)');
        flash_set(t('ml_confirm_sent'));
    } else {
        log_action('mailing_subscribe', 'Event #' . (int)$event['id']);
        flash_set(t('ml_subscribed'));
    }
}

redirect('index.php?day=' . $day . '#mailing');
