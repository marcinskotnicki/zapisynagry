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
                <a href="register.php"><?= e(t('register')) ?></a>
            <?php endif; ?>
        </nav>
        <?php
        // Guest pickers: logged-in users change these in the user panel instead,
        // so the dropdowns render only for guests — and only for the pref(s) the
        // admin enabled (allow_guest_template / allow_guest_language). Selects
        // auto-submit; the <noscript> button covers JS-free browsers. 'back'
        // returns the visitor to the page they were on.
        $showTplPick  = !is_logged_in() && tpl_switch_allowed()  && count(tpl_available())  > 1;
        $showLangPick = !is_logged_in() && lang_switch_allowed() && count(lang_available()) > 1;
        ?>
        <?php if ($showTplPick || $showLangPick): ?>
            <form class="topbar-prefs" method="post" action="prefs.php">
                <?= csrf_field() ?>
                <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
                <?php if ($showTplPick): ?>
                    <select name="template" title="<?= e(t('pref_template')) ?>" onchange="this.form.submit()">
                        <?php foreach (tpl_available() as $tn): ?>
                            <option value="<?= e($tn) ?>"<?= $tn === tpl_current() ? ' selected' : '' ?>><?= e(ucfirst($tn)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if ($showLangPick): ?>
                    <select name="lang" title="<?= e(t('pref_language')) ?>" onchange="this.form.submit()">
                        <?php foreach (lang_available() as $lc): ?>
                            <option value="<?= e($lc) ?>"<?= $lc === ($GLOBALS['LANG_CODE'] ?? '') ? ' selected' : '' ?>><?= e(strtoupper($lc)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <noscript><button type="submit" class="btn btn-small">OK</button></noscript>
            </form>
        <?php endif; ?>
    </div>
</header>
<?php // <main> is full-width; .content is the centred, width-capped column.
      // Full-bleed sections (e.g. the timeline) render OUTSIDE .content via the
      // footer's $after_content slot — no negative-margin tricks needed. ?>
<main>
<div class="content">
