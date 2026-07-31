<?php
/* =============================================================================
 *  templates/light/poll_delete_confirm.php — delete-a-poll confirm. Presentation.
 * -----------------------------------------------------------------------------
 *  One form, two submit buttons distinguished by the "choice" value the
 *  controller reads: back (do nothing) / everything (delete). There's no
 *  archive option like a game has — a poll can't be brought back.
 *
 *  The verification challenge (when the poll was created by a guest) rides in
 *  the same form and is checked on submit, exactly as the game version does.
 *
 *  RENDER VARS:
 *    $poll     — the polls row being deleted.
 *    $decision — 'allow' | 'email_match' | 'email_code'.
 *    $error    — message above the form, or null.
 *    $csrf     — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('delpoll_title')) ?></h1>
    <p><?= e(t('delpoll_confirm')) ?></p>
    <?php // Spell out the collateral: candidates and every vote go with it. ?>
    <p class="muted"><?= e(t('delpoll_note')) ?></p>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="delete_poll.php?poll=<?= (int)$poll['id'] ?>" class="delgame-form">
        <?= $csrf ?>
        <?= antibot_field() ?>
        <input type="hidden" name="poll" value="<?= (int)$poll['id'] ?>">

        <?php if ($decision === 'email_match'): // retype the stored email ?>
            <label for="vemail"><?= e(t('verify_email_label')) ?></label>
            <input type="email" id="vemail" name="vemail" required>
        <?php elseif ($decision === 'email_code'): // enter the emailed code ?>
            <p class="muted"><?= e(t('verify_code_sent')) ?></p>
            <label for="vcode"><?= e(t('verify_code_label')) ?></label>
            <input type="text" id="vcode" name="vcode" inputmode="numeric" required>
        <?php endif; ?>

        <div class="delgame-buttons">
            <button type="submit" name="choice" value="back" class="btn"><?= e(t('delpoll_back')) ?></button>
            <button type="submit" name="choice" value="everything" class="btn btn-danger"><?= e(t('delpoll_everything')) ?></button>
        </div>
    </form>
</div>
