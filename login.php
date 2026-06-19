<?php
/* =============================================================================
 *  login.php — log in by email + password.
 * -----------------------------------------------------------------------------
 *  GET shows the form; POST verifies via auth_login() and, on success, sends the
 *  user to a (sanitised) return target. The form carries a `next` value so a
 *  guard like require_login() can bounce the user here and back again.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';

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
    if (auth_login($_POST['email'] ?? '', $_POST['password'] ?? '')) {
        log_action('login', 'User logged in');
        redirect($next);                         // success -> where they were headed
    }
    $error = t('login_failed');                  // generic (doesn't reveal which field was wrong)
}

// Render: header + login card + footer.
tpl_render('header', ['page_title' => t('login')]);
tpl_render('login', ['error' => $error, 'next' => $next, 'csrf' => csrf_field()]);
tpl_render('footer');
