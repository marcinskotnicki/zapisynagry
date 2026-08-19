<?php
/* =============================================================================
 *  google_auth.php — starts and finishes "Sign in with Google".
 * -----------------------------------------------------------------------------
 *  Two jobs on one URL, because Google needs the redirect URI registered
 *  literally and one is easier for a club admin to paste than two:
 *
 *    no query      → send the visitor to Google.
 *    ?code=&state= → Google sent them back; finish the sign-in.
 *
 *  GUEST-ONLY MODE HAS NO FRONTEND LOGIN AT ALL, so this refuses outright
 *  there — the same rule register.php follows, applied to the other door into
 *  the same building.
 *
 *  EVERY FAILURE LOOKS THE SAME to the visitor: "could not sign in with
 *  Google". Distinguishing "no such account" from "that address is taken"
 *  would let a stranger test which addresses are registered here.
 * ============================================================================= */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/google.php';

// Not configured, or switched off: nothing here to use.
if (!google_login_enabled()) redirect('login.php');
// Guest-only clubs have no member accounts to sign in to.
if (opt('registration_mode') === 'guest_only') redirect('index.php');
// Already signed in — nothing to do, and re-running the dance would only
// confuse whoever is holding the session.
if (is_logged_in()) redirect('user.php');

$fail = function () {
    flash_set(t('google_failed'), 'error');
    redirect('login.php');
};

/* ---- 1. Starting out ------------------------------------------------------ */
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $start = google_auth_start();
    if ($start['url'] === '') $fail();
    // The state lives in the SESSION, never in a cookie: a value the browser
    // carries is a value an attacker can plant, and this is the one thing
    // proving the login was started from here.
    $_SESSION['google_state'] = $start['state'];
    redirect($start['url']);
}

/* ---- 2. Coming back ------------------------------------------------------- */
// The visitor declined at Google's screen, or Google refused. Not an error
// worth alarming anybody about — they simply chose not to.
if (isset($_GET['error'])) redirect('login.php');

$state = (string)($_GET['state'] ?? '');
$known = (string)($_SESSION['google_state'] ?? '');
// One-shot: cleared before anything else can go wrong, so a replayed callback
// has no state left to match.
unset($_SESSION['google_state']);
if ($known === '' || !hash_equals($known, $state)) $fail();

$identity = google_exchange_code((string)($_GET['code'] ?? ''));
if (!$identity) $fail();

/* An address Google has not verified proves nothing about who is holding it,
 * and this is the whole basis on which an account gets matched below. */
if (empty($identity['email_verified']) || $identity['email'] === '') $fail();

/* ---- 3. Who is this? ------------------------------------------------------ */
$user = google_find_linked_user($identity['sub']);

if (!$user) {
    // No link yet. Is there an account with this address to attach it to?
    $existing = db_one('SELECT * FROM users WHERE LOWER(email) = ?', [$identity['email']]);

    if ($existing) {
        /* An ADMIN account is never linked automatically — they link it from
         * inside their own session instead. Telling the visitor exactly that
         * would confirm the address belongs to an admin, so this is simply a
         * failure like any other. */
        if (!google_may_autolink($existing, $identity)) $fail();
        google_link_identity((int)$existing['id'], $identity);
        $user = $existing;

    } else {
        // Nobody here yet: create the account. No password is set, and none is
        // invented — '' can never satisfy password_verify(), so the only way in
        // is Google until they choose to add one.
        if (opt('registration_mode') !== 'registration') $fail();

        $name = $identity['name'] !== '' ? $identity['name'] : strtok($identity['email'], '@');
        db_run(
            'INSERT INTO users (email, password_hash, display_name) VALUES (?,?,?)',
            [$identity['email'], '', mb_substr($name, 0, 60)]
        );
        $newId = (int)db()->lastInsertId();
        google_link_identity($newId, $identity);
        log_action('google_register', 'User #' . $newId);
        $user = db_one('SELECT * FROM users WHERE id = ?', [$newId]);
        if (!$user) $fail();
    }
}

// A blocked account is blocked whichever door it arrives at.
if (!empty($user['is_blocked'])) {
    flash_set(t('login_blocked'), 'error');
    redirect('login.php');
}

/* ---- 4. In ---------------------------------------------------------------- */
// Same three steps auth_login() takes, and for the same reasons: a new session
// id on privilege change, a CSRF token that matches it, then the id itself.
session_regenerate_id(true);
csrf_rotate();
$_SESSION['user_id'] = (int)$user['id'];
auth_remember_issue((int)$user['id']);
log_action('google_login', 'User #' . (int)$user['id']);

redirect('user.php');
