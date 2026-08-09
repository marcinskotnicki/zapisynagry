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
require __DIR__ . '/library.php';   // the header asks library_enabled() on every page
require __DIR__ . '/antibot.php';   // small + universally needed; see its own header
require __DIR__ . '/chat.php';      // chat_enabled() is asked on every page render

// ---- 3. Wire everything up for this request --------------------------------
// ORDER MATTERS here:
//  - A timezone is pinned FIRST so nothing reads the clock unconfigured, then
//    app_timezone_init() swaps in the admin's choice as soon as the options are
//    in memory. It has to be the site's REAL timezone: event start times and
//    poll deadlines are wall-clock values with no offset stored, so the sweep
//    that asks "has this deadline passed?" is only right on the venue's clock.
//    The columns that DO come from SQLite's datetime('now') (the created_at
//    defaults) are display-only and never compared against PHP's clock, and the
//    verification/password-reset expiries use gmdate() on both sides, so both
//    stay correct whatever this is set to.
//  - options_load() runs BEFORE auth_init(): the session restore inside
//    auth_init() reads opt_int('login_days'); with a cold cache it would see
//    the default 0 and mis-set every token/cookie lifetime to the 1-day floor.
date_default_timezone_set('UTC');   // safe baseline until the options are readable
options_load();     // pull settings into memory (opt* now usable)
app_timezone_init();// switch to the venue's own clock (needs opt(), so not earlier)
auth_init();        // start session (current_user(), csrf_*, flash all need it)
// Mint/refresh the CSRF token HERE, while headers can still be sent. Its mirror
// cookie (see csrf_token()) is what keeps a form working after the host has
// garbage-collected the session — but a page whose first csrf_field() call comes
// from the footer would be too late to send it, so we do it up front instead.
csrf_token();
lang_load();        // pick + load language strings (t() now usable)
tpl_init();         // pick active theme (tpl_render() now usable)
