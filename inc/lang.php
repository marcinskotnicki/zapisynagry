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
    $s = $GLOBALS['LANG'][$key] ?? $key;          // fall back to the key text
    return $args ? vsprintf($s, $args) : $s;       // only format when args given
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
