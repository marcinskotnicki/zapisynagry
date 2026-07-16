<?php
/* =============================================================================
 *  inc/template.php — theming / view rendering.
 * -----------------------------------------------------------------------------
 *  A template is a folder under /templates containing presentation files only
 *  (HTML + CSS). Logic NEVER lives in a template; logic pages prepare data and
 *  then call tpl_render() to emit HTML.
 *
 *  BASE_TEMPLATE ('light') is the reference theme that contains every template
 *  file. Other themes override only what they want; anything missing falls back
 *  to the base. That's why the bundled 'dark' theme can be just a stylesheet.
 *
 *  Selection order mirrors language: a valid 'template' cookie wins, else the
 *  admin default, else the base.
 *
 *  RENDERING CONTRACT: a controller gathers data, then calls
 *      tpl_render('header', [...]);  tpl_render('some_view', [...]);  tpl_render('footer');
 *  Each $vars entry becomes a local variable inside the template file. Inside
 *  templates, use t() for copy and e() for escaping — both are global.
 *
 *  ADDING A TEMPLATE FILE: create it in templates/light/ (the base) so every
 *  theme inherits it. Only add a copy under another theme when that theme needs
 *  different markup (rare — usually only the CSS differs).
 * ============================================================================= */

// The reference theme. Contains one file for every view; the fallback target.
const BASE_TEMPLATE = 'light';

/**
 * Available themes = subdirectories of /templates.
 * @return string[]  e.g. ['dark', 'light']
 */
function tpl_available() {
    $names = [];
    foreach (glob(__DIR__ . '/../templates/*', GLOB_ONLYDIR) as $dir) {
        $names[] = basename($dir);
    }
    return $names;
}

/**
 * True if $name is a real template directory.
 * Regex whitelist first, so a cookie value can't traverse the filesystem.
 * @return bool
 */
function tpl_exists($name) {
    return $name !== '' && preg_match('/^[a-z0-9_-]+$/i', $name)
        && is_dir(__DIR__ . '/../templates/' . $name);
}

/**
 * Which theme to use this request: per-user cookie, else admin default, else base.
 * @return string  A theme name guaranteed to exist (base is always present).
 */
/**
 * May the CURRENT VISITOR change the theme? Two admin toggles decide:
 * 'allow_user_template' for accounts, 'allow_guest_template' for guests.
 * Gates both the switcher UI and (in tpl_current) whether the cookie is even
 * honoured — so flipping a toggle off immediately neutralises old cookies.
 * @return bool
 */
function tpl_switch_allowed() {
    return opt_bool(is_logged_in() ? 'allow_user_template' : 'allow_guest_template');
}

function tpl_current() {
    $cookie = $_COOKIE['template'] ?? '';
    if (tpl_switch_allowed() && tpl_exists($cookie)) return $cookie;

    $default = opt('default_template', BASE_TEMPLATE);
    if (tpl_exists($default)) return $default;

    return BASE_TEMPLATE;
}

/**
 * Remember the active theme for the request. Called by bootstrap.
 * Stored in a global so the resolve/render helpers below don't recompute it.
 * @return void
 */
function tpl_init() {
    $GLOBALS['TEMPLATE'] = tpl_current();
}

/**
 * Resolve a template file by name (without extension), preferring the active
 * theme and falling back to the base theme. Returns an absolute path or null.
 *
 * This two-step lookup is the whole "themes override only what they want"
 * mechanism: active theme first, base theme second.
 *
 * @param string $name  View name, e.g. 'header', 'game_card'.
 * @return string|null  Absolute path, or null if neither theme has the file.
 */
function tpl_file($name) {
    $active = $GLOBALS['TEMPLATE'] ?? BASE_TEMPLATE;
    $tryActive = __DIR__ . '/../templates/' . $active . '/' . $name . '.php';
    if (is_file($tryActive)) return $tryActive;

    $tryBase = __DIR__ . '/../templates/' . BASE_TEMPLATE . '/' . $name . '.php';
    if (is_file($tryBase)) return $tryBase;

    return null;   // caller (tpl_render) prints an HTML comment in this case
}

/**
 * Render a template file, making $vars available as local variables inside it.
 * Templates use t() for text and e() for escaping; both are global helpers.
 *
 * EXTR_SKIP: if a $vars key would clobber an existing local (like $name/$file
 * here), it's skipped rather than overwriting — a small safety net.
 *
 * @param string $name  View name.
 * @param array  $vars  key => value pairs exposed as $key inside the template.
 * @return void          Echoes directly to the output buffer.
 */
function tpl_render($__tpl_name, array $__tpl_vars = []) {
    // NOTE the odd local names: extract(..., EXTR_SKIP) refuses to overwrite
    // existing locals, so ordinary names here would silently SWALLOW render
    // vars with the same name (a $vars['name'] used to arrive as the template
    // name instead of the value — the "admin_new_event" bug). The __tpl_
    // prefix makes a collision with real render vars practically impossible.
    $__tpl_file = tpl_file($__tpl_name);
    if ($__tpl_file === null) {
        echo "<!-- template '$__tpl_name' not found -->";
        return;
    }
    extract($__tpl_vars, EXTR_SKIP);
    include $__tpl_file;
}

/**
 * Render a template to a string instead of echoing (for nesting views).
 * Used when one view needs another's HTML as a value (output buffering).
 * @return string
 */
function tpl_capture($name, array $vars = []) {
    ob_start();
    tpl_render($name, $vars);
    return ob_get_clean();
}

/**
 * URL to the active theme's stylesheet (falls back to base if the active theme
 * has no own CSS). Used in the <head>.
 *
 * The `?v=<mtime>` suffix is a cache-buster: when you edit the CSS, its modified
 * time changes, the URL changes, and browsers fetch the new file instead of a
 * stale cached copy.
 *
 * @return string  Relative URL like 'templates/dark/css/style.css?v=1718...'.
 */
function tpl_css_url() {
    $active = $GLOBALS['TEMPLATE'] ?? BASE_TEMPLATE;
    $rel = 'templates/' . $active . '/css/style.css';
    if (!is_file(__DIR__ . '/../' . $rel)) {
        $rel = 'templates/' . BASE_TEMPLATE . '/css/style.css';
    }
    // Cache-bust with the file's mtime so theme edits show up immediately.
    $mtime = @filemtime(__DIR__ . '/../' . $rel) ?: 0;
    return $rel . '?v=' . $mtime;
}

/**
 * Set the per-user theme cookie (one year). Used by the switcher.
 * Ignores unknown themes so a bad value can't poison the cookie.
 * @return void
 */
function tpl_set_cookie($name) {
    if (tpl_exists($name)) {
        setcookie('template', $name, time() + 31536000, '/');   // 365 days
    }
}
