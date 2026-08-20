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

/* THE VISITOR ALWAYS SEES THE SAME THING — distinguishing "no such account"
 * from "that address is taken" would let a stranger test which addresses are
 * registered here. But the ADMIN needs to know which of seven things went
 * wrong, so the reason goes to the audit log, which only they can read.
 *
 * Without this, every failure was one identical sentence and the only way to
 * tell them apart was to read the source. */
$fail = function ($why) {
    log_action('google_login_failed', $why);
    flash_set(t('google_failed'), 'error');
    redirect('login.php');
};

/* ---- 1. Starting out ------------------------------------------------------ */
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $start = google_auth_start();
    if ($start['url'] === '') $fail('cannot build the authorise URL (is site address set?)');
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
if ($known === '' || !hash_equals($known, $state)) {
    /* Nearly always the session being lost between leaving for Google and
     * coming back — most often because the site address configured here is on
     * a different host from the one being browsed (www versus not), so the
     * session cookie is not sent on the return trip. */
    $fail($known === '' ? 'no state in session (session lost on the way back?)' : 'state mismatch');
}

$identity = google_exchange_code((string)($_GET['code'] ?? ''));
if (!$identity) $fail('token exchange with Google failed (client secret, redirect URI, or no curl)');

/* An address Google has not verified proves nothing about who is holding it,
 * and this is the whole basis on which an account gets matched below. */
if (empty($identity['email_verified']) || $identity['email'] === '') $fail('Google did not return a verified email address');

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
        if (!google_may_autolink($existing, $identity)) {
            $fail(!empty($existing['is_admin'])
                ? 'admin account, and google_admin_autolink is off'
                : 'auto-link refused');
        }
        google_link_identity((int)$existing['id'], $identity);
        $user = $existing;

    } else {
        // Nobody here yet: create the account. No password is set, and none is
        // invented — '' can never satisfy password_verify(), so the only way in
        // is Google until they choose to add one.
        if (opt('registration_mode') !== 'registration') $fail('no account for that address and self-registration is off');

        $name = $identity['name'] !== '' ? $identity['name'] : strtok($identity['email'], '@');
        db_run(
            'INSERT INTO users (email, password_hash, display_name) VALUES (?,?,?)',
            [$identity['email'], '', mb_substr($name, 0, 60)]
        );
        $newId = (int)db()->lastInsertId();
        google_link_identity($newId, $identity);
        log_action('google_register', 'User #' . $newId);
        $user = db_one('SELECT * FROM users WHERE id = ?', [$newId]);
        if (!$user) $fail('account row vanished right after being created');
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
