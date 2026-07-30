<?php
/* =============================================================================
 *  inc/options.php — load and access the admin-editable settings.
 * -----------------------------------------------------------------------------
 *  All settings live in the `options` key/value table (see database.sql). We
 *  load the whole thing once per request into $GLOBALS['OPTIONS'] because it's
 *  tiny and almost every page reads several values.
 *
 *  WHY KEY/VALUE (not columns): adding a new setting is just an INSERT of a new
 *  row (the updater seeds it) — never a schema migration. That's a core project
 *  rule: new settings must not require ALTER TABLE.
 *
 *  Reading:  opt('key')          raw string (with optional default)
 *            opt_int('key')      same, cast to int
 *            opt_bool('key')     true only when the stored value is exactly "1"
 *  Writing:  opt_set('key', $v)  upsert + refresh the in-memory copy
 * ============================================================================= */

/**
 * Load every option row into memory. Called once, by bootstrap.
 * Overwrites $GLOBALS['OPTIONS'] each time, so it's also safe to call again in
 * tests after changing settings directly in the DB.
 * @return void
 */
function options_load() {
    $GLOBALS['OPTIONS'] = [];
    foreach (db_all('SELECT key, value FROM options') as $row) {
        $GLOBALS['OPTIONS'][$row['key']] = $row['value'];
    }
}

/**
 * Apply the admin-chosen timezone. Called by bootstrap immediately AFTER
 * options_load() (it needs opt()) and BEFORE anything that reads the clock.
 *
 * WHY IT MATTERS: event start times and poll deadlines are wall-clock times with
 * no offset stored, and the deadline sweep compares them against date(). If PHP
 * runs on a different clock than the venue, polls resolve at the wrong moment —
 * an hour or two off, depending on the season.
 *
 * An unset or unrecognised value falls back to UTC rather than throwing, so a
 * typo in the Options screen can never take the whole site down.
 * @return void
 */
function app_timezone_init() {
    $tz = opt('timezone', 'UTC');
    try {
        new DateTimeZone($tz);          // throws on an unknown identifier
    } catch (Throwable $e) {
        $tz = 'UTC';
    }
    date_default_timezone_set($tz);
}

/**
 * The admin-editable free-text messages shown around the site.
 *
 * ONE list, used by three places that must never disagree: the fields the
 * Options screen renders, the keys its save handler accepts, and the one-time
 * upgrade copy in inc/update.php. Adding a seventh message means adding it
 * here and nowhere else.
 * @return string[]
 */
function custom_msg_keys() {
    return [
        'msg_below_event',
        'msg_adding_game',
        'msg_assigning_player',
        'msg_adding_poll',
        'msg_voting',
        'msg_email_field',
    ];
}

/**
 * The option key holding one message in one language, e.g. 'msg_voting_en'.
 * @param string $key   One of custom_msg_keys().
 * @param string $lang  Language code, as returned by lang_available().
 * @return string
 */
function custom_msg_option($key, $lang) {
    return $key . '_' . $lang;
}

/**
 * An admin-configured message, in the language the visitor is reading.
 *
 * FALLBACK CHAIN, in order:
 *   1. the current language's text;
 *   2. the site's default language's text — so an admin who only filled Polish
 *      still shows something to an English visitor, rather than a blank gap;
 *   3. the bare legacy key (`msg_voting` with no suffix), which is what every
 *      one of these was before they became per-language. Existing installs
 *      keep working untouched, and any language added to the site later still
 *      inherits that text until someone translates it.
 *
 * MUST NOT be called before lang_load() — it needs lang_current(). In practice
 * every caller is a template, which renders long after bootstrap.
 *
 * @param string $key  One of custom_msg_keys().
 * @return string  '' when nothing is configured anywhere.
 */
function opt_msg($key) {
    $lang = function_exists('lang_current') ? lang_current() : '';
    if ($lang !== '') {
        $v = trim((string)opt(custom_msg_option($key, $lang), ''));
        if ($v !== '') return $v;
    }
    $default = (string)opt('default_language', '');
    if ($default !== '' && $default !== $lang) {
        $v = trim((string)opt(custom_msg_option($key, $default), ''));
        if ($v !== '') return $v;
    }
    return trim((string)opt($key, ''));   // pre-translation value
}

/**
 * Raw string value of an option, or $default if it doesn't exist.
 * @param string $key
 * @param string $default  Returned when the key was never seeded.
 * @return string
 */
function opt($key, $default = '') {
    return $GLOBALS['OPTIONS'][$key] ?? $default;
}

/**
 * Option as an integer.
 * @param string $key
 * @param int    $default  Returned when the key is absent (note: a present-but-
 *                         non-numeric value casts to 0, it does NOT use default).
 * @return int
 */
function opt_int($key, $default = 0) {
    return isset($GLOBALS['OPTIONS'][$key]) ? (int)$GLOBALS['OPTIONS'][$key] : $default;
}

/**
 * Option as a boolean toggle ("1" = true).
 * Strict compare: only the literal string "1" is true; "", "0", anything else
 * is false. Toggles are stored as "1"/"0" by the admin Options screen.
 * @return bool
 */
function opt_bool($key) {
    return opt($key) === '1';
}

/**
 * Persist an option value (and update the in-memory copy so later code in the
 * same request sees the new value). Inserts the key if it didn't exist.
 *
 * Uses SQLite's UPSERT (ON CONFLICT) keyed on the unique `key` column, so one
 * statement handles both "create" and "update".
 *
 * @param string $key
 * @param mixed  $value  Cast to string for storage (toggles pass "1"/"0").
 * @return void
 */
function opt_set($key, $value) {
    db_run(
        'INSERT INTO options (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        [$key, (string)$value]
    );
    // Keep the request-local cache in sync so a read later this request is correct.
    $GLOBALS['OPTIONS'][$key] = (string)$value;
}
