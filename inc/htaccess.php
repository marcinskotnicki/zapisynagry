<?php
/* =============================================================================
 *  inc/htaccess.php — the managed HTTPS-redirect block in .htaccess.
 * -----------------------------------------------------------------------------
 *  The redirect lives in .htaccess rather than in PHP because .htaccess catches
 *  everything — images, CSS, the lot — while a PHP redirect only ever sees
 *  requests that reach PHP. This app already depends on .htaccess working (the
 *  extensionless URLs and the data/ deny rule), so there is nothing gained by
 *  carrying a second mechanism for hosts where it does not.
 *
 *  The block is delimited by markers so it can be added and removed without
 *  disturbing anything else in the file — an admin's own rules, or a host's.
 *
 *  DELIBERATELY NO HSTS. A Strict-Transport-Security header would make browsers
 *  refuse to offer the "I understand the risks" bypass, so an expired
 *  certificate would lock every visitor AND the admin out of the site with no
 *  way back through the browser. With a plain redirect and no HSTS, an expired
 *  certificate is an ugly warning that can still be clicked through.
 *
 *  AND DELIBERATELY 302, NOT 301. A permanent redirect is cached by the browser
 *  itself, so unticking the box would appear to do nothing until the visitor
 *  cleared their cache. The setting is meant to be reversible.
 * ============================================================================= */

const HTACCESS_SSL_BEGIN = '# BEGIN zapisynagry-ssl';
const HTACCESS_SSL_END   = '# END zapisynagry-ssl';

/**
 * Absolute path to the app's .htaccess.
 * @return string
 */
function htaccess_path() {
    return dirname(__DIR__) . '/.htaccess';
}

/**
 * The block, built fresh each time so an old copy is always replaced by the
 * current wording rather than left in place.
 *
 * The X-Forwarded-Proto condition matters on any host that terminates TLS at a
 * proxy or load balancer: there %{HTTPS} is off even for an https request, and
 * without this the rule would redirect to itself forever.
 *
 * @return string
 */
function htaccess_ssl_block() {
    return HTACCESS_SSL_BEGIN . "\n"
         . "# Managed by the admin panel (Options -> Security). Edit there, not here.\n"
         . "<IfModule mod_rewrite.c>\n"
         . "    RewriteEngine On\n"
         . "    RewriteCond %{HTTPS} !=on\n"
         . "    RewriteCond %{HTTP:X-Forwarded-Proto} !=https\n"
         . "    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=302]\n"
         . "</IfModule>\n"
         . HTACCESS_SSL_END;
}

/**
 * Is the redirect currently in place?
 *
 * Read from the FILE rather than from a stored option, so the checkbox can
 * never disagree with what the server is actually doing — someone editing
 * .htaccess over FTP is a normal thing on shared hosting.
 *
 * @return bool
 */
function htaccess_ssl_enabled() {
    $path = htaccess_path();
    if (!is_file($path)) return false;
    return strpos((string)@file_get_contents($path), HTACCESS_SSL_BEGIN) !== false;
}

/**
 * Can we write to .htaccess at all?
 *
 * Checked before offering the switch, so a host with a read-only file says so
 * instead of silently failing to apply the setting.
 *
 * @return bool
 */
function htaccess_writable() {
    $path = htaccess_path();
    return is_file($path) ? is_writable($path) : is_writable(dirname($path));
}

/**
 * Add or remove the block.
 *
 * Placed at the TOP of the file: a redirect has to happen before the rewrite
 * rules that map /admin to admin.php, or a visitor on http would be served the
 * page over http and only then redirected.
 *
 * @param bool $on
 * @return bool  False if the file could not be written.
 */
function htaccess_ssl_set($on) {
    $path = htaccess_path();
    $text = is_file($path) ? (string)@file_get_contents($path) : '';

    // Strip any existing block first, so this is the same operation whether it
    // is being turned on, off, or rewritten to a newer wording.
    $pattern = '/' . preg_quote(HTACCESS_SSL_BEGIN, '/') . '.*?'
             . preg_quote(HTACCESS_SSL_END, '/') . '\n?/s';
    $text = preg_replace($pattern, '', $text);
    $text = ltrim($text, "\n");

    if ($on) $text = htaccess_ssl_block() . "\n\n" . $text;

    // Written via a temp file in the same directory and renamed, so a failure
    // part-way through cannot leave a half-written .htaccess behind — a
    // truncated one would take the whole site down with a 500.
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $text) === false) { @unlink($tmp); return false; }
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Does this request itself look like it arrived over HTTPS?
 *
 * Used by the installer to decide whether switching the redirect on is safe:
 * turning it on from a plain-http install would make the site immediately
 * unreachable.
 *
 * @return bool
 */
function request_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    // Behind a TLS-terminating proxy the connection to PHP is plain http.
    $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $fwd === 'https';
}
