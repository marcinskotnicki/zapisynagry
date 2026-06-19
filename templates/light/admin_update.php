<?php
/* =============================================================================
 *  templates/light/admin_update.php — the Update tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  An intro, the result lines from the last run (if any), and a single button
 *  that POSTs run=1 to trigger the updater (pull files + reconcile the schema).
 *
 *  RENDER VARS:
 *    $csrf    — hidden CSRF field.
 *    $results — array of translated result lines after a run, or null before one.
 * ============================================================================= */
?>
<div class="updater">
    <h2><?= e(t('update_title')) ?></h2>
    <p class="muted"><?= e(t('update_intro')) ?></p>

    <?php if (!empty($results)): // show the outcome of the run just performed ?>
        <ul class="update-log">
            <?php foreach ($results as $line): ?>
                <li><?= e($line) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="admin.php?tab=update">
        <?= $csrf ?>
        <input type="hidden" name="run" value="1">
        <button type="submit" class="btn btn-primary"><?= e(t('update_run')) ?></button>
    </form>
</div>
