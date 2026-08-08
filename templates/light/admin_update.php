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
 *    $remote_why — why the upstream check could not run, or '' when it did.
 *    $checked_at — when the stored answer was taken, or '' if never checked.
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
        <?php // The upstream row is ALWAYS present, so the button beside it is
              // discoverable before anyone has ever pressed it. What changes is
              // the value: a date, a failure reason, or an invitation to check. ?>
        <dt><?= e(t('update_remote_latest')) ?></dt>
        <dd>
            <?php if ($remote !== ''): ?>
                <?= e($remote) ?><?php if ($remote_sha !== ''): ?>
                    <span class="muted">(<?= e($remote_sha) ?>)</span>
                <?php endif; ?>
                <?php // Dated, because nothing refreshes this on its own any
                      // more — a week-old answer would otherwise look current. ?>
                <?php if ($checked_at !== ''): ?>
                    <span class="muted update-checked"><?= e(t('update_checked_at', $checked_at)) ?></span>
                <?php endif; ?>
            <?php elseif (!empty($remote_why)): ?>
                <?php // Shown, not hidden: a check that cannot run is worth one
                      // muted line, so nobody has to guess whether the feature
                      // is broken or the host has no outbound network. ?>
                <span class="muted"><?= e(t('update_remote_unavailable', $remote_why)) ?></span>
            <?php else: ?>
                <span class="muted"><?= e(t('update_remote_never')) ?></span>
            <?php endif; ?>
        </dd>
    </dl>

    <?php // Its own form: this only reads from GitHub, and must never be one
          // mis-click away from actually updating the site. Separate button,
          // separate POST field, checked separately in the controller. ?>
    <form method="post" action="admin.php?tab=update" class="update-check">
        <?= $csrf ?>
        <input type="hidden" name="check" value="1">
        <button type="submit" class="btn btn-small"><?= e(t('update_check_now')) ?></button>
    </form>

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
