<?php
/* =============================================================================
 *  verify.php — finishes an email-confirmed registration.
 * -----------------------------------------------------------------------------
 *  A GET on purpose: it is a link clicked in a mail client, and those cannot
 *  POST. Safe as a GET because the token IS the authorisation — unguessable,
 *  one-shot, and it confirms only the one account it belongs to.
 *
 *  Says the same thing for an unknown token as for an already-used one. There
 *  is nothing useful to tell a stranger holding a bad link, and a distinct
 *  reply would confirm whether an account exists.
 * ============================================================================= */

require __DIR__ . '/inc/bootstrap.php';

$user = account_verify_by_token((string)($_GET['token'] ?? ''));
if ($user) {
    log_action('account_verified', 'User #' . (int)$user['id'] . ' confirmed their address');
    flash_set(t('verify_ok'));
} else {
    flash_set(t('verify_bad'), 'error');
}
redirect('login.php');
