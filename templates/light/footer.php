<?php
/* =============================================================================
 *  templates/light/footer.php — closing chrome for every page.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Closes the .content column, emits the optional FULL-WIDTH
 *  slot, closes <main>/<body>/<html>, and loads the shared script bundle.
 *
 *  RENDER VARS:
 *    $after_content (optional) — pre-rendered HTML placed AFTER the centred
 *      .content div but still inside <main>, i.e. at full page width. Used for
 *      wide sections like the timeline (index.php builds it via tpl_capture).
 *      Trusted HTML: always produce it with tpl_capture(), never from raw input.
 * ============================================================================= */
?>
</div>
<?php // Wrapped in its own element so it is ONE flex item alongside .content —
      // needed for the home_layout order swap (see .content/.after-content in
      // style.css). Guarded on non-empty so a page with no timeline (anything
      // but index.php) never emits a stray empty div. ?>
<?php if (!empty($after_content)): ?>
    <div class="after-content"><?= $after_content ?></div>
<?php endif; ?>
</main>
<?php
// The preference pickers. WHETHER they may switch, WHERE the switcher sits,
// and whether logged-in users see it in the chrome at all are three separate
// admin settings — switcher_visible() is the one place that combines them, so
// the header and this footer can never disagree. Selects auto-submit; the
// <noscript> button covers JS-free browsers. 'back' returns to this page.
// See the note in header.php: guarded so a partial upload (this file present,
// inc/template.php not yet) degrades to "no switcher" instead of fataling on
// every page. Falls back to the pre-placement behaviour — guests only — so the
// footer keeps doing something sensible in the meantime.
if (function_exists('switcher_visible')) {
    $showTplPick  = switcher_visible('template', 'footer', 'tpl_switch_allowed',  count(tpl_available()));
    $showLangPick = switcher_visible('language', 'footer', 'lang_switch_allowed', count(lang_available()));
} else {
    $showTplPick  = !is_logged_in() && tpl_switch_allowed()  && count(tpl_available())  > 1;
    $showLangPick = !is_logged_in() && lang_switch_allowed() && count(lang_available()) > 1;
}
?>
<?php if ($showTplPick || $showLangPick): ?>
<footer class="sitefooter">
    <form class="footer-prefs" method="post" action="prefs.php">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
        <?php if ($showTplPick): ?>
            <label class="footer-prefs-item">
                <span><?= e(t('pref_template')) ?></span>
                <select name="template" onchange="this.form.submit()">
                    <?php foreach (tpl_available() as $tn): ?>
                        <option value="<?= e($tn) ?>"<?= $tn === tpl_current() ? ' selected' : '' ?>><?= e(ucfirst($tn)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <?php if ($showLangPick): ?>
            <label class="footer-prefs-item">
                <span><?= e(t('pref_language')) ?></span>
                <select name="lang" onchange="this.form.submit()">
                    <?php foreach (lang_available() as $lc): ?>
                        <option value="<?= e($lc) ?>"<?= $lc === ($GLOBALS['LANG_CODE'] ?? '') ? ' selected' : '' ?>><?= e(strtoupper($lc)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <noscript><button type="submit" class="btn btn-small">OK</button></noscript>
    </form>
</footer>
<?php endif; ?>
<?php // Footer menu of admin-written pages, above the BGG logo. Nothing is
      // emitted when no pages exist, so the default install is unchanged.
      // Titles are ESCAPED: they are link text, and unlike the page bodies they
      // are not meant to carry markup. ?>
<?php if (function_exists('custom_pages') && ($footerPages = custom_pages())): ?>
    <nav class="footer-pages">
        <?php foreach ($footerPages as $fp): ?>
            <a href="page.php?id=<?= (int)$fp['id'] ?>"><?= e($fp['title']) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>
<div class="bgg_logo">
<img src="img/powered_by_BGG_01_SM.png" alt="Powered by BGG"/>
</div>
<?php // Admin's own HTML, at the very bottom. Deliberately NOT escaped: only an
      // admin can set this option, and its purpose is copyright lines, sponsor
      // logos and links, which need markup. Anyone who can edit it can already
      // change every other setting on the site.
      //
      // Guarded on non-empty so the default install emits no stray element. ?>
<?php if (trim((string)opt('footer_custom_text')) !== ''): ?>
    <div id="footer_custom"><?= opt('footer_custom_text') ?></div>
<?php endif; ?>
<?php
// The chat panel, when switched on. Rendered here rather than in each page
// controller so it is present site-wide from one place; it starts closed and
// costs a hidden <aside> until the visitor opens it.
if (chat_enabled()):
    tpl_render('chat_panel', [
        'csrf'       => csrf_field(),
        'antibot'    => antibot_field(),
        'logged_in'  => is_logged_in(),
        'guest_name' => is_logged_in() ? '' : guest_identity()['name'],
    ]);
endif;
?>
<?php
// Load the shared script bundle if it exists. We check existence so a brand-new
// install (or a build where js/ isn't present yet) doesn't emit a 404 for it.
// The ?v=<mtime> suffix busts the browser cache whenever scripts.js changes.
$jsRel = 'js/scripts.js';
if (is_file(__DIR__ . '/../../' . $jsRel)):
    $jsV = @filemtime(__DIR__ . '/../../' . $jsRel) ?: 0;
?>
<script src="<?= e($jsRel) ?>?v=<?= $jsV ?>"></script>
<?php endif; ?>
</body>
</html>
