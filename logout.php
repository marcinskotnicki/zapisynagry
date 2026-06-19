<?php
/* =============================================================================
 *  logout.php — end the session and return to the front page.
 * -----------------------------------------------------------------------------
 *  No form/confirm: hitting this URL logs out. auth_logout() clears the session
 *  and rotates the id so the old cookie can't be replayed.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';

if (is_logged_in()) {
    log_action('logout', 'User logged out');     // record before we drop the session
}
auth_logout();
redirect('index.php');
