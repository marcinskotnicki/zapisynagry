<?php
/* =============================================================================
 *  inc/lang.php — multilingual support.
 * -----------------------------------------------------------------------------
 *  Each language is a plain PHP file in /languages that RETURNS an associative
 *  array of key => translated string (e.g. languages/en.php, languages/pl.php).
 *  Adding a language = dropping in a new file; it then shows up automatically
 *  in the admin "default language" picker and the per-user switcher.
 *
 *  Selection order: a valid 'lang' cookie wins; otherwise the admin default.
 *  The chosen strings live in $GLOBALS['LANG']; use t('key') to read them.
 *
 *  TRANSLATION WORKFLOW for future edits: add the SAME new key to *every* file
 *  in /languages (en.php and pl.php today). A missing key falls back to printing
 *  the key text itself (see t()), which makes an omission easy to spot on screen.
 * ============================================================================= */

/**
 * Return the list of available language codes by scanning /languages.
 * The code is the filename without extension ("en", "pl"). Order follows glob's
 * (alphabetical) listing, which also decides the last-resort default below.
 * @return string[]  e.g. ['en', 'pl']
 */
function lang_available() {
    $codes = [];
    foreach (glob(__DIR__ . '/../languages/*.php') as $file) {
        $codes[] = basename($file, '.php');
    }
    return $codes;
}

/**
 * True if $code corresponds to a real language file.
 * The regex whitelists the characters allowed in a code BEFORE we build a path,
 * so a hostile cookie value can't be used for directory traversal.
 * @param string $code
 * @return bool
 */
function lang_exists($code) {
    return $code !== '' && preg_match('/^[a-z0-9_-]+$/i', $code)
        && file_exists(__DIR__ . '/../languages/' . $code . '.php');
}

/**
 * Work out which language to use this request (cookie, else admin default).
 * Three tiers, in order: a valid per-user cookie, the admin's default_language
 * option, then the first file on disk so the UI is never rendered key-less.
 * @return string  A language code that is guaranteed to exist on disk.
 */
/**
 * May the CURRENT VISITOR change the language? (Mirrors tpl_switch_allowed:
 * 'allow_user_language' for accounts, 'allow_guest_language' for guests.)
 * @return bool
 */
function lang_switch_allowed() {
    return opt_bool(is_logged_in() ? 'allow_user_language' : 'allow_guest_language');
}

function lang_current() {
    // 1) Per-user choice from the switcher — honoured only while the admin
    //    allows switching for this visitor type (cookie is validated anyway).
    $cookie = $_COOKIE['lang'] ?? '';
    if (lang_switch_allowed() && lang_exists($cookie)) return $cookie;

    // 2) Admin default.
    $default = opt('default_language', 'en');
    if (lang_exists($default)) return $default;

    // 3) Last resort: first available file, so the app never renders keyless.
    $all = lang_available();
    return $all[0] ?? 'en';
}

/**
 * Load the active language strings into $GLOBALS['LANG']. Called by bootstrap.
 * Also stashes the resolved code in $GLOBALS['LANG_CODE'] (the header uses it
 * for the <html lang="..."> attribute and the switcher's current state).
 * @return void
 */
function lang_load() {
    $code = lang_current();
    $GLOBALS['LANG_CODE'] = $code;
    $strings = include __DIR__ . '/../languages/' . $code . '.php';
    // Defensive: if a file somehow didn't return an array, fall back to empty
    // (every t() then prints its key, rather than fatalling).
    $GLOBALS['LANG'] = is_array($strings) ? $strings : [];
}

/**
 * Translate a key. Falls back to the key itself if missing, so a forgotten
 * string is visible (and obviously wrong) rather than blank.
 *
 * Optional sprintf-style args fill %s/%d placeholders in the string:
 *   t('greeting', $name)         -> "Hello, Marcin"   (from 'greeting' => 'Hello, %s')
 *   t('players_label', 3, 5)     -> "Players: 3/5"
 * Keep the placeholder count in the translations matching the call sites.
 *
 * @param string $key      Lookup key.
 * @param mixed  ...$args   Values for vsprintf, if the string has placeholders.
 * @return string
 */
function t($key, ...$args) {
    /* An admin's own wording wins, when they have set one for this key. Read
     * from the parsed map rather than the option text, so the parse happens
     * once per request and not once per string. */
    $over = lang_overrides();
    $s = $over[$key] ?? ($GLOBALS['LANG'][$key] ?? $key);   // fall back to the key text
    return $args ? vsprintf($s, $args) : $s;                 // only format when args given
}

/**
 * The admin's text overrides for the CURRENT language, parsed and checked.
 *
 * Cached per request: t() is called hundreds of times per page and the option
 * text would otherwise be re-parsed for every one of them.
 *
 * @return array  key => replacement text.
 */
function lang_overrides() {
    static $cache = null;
    static $forKey = null;

    $lang = (string)($GLOBALS['LANG_CODE'] ?? '');
    if ($lang === '' || !function_exists('opt')) return [];

    $raw = (string)opt('lang_override_' . $lang, '');

    /* Keyed on the language AND the text itself, not the language alone. Both
     * can change within one process — the Options screen previews another
     * language, and saving the field changes the text — and a cache that only
     * watched the language would keep serving the parse from before the
     * change. Hashed rather than kept whole so the key stays small. */
    $key = $lang . ':' . md5($raw);
    if ($cache !== null && $forKey === $key) return $cache;

    $forKey = $key;
    $cache = trim($raw) === '' ? [] : lang_parse_overrides($raw, $GLOBALS['LANG'] ?? []);
    return $cache;
}

/**
 * Parse an admin's override text into a key => value map.
 *
 * PURE, so the parsing and — more importantly — the REFUSALS can be tested
 * without a request around them.
 *
 * Format is `key = value`, one per line, split on the FIRST '=' only so a
 * value may itself contain one. Blank lines and lines starting '#' are
 * ignored. A line that makes no sense is skipped rather than fatal: this field
 * lives in Advanced options, and a typo there must not take the site down.
 *
 * TWO THINGS ARE REFUSED, both of which would otherwise break a page:
 *
 *   - a key that does not exist. Silently doing nothing beats inventing a
 *     string that nothing reads.
 *   - a value whose printf placeholders do not match the original's. Several
 *     strings are filled in with vsprintf() — "added %d, removed %d" — and an
 *     override that drops or adds one produces a PHP error or nonsense where a
 *     number should be. The built-in wording is kept for that key instead.
 *
 * Overrides are escaped on output exactly like any other string, so the worst
 * a mistake can do is show the wrong words.
 *
 * @param string $raw       The admin's text.
 * @param array  $existing  The language map, for validating keys and placeholders.
 * @return array
 */
function lang_parse_overrides($raw, array $existing = []) {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;      // blank or comment

        $pos = strpos($line, '=');
        if ($pos === false || $pos === 0) continue;          // no key, or no '='

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if ($key === '' || $val === '') continue;

        // Only keys that actually exist; an unknown one does nothing.
        if ($existing && !array_key_exists($key, $existing)) continue;

        // Placeholders must survive, or vsprintf() breaks on the way out.
        if ($existing && lang_placeholder_count($existing[$key]) !== lang_placeholder_count($val)) continue;

        $out[$key] = $val;
    }
    return $out;
}

/**
 * How many printf placeholders a string takes.
 *
 * '%%' is an escaped literal per cent and consumes no argument, so it must not
 * be counted — a string containing "100%%" would otherwise look like it needs
 * an argument it never uses.
 *
 * @param string $s
 * @return int
 */
function lang_placeholder_count($s) {
    $s = str_replace('%%', '', (string)$s);
    return preg_match_all('/%[0-9]*\$?[-+ 0#]*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/', $s);
}

/**
 * Set the per-user language cookie (one year). Used by the switcher.
 * Silently ignores an unknown code so a bad value can't poison the cookie.
 * @param string $code
 * @return void
 */
function lang_set_cookie($code) {
    if (lang_exists($code)) {
        setcookie('lang', $code, time() + 31536000, '/');   // 31536000s = 365 days
    }
}
