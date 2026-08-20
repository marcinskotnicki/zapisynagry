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
        /* HARDEN THE SESSION COOKIE BEFORE STARTING, not after — the cookie is
         * emitted by session_start() and cannot be changed afterwards.
         *
         * The remember-me and CSRF cookies have always set these flags
         * explicitly; the session cookie itself was left to php.ini, which on
         * shared hosting — this app's usual home — frequently ships with
         * HttpOnly off. That makes the session id readable by any script on
         * the page, which is the whole prize behind an XSS.
         *
         * Secure only on HTTPS, matching the other cookies: setting it on a
         * plain-HTTP install would mean the browser never sends the cookie and
         * nobody could stay logged in at all. */
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
    // Shared hosting garbage-collects session files aggressively (often after
    // ~24 minutes idle), which is why logins used to evaporate. If the session
    // no longer knows the user but the browser carries a valid remember-me
    // cookie, transparently rebuild the login from it.
    if (empty($_SESSION['user_id'])) {
        auth_remember_check();
    }
}

/**
 * Attempt a login. Returns true on success (and sets the session), the string
 * 'blocked' when the credentials are right but the account is blocked, and
 * false if the email is unknown or the password is wrong.
 *
 * SECURITY: the same false is returned for "no such email" and "wrong password"
 * so the form can't be used to discover which emails have accounts. 'blocked'
 * is only revealed AFTER a correct password, so it leaks nothing to strangers.
 *
 * NOTE FOR CALLERS: compare with === true — 'blocked' is truthy!
 *
 * @param string $email
 * @param string $password  Plain text from the form; compared via bcrypt.
 * @return bool|string  true | 'blocked' | false
 */
function auth_login($email, $password) {
    $user = db_one('SELECT * FROM users WHERE email = ?', [trim($email)]);
    if ($user && password_verify($password, $user['password_hash'])) {
        if ((int)($user['is_blocked'] ?? 0) === 1) {
            return 'blocked';                      // right password, blocked account
        }
        /* Right password, but the account has not been let in yet — waiting on
         * a verification link or on an admin. Told apart from a wrong password
         * on purpose: they typed it correctly and deserve to know what is
         * actually holding them up. Safe to say, because they have just proved
         * the password. */
        if (!account_is_usable($user)) {
            return 'unverified';
        }
        // Regenerate the id on privilege change to thwart session fixation, and
        // mint a fresh CSRF token to match (it outlives the session otherwise —
        // see the mirror cookie in csrf_token()).
        session_regenerate_id(true);
        csrf_rotate();
        $_SESSION['user_id'] = (int)$user['id'];
        auth_remember_issue((int)$user['id']);     // persistent login (option-gated)
        return true;
    }
    return false;
}

/**
 * How a new account becomes usable: 'auto' | 'email' | 'admin'.
 *
 * Anything unrecognised reads as 'auto' — the behaviour from before the
 * setting existed. A garbled option must not lock a club out of its own site.
 *
 * @return string
 */
function account_activation_mode() {
    $m = (string)opt('account_activation', 'auto');
    return in_array($m, ['auto', 'email', 'admin'], true) ? $m : 'auto';
}

/**
 * Does a NEW account start out usable?
 *
 * Only under 'auto'. Both other policies exist precisely to hold it back.
 *
 * @return bool
 */
function account_starts_verified() {
    return account_activation_mode() === 'auto';
}

/**
 * Does a new account made through GOOGLE start out usable?
 *
 * Under 'email' it does: the point of that policy is proving the address
 * belongs to whoever registered, and Google has already done exactly that —
 * asking them to prove it twice is friction with nothing behind it.
 *
 * Under 'admin' it does NOT. That policy is not about the address at all; it
 * is about an admin deciding who joins, and a Google sign-in says nothing
 * about that.
 *
 * @return bool
 */
function account_google_starts_verified() {
    return account_activation_mode() !== 'admin';
}

/**
 * May this account be signed in to?
 *
 * Checked on EVERY route in, not just the password form — an unverified
 * account that could still get in through Google would make the whole setting
 * decorative.
 *
 * @param array $user
 * @return bool
 */
function account_is_usable(array $user) {
    if (!empty($user['is_blocked'])) return false;
    // Missing column on a half-upgraded install reads as verified, matching the
    // schema default: the failure direction has to be "keeps working".
    return !array_key_exists('is_verified', $user) || (int)$user['is_verified'] === 1;
}

/**
 * Tell the admins that somebody is waiting to be let in.
 *
 * Only under the 'admin' policy, where nothing else would tell them — the
 * member cannot act, so if this does not arrive the account simply sits there
 * unnoticed until an admin happens to open the users tab.
 *
 * Sent to every admin, because "the admin" is not a single person at a club
 * and whoever reads mail first should be able to act.
 *
 * @param string $name
 * @param string $email
 * @return void
 */
function account_notify_admins_pending($name, $email) {
    if (!opt_bool('send_emails')) return;
    require_once __DIR__ . '/mail.php';

    $link = rtrim(site_base_url(), '/') . '/admin.php?tab=users';
    foreach (db_all('SELECT email FROM users WHERE is_admin = 1 AND is_blocked = 0') as $a) {
        if (trim((string)$a['email']) === '') continue;
        send_mail($a['email'], t('verify_admin_subject'),
                  t('verify_admin_body', $name, $email, $link));
    }
}

/**
 * Confirm an account from the link in its verification email.
 *
 * The token is CLEARED on success, so a forwarded or logged link is inert.
 *
 * @param string $token
 * @return array|null  The account, or null.
 */
function account_verify_by_token($token) {
    $token = trim((string)$token);
    if ($token === '') return null;

    $user = db_one('SELECT * FROM users WHERE verify_token = ?', [$token]);
    if (!$user) return null;

    db_run('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?', [(int)$user['id']]);
    return db_one('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
}

/**
 * Delete an account WITHOUT deleting what that person did.
 *
 * Games they proposed, seats they took, votes they cast and comments they left
 * all stay exactly where they are, under the same name — as though they had
 * taken part without ever registering. An event that already happened must not
 * quietly rewrite itself because somebody left the club.
 *
 * That works because every one of those tables already stores a NAME beside
 * its user_id: the account was only ever a link to a person, never the record
 * of what they did. Detaching the link is enough, and each row keeps the name
 * it was created with.
 *
 * What DOES go with the account is anything that only makes sense while it
 * exists: login tokens, and any linked third-party sign-in (that one by
 * cascade). Their own library entries go too — a library belongs to a member,
 * and there is no un-registered version of one to leave behind.
 *
 * @param int $userId
 * @return void
 */
function user_delete_keeping_history($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) return;

    db()->beginTransaction();
    try {
        // Detach, keeping the name each row already carries.
        db_run('UPDATE games      SET brings_user_id  = NULL WHERE brings_user_id  = ?', [$userId]);
        db_run('UPDATE games      SET added_by_user_id = NULL WHERE added_by_user_id = ?', [$userId]);
        db_run('UPDATE players    SET user_id = NULL WHERE user_id = ?', [$userId]);
        db_run('UPDATE polls      SET proposer_user_id = NULL WHERE proposer_user_id = ?', [$userId]);
        db_run('UPDATE poll_votes SET user_id = NULL WHERE user_id = ?', [$userId]);
        db_run('UPDATE comments   SET user_id = NULL WHERE user_id = ?', [$userId]);

        // Things that only mean anything while the account does.
        db_run('DELETE FROM auth_tokens   WHERE user_id = ?', [$userId]);
        db_run('DELETE FROM library_games WHERE user_id = ?', [$userId]);

        // user_identities goes by cascade; see its foreign key.
        db_run('DELETE FROM users WHERE id = ?', [$userId]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}

/**
 * Log out the current user.
 * Clears all session data AND rotates the id, so the old session cookie can't
 * be replayed. Also revokes THIS device's remember token (other devices where
 * the same account is logged in keep their own tokens).
 * @return void
 */
function auth_logout() {
    auth_remember_forget();
    csrf_rotate();          // the token is cookie-backed now, so clear it explicitly
    $_SESSION = [];
    session_regenerate_id(true);
}

/* =============================================================================
 *  PERSISTENT LOGINS ("remember me")
 * -----------------------------------------------------------------------------
 *  PHP sessions alone don't survive shared hosting: the server's session GC
 *  wipes idle session files within minutes. So a login also issues a long-lived
 *  cookie holding a random token; the database stores only sha256(token), one
 *  row per DEVICE (auth_tokens). When a request arrives with a dead session but
 *  a valid cookie, auth_init() silently logs the user back in — to the person
 *  it simply looks like they stayed logged in.
 *
 *  Lifetime comes from the 'login_days' option (admin-editable; 0 disables the
 *  mechanism entirely = browser-session logins only). Expiry SLIDES: every
 *  restore pushes the deadline forward, so someone who checks the page even
 *  occasionally stays logged in until they log out manually.
 *
 *  Security properties: cookie is HttpOnly + SameSite=Lax (+ Secure on HTTPS);
 *  the DB never holds raw tokens; logout revokes the presented token only;
 *  expired rows are purged opportunistically on new logins.
 * ============================================================================= */

/**
 * Issue a fresh remember token for a user and set its cookie.
 * No-op when 'login_days' is 0. Returns the raw token (used by tests).
 * @param int $userId
 * @return string|null  The raw token, or null when the feature is off.
 */
function auth_remember_issue($userId) {
    $days = opt_int('login_days');
    if ($days <= 0) return null;                   // feature disabled: session-only logins
    $raw = bin2hex(random_bytes(32));              // 64 hex chars of CSPRNG
    $exp = time() + $days * 86400;
    db_run('INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (?,?,?)',
           [$userId, hash('sha256', $raw), date('Y-m-d H:i:s', $exp)]);
    auth_remember_setcookie($raw, $exp);
    // Housekeeping while we're here: expired rows serve no one.
    db_run('DELETE FROM auth_tokens WHERE expires_at <= ?', [date('Y-m-d H:i:s')]);
    return $raw;
}

/**
 * Try to restore a login from the remember cookie (called by auth_init when the
 * session doesn't know the user). On success also SLIDES the expiry forward —
 * both in the DB row and the cookie — so active accounts never lapse.
 * @return void
 */
function auth_remember_check() {
    $raw = (string)($_COOKIE['remember'] ?? '');
    if ($raw === '') return;
    $row = db_one('SELECT * FROM auth_tokens WHERE token_hash = ? AND expires_at > ?',
                  [hash('sha256', $raw), date('Y-m-d H:i:s')]);
    if (!$row) return;                             // unknown or expired -> stay logged out
    session_regenerate_id(true);                   // fresh session id for the fresh login
    $_SESSION['user_id'] = (int)$row['user_id'];
    $exp = time() + max(1, opt_int('login_days')) * 86400;
    db_run('UPDATE auth_tokens SET expires_at = ? WHERE id = ?',
           [date('Y-m-d H:i:s', $exp), $row['id']]);
    auth_remember_setcookie($raw, $exp);           // same token, later expiry
}

/**
 * Revoke the remember token the browser presented (if any) and clear the
 * cookie. Called on logout. Other devices' tokens are untouched.
 * @return void
 */
function auth_remember_forget() {
    $raw = (string)($_COOKIE['remember'] ?? '');
    if ($raw !== '') {
        db_run('DELETE FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $raw)]);
    }
    auth_remember_setcookie('', time() - 3600);    // expire it client-side too
    unset($_COOKIE['remember']);
}

/**
 * The one place cookie attributes live: HttpOnly (no JS access), SameSite=Lax
 * (sent on normal navigation, not cross-site POSTs), Secure when on HTTPS.
 * @param string $value
 * @param int    $expires  Unix timestamp.
 * @return void
 */
function auth_remember_setcookie($value, $expires) {
    setcookie('remember', $value, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
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
    // A block takes effect IMMEDIATELY: an existing session (or remember-me
    // rebuild) of a blocked account resolves to "nobody logged in".
    if ($cache && (int)($cache['is_blocked'] ?? 0) === 1) {
        $cache = null;
    }
    return $cache;
}

/**
 * May the current visitor use the messaging (envelope) feature?
 * Two admin switches stack: 'allow_messaging' turns the whole feature on/off,
 * and 'allow_guest_messaging' decides whether it's open to everyone or only to
 * logged-in accounts (the default — guests' messages would otherwise be easy
 * anonymous spam). Templates use this ONE gate for envelope visibility and
 * message.php uses it as its access check, so the two can never disagree.
 * @return bool
 */
function messaging_allowed() {
    if (!opt_bool('allow_messaging')) return false;
    return is_logged_in() || opt_bool('allow_guest_messaging');
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

// Name and lifetime of the mirror cookie that keeps the token alive across
// session garbage collection (see csrf_token()).
const CSRF_COOKIE_NAME = 'csrf';
const CSRF_COOKIE_DAYS = 30;

/**
 * The session CSRF token, generated on first use.
 * 16 random bytes -> 32 hex chars.
 *
 * WHY THE MIRROR COOKIE: shared hosting garbage-collects session files
 * aggressively (often after ~24 idle minutes). That used to wipe the token
 * while the visitor still had a form open in a tab, so submitting it hit the
 * "invalid or expired form token" wall — even though the remember-me cookie had
 * quietly logged them back in. The token is therefore ALSO written to a
 * long-lived cookie and restored from it when the session no longer has one, so
 * an open form keeps working for as long as the cookie lives.
 *
 * SECURITY: this is the standard "double submit cookie" property — the cookie is
 * HttpOnly and SameSite=Lax, so a malicious site can neither read the token nor
 * script it into a forged form. What an attacker must know to forge a POST is
 * unchanged: the token value itself.
 *
 * @return string
 */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $fromCookie = (string)($_COOKIE[CSRF_COOKIE_NAME] ?? '');
        // Only adopt a cookie that LOOKS like one of ours; anything else (a
        // truncated or hand-edited value) gets replaced with a fresh token.
        $_SESSION['csrf'] = preg_match('/^[a-f0-9]{32}$/', $fromCookie)
            ? $fromCookie
            : bin2hex(random_bytes(16));
    }
    csrf_cookie_refresh($_SESSION['csrf']);
    return $_SESSION['csrf'];
}

/**
 * (Re)write the mirror cookie, sliding its expiry forward on every request so an
 * account in regular use never lapses.
 *
 * Guarded twice: once per request (a static flag — csrf_field() can be called
 * several times per page) and only while headers can still be sent, since the
 * footer renders a form long after output has begun.
 *
 * @param string $token
 * @return void
 */
function csrf_cookie_refresh($token) {
    static $done = false;
    if ($done || headers_sent()) return;
    $done = true;
    setcookie(CSRF_COOKIE_NAME, $token, [
        'expires'  => time() + CSRF_COOKIE_DAYS * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    $_COOKIE[CSRF_COOKIE_NAME] = $token;   // keep this request self-consistent
}

/**
 * Throw the current token away (session copy AND cookie) so the next call to
 * csrf_token() mints a fresh one. Called on login and logout: the token should
 * not outlive a change of who is logged in.
 * @return void
 */
function csrf_rotate() {
    unset($_SESSION['csrf']);
    if (!headers_sent()) {
        setcookie(CSRF_COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }
    unset($_COOKIE[CSRF_COOKIE_NAME]);
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
    if (hash_equals(csrf_token(), $sent)) return;

    // A mismatch is now rare (the mirror cookie survives session GC), but a
    // genuinely stale tab can still land here — so show a real page with a way
    // out instead of a blank screen the visitor has to back-button off.
    http_response_code(400);

    // Offer "back to the form" only when the referrer is our OWN page: an
    // attacker-controlled Referer must never become a link we render.
    $back = '';
    $ref  = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref !== '' && isset($_SERVER['HTTP_HOST'])) {
        $parts = parse_url($ref);
        if (!empty($parts['host']) && strcasecmp($parts['host'], preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) === 0) {
            $back = $ref;
        }
    }

    tpl_render('header', ['page_title' => t('csrf_error_title')]);
    tpl_render('csrf_error', ['back' => $back]);
    tpl_render('footer');
    exit;
}
