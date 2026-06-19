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
