<?php
/* =============================================================================
 *  templates/light/admin_update.php — the Update tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  An intro, the result lines from the last run (if any), and a single button
 *  that POSTs run=1 to trigger the updater (pull files + reconcile the schema).
 *
 *  RENDER VARS:
 *    $csrf       — hidden CSRF field.
 *    $results    — array of translated result lines after a run, or null before one.
 *    $last_run   — when the updater last finished, already formatted, or ''.
 *    $remote     — when the newest commit upstream was made, formatted, or ''.
 *    $remote_sha — that commit's short hash, or ''.
 * ============================================================================= */
?>
<div class="updater">
    <h2><?= e(t('update_title')) ?></h2>
    <p class="muted"><?= e(t('update_intro')) ?></p>

    <?php // Each line is omitted rather than shown empty: "last updated: never"
          // on a fresh install is noise, and the upstream lookup is best-effort
          // (no curl, no network, rate limit) so it must be allowed to be absent. ?>
    <dl class="update-status">
        <?php if ($last_run !== ''): ?>
            <dt><?= e(t('update_last_run')) ?></dt>
            <dd><?= e($last_run) ?></dd>
        <?php endif; ?>
        <?php if ($remote !== ''): ?>
            <dt><?= e(t('update_remote_latest')) ?></dt>
            <dd><?= e($remote) ?><?php if ($remote_sha !== ''): ?>
                <span class="muted">(<?= e($remote_sha) ?>)</span>
            <?php endif; ?></dd>
        <?php endif; ?>
    </dl>

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
