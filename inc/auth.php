<?php
/* =============================================================================
 *  inc/auth.php — sessions, login, and access control.
 * -----------------------------------------------------------------------------
 *  Login is by EMAIL + password (no usernames). Passwords are bcrypt via
 *  password_hash()/password_verify(). The logged-in user's id sits in the
 *  session; current_user() resolves it to a row.
 *
 *  Also home to the CSRF token helpers, since every state-changing POST in the
 *  app should carry one.
 *
 *  TYPICAL USAGE in a controller:
 *    require_login();   // or require_admin();  — guards at the top of the page
 *    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); ... }
 *  In a form template: echo csrf_field();
 * ============================================================================= */

/**
 * Start the session if it isn't already. Called by bootstrap.
 * Guarded so it's harmless to call when a session is already active (e.g. tests
 * that start their own session).
 * @return void
 */
function auth_init() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Attempt a login. Returns true on success (and sets the session), false if
 * the email is unknown or the password is wrong.
 *
 * SECURITY: the same false is returned for "no such email" and "wrong password"
 * so the form can't be used to discover which emails have accounts.
 *
 * @param string $email
 * @param string $password  Plain text from the form; compared via bcrypt.
 * @return bool
 */
function auth_login($email, $password) {
    $user = db_one('SELECT * FROM users WHERE email = ?', [trim($email)]);
    if ($user && password_verify($password, $user['password_hash'])) {
        // Regenerate the id on privilege change to thwart session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        return true;
    }
    return false;
}

/**
 * Log out the current user.
 * Clears all session data AND rotates the id, so the old session cookie can't
 * be replayed.
 * @return void
 */
function auth_logout() {
    $_SESSION = [];
    session_regenerate_id(true);
}

/**
 * The currently logged-in user row, or null. Cached per request so repeated
 * calls don't re-hit the database (templates call this a lot).
 * @return array|null
 */
function current_user() {
    static $cache = false;            // false = not looked up yet (null is a valid result)
    if ($cache !== false) return $cache;

    $id = $_SESSION['user_id'] ?? null;
    $cache = $id ? db_one('SELECT * FROM users WHERE id = ?', [$id]) : null;
    return $cache;
}

/**
 * True if someone is logged in.
 * @return bool
 */
function is_logged_in() {
    return current_user() !== null;
}

/**
 * True if the logged-in user is an admin.
 * @return bool
 */
function is_admin() {
    $u = current_user();
    return $u && (int)$u['is_admin'] === 1;
}

/**
 * Guard for logged-in-only pages: bounce guests to login with a return target.
 * The `next` query param lets login.php send the user back where they started.
 * @return void  (redirects + exits for guests; returns for logged-in users)
 */
function require_login() {
    if (is_logged_in()) return;
    $next = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
    redirect('login.php?next=' . $next);
}

/**
 * Guard for admin-only pages: if not an admin, bounce to login (remembering
 * where we were trying to go) or show a forbidden page.
 *
 * Two distinct cases on purpose:
 *   - logged in but not admin -> 403 Forbidden (no point sending to login).
 *   - not logged in           -> login with a return target.
 * @return void
 */
function require_admin() {
    if (is_admin()) return;

    if (is_logged_in()) {
        // Logged in but not an admin -> genuine "forbidden".
        http_response_code(403);
        exit(t('error_forbidden'));
    }
    // Not logged in -> send to login with a return target.
    $next = urlencode($_SERVER['REQUEST_URI'] ?? 'admin.php');
    redirect('login.php?next=' . $next);
}

/* ---- CSRF -------------------------------------------------------------------
 *  Cross-Site Request Forgery protection. One random token per session, embedded
 *  in every form as a hidden field and verified on POST. If a malicious site
 *  tries to auto-submit a form to us, it can't know the token, so csrf_check()
 *  rejects it. Simple synchroniser-token pattern — sufficient here.
 * --------------------------------------------------------------------------- */

/**
 * The session CSRF token, generated on first use.
 * 16 random bytes -> 32 hex chars. Stable for the life of the session.
 * @return string
 */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/**
 * A ready-to-print hidden input carrying the token.
 * Drop `<?= csrf_field() ?>` inside every <form method="post">.
 * @return string  HTML.
 */
function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Verify a submitted token; abort the request if it's missing or wrong.
 * hash_equals() is a constant-time compare (avoids timing side-channels).
 * Call this at the top of every POST handler, before touching the database.
 * @return void  (exits with HTTP 400 on mismatch)
 */
function csrf_check() {
    $sent = $_POST['csrf'] ?? '';
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Invalid or expired form token. Go back and try again.');
    }
}
