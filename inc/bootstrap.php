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

/* ---- 0. Notices must never reach the page ---------------------------------
 *
 * A PHP notice printed before a header() call turns "cannot modify header
 * information" into a BROKEN REDIRECT: the action itself succeeded, but the
 * browser stays on the POST and a refresh repeats it. That is how one
 * deprecation notice on PHP 8.4 turned into a visibly broken sync.
 *
 * The real defence is not fixing each notice as it appears — a future PHP will
 * always deprecate something else — but making sure a notice cannot break a
 * redirect in the first place:
 *
 *   - error_reporting stays at E_ALL, so nothing is swept under the carpet and
 *     the host's error log still records every deprecation and notice. This is
 *     deliberately NOT lowered: silencing diagnostics would hide the next real
 *     bug as effectively as it hides this cosmetic one.
 *   - display_errors is off, so those diagnostics never print INTO the page.
 *     That is what actually protects the redirect.
 *   - output buffering means even an unexpected print — from a stray echo, or
 *     a warning some extension writes directly — cannot commit the response
 *     before a header() call gets its chance.
 *
 * Set here rather than relied on from php.ini, because shared hosts vary and
 * this app is installed on plenty of them. A host with display_errors ON was
 * exactly the case that broke; a developer wanting errors on screen can turn
 * display_errors back on in config.php, which is required just below.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
if (!ob_get_level()) ob_start();

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
/* ---- Security headers ------------------------------------------------------
 *
 * Sent before anything else can commit the response. Three cheap ones that
 * carry no compatibility risk:
 *
 *   X-Content-Type-Options  stops a browser second-guessing a file's type and
 *                           running an uploaded thumbnail as script.
 *   X-Frame-Options         stops the site being framed and clickjacked — an
 *                           invisible frame over a "delete" button is the
 *                           classic use.
 *   Referrer-Policy         keeps event tokens in the URL from leaking to
 *                           whatever a game's BGG link points at.
 *
 * Deliberately NOT here: a Content-Security-Policy, which would need every
 * inline handler in the templates removing first and is a change with real
 * regression risk; and HSTS, which stays off on purpose — an expired
 * certificate with HSTS set means no way back into the site through the
 * browser, and these installs are looked after by club volunteers.
 */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

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
