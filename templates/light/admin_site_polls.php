<?php
/* =============================================================================
 *  templates/light/admin_site_polls.php — the Polls tab: list + new poll.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY.
 *
 *  RENDER VARS:
 *    $polls   — every poll, newest first.
 *    $pending — [poll_id => answers awaiting approval].
 *    $csrf    — hidden CSRF field.
 * ============================================================================= */

/** "3 h 20 min left" / "completed" / "unlimited". */
$spLeft = function ($p) {
    $s = sp_seconds_left($p);
    if ($s === null) return t('sp_unlimited');
    if ($s <= 0)     return t('sp_completed');
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    return t('sp_time_left', $h > 0 ? $h . ' h ' . $m . ' min' : max(1, $m) . ' min');
};
?>
<h3><?= e(t('sp_admin_title')) ?></h3>

<?php // Closed until opened: most visits are to check results, not to add a poll. ?>
<details class="card sp-new">
    <summary class="btn btn-primary"><?= e(t('sp_new')) ?></summary>
    <form method="post" action="admin.php?tab=site_polls">
        <?= $csrf ?>
        <input type="hidden" name="action" value="create">
        <div class="field">
            <label for="sp_title"><?= e(t('sp_f_title')) ?></label>
            <input type="text" id="sp_title" name="title" required maxlength="<?= (int)TEXT_NAME_MAX ?>">
        </div>
        <div class="field">
            <label for="sp_answers"><?= e(t('sp_f_answers')) ?></label>
            <textarea id="sp_answers" name="answers" rows="5"></textarea>
            <p class="field-note"><?= e(t('sp_f_answers_note')) ?></p>
        </div>
        <div class="field">
            <label for="sp_hours"><?= e(t('sp_f_hours')) ?></label>
            <input type="number" id="sp_hours" name="length_hours" min="0" value="0">
            <p class="field-note"><?= e(t('sp_f_hours_note')) ?></p>
        </div>
        <?php foreach (['show_results', 'multi', 'vote_login_only'] as $spBox): ?>
            <div class="field field-check">
                <label><input type="checkbox" name="<?= $spBox ?>" value="1"> <?= e(t('sp_f_' . $spBox)) ?></label>
            </div>
        <?php endforeach; ?>
        <div class="field field-check">
            <label><input type="checkbox" name="allow_custom" value="1" class="js-sp-custom"> <?= e(t('sp_f_allow_custom')) ?></label>
        </div>
        <?php /* Only meaningful with custom answers on. Shown always (indented),
                 and simply stored as off if custom answers are not allowed —
                 no script needed, and nothing half-set can be saved. */ ?>
        <div class="sp-custom-opts">
            <?php foreach (['custom_login_only', 'custom_needs_approval'] as $spBox): ?>
                <div class="field field-check">
                    <label><input type="checkbox" name="<?= $spBox ?>" value="1"> <?= e(t('sp_f_' . $spBox)) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('sp_create')) ?></button>
        </div>
    </form>
</details>

<?php if (!$polls): ?>
    <p class="muted"><?= e(t('sp_none')) ?></p>
<?php else: ?>
    <table class="grid">
        <thead>
            <tr>
                <th><?= e(t('sp_col_title')) ?></th>
                <th><?= e(t('sp_col_time')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($polls as $p): ?>
            <?php $pid = (int)$p['id']; $on = (int)$p['is_enabled'] === 1; ?>
            <tr class="<?= $on ? '' : 'sp-row-off' ?>">
                <td data-label="<?= e(t('sp_col_title')) ?>">
                    <?= e($p['title']) ?>
                    <?php if (!$on): ?><span class="muted">(<?= e(t('sp_off')) ?>)</span><?php endif; ?>
                    <?php if (!empty($pending[$pid])): ?>
                        <?php // So the admin knows which poll has answers to approve. ?>
                        <span class="badge"><?= e(t('sp_pending_n', (int)$pending[$pid])) ?></span>
                    <?php endif; ?>
                </td>
                <td data-label="<?= e(t('sp_col_time')) ?>"><?= e($spLeft($p)) ?></td>
                <td class="row-actions">
                    <a class="btn btn-small" href="admin.php?tab=site_polls&amp;poll=<?= $pid ?>"><?= e(t('sp_details')) ?></a>
                    <form method="post" action="admin.php?tab=site_polls" class="inline">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="poll" value="<?= $pid ?>">
                        <button class="btn btn-small"><?= e(t($on ? 'sp_disable' : 'sp_enable')) ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
