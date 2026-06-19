<?php
/* =============================================================================
 *  templates/light/header.php — opening chrome for every page.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Emits the <head>, the top bar, and opens <main>; every
 *  page renders this first (via tpl_render('header', ...)) and footer.php last.
 *
 *  Reads request state through read-only helpers only — t() (copy), e()
 *  (escaping), is_logged_in()/is_admin() (which nav links to show),
 *  tpl_css_url() (the active theme's stylesheet), opt() (venue name) — and
 *  contains no business logic or writes.
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
        <a class="brand" href="index.php"><?= e(opt('venue_name') ?: t('app_name')) ?></a>
        <nav class="topnav">
            <?php if (is_admin()): // admins get the panel link ?>
                <a href="admin.php"><?= e(t('admin')) ?></a>
            <?php endif; ?>
            <?php if (is_logged_in()): // logged in: panel + logout ?>
                <a href="user.php"><?= e(t('user_panel')) ?></a>
                <a href="logout.php"><?= e(t('logout')) ?></a>
            <?php elseif (opt('registration_mode') !== 'guest_only'): // guest-only mode hides login ?>
                <a href="login.php"><?= e(t('login')) ?></a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="content">
