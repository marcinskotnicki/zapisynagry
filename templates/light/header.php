<?php
/* =============================================================================
 *  templates/light/header.php — opening chrome for every page.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Emits the <head>, the top bar, and opens <main>; every
 *  page renders this first (via tpl_render('header', ...)) and footer.php last.
 *
 *  Reads request state through read-only helpers only — t() (copy), e()
 *  (escaping), is_logged_in()/is_admin() (which nav links to show),
 *  current_page() (to hide the link for the page you're on), nav_link() (the
 *  icon/text style option), tpl_css_url() (the active theme's stylesheet),
 *  opt() (venue name) — and contains no business logic or writes.
 *
 *  NAV: a Home link shows on every page except index; the link for the current
 *  page is omitted. Guest theme/language pickers live in the FOOTER now, not
 *  here (logged-in users set those in the user panel).
 *
 *  RENDER VARS:
 *    $page_title (optional) — the <title> prefix; defaults to the app name.
 *
 *  THEMING: <body> gets a `tpl-<theme>` class so a theme's CSS can scope rules
 *  (e.g. the dark theme overrides variables under .tpl-dark). The <html lang>
 *  comes from the resolved language code.
 * ============================================================================= */
$page_title = $page_title ?? t('app_name');
?>
<!doctype html>
<html lang="<?= e($GLOBALS['LANG_CODE'] ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title) ?> — <?= e(opt('venue_name') ?: t('app_name')) ?></title>
    <?php
    // Site icon links, only when the admin uploaded one (Thumbnails tab). The
    // 'site_icon' option holds a version stamp; ?v= busts favicon caches after
    // a replacement upload. Files live under /icons with fixed names.
    $iconV = opt('site_icon');
    ?>
    <?php if ($iconV !== ''): ?>
        <link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32.png?v=<?= e($iconV) ?>">
        <link rel="icon" type="image/png" sizes="16x16" href="icons/favicon-16.png?v=<?= e($iconV) ?>">
        <link rel="apple-touch-icon" sizes="180x180" href="icons/apple-touch-icon.png?v=<?= e($iconV) ?>">
        <link rel="manifest" href="icons/site.webmanifest?v=<?= e($iconV) ?>">
    <?php endif; ?>
    <?php // Base first, then the theme. Two links rather than an @import inside
          // the theme file: each gets its own ?v= stamp, so editing EITHER busts
          // the cache, and they download in parallel. ?>
    <?php foreach (tpl_css_urls() as $cssUrl): ?>
        <link rel="stylesheet" href="<?= e($cssUrl) ?>">
    <?php endforeach; ?>
    <?php /* The admin's own CSS, last so it wins over the theme without anyone
             needing to write !important. custom_css_block() returns '' when
             there is none AND on the admin panel — see its note for why that
             exclusion is the thing keeping a bad paste recoverable.
             NOT escaped with e(): this is a stylesheet, not text, and entity
             encoding would break every > child combinator and " in a font
             stack. The helper does the one escape a <style> block needs.

             function_exists() GUARDS THIS SPECIFIC CALL, not helper calls in
             general: header.php runs on every single page, and a function
             defined only in a NEWER inc/template.php than the one this
             process already required (via require_once earlier in the same
             request) is undefined here no matter how current the file on
             disk now is — that took a live site down mid-update, once. The
             admin controller now redirects after running the updater so its
             OWN render is never affected; this guard is the fallback for
             everything the redirect can't reach — most plausibly a host
             whose OPcache does not share memory across workers, so a sibling
             worker keeps serving a stale compile of this file for a moment
             after another worker updates it. Skipping a decorative feature
             for one stale render beats a fatal error on every page. */ ?>
    <?php $customCss = function_exists('custom_css_block') ? custom_css_block() : ''; ?>
    <?php if ($customCss !== ''): ?>
        <style><?= $customCss ?></style>
    <?php endif; ?>
    <?php // The one allowed inline JS: expose chosen UI strings to client scripts. ?>
    <script>
        window.APP_LANG = <?= json_encode([
            // Add client-facing strings here as the front end needs them.
            'confirm' => t('yes'),
            'cancel'  => t('cancel'),
            // Poll deadline live preview (add_poll.php) — {date}/{weekday}/{time} tokens.
            'pollDeadlinePreview' => t('poll_deadline_preview'),
            'pollNoAutoDeadline'  => t('poll_no_auto_deadline'),
            'weekdays' => [
                t('weekday_0'), t('weekday_1'), t('weekday_2'), t('weekday_3'),
                t('weekday_4'), t('weekday_5'), t('weekday_6'),
            ],
            // Chat panel strings. Always sent (they are a handful of bytes) so
            // the block stays one shape whether or not the chat is on.
            'chatEmpty'   => t('chat_empty'),
            'chatFailed'  => t('chat_failed'),
            'chatErrName' => t('chat_err_name'),
            'chatErrEmpty'=> t('chat_err_empty'),
            'chatErrLong' => t('chat_err_long'),
        ], JSON_UNESCAPED_UNICODE) ?>;
        <?php // Chat runtime config. Absent entirely when the chat is off, so
              // scripts.js has one unambiguous signal to check rather than
              // having to infer it from a flag AND the panel's presence. ?>
        <?php if (chat_enabled()): ?>
        window.APP_CHAT = <?= json_encode([
            'refresh'   => chat_refresh_seconds() * 1000,
            'sendDelay' => chat_send_delay() * 1000,
            'closeOutside' => opt_bool('chat_close_outside'),
        ], JSON_UNESCAPED_UNICODE) ?>;
        <?php endif; ?>
    </script>
</head>
<?php // The home-page block order rides on the body as one class per slot
      // (layout-1-content, layout-2-mail, ...). Emitting the POSITION rather
      // than the layout's name keeps the CSS to three rules instead of one set
      // per layout, and adding a fifth arrangement later needs no new CSS. ?>
<?php $layoutSlots = ''; ?>
<?php foreach (home_layout_order() as $slotNo => $slotName): ?>
    <?php $layoutSlots .= ' layout-' . ($slotNo + 1) . '-' . $slotName; ?>
<?php endforeach; ?>
<body class="tpl-<?= e($GLOBALS['TEMPLATE'] ?? 'light') ?><?= e($layoutSlots) ?>">
<?php
// Build the nav BEFORE deciding whether to draw the bar at all. With the venue
// name hidden AND accounts set to guest-only, a visitor's top bar can come out
// completely empty — no brand, no login (guest-only suppresses it), and no Home
// link on the home page itself. An empty bar is just a stray grey stripe, so in
// that case the whole <header> is skipped.
//
// Deliberately driven by "did anything render?" rather than by testing those two
// options directly: that way an admin still keeps their panel/logout links, and
// a guest on a sub-page still keeps Home, in exactly the same configuration.
// Hard-coding the option check would strand people on sub-pages with no way back.
$here = current_page();
ob_start();
    // Home, except on the home page. nav_link() applies the icon/text option.
    if ($here !== 'index.php') {
        echo nav_link('index.php', 'home', t('nav_home'));
    }
    // Public archive list, when the feature is on and we're not already on it.
    if (public_archives_enabled() && $here !== 'archive.php') {
        echo nav_link('archive.php', 'archive', t('archive_title'));
    }
    if (public_archives_enabled() && $here !== 'calendar.php') {
        echo nav_link('calendar.php', 'calendar', t('calendar_title'));
    }
    // The club library, when the admin has switched it on. Public, like the
    // archive above it — the whole point is that anyone can see who owns what.
    if (library_any_enabled() && $here !== 'library.php') {
        echo nav_link('library.php', 'library', t('lib_title'));
    }
    if (is_admin() && $here !== 'admin.php') {          // admins: the panel link
        echo nav_link('admin.php', 'admin', t('admin'));
    }
    if (is_logged_in()) {                               // panel (unless on it) + logout
        if ($here !== 'user.php') {
            echo nav_link('user.php', 'user', t('user_panel'));
        }
        echo nav_link('logout.php', 'logout', t('logout'));
    } elseif (opt('registration_mode') !== 'guest_only') {   // guest-only hides login
        echo nav_link('login.php', 'login', t('login'));
        echo nav_link('register.php', 'register', t('register'));
    }
$topnav   = trim(ob_get_clean());
// 'auto' | 'both' | 'none' — see header_brand_mode(). $showName stays as the
// "is there anything in the left slot" test the rest of this template uses.
$brandMode = header_brand_mode();
$showName  = $brandMode !== 'none';

// The preference pickers, when the admin has placed them in the header. Three
// separate settings decide this (may they switch / where / do logged-in users
// see it); switcher_visible() combines them, and the footer asks the same
// question for its own slot, so the two can never disagree.
// function_exists guard: this template and inc/template.php (which defines
// switcher_visible) must ship together, but an FTP upload that copies one and
// not the other would otherwise take the WHOLE SITE down with a fatal — every
// page renders this header. Degrading to "no header switcher" is a far better
// failure than a white screen, and the footer keeps working meanwhile.
$hdrTplPick  = function_exists('switcher_visible')
    && switcher_visible('template', 'header', 'tpl_switch_allowed',  count(tpl_available()));
$hdrLangPick = function_exists('switcher_visible')
    && switcher_visible('language', 'header', 'lang_switch_allowed', count(lang_available()));
?>
<?php // The bar hides itself when it would be empty (see the note above), so
      // header switchers have to count as content — otherwise placing them
      // here on an otherwise-bare bar would render nothing at all. ?>
<?php if ($showName || $topnav !== '' || $hdrTplPick || $hdrLangPick): ?>
<header class="topbar">
    <div class="topbar-inner">
        <?php // Brand (venue name) top-left, unless the admin hid it (when the
              // venue and event names are the same, showing both is redundant).
              // An empty spacer keeps the nav right-aligned via space-between.
              // If a logo is uploaded AND the venue name would otherwise show,
              // the logo REPLACES the text — same slot, same link, same "hide
              // it all" toggle. No logo -> unchanged text behaviour, exactly as
              // before this feature existed. ?>
        <?php $logoV = opt('site_logo'); ?>
        <?php $brandText = opt('venue_name') ?: t('app_name'); ?>
        <?php if ($brandMode === 'both' && $logoV !== ''): ?>
            <?php // Logo first, then the name beside it — one link, so the whole
                  // thing is a single target back to the front page. The alt is
                  // empty here because the name is right there as real text;
                  // repeating it would have a screen reader say it twice. ?>
            <a class="brand brand-both" href="index.php">
                <img src="logo/logo.png?v=<?= e($logoV) ?>" alt="">
                <span><?= e($brandText) ?></span>
            </a>
        <?php elseif ($showName && $logoV !== ''): ?>
            <?php // 'auto': the logo stands in for the name. ?>
            <a class="brand brand-logo" href="index.php">
                <img src="logo/logo.png?v=<?= e($logoV) ?>" alt="<?= e($brandText) ?>">
            </a>
        <?php elseif ($showName): ?>
            <a class="brand" href="index.php"><?= e($brandText) ?></a>
        <?php else: ?>
            <?php // An empty spacer keeps the nav right-aligned via space-between. ?>
            <span class="brand-spacer"></span>
        <?php endif; ?>
        <?php // Hamburger. Only shown on narrow screens (CSS), and only when
              // there is actually a nav to toggle — with no links it would be a
              // button that opens nothing. Rendered as a real <button> with
              // aria-expanded so it announces its state; the panel it controls
              // is the same <nav>, which stays in the DOM either way so nothing
              // depends on JS having run. ?>
        <?php if ($topnav !== ''): ?>
            <button type="button" class="nav-burger" id="nav-burger"
                    aria-expanded="false" aria-controls="topnav"
                    aria-label="<?= e(t('nav_menu')) ?>" title="<?= e(t('nav_menu')) ?>">
                <span class="nav-burger-bars" aria-hidden="true"></span>
            </button>
        <?php endif; ?>
        <nav class="topnav" id="topnav"><?= $topnav ?></nav>
        <?php if ($hdrTplPick || $hdrLangPick): ?>
            <form class="topbar-prefs" method="post" action="prefs.php">
                <?= csrf_field() ?>
                <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
                <?php if ($hdrTplPick): ?>
                    <label class="topbar-prefs-item">
                        <span class="sr-only"><?= e(t('pref_template')) ?></span>
                        <select name="template" onchange="this.form.submit()"
                                aria-label="<?= e(t('pref_template')) ?>">
                            <?php foreach (tpl_available() as $tn): ?>
                                <option value="<?= e($tn) ?>"<?= $tn === tpl_current() ? ' selected' : '' ?>><?= e(ucfirst($tn)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <?php if ($hdrLangPick): ?>
                    <label class="topbar-prefs-item">
                        <span class="sr-only"><?= e(t('pref_language')) ?></span>
                        <select name="lang" onchange="this.form.submit()"
                                aria-label="<?= e(t('pref_language')) ?>">
                            <?php foreach (lang_available() as $lc): ?>
                                <option value="<?= e($lc) ?>"<?= $lc === ($GLOBALS['LANG_CODE'] ?? '') ? ' selected' : '' ?>><?= e(strtoupper($lc)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <noscript><button type="submit" class="btn btn-small">OK</button></noscript>
            </form>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>
<?php // <main> is full-width; .content is the centred, width-capped column.
      // Full-bleed sections (e.g. the timeline) render OUTSIDE .content via the
      // footer's $after_content slot — no negative-margin tricks needed. ?>
<main>
<div class="content">
