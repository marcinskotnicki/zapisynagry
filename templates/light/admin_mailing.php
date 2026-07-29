<?php
/* =============================================================================
 *  templates/light/admin_mailing.php — Mailing tab body. Presentation only.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $draft     — ['audience','subject','body'] to repopulate after an error.
 *    $counts    — audience key => number of addresses, shown beside each option.
 *    $audiences — the allowed keys, in display order.
 *    $enabled   — whether the feature is switched on at all.
 *    $error     — message above the form, or null.
 *    $csrf      — hidden CSRF field.
 * ============================================================================= */
?>
<h2><?= e(t('ml_admin_title')) ?></h2>

<?php if (!$enabled): ?>
    <?php // The form is useless with the feature off, so say why rather than
          // letting someone write a message that can't be sent. ?>
    <p class="msg msg-error"><?= e(t('ml_admin_disabled')) ?></p>
<?php else: ?>
    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <p class="muted"><?= e(t('ml_admin_intro')) ?></p>

    <form method="post" action="admin.php?tab=mailing">
        <?= $csrf ?>
        <input type="hidden" name="action" value="send">

        <div class="field">
            <label for="ml_audience"><?= e(t('ml_admin_audience')) ?></label>
            <select id="ml_audience" name="audience">
                <?php foreach ($audiences as $a): ?>
                    <option value="<?= e($a) ?>"<?= ($draft['audience'] ?? '') === $a ? ' selected' : '' ?>>
                        <?= e(t('ml_aud_' . $a)) ?> (<?= (int)($counts[$a] ?? 0) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('ml_admin_audience_note')) ?></p>
        </div>

        <div class="field">
            <label for="ml_subject"><?= e(t('ml_admin_subject')) ?></label>
            <input type="text" id="ml_subject" name="subject" value="<?= e($draft['subject'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="ml_body"><?= e(t('ml_admin_body')) ?></label>
            <textarea id="ml_body" name="body" rows="10"><?= e($draft['body'] ?? '') ?></textarea>
        </div>

        <p class="poll-form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('ml_admin_send')) ?></button>
        </p>
    </form>
<?php endif; ?>
