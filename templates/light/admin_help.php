<?php
/* =============================================================================
 *  templates/light/admin_help.php — the getting-started guide.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. $html arrives already rendered and already escaped by
 *  md_render(), which escapes every line before it produces a single tag — so
 *  it is echoed raw here, and that is the one place in this file where output
 *  is not passed through e().
 *
 *  RENDER VARS:
 *    $html    — the guide as HTML, or null if the file is missing.
 *    $missing — the path that was not found, when $html is null.
 * ============================================================================= */
?>
<div class="card admin-help">
    <?php if ($html === null): ?>
        <?php // Naming the file matters: an admin can pass that on to whoever
              // installed the system, instead of reporting "help is broken". ?>
        <p class="msg msg-error"><?= e(t('help_missing', $missing)) ?></p>
    <?php else: ?>
        <?php // Already escaped by md_render() — see the note above. ?>
        <?= $html ?>
    <?php endif; ?>
</div>
