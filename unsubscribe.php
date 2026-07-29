<?php
/* =============================================================================
 *  unsubscribe.php — leave a mailing list, in one click.
 * -----------------------------------------------------------------------------
 *  Reached from the link in every mailing-list message. NO login and NO
 *  confirmation step: an unsubscribe that takes more than one click isn't one.
 *  The token is 32 random hex chars, which is what stands in for authentication.
 *
 *  GET is used deliberately even though it changes state — that's the only thing
 *  a link in an email can do, and the alternative (a page with a button) is the
 *  friction this is meant to avoid. Nothing destructive beyond removing the row
 *  hangs off it.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/mailing.php';

$email = mailing_unsubscribe($_GET['token'] ?? '');
if ($email !== null) {
    log_action('mailing_unsubscribe', 'via token');
}

tpl_render('header', ['page_title' => t('ml_unsub_title')]);
tpl_render('unsubscribed', ['email' => $email]);
tpl_render('footer');
