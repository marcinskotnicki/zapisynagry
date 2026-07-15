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
