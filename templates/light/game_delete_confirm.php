<?php
/* =============================================================================
 *  templates/light/game_delete_confirm.php — 3-way delete confirm. Presentation.
 * -----------------------------------------------------------------------------
 *  RENDER VAR $mode — the admin's game_deletion setting: 'choose' offers both
 *  removals, 'soft'/'hard' offer only that one.
 *
 *  One form, three submit buttons distinguished by the "choice" value the
 *  controller reads: back (do nothing) / archive (soft-delete, recoverable) /
 *  everything (hard delete). The verification challenge (if needed) is in the
 *  same form and checked on submit.
 *
 *  RENDER VARS:
 *    $game     — the game being deleted.
 *    $decision — 'allow' | 'email_match' | 'email_code'.
 *    $error    — message above the form, or null.
 *    $purge    — true when the game is ALREADY soft-deleted (admin permanent
 *                delete): the archive button is pointless, so it's hidden.
 *    $csrf     — hidden CSRF field.
 * ============================================================================= */
$purge = !empty($purge);
?>
<div class="card card-narrow">
    <h1><?= e(t('delgame_title')) ?></h1>
    <p><?= e(t('delgame_confirm', $game['name'])) ?></p>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="delete_game.php?game=<?= (int)$game['id'] ?>" class="delgame-form">
        <?= $csrf ?>
        <?= antibot_field() ?>
        <input type="hidden" name="game" value="<?= (int)$game['id'] ?>">

        <?php if ($decision === 'email_match'): // retype the stored email ?>
            <div class="field field-vemail">
                <label for="vemail"><?= e(t('verify_email_label')) ?></label>
                <input type="email" id="vemail" name="vemail" required>
            </div>
        <?php elseif ($decision === 'email_code'): // enter the emailed code ?>
            <p class="muted"><?= e(t('verify_code_sent')) ?></p>
            <div class="field field-vcode">
                <label for="vcode"><?= e(t('verify_code_label')) ?></label>
                <input type="text" id="vcode" name="vcode" inputmode="numeric" required>
            </div>
        <?php endif; ?>

        <div class="delgame-buttons">
            <button type="submit" name="choice" value="back" class="btn"><?= e(t('delgame_back')) ?></button>
            <?php // Which removals are offered follows the admin's game_deletion
                  // setting. The controller enforces it too — a hidden button is
                  // not a restriction. An admin purging an ALREADY soft-deleted
                  // game always gets the permanent option: that is the second
                  // half of a soft delete rather than a way round the setting. ?>
            <?php $canSoft = !$purge && $mode !== 'hard'; ?>
            <?php $canHard = $purge || $mode !== 'soft' || is_admin(); ?>
            <?php if ($canSoft): ?>
                <button type="submit" name="choice" value="archive" class="btn"><?= e(t('delgame_archive')) ?></button>
            <?php endif; ?>
            <?php if ($canHard): ?>
                <button type="submit" name="choice" value="everything" class="btn btn-danger"><?= e(t('delgame_everything')) ?></button>
            <?php endif; ?>
        </div>
    </form>
</div>
