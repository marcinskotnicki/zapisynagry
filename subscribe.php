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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();

$email   = trim($_POST['ml_email'] ?? '');
$consent = mailing_gdpr_text();
$day     = (int)($_POST['day'] ?? 1);

if (!email_valid($email)) {
    flash_set(t('ml_err_email'));
} elseif ($consent !== '' && empty($_POST['ml_consent'])) {
    // Only demanded when the admin actually configured wording to consent to.
    flash_set(t('ml_err_consent'));
} elseif (captcha_required() && !captcha_verify()) {
    flash_set(t('error_captcha'));
} else {
    // The consent text is snapshotted with the row, so a later edit to the
    // admin's wording can't rewrite what this person agreed to.
    $sub = mailing_subscribe((int)$event['id'], $email, $consent);
    if ($sub === null) {
        flash_set(t('ml_err_email'));
    } else {
        log_action('mailing_subscribe', 'Event #' . (int)$event['id']);
        flash_set(t('ml_subscribed'));
    }
}

redirect('index.php?day=' . $day . '#mailing');
