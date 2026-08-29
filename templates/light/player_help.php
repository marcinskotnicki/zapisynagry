<?php
/* =============================================================================
 *  templates/light/player_help.php — the players' guide.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. $html arrives already rendered AND already escaped by
 *  md_render(), which escapes every line before producing a single tag — so it
 *  is echoed raw, and that is the one place here that skips e().
 *
 *  Reuses .admin-help for the document styling: it is the same kind of thing —
 *  a long piece of prose with headings and callouts — and a second near-identical
 *  block of CSS would only drift from the first.
 *
 *  RENDER VARS:
 *    $html    — the guide as HTML, or null when the file is missing.
 *    $missing — the path that was not found, when $html is null.
 * ============================================================================= */
?>
<div class="card admin-help player-help">
    <?php if ($html === null): ?>
        <p class="msg msg-error"><?= e(t('help_missing', $missing)) ?></p>
    <?php else: ?>
        <?= $html ?>
    <?php endif; ?>
</div>
