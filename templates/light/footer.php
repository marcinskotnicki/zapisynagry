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
<?= $after_content ?? '' ?>
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
<div class="bgg_logo">
<img src="img/powered_by_BGG_01_SM.png" alt="Powered by BGG"/>
</div>
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
