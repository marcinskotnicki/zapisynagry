<?php
/* =============================================================================
 *  templates/light/admin_site_poll.php — one poll: results and its answers.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Every answer, including voters' answers still waiting for
 *  approval — which appear only here until approved.
 *
 *  RENDER VARS:
 *    $poll    — the poll row.
 *    $options — every answer, approved or not.
 *    $tally   — [option_id => votes].
 *    $csrf    — hidden CSRF field.
 * ============================================================================= */
$pid   = (int)$poll['id'];
$total = array_sum($tally);
// Winners among APPROVED answers only — a pending one is not on the public poll.
$approved = array_values(array_filter($options, function ($o) { return (int)$o['is_approved'] === 1; }));
$winners  = sp_concluded($poll) ? sp_winners($approved, $tally) : [];
?>
<p><a href="admin.php?tab=site_polls">&larr; <?= e(t('sp_back')) ?></a></p>
<h3><?= e($poll['title']) ?></h3>

<?php // What this poll was set up to do, at a glance. ?>
<ul class="sp-settings muted">
    <li><?= e(sp_seconds_left($poll) === null ? t('sp_unlimited')
              : (sp_concluded($poll) ? t('sp_completed') : t('sp_ends_at', $poll['ends_at'] . ' UTC'))) ?></li>
    <?php foreach (['show_results', 'multi', 'vote_login_only', 'allow_custom', 'custom_login_only', 'custom_needs_approval'] as $k): ?>
        <?php if ((int)$poll[$k] === 1): ?><li><?= e(t('sp_f_' . $k)) ?></li><?php endif; ?>
    <?php endforeach; ?>
    <li><?= e(t('sp_total_votes', $total)) ?></li>
</ul>

<table class="grid">
    <thead>
        <tr>
            <th><?= e(t('sp_col_answer')) ?></th>
            <th><?= e(t('sp_col_votes')) ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($options as $o): ?>
        <?php $oid = (int)$o['id']; $pending = (int)$o['is_approved'] !== 1; $n = $tally[$oid] ?? 0; ?>
        <tr class="<?= $pending ? 'sp-pending' : '' ?><?= in_array($oid, $winners, true) ? ' sp-option-winner' : '' ?>">
            <td data-label="<?= e(t('sp_col_answer')) ?>">
                <?= e($o['text']) ?>
                <?php if ($pending): ?><span class="badge"><?= e(t('sp_pending')) ?></span><?php endif; ?>
                <?php if (!empty($o['added_by_user_id']) || !empty($o['added_by_ip'])): ?>
                    <span class="muted">— <?= e(t('sp_added_by_voter')) ?></span>
                <?php endif; ?>
            </td>
            <td data-label="<?= e(t('sp_col_votes')) ?>">
                <div class="sp-result<?= in_array($oid, $winners, true) ? ' sp-winner' : '' ?>">
                    <div class="sp-bar"><span style="width:<?= $total > 0 ? (int)round($n / $total * 100) : 0 ?>%"></span></div>
                    <span class="sp-count"><?= (int)$n ?></span>
                </div>
            </td>
            <td class="row-actions">
                <?php if ($pending): ?>
                    <form method="post" action="admin.php?tab=site_polls&amp;poll=<?= $pid ?>" class="inline">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="approve_option">
                        <input type="hidden" name="poll" value="<?= $pid ?>">
                        <input type="hidden" name="option" value="<?= $oid ?>">
                        <button class="btn btn-small"><?= e(t('sp_approve')) ?></button>
                    </form>
                <?php endif; ?>
                <form method="post" action="admin.php?tab=site_polls&amp;poll=<?= $pid ?>" class="inline"
                      onsubmit="return confirm('<?= e(t('sp_delete_option_confirm')) ?>');">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="delete_option">
                    <input type="hidden" name="poll" value="<?= $pid ?>">
                    <input type="hidden" name="option" value="<?= $oid ?>">
                    <button class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<form method="post" action="admin.php?tab=site_polls&amp;poll=<?= $pid ?>" class="sp-add-option">
    <?= $csrf ?>
    <input type="hidden" name="action" value="add_option">
    <input type="hidden" name="poll" value="<?= $pid ?>">
    <div class="field">
        <label for="sp_new_option"><?= e(t('sp_add_option')) ?></label>
        <input type="text" id="sp_new_option" name="text" required maxlength="<?= (int)TEXT_NAME_MAX ?>">
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-small"><?= e(t('sp_add')) ?></button></div>
</form>

<?php // Deleting the whole poll — set apart, and confirmed: it takes every vote with it. ?>
<form method="post" action="admin.php?tab=site_polls" class="sp-delete-poll"
      onsubmit="return confirm('<?= e(t('sp_delete_poll_confirm')) ?>');">
    <?= $csrf ?>
    <input type="hidden" name="action" value="delete_poll">
    <input type="hidden" name="poll" value="<?= $pid ?>">
    <button class="btn btn-small btn-danger"><?= e(t('sp_delete_poll')) ?></button>
</form>
