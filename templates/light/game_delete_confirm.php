<?php
/* =============================================================================
 *  templates/light/game_delete_confirm.php — 3-way delete confirm. Presentation.
 * -----------------------------------------------------------------------------
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
        <input type="hidden" name="game" value="<?= (int)$game['id'] ?>">

        <?php if ($decision === 'email_match'): // retype the stored email ?>
            <label for="vemail"><?= e(t('verify_email_label')) ?></label>
            <input type="email" id="vemail" name="vemail" required>
        <?php elseif ($decision === 'email_code'): // enter the emailed code ?>
            <p class="muted"><?= e(t('verify_code_sent')) ?></p>
            <label for="vcode"><?= e(t('verify_code_label')) ?></label>
            <input type="text" id="vcode" name="vcode" inputmode="numeric" required>
        <?php endif; ?>

        <div class="delgame-buttons">
            <button type="submit" name="choice" value="back" class="btn"><?= e(t('delgame_back')) ?></button>
            <?php if (!$purge): // already archived -> no point re-archiving ?>
                <button type="submit" name="choice" value="archive" class="btn"><?= e(t('delgame_archive')) ?></button>
            <?php endif; ?>
            <button type="submit" name="choice" value="everything" class="btn btn-danger"><?= e(t('delgame_everything')) ?></button>
        </div>
    </form>
</div>
