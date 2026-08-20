<?php
/* =============================================================================
 *  login.php — log in by email + password.
 * -----------------------------------------------------------------------------
 *  GET shows the form; POST verifies via auth_login() and, on success, sends the
 *  user to a (sanitised) return target. The form carries a `next` value so a
 *  guard like require_login() can bounce the user here and back again.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
// google_login_enabled() decides whether the alternative button is offered.
require __DIR__ . '/inc/google.php';

// Already logged in? Nothing to do here.
if (is_logged_in()) redirect('index.php');

/**
 * Sanitise the post-login redirect target to a local page, to avoid being used
 * as an open redirect. Only "name.php" (optionally with a query string) passes;
 * anything else (a full URL, a path) falls back to index.php.
 * @param string $next
 * @return string
 */
function safe_next($next) {
    $next = (string)$next;
    return preg_match('/^[a-z0-9_]+\.php(\?.*)?$/i', $next) ? $next : 'index.php';
}

$error = null;
// $_REQUEST so `next` works whether it arrives in the query string (GET form) or
// the posted body. It's always run through safe_next() before use.
$next  = safe_next($_REQUEST['next'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    antibot_check('click');
    // NB: strict === true — auth_login() may also return the (truthy!) string
    // 'blocked' when the password was right but the account is blocked.
    $result = auth_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($result === true) {
        log_action('login', 'User logged in');
        redirect($next);                         // success -> where they were headed
    }
    $error = $result === 'blocked'
        ? t('login_blocked')                     // only shown after a CORRECT password
        : t('login_failed');                     // generic (doesn't reveal which field was wrong)
}

/* A message left by somewhere that redirected HERE — a failed Google sign-in
 * is the one that matters, since google_auth.php has no screen of its own.
 *
 * This page never read the flash before, so the message sat in the session
 * until whatever the visitor opened next happened to render one. The reported
 * symptom was a Google error appearing later, on the front page, next to the
 * newsletter box.
 *
 * A POST error from this page's own form wins: it describes what was just
 * typed, which is more immediate than anything carried over. */
if ($error === null) {
    $flashed = flash_get();
    if ($flashed !== '' && $flashed !== null) $error = $flashed;
}

// Render: header + login card + footer.
tpl_render('header', ['page_title' => t('login')]);
tpl_render('login', ['error' => $error, 'next' => $next, 'csrf' => csrf_field()]);
tpl_render('footer');
