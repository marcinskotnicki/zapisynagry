<?php
/* =============================================================================
 *  inc/google.php — "Sign in with Google", optional and per-club.
 * -----------------------------------------------------------------------------
 *  STRICTLY ADDITIVE. Email and password stays the main way in and keeps
 *  working whatever this is set to — not everyone has a Google account, and a
 *  club that never touches this sees no change at all.
 *
 *  NO LIBRARY. OAuth 2.0 here is: send them to Google, get a code back, POST
 *  the code for tokens, read the identity out of the id_token. That is two
 *  requests and a base64 decode, and this project has no composer — pulling in
 *  a dependency to save fifty lines would cost more than it saves.
 *
 *  CREDENTIALS ARE PER CLUB. Google matches redirect URIs literally, with no
 *  wildcards, so one shared client would mean every club's callback URL being
 *  registered centrally. Each club uses its own Google Cloud project instead:
 *  self-service, and no club's logins depend on another's project.
 *
 *  WHAT THIS FILE WILL NOT DO:
 *    - log anybody in on an UNVERIFIED address. Google says whether it has
 *      verified the mailbox; without that, "sign in with Google" would mean
 *      "anyone who can make Google assert an address owns that account".
 *    - link into an ADMIN account by itself. See google_link_policy().
 * ============================================================================= */

/* Google's endpoints. Constants rather than options: an admin has no reason to
 * point these elsewhere, and a settable token endpoint is a credential leak
 * waiting to happen. */
const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

/**
 * Is the feature switched on AND actually configured?
 *
 * Both halves, deliberately. A club that ticks the box and has not yet pasted
 * its credentials gets no button, rather than one that fails on click — the
 * option is an intention, this is whether it can work.
 *
 * @return bool
 */
function google_login_enabled() {
    return opt_bool('google_login')
        && trim((string)opt('google_client_id')) !== ''
        && trim((string)opt('google_client_secret')) !== '';
}

/**
 * Where Google sends people back to. Must match a redirect URI registered in
 * the club's own Google Cloud project, character for character.
 *
 * @return string  '' when the site URL cannot be determined (CLI, cron).
 */
function google_redirect_uri() {
    $base = site_base_url();
    return $base === '' ? '' : rtrim($base, '/') . '/google_auth.php';
}

/**
 * The URL to send somebody to, plus the state to remember.
 *
 * `state` is an unguessable value echoed back by Google and compared on
 * return: without it, an attacker can hand somebody a callback URL of their
 * choosing and have the victim's browser complete a login the victim did not
 * start. Stored in the session, not in a cookie, so it cannot be planted.
 *
 * @return array ['url' => string, 'state' => string] — url '' if unusable.
 */
function google_auth_start() {
    $redirect = google_redirect_uri();
    if (!google_login_enabled() || $redirect === '') return ['url' => '', 'state' => ''];

    $state = bin2hex(random_bytes(16));
    $url = GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id'     => trim((string)opt('google_client_id')),
        'redirect_uri'  => $redirect,
        'response_type' => 'code',
        // Only what is needed to identify somebody. Asking for less keeps this
        // out of Google's sensitive-scope review entirely.
        'scope'         => 'openid email profile',
        'state'         => $state,
        // Re-prompt rather than silently reusing a stale grant, so somebody on
        // a shared machine is not signed in as whoever used it last.
        'prompt'        => 'select_account',
    ]);
    return ['url' => $url, 'state' => $state];
}

/**
 * Decode the payload of a JWT WITHOUT verifying its signature.
 *
 * SAFE ONLY HERE, and only because of where this token came from: it was
 * fetched directly from Google's token endpoint over HTTPS in the call below,
 * not accepted from the browser. That direct fetch is what authenticates it.
 * Never use this on a token that arrived from a client.
 *
 * @param string $jwt
 * @return array|null
 */
function google_decode_id_token($jwt) {
    $parts = explode('.', (string)$jwt);
    if (count($parts) !== 3) return null;
    // JWT uses base64url: '-' and '_' instead of '+' and '/', padding stripped.
    $payload = strtr($parts[1], '-_', '+/');
    $payload = str_pad($payload, (int)(ceil(strlen($payload) / 4) * 4), '=', STR_PAD_RIGHT);
    $json = base64_decode($payload, true);
    if ($json === false) return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Exchange the code Google sent for the person's identity.
 *
 * NETWORK. Returns null on any failure — an unreachable Google means "could not
 * sign in", never a partial or guessed identity.
 *
 * @param string $code
 * @return array|null  ['sub','email','email_verified','name'] or null.
 */
function google_exchange_code($code) {
    $redirect = google_redirect_uri();
    if (!google_login_enabled() || $redirect === '' || trim((string)$code) === '') return null;
    if (!function_exists('curl_init')) return null;

    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code'          => (string)$code,
        'client_id'     => trim((string)opt('google_client_id')),
        'client_secret' => trim((string)opt('google_client_secret')),
        'redirect_uri'  => $redirect,
        'grant_type'    => 'authorization_code',
    ]));
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $http !== 200) return null;
    $token = json_decode((string)$body, true);
    if (!is_array($token) || empty($token['id_token'])) return null;

    $claims = google_decode_id_token($token['id_token']);
    if (!is_array($claims) || empty($claims['sub'])) return null;

    return [
        'sub'            => (string)$claims['sub'],
        'email'          => strtolower(trim((string)($claims['email'] ?? ''))),
        // Google sends this as a real boolean or the string "true" depending on
        // the path; both must count, and anything else must not.
        'email_verified' => ($claims['email_verified'] ?? false) === true
                         || ($claims['email_verified'] ?? '') === 'true',
        'name'           => trim((string)($claims['name'] ?? '')),
    ];
}

/**
 * Find the account already linked to this Google identity, if any.
 *
 * @param string $sub
 * @return array|null  The user row.
 */
function google_find_linked_user($sub) {
    $sub = trim((string)$sub);
    if ($sub === '') return null;
    return db_one(
        'SELECT u.* FROM users u
           JOIN user_identities i ON i.user_id = u.id
          WHERE i.provider = ? AND i.provider_uid = ?',
        ['google', $sub]
    );
}

/**
 * May this Google identity be linked to this existing account automatically?
 *
 * The decision, pure and on its own, because it is the security of the whole
 * feature in one place:
 *
 *   - the address must be VERIFIED by Google. Otherwise this reduces to
 *     "anyone who can make Google assert an address owns that account".
 *   - an ADMIN account is never linked automatically. The blast radius is
 *     different in kind, so an admin links it deliberately, from inside their
 *     own session, at the cost of one click made once.
 *
 * @param array $user      The existing account matched by email.
 * @param array $identity  From google_exchange_code().
 * @return bool
 */
function google_may_autolink(array $user, array $identity) {
    /* NOT NEGOTIABLE, whatever the options say: Google must have verified the
     * address. Everything else here is about how much that verification is
     * trusted to be worth; without it there is nothing to weigh at all. */
    if (empty($identity['email_verified'])) return false;

    /* An admin account only when a club has said so. The default is that an
     * admin links Google deliberately, from inside their own session — the
     * address is verified either way, but an admin account is worth more than
     * a member's and the cost of the safe default is one click, made once. */
    if (!empty($user['is_admin']) && !opt_bool('google_admin_autolink')) return false;

    return true;
}

/**
 * Record the link. Idempotent: signing in again does not pile up rows.
 *
 * @param int    $userId
 * @param array  $identity
 * @return void
 */
function google_link_identity($userId, array $identity) {
    db_run(
        'INSERT OR IGNORE INTO user_identities (user_id, provider, provider_uid, email)
         VALUES (?,?,?,?)',
        [(int)$userId, 'google', (string)$identity['sub'], (string)($identity['email'] ?? '')]
    );
}

/**
 * Does this account have a usable password?
 *
 * An account created through Google has none, and stores '' rather than a hash.
 * password_verify() against '' is always false, so an empty value can never let
 * anybody in — which is why '' is safe as the marker and no NOT NULL constraint
 * had to be loosened on live installs.
 *
 * @param array $user
 * @return bool
 */
function user_has_password(array $user) {
    return trim((string)($user['password_hash'] ?? '')) !== '';
}

/**
 * How many people could ONLY get in through Google?
 *
 * Shown to an admin before they switch the feature off, because doing so locks
 * every one of them out and nothing else on the screen would say so.
 *
 * @return int
 */
function google_only_user_count() {
    return (int)db_val(
        "SELECT COUNT(DISTINCT u.id) FROM users u
           JOIN user_identities i ON i.user_id = u.id
          WHERE i.provider = 'google'
            AND (u.password_hash IS NULL OR TRIM(u.password_hash) = '')"
    );
}
