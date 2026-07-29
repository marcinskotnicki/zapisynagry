<?php
/* =============================================================================
 *  templates/light/unsubscribed.php — result of an unsubscribe link.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY.
 *
 *  RENDER VARS:
 *    $email — the address removed, or null when the token was unknown (already
 *             used, or the event has since been deleted).
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('ml_unsub_title')) ?></h1>
    <?php if ($email !== null): ?>
        <p class="msg msg-ok"><?= e(t('ml_unsub_done', $email)) ?></p>
    <?php else: ?>
        <?php // Most likely an already-used link, which for the reader amounts
              // to the same outcome — so say so rather than raising an alarm. ?>
        <p class="msg"><?= e(t('ml_unsub_unknown')) ?></p>
    <?php endif; ?>
    <p><a class="btn" href="index.php"><?= e(t('csrf_error_home')) ?></a></p>
</div>
