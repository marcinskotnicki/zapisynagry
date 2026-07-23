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
    <link rel="stylesheet" href="<?= e(tpl_css_url()) ?>">
    <?php // The one allowed inline JS: expose chosen UI strings to client scripts. ?>
    <script>
        window.APP_LANG = <?= json_encode([
            // Add client-facing strings here as the front end needs them.
            'confirm' => t('yes'),
            'cancel'  => t('cancel'),
        ], JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body class="tpl-<?= e($GLOBALS['TEMPLATE'] ?? 'light') ?>">
<header class="topbar">
    <div class="topbar-inner">
        <?php // Brand (venue name) top-left, unless the admin hid it (when the
              // venue and event names are the same, showing both is redundant).
              // An empty spacer keeps the nav right-aligned via space-between. ?>
        <?php if (opt_bool('show_venue_name')): ?>
            <a class="brand" href="index.php"><?= e(opt('venue_name') ?: t('app_name')) ?></a>
        <?php else: ?>
            <span class="brand-spacer"></span>
        <?php endif; ?>
        <nav class="topnav">
            <?php
            // Hide the link for the page you're already on, and show a Home link
            // on the panel pages (but not on index itself). $here is the current
            // script; nav_link() applies the icon/text style option.
            $here = current_page();
            if ($here !== 'index.php') {
                echo nav_link('index.php', 'home', t('nav_home'));
            }
            ?>
            <?php if (is_admin() && $here !== 'admin.php'): // admins get the panel link (not on the panel) ?>
                <?= nav_link('admin.php', 'admin', t('admin')) ?>
            <?php endif; ?>
            <?php if (is_logged_in()): // logged in: panel (unless on it) + logout ?>
                <?php if ($here !== 'user.php'): ?>
                    <?= nav_link('user.php', 'user', t('user_panel')) ?>
                <?php endif; ?>
                <?= nav_link('logout.php', 'logout', t('logout')) ?>
            <?php elseif (opt('registration_mode') !== 'guest_only'): // guest-only mode hides login ?>
                <?= nav_link('login.php', 'login', t('login')) ?>
                <?= nav_link('register.php', 'register', t('register')) ?>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php // <main> is full-width; .content is the centred, width-capped column.
      // Full-bleed sections (e.g. the timeline) render OUTSIDE .content via the
      // footer's $after_content slot — no negative-margin tricks needed. ?>
<main>
<div class="content">
