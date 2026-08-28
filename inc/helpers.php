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
 *
 * $kind decides how it is drawn. It defaults to 'ok' so every existing caller
 * keeps working untouched — but a FAILURE must pass 'error', because a flash is
 * the only thing the visitor sees after a redirect and one drawn green reads as
 * "done" no matter what it says. ("That is not a BoardGameGeek address" was
 * being shown in the success colour, which is what prompted this.)
 *
 * @param string $msg   Already-translated, human-readable text.
 * @param string $kind  'ok' | 'error' | 'warn'.
 */
function flash_set($msg, $kind = 'ok') {
    $_SESSION['flash'] = $msg;
    $_SESSION['flash_kind'] = in_array($kind, ['ok', 'error', 'warn'], true) ? $kind : 'ok';
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
 * The kind of the last flash — call AFTER flash_get(), which clears the text.
 *
 * Deliberately a separate reader rather than making flash_get() return an
 * array: every existing caller passes its result straight to a template as a
 * string, and changing that shape would break all of them silently (a template
 * printing "Array"). This way the kind is opt-in per template.
 *
 * @return string  'ok' | 'error' | 'warn'
 */
function flash_kind() {
    $k = $_SESSION['flash_kind'] ?? 'ok';
    unset($_SESSION['flash_kind']);
    return $k;
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

/** Longest accepted single-line name (game, table, event, library entry). */
const TEXT_NAME_MAX = 200;
/**
 * Longest accepted name for a PERSON — someone signing up, an account's display
 * name, a comment or chat author.
 *
 * Separate from, and far shorter than, TEXT_NAME_MAX because the two are used
 * differently. A game called "Terraforming Mars: Ares Expedition" needs room; a
 * person's name in a seat list has to fit on one line beside a rules answer and
 * a couple of buttons, and at 200 characters one entry could push a card's
 * layout apart for everybody looking at it.
 *
 * Only checked when a name is ENTERED. Anything already stored is left alone —
 * lowering a limit should not make an existing member's account unusable.
 */
const TEXT_PERSON_MAX = 25;
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

/**
 * Absolute base URL of this installation, with a trailing slash, or '' if it
 * can't be determined.
 *
 * Prefers the admin-configured 'site_url' because that's the only thing that
 * works from CRON — a deadline sweep run by the scheduler has no HTTP_HOST to
 * infer from. Falls back to reconstructing it from the current request, which
 * covers every web-triggered email without any configuration.
 *
 * @return string  e.g. 'https://example.org/zapisy/' or ''.
 */
function site_base_url() {
    $configured = trim((string)opt('site_url'));
    if ($configured !== '') return rtrim($configured, '/') . '/';

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '';                    // CLI/cron and nothing configured
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir . '/';
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
/** The home-page block orders an admin can choose between. */
function home_layouts() {
    return ['tables_timeline_mail', 'timeline_tables_mail',
            'timeline_mail_tables', 'tables_mail_timeline'];
}

/**
 * The three home-page blocks in their configured order.
 *
 * The two older values are still understood, so a site keeps its arrangement
 * until an admin picks one of the new ones: back when the signup box was glued
 * to the timeline there were only two possible orders, and those are two of
 * these four.
 *
 * @return string[]  Some order of 'content', 'timeline', 'mail'.
 */
function home_layout_order() {
    return home_layout_order_of(opt('home_layout'));
}

/**
 * The order a NAMED layout produces. Split out so the admin form can ask about
 * a value other than the stored one.
 *
 * @param string $layout
 * @return string[]
 */
function home_layout_order_of($layout) {
    switch ($layout) {
        case 'timeline_tables_mail': return ['timeline', 'content', 'mail'];
        case 'tables_mail_timeline': return ['content', 'mail', 'timeline'];
        case 'timeline_mail_tables':
        case 'timeline_first':        // legacy: timeline + box, then tables
            return ['timeline', 'mail', 'content'];
        default:                      // and 'tables_first'
            return ['content', 'timeline', 'mail'];
    }
}

/**
 * What the header's left slot shows.
 *
 * Replaces the older show_venue_name on/off toggle, which could not express
 * "logo AND name". An empty stored value falls back to that toggle, so an
 * existing site keeps whatever it had until an admin saves the form — without
 * this a site that had the name hidden would have it reappear on update.
 *
 * @return string 'auto'|'both'|'none'
 */
function header_brand_mode() {
    $mode = trim((string)opt('header_brand'));
    if (in_array($mode, ['auto', 'both', 'none'], true)) return $mode;
    return opt_bool('show_venue_name') ? 'auto' : 'none';
}

/* ---------------------------------------------------------------------------
 *  Data-protection consent (GDPR / RODO)
 *
 *  One admin-written sentence, shown beside every form where a GUEST hands over
 *  personal data. Registered users are never asked: they agreed when they made
 *  the account.
 *
 *  Empty wording means the whole thing is off — no checkbox anywhere, and
 *  nothing is required. A club that does not need it sees no change.
 * ------------------------------------------------------------------------- */

/** How long a remembered consent stays valid. */
const CONSENT_COOKIE = 'gdpr_ok';
const CONSENT_DAYS   = 365;

/**
 * Render admin-written text with a SMALL allowlist of HTML.
 *
 * ESCAPE FIRST, THEN SELECTIVELY RESTORE. The opposite order — accepting HTML
 * and trying to strip the dangerous parts — is the approach that keeps being
 * found wanting, because the set of dangerous parts is open-ended (attributes,
 * entities, malformed tags, javascript: URLs). Here nothing survives that was
 * not explicitly put back, so anything unanticipated arrives on the page as
 * visible text rather than as markup.
 *
 * Allowed: <b> <strong> <i> <em> <u>, <br>, and <a href="..."> with an
 * http/https/mailto target. Everything else, including any attribute on the
 * simple tags, is shown literally.
 *
 * WHY IT IS RESTRICTED AT ALL, given only an admin can write it: an admin
 * already has more dangerous tools (custom CSS, the updater). The reason is
 * blast radius rather than trust — this text renders on public pages for
 * visitors who are not signed in, so a mistake pasted from a word processor
 * should degrade into odd-looking text, not a broken page.
 *
 * Newlines become <br>, so a notice can be laid out in the textarea as typed.
 *
 * @param string $raw
 * @return string  Safe to echo unescaped.
 */
function rich_text_html($raw) {
    $out = e((string)$raw);

    // The simple tags, opening and closing, with no attributes permitted.
    $out = preg_replace('~&lt;(/?)(b|strong|i|em|u)&gt;~i', '<$1$2>', $out);
    $out = preg_replace('~&lt;br\s*/?&gt;~i', '<br>', $out);

    /* Links. The href is decoded and re-checked here rather than trusted: a
     * javascript: or data: target is dropped entirely, which leaves the link
     * text on the page without the link. */
    $out = preg_replace_callback(
        '~&lt;a\s+href=(?:&quot;|&#039;)([^&<>"\']*)(?:&quot;|&#039;)\s*&gt;~i',
        function ($m) {
            $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            if (!preg_match('~^(?:https?://|mailto:)~i', $url)) return '';
            // noopener/noreferrer because these open in a new tab and the
            // target is written by hand.
            return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">';
        },
        $out
    );
    $out = preg_replace('~&lt;/a&gt;~i', '</a>', $out);

    /* Drop closing tags with nothing to close. They arise whenever an opening
     * tag was REJECTED but its partner was fine — a javascript: link, or a tag
     * carrying an attribute — and would otherwise leave a stray </a> in the
     * page. Browsers ignore those, but emitting balanced markup costs one pass
     * and avoids a puzzle for anyone reading the source later. */
    foreach (['a', 'b', 'strong', 'i', 'em', 'u'] as $tag) {
        $open  = preg_match_all('~<' . $tag . '(?:\s[^>]*)?>~i', $out);
        $close = preg_match_all('~</' . $tag . '>~i', $out);
        for ($i = 0; $i < $close - $open; $i++) {
            // Remove from the END, so the ones that do pair up keep their partner.
            $at = strripos($out, '</' . $tag . '>');
            if ($at === false) break;
            $out = substr($out, 0, $at) . substr($out, $at + strlen('</' . $tag . '>'));
        }
    }

    return nl2br($out, false);
}

/**
 * Who signed this player up, or '' when they signed themselves up.
 *
 * ONE function because TWELVE themes fork game_card.php and every one of them
 * renders this. Pasting the logic into each is how the copies drift — the
 * decision lives here and the cards just print the result.
 *
 * Two ways a player can have been entered by somebody else:
 *
 *   1. signed_up_by is set — the second name box was used. Works for GUESTS,
 *      which is the common case and why it is stored rather than derived.
 *   2. the row is bound to an account whose name DIFFERS from the player name —
 *      the older path, still true of rows written before that box existed.
 *
 * The stored value wins: it is what someone actually typed, where the account
 * name is only an inference.
 *
 * @param array $p  A players row (with account_name joined in).
 * @return string
 */
function player_signed_up_by($p) {
    if (!empty($p['signed_up_by'])) return (string)$p['signed_up_by'];
    if (!empty($p['user_id']) && !empty($p['account_name'])
        && mb_strtolower(trim((string)$p['name'])) !== mb_strtolower(trim((string)$p['account_name']))) {
        return (string)$p['account_name'];
    }
    return '';
}

/**
 * The front-page URL to return to after an action, on the RIGHT event.
 *
 * WHY THIS EXISTS: every redirect after adding a game, creating a table,
 * voting and so on used to build 'index.php?day=N' by hand. index.php then
 * resolved the event with current_event() — the EARLIEST open one — so with
 * public archives on and more than one event live, somebody working in the
 * second event was thrown back to day N of the first. The day survived the
 * round trip; the event did not.
 *
 * The event id is appended only when it is needed:
 *   - public archives off  -> at most one event is live, so there is nothing
 *     to disambiguate and the plain URL is right.
 *   - it IS the current event -> index.php would land there anyway, and a
 *     bare ?day= keeps the common case's links clean and shareable.
 *   - otherwise -> named explicitly, because nothing else would find it.
 *
 * @param int    $day      1-based day index.
 * @param int    $eventId  The event the action happened on. 0 = don't care.
 * @param string $anchor   Optional '#fragment', including the hash.
 * @return string
 */
function front_url($day, $eventId = 0, $anchor = '') {
    $url = 'index.php?day=' . (int)$day;

    $eventId = (int)$eventId;
    if ($eventId > 0 && public_archives_enabled()) {
        $current = current_event();
        if ((int)($current['id'] ?? 0) !== $eventId) {
            $url .= '&event=' . $eventId;
        }
    }
    return $url . $anchor;
}

/**
 * Is the BoardGameGeek integration usable at all?
 *
 * The endpoint this app talks to needs a bearer code, so with none configured
 * every lookup returns nothing — which used to look identical to "that game
 * does not exist". Everything that OFFERS a BGG route asks this first, so a
 * site without a code shows no BGG buttons at all rather than buttons that
 * quietly fail.
 *
 * Lives in helpers.php, not bgg.php: it reads one option and is asked on
 * ordinary page renders, so requiring the BGG client for it would load that
 * client on every request.
 *
 * @return bool
 */
function bgg_configured() {
    return trim((string)opt('bgg_api_code')) !== '';
}

/**
 * May one person sign another up, with a second name box on the form?
 *
 * A parent entering a child, most often. Off by default: an extra field on the
 * commonest form on the site is not something to inflict on clubs that do not
 * need it.
 *
 * A second switch narrows it to signed-in members — for clubs happy to let
 * their own people book a seat for a child, but not to have passers-by adding
 * arbitrary names to a table.
 *
 * ONE function, asked by both the form and the POST handler, so a field that is
 * not offered cannot be used by posting it anyway.
 *
 * @return bool
 */
function signup_proxy_enabled() {
    if (!opt_bool('signup_proxy_name')) return false;
    if (opt_bool('signup_proxy_members') && !is_logged_in()) return false;
    return true;
}

/**
 * The configured consent wording, or '' when the feature is off.
 * @return string
 */
function consent_text() {
    return trim((string)opt('mailing_gdpr_text'));
}

/**
 * Must this visitor tick a consent box on this request?
 *
 * @return bool
 */
function consent_needed() {
    return consent_text() !== '' && !is_logged_in();
}

/**
 * A short fingerprint of the CURRENT wording.
 *
 * The remembered consent is tied to it, so rewording the notice asks everyone
 * again rather than silently carrying an old agreement over to new terms —
 * consent is to specific wording, not to the idea of consenting.
 *
 * @return string
 */
function consent_fingerprint() {
    return substr(hash('sha256', consent_text()), 0, 16);
}

/**
 * Has this visitor already agreed to THIS wording before?
 * @return bool
 */
function consent_remembered() {
    return isset($_COOKIE[CONSENT_COOKIE])
        && hash_equals(consent_fingerprint(), (string)$_COOKIE[CONSENT_COOKIE]);
}

/**
 * Remember the agreement, so the next form starts ticked.
 *
 * Called only after a form that ASKED has been accepted, so the cookie can
 * never claim an agreement that was not actually given.
 *
 * @return void
 */
function consent_remember() {
    if (consent_text() === '') return;
    $params = [
        'expires'  => time() + CONSENT_DAYS * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => false,   // no secret in it; readable is harmless
        'samesite' => 'Lax',
    ];
    if (!headers_sent()) setcookie(CONSENT_COOKIE, consent_fingerprint(), $params);
}

/**
 * Was consent given on this submission?
 *
 * True when the feature is off or the visitor is signed in, so callers can ask
 * unconditionally.
 *
 * @return bool
 */
function consent_ok() {
    if (!consent_needed()) return true;
    return !empty($_POST['gdpr_consent']);
}

/**
 * The checkbox itself, or '' when nothing should be asked.
 *
 * Pre-ticked for someone who already agreed to this same wording — the point
 * is not to make a regular visitor re-read it at every form.
 *
 * @return string  HTML.
 */
function consent_field() {
    if (!consent_needed()) return '';
    // Pre-ticking is the admin's call. Note that consent is still REMEMBERED
    // when this is off — only the tick is withheld — so turning it back on
    // works immediately for anyone who has already agreed.
    $checked = (opt_bool('gdpr_prefill') && consent_remembered()) ? ' checked' : '';
    return '<div class="field field-check gdpr-consent">'
         . '<label><input type="checkbox" name="gdpr_consent" value="1"' . $checked . ' required> '
         // rich_text_html(), not e(): the wording may carry bold and a link to
         // the club's privacy policy, which is the one place a visitor would
         // reasonably expect one. It escapes everything else.
         . '<span>' . rich_text_html(consent_text()) . '</span></label></div>';
}

/**
 * The admin-written footer pages, in menu order.
 *
 * Cached per request: the footer renders on every page, and this would
 * otherwise be a query on each one.
 *
 * @return array
 */
function custom_pages() {
    static $cache = null;
    if ($cache === null) {
        $cache = db_all('SELECT id, title FROM custom_pages ORDER BY sort_order, id');
    }
    return $cache;
}

/**
 * One page by id, or null.
 * @return array|null
 */
function custom_page($id) {
    return db_one('SELECT * FROM custom_pages WHERE id = ?', [(int)$id]);
}

/**
 * Is the public archive feature switched on?
 *
 * Lives HERE rather than in inc/events.php because the header asks it on every
 * page to decide whether to show the Archive link — and events.php is only
 * loaded by the controllers that need event data, so login.php and register.php
 * would fatal on it. (They did, until this moved.)
 *
 * @return bool
 */
/**
 * The example addresses written into the admin guides.
 *
 * The guides are ordinary Markdown, readable on their own, so they use a
 * plausible-looking address rather than a placeholder like {{SITE_URL}} —
 * somebody reading the file outside the app should see a sentence, not a
 * template. The cost is that these two strings are load-bearing: change one in
 * a guide without changing it here and the substitution silently stops
 * happening. A test asserts the guides still contain them.
 */
const HELP_EXAMPLE_URLS = ['https://twojklub.pl', 'https://yourclub.com'];

/**
 * Swap the guides' example addresses for this club's real one.
 *
 * An admin reading "go to https://yourclub.com/admin" has to translate it into
 * their own address every time; showing the address they actually use removes
 * that step, and makes the guide something they can copy from directly.
 *
 * Only the BASE address is substituted — every other mention in the guides is
 * that base plus a path ("/admin"), so replacing the host fixes those too
 * without needing a rule per page.
 *
 * @param string $md       The guide's Markdown source.
 * @param string $siteUrl  The configured site address; '' to leave it alone.
 * @return string
 */
function help_localise_urls($md, $siteUrl) {
    $siteUrl = rtrim(trim((string)$siteUrl), '/');
    /* Nothing configured, or something that is not an address: leave the
     * examples in place. A guide that says "yourclub.com" is mildly annoying;
     * one that says "go to (nothing)/admin" is broken. */
    if ($siteUrl === '' || !filter_var($siteUrl, FILTER_VALIDATE_URL)) return $md;
    return str_replace(HELP_EXAMPLE_URLS, $siteUrl, $md);
}

/**
 * Is this the filename of an admin-uploaded predefined thumbnail?
 *
 * The picker offers a radio group, and a radio group is only a suggestion: the
 * value that arrives is whatever was posted. Everywhere a member may choose a
 * picture, the answer is checked against the predefined_thumbnails table rather
 * than trusted, so a hand-built POST cannot point a game's art at an arbitrary
 * path or an off-site URL.
 *
 * An empty string is "no picture" and is always allowed — that is the picker's
 * own first option.
 *
 * @param string $file  The submitted filename.
 * @return bool
 */
function thumbnail_is_predefined($file) {
    $file = trim((string)$file);
    if ($file === '') return true;                  // "none" is a valid choice
    return (int)db_val('SELECT COUNT(*) FROM predefined_thumbnails WHERE filename = ?', [$file]) > 0;
}

/**
 * The "does this player know the rules?" marker, as inline SVG.
 *
 * REPLACES A CHARACTER THAT DOES NOT EXIST ON MOST PHONES. This used to be
 * &#128366; (U+1F56E, an open book). Desktop browsers with a big font stack
 * find it; Android in particular has no glyph for it and draws the empty
 * "tofu" box instead, so the one thing on the card that tells you whether
 * somebody can teach the game rendered as a blank square.
 *
 * Inline SVG has no such problem: it is drawn, not looked up in a font. It
 * inherits the surrounding colour through currentColor, so the rules-*
 * classes keep tinting it green/amber/red exactly as before, on every theme.
 *
 * Returns MARKUP, already escaped where it needs to be — the caller echoes it
 * raw. $tone and $label come from rules_tone() and knows_rules_label().
 *
 * @param string $tone   'yes'|'some'|'no' — the CSS tone class.
 * @param string $label  Human-readable text for the tooltip.
 * @return string
 */
function rules_icon_html($tone, $label) {
    /* FILLED, not outlined. The first version of this was two rounded
     * rectangles drawn with a 2px stroke on a 24px grid — at the size it
     * actually renders (about 16px, inline with the text) that leaves barely
     * any interior, and it read as two bare boxes rather than a book. A filled
     * silhouette keeps its shape all the way down. */
    return '<span class="p-knows rules-' . e($tone) . '" title="' . e($label) . '">'
         . '<svg class="p-knows-icon" viewBox="0 0 24 24" aria-hidden="true" '
         . 'fill="currentColor">'
         // Left page: spine at the top centre, curving out and down, with the
         // bottom edge sagging the way an open book's pages do.
         . '<path d="M11.4 8.0 C 9.3 6.3, 6.6 5.5, 3.6 5.5 C 2.9 5.5, 2.4 6.0, 2.4 6.7 '
         . 'L 2.4 15.2 C 2.4 15.9, 2.9 16.4, 3.6 16.4 C 6.4 16.4, 9.0 17.2, 11.0 18.8 '
         . 'C 11.2 18.9, 11.4 18.8, 11.4 18.6 Z"></path>'
         // Right page: the same shape mirrored about x=12, leaving a gap down
         // the middle that stays visible as the spine even at 14px.
         . '<path d="M12.6 8.0 C 14.7 6.3, 17.4 5.5, 20.4 5.5 C 21.1 5.5, 21.6 6.0, 21.6 6.7 '
         . 'L 21.6 15.2 C 21.6 15.9, 21.1 16.4, 20.4 16.4 C 17.6 16.4, 15.0 17.2, 13.0 18.8 '
         . 'C 12.8 18.9, 12.6 18.8, 12.6 18.6 Z"></path>'
         . '</svg></span>';
}

function public_archives_enabled() {
    return opt_bool('public_archives');
}

/**
 * Which public views of past events are offered: 'both' | 'archive' | 'calendar'.
 *
 * Anything unrecognised reads as 'both' — the behaviour from before the setting
 * existed. A garbled option should not silently remove a section of the site.
 *
 * Lives here rather than in events.php for the same reason as the function
 * above: the HEADER asks it on every page, including ones that never load
 * event data.
 *
 * @return string
 */
function archive_views_mode() {
    $m = (string)opt('archive_views', 'both');
    return in_array($m, ['both', 'archive', 'calendar'], true) ? $m : 'both';
}

/**
 * Is the LIST view of past events available?
 *
 * Needs the archives switched on at all: this setting chooses between the two
 * shapes, it does not turn the feature on.
 *
 * @return bool
 */
function archive_list_enabled() {
    return public_archives_enabled() && archive_views_mode() !== 'calendar';
}

/**
 * Is the CALENDAR view available?
 * @return bool
 */
function archive_calendar_enabled() {
    return public_archives_enabled() && archive_views_mode() !== 'archive';
}

function current_event() {
    // $cache starts at false ("not looked up yet"); null is a *valid* cached
    // result (no event), so we can't use null as the sentinel.
    static $cache = false;
    if ($cache !== false) return $cache;

    if (public_archives_enabled()) {
        /* Clubs using this feature publish months of dates at once, so "the
         * event that matters" is the SOONEST one still open, not the one added
         * most recently. Ordered by the event's first day, not by id — a club
         * entering three months of dates will not necessarily enter them in
         * order.
         *
         * Events with no days yet sort LAST: a draft with no dates should never
         * hijack the front page ahead of a real one.
         *
         * NOTE this is "earliest still open", which is only the same thing as
         * "next upcoming" while finished events actually get archived. A club
         * that never archives will keep seeing a past event here — which is
         * what 'auto_archive_days' is for. */
        $cache = db_one(
            "SELECT e.*,
                    (SELECT MIN(day_date) FROM event_days d WHERE d.event_id = e.id) AS date_from
               FROM events e
              WHERE e.is_archived = 0 AND e.is_deleted = 0
              ORDER BY date_from IS NULL, date_from ASC, e.id ASC
              LIMIT 1");
        return $cache;
    }

    // Feature off: unchanged. At most one event is live at a time here, because
    // creating one archives the previous.
    $cache = db_one('SELECT * FROM events WHERE is_archived = 0 AND is_deleted = 0 ORDER BY id DESC LIMIT 1');
    return $cache;
}

/**
 * Drop audit entries older than the configured retention period.
 *
 * Called from the same poor-man's-cron slot as the poll and archive sweeps, and
 * throttled to once a day: the DELETE is cheap but pointless to repeat on every
 * page view.
 *
 * Size is not the reason this exists — a log row is about 114 bytes, so even a
 * club running weekly for a decade would not notice. The reason is that the
 * rows carry names and IP addresses, and holding personal data indefinitely
 * with no purpose is the thing a retention period is meant to prevent.
 *
 * @return int  Rows removed on this run.
 */
function logs_prune() {
    $days = (int)opt('log_retention_days');
    if ($days <= 0) return 0;                     // 0 = keep everything

    // Once a day is plenty; the marker costs one option read.
    $today = gmdate('Y-m-d');
    if (opt('log_pruned_on') === $today) return 0;
    opt_set('log_pruned_on', $today);

    $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $n = (int)db_val('SELECT COUNT(*) FROM logs WHERE created_at < ?', [$cutoff]);
    if ($n > 0) db_run('DELETE FROM logs WHERE created_at < ?', [$cutoff]);
    return $n;
}

/**
 * Is this action about the SITE rather than about an event?
 *
 * Site-level: configuration, accounts, updates, uploads, the chat, the footer
 * pages, and anything to do with events THEMSELVES (creating, renaming,
 * deleting one) — which event that concerns is already named in the detail, and
 * filing "event deleted" inside that event's own log makes it unreachable the
 * moment the event is gone.
 *
 * Event-level is what happens INSIDE an event: games, players, polls, comments,
 * tables, messages, mailing signups.
 *
 * Listed explicitly rather than matched by prefix, so a new action has to be
 * classified deliberately. An unknown one falls through to the event log, which
 * is the safer default: it stays next to whatever it relates to instead of
 * disappearing into a site-wide list.
 *
 * @param string $action
 * @return bool
 */
function log_action_is_system($action) {
    static $system = [
        // Configuration and the site itself
        'options_save', 'options_export', 'options_import', 'db_backup',
        'force_ssl', 'system_update',
        // Accounts
        'login', 'logout', 'register',
        'user_create', 'user_block', 'user_unblock', 'user_promote', 'user_demote',
        'user_email', 'user_password',
        // Uploads and branding
        'thumb_upload', 'thumb_delete', 'icon_upload', 'icon_delete',
        'logo_upload', 'logo_delete',
        // Footer pages
        'page_add', 'page_edit', 'page_delete',
        // Moderating the chat is running the site, not running an event.
        'chat_delete', 'chat_purge',
        // Events as objects, rather than what happens inside them.
        'event_create', 'event_rename', 'event_archive', 'event_unarchive',
        'event_delete', 'event_restore', 'event_purge',
        'event_day_add', 'event_day_edit', 'event_day_delete',
    ];
    return in_array($action, $system, true);
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
    // System actions are recorded globally (event_id NULL). Without this every
    // settings save and login was stapled to whichever event happened to be
    // current, so an event's log filled with noise about the site rather than
    // about the event.
    $event = log_action_is_system($action) ? null : current_event();
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
