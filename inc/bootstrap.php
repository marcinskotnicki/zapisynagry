<?php
/* =============================================================================
 *  inc/bootstrap.php — the single include every page starts with.
 * -----------------------------------------------------------------------------
 *      require __DIR__ . '/inc/bootstrap.php';
 *
 *  After this returns you have: a live DB (db()), all settings loaded
 *  (opt()/opt_bool()...), the active language ready (t()), the active theme
 *  chosen (tpl_render()), the session started, and current_user() available.
 *
 *  Controllers then load only the EXTRA modules they need on top of this, e.g.
 *  inc/events.php, inc/verify.php, inc/notify.php. This file pulls in the
 *  always-needed core only, to keep every request lean.
 * ============================================================================= */

// ---- 1. Config -------------------------------------------------------------
// config.php is written by the installer and is NOT in version control (it
// holds the DB path + app secret). If it isn't here, the app hasn't been
// installed yet — send the user to the installer.
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    if (file_exists(__DIR__ . '/../install.php')) {
        header('Location: install.php');
        exit;
    }
    // Installed-but-config-deleted, or a broken deploy: fail loudly, don't guess.
    http_response_code(500);
    exit('Application is not configured: config.php is missing.');
}
require $configPath;

// ---- 2. Core includes (order matters: db -> options -> lang/template) ------
// helpers first (e(), redirect()... used everywhere), then db (needed by
// options), then options (lang/template read settings), then auth.
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/options.php';
require __DIR__ . '/lang.php';
require __DIR__ . '/template.php';
require __DIR__ . '/auth.php';

// ---- 3. Wire everything up for this request --------------------------------
// ORDER MATTERS here:
//  - The timezone is pinned FIRST: stored timestamps use SQLite's
//    datetime('now') = UTC, and auth_init() below compares remember-token
//    expiries with PHP date() — both must be on the same (UTC) clock.
//  - options_load() runs BEFORE auth_init(): the session restore inside
//    auth_init() reads opt_int('login_days'); with a cold cache it would see
//    the default 0 and mis-set every token/cookie lifetime to the 1-day floor.
date_default_timezone_set('UTC');
options_load();     // pull settings into memory (opt* now usable)
auth_init();        // start session (current_user(), csrf_*, flash all need it)
lang_load();        // pick + load language strings (t() now usable)
tpl_init();         // pick active theme (tpl_render() now usable)
