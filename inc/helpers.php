<?php
/* =============================================================================
 *  inc/helpers.php — small shared utilities used across the app.
 * -----------------------------------------------------------------------------
 *  A grab-bag of tiny, dependency-light functions that several pages need:
 *  output escaping, redirects, one-shot "flash" messages, date/time validation,
 *  the "which event are we looking at" lookup, and the audit log writer.
 *  Nothing here holds state except the per-request cache inside current_event().
 * ============================================================================= */

/**
 * HTML-escape for output. Short name because it's used constantly in templates.
 * ALWAYS wrap any value that came from a user or the database in e() before
 * printing it into HTML — this is the app's main defence against XSS.
 *
 * @param mixed $s  Anything stringable.
 * @return string   Safe-to-print HTML text (quotes encoded too, for attributes).
 */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Send a Location redirect and stop.
 * Calls exit so nothing after the redirect ever runs (important: a redirect that
 * keeps executing can leak output or perform unintended writes).
 *
 * @param string $url  Where to send the browser (relative URLs are fine).
 * @return void         Never returns — the script ends here.
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/* ---- One-shot flash messages (survive a PRG redirect) -------------------- *
 * PRG = Post/Redirect/Get: after handling a POST we redirect so a page refresh
 * doesn't resubmit the form. But a redirect throws away any message we'd built.
 * The flash stashes one message in the session, to be read + cleared by the
 * page we land on. Relies on the session already being started (bootstrap does).
 * --------------------------------------------------------------------------- */

/**
 * Stash a message to show after the next redirect.
 * @param string $msg  Already-translated, human-readable text.
 */
function flash_set($msg) {
    $_SESSION['flash'] = $msg;
}

/**
 * Read and clear the stashed flash message (or null).
 * One-shot: reading it removes it, so a later refresh won't show it again.
 * @return string|null
 */
function flash_get() {
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

/**
 * The visitor's IP, best-effort (for the audit log).
 * Not used for security decisions — just so log entries note where an action
 * came from. Empty string if the server didn't provide it (e.g. CLI tests).
 * @return string
 */
function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * True if $s is a real calendar date in YYYY-MM-DD form.
 * The round-trip compare (parse then re-format and check equality) rejects
 * impossible dates like 2026-02-31 that a loose regex would let through.
 * @return bool
 */
function is_valid_date($s) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$s);
    return $d && $d->format('Y-m-d') === $s;
}

/**
 * True if $s is a time in HH:MM (24h) form.
 * Anchored regex: hours 00–23, minutes 00–59. Used to sanitise game start times.
 * @return bool
 */
function is_valid_time($s) {
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$s);
}

/**
 * True if $s looks like a plausible email address (the X@Y.Z shape).
 * filter_var does the heavy lifting; the extra regex pins down "the domain
 * must contain a dot" explicitly, so the contract doesn't silently depend on
 * a PHP version's idea of dotless domains. Deliberately NOT stricter than
 * this — the goal is to catch "ksjhdfgkshdfk", not to out-lawyer RFC 5322.
 * Callers only check NON-EMPTY values: whether an empty email is acceptable
 * stays a separate decision (the 'require_email' option).
 * @param string $email
 * @return bool
 */
function email_valid($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && preg_match('/@[^@]+\.[^@]+$/', $email) === 1;
}

/* ---- Free-text input sanity ---------------------------------------------- *
 * Two cheap guards applied to user-supplied names and comments. Neither is a
 * security measure — injection is already impossible because every query binds
 * its parameters (inc/db.php) — they're CONTENT-QUALITY checks that keep junk
 * out of the listings.
 * --------------------------------------------------------------------------- */

/** Longest accepted single-line name (game, player, table, display name). */
const TEXT_NAME_MAX = 200;
/** Longest accepted free-text block (comments, messages). */
const TEXT_BODY_MAX = 2000;

/**
 * Does this text contain any actual content — at least one letter or digit?
 *
 * Rejects entries that are nothing but punctuation or symbols ("'", ".", "---",
 * "???") while accepting everything genuine. Deliberately Unicode-aware (\p{L}
 * with the /u flag) so Polish diacritics and any other alphabet count as
 * letters, and digits pass so numeric titles like "1830" or "7 Wonders" work.
 *
 * NOTE this only asks whether SOME content exists — an apostrophe INSIDE a word
 * is perfectly fine, which matters for real titles and names like "Tzolk'in",
 * "King's Dilemma" or "O'Brien".
 *
 * @param string $s  Already-trimmed text.
 * @return bool
 */
function text_has_content($s) {
    return preg_match('/[\p{L}\p{N}]/u', (string)$s) === 1;
}

/**
 * Is this text longer than $max characters? Counts CHARACTERS, not bytes, so a
 * name with Polish diacritics isn't penalised for its encoding.
 *
 * @param string $s
 * @param int    $max  Usually TEXT_NAME_MAX or TEXT_BODY_MAX.
 * @return bool
 */
function text_too_long($s, $max) {
    return mb_strlen((string)$s, 'UTF-8') > $max;
}

/* ---- Guest identity prefill ---------------------------------------------- *
 * Guests have no account, so every signup / vote form asks for their name and
 * email again. We remember the last values they typed in a plain cookie and
 * prefill the fields, which is the difference between voting for four games
 * being four retypes and four clicks.
 *
 * Deliberately NOT a login: it's a convenience prefill only, never used to
 * authorise anything, so a forged cookie gains nothing an attacker couldn't
 * type by hand anyway. Not HttpOnly for the same reason (no secret in it), and
 * the values are length-capped before use.
 * --------------------------------------------------------------------------- */

/** Cookie name holding the guest's last-used name/email pair (JSON). */
const GUEST_ID_COOKIE = 'guest_id';

/**
 * Remember a guest's name/email for next time. No-op for logged-in users (they
 * already have an identity) and when there's nothing worth storing.
 *
 * @param string $name
 * @param string $email
 * @return void
 */
function guest_identity_remember($name, $email) {
    if (is_logged_in()) return;
    $name  = trim((string)$name);
    $email = trim((string)$email);
    if ($name === '' && $email === '') return;
    $payload = json_encode([
        'n' => mb_substr($name,  0, TEXT_NAME_MAX, 'UTF-8'),
        'e' => mb_substr($email, 0, TEXT_NAME_MAX, 'UTF-8'),
    ], JSON_UNESCAPED_UNICODE);
    // 90 days: long enough to span a season of events, short enough to expire.
    @setcookie(GUEST_ID_COOKIE, $payload, [
        'expires'  => time() + 90 * 86400,
        'path'     => '/',
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

/**
 * The remembered guest name/email, as ['name' => ..., 'email' => ...]. Both are
 * '' when nothing is stored, the cookie is corrupt, or a user is logged in.
 *
 * @return array{name:string,email:string}
 */
function guest_identity() {
    $blank = ['name' => '', 'email' => ''];
    if (is_logged_in()) return $blank;
    $raw = $_COOKIE[GUEST_ID_COOKIE] ?? '';
    if ($raw === '' || strlen($raw) > 1000) return $blank;   // absurd -> ignore
    $data = json_decode($raw, true);
    if (!is_array($data)) return $blank;                     // corrupt/forged -> ignore
    return [
        'name'  => mb_substr(trim((string)($data['n'] ?? '')), 0, TEXT_NAME_MAX, 'UTF-8'),
        'email' => mb_substr(trim((string)($data['e'] ?? '')), 0, TEXT_NAME_MAX, 'UTF-8'),
    ];
}

/* ---- Email requirement modes --------------------------------------------- *
 * The 'require_email' option is a three-way integer code:
 *   0 = emails never required (fields stay optional everywhere)
 *   1 = emails always required (adding games and signing up)
 *   2 = per-game: the proposer decides via a checkbox when creating a game or
 *       poll; when they tick it, signups for THAT game/poll need an email —
 *       and so does the proposer themself (they're demanding it of others).
 * The per-row flag lives in games.require_email / polls.require_email and is
 * only honoured in mode 2 (so flipping the option off also disables old flags).
 * --------------------------------------------------------------------------- */

/**
 * The configured email requirement mode as an int code (see map above).
 * @return int  0, 1 or 2.
 */
function email_require_mode() {
    return opt_int('require_email');
}

/**
 * Does signing up for THIS game need an email? True in global mode 1, or in
 * per-game mode 2 when the game's own flag is set.
 * @param array $game  A games row (needs the require_email column).
 * @return bool
 */
function email_required_for_game($game) {
    $mode = email_require_mode();
    return $mode === 1 || ($mode === 2 && (int)($game['require_email'] ?? 0) === 1);
}

/**
 * Does voting in THIS poll need an email? Same rule as games, read from the
 * polls row (the flag also carries into the game the poll resolves to).
 * @param array $poll  A polls row (needs the require_email column).
 * @return bool
 */
function email_required_for_poll($poll) {
    $mode = email_require_mode();
    return $mode === 1 || ($mode === 2 && (int)($poll['require_email'] ?? 0) === 1);
}

/**
 * The current (non-archived) event row, or null if none exists yet.
 *
 * There is at most one current event; if several somehow exist we take the
 * newest (highest id). Cached per request because many code paths ask for it.
 *
 * NOTE: this is the *live* event. The read-only archive view in index.php looks
 * up a specific event by its access token instead and does not use this.
 *
 * @return array|null  The event row, or null before the first event is created.
 */
function current_event() {
    // $cache starts at false ("not looked up yet"); null is a *valid* cached
    // result (no event), so we can't use null as the sentinel.
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = db_one('SELECT * FROM events WHERE is_archived = 0 ORDER BY id DESC LIMIT 1');
    return $cache;
}

/**
 * Write an audit-log entry.
 *
 * Most callers only pass action + detail; actor and event default to the
 * current user / current event so call sites stay short (e.g.
 * `log_action('signup', $name)`). Pass $actorName explicitly for guest actions
 * where there's no logged-in user but we still know a name.
 *
 * @param string      $action     Short machine code, e.g. 'signup', 'game_edit'.
 * @param string      $detail     Free-text context shown in the admin log.
 * @param string|null $actorName  Override the actor label (else current user).
 * @return void
 */
function log_action($action, $detail = '', $actorName = null) {
    $user  = current_user();     // null for guests
    $event = current_event();    // null before any event exists
    db_run(
        'INSERT INTO logs (event_id, action, detail, actor_name, actor_user_id, ip)
         VALUES (?,?,?,?,?,?)',
        [
            $event ? $event['id'] : null,                       // scope to the event if we have one
            $action,
            $detail,
            $actorName ?? ($user['display_name'] ?? null),      // explicit name > logged-in name > null
            $user ? $user['id'] : null,                         // link to account when logged in
            client_ip(),
        ]
    );
}
