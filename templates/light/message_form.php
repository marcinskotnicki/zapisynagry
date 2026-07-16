<?php
/* =============================================================================
 *  templates/light/message_form.php — compose a message. Presentation only.
 * -----------------------------------------------------------------------------
 *  A narrow card with a single message box. Exactly one hidden field is emitted
 *  — player OR game — so the controller knows the target; recipients' addresses
 *  are never shown here.
 *
 *  RENDER VARS:
 *    $target_label — human description of who this goes to ("Message to Ann").
 *    $player       — player id (single-recipient mode) or 0.
 *    $game_id      — game id (whole-game mode) or 0.
 *    $poll_owner   — poll id when messaging the poll's proposer, or 0.
 *    $poll_id      — poll id when messaging all its voters, or 0.
 *                    Exactly one of the four is non-zero.
 *    $recipients   — recipient count (available for display if desired).
 *    $error        — message above the form, or null.
 *    $csrf         — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('msg_title')) ?></h1>
    <p class="muted"><?= e($target_label) ?></p>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="message.php">
        <?= $csrf ?>
        <?php // Exactly one target id is non-zero; re-emit that one (see message.php). ?>
        <?php if ($player): ?>
            <input type="hidden" name="player" value="<?= (int)$player ?>">
        <?php elseif (!empty($poll_owner)): ?>
            <input type="hidden" name="poll_owner" value="<?= (int)$poll_owner ?>">
        <?php elseif (!empty($poll_id)): ?>
            <input type="hidden" name="poll" value="<?= (int)$poll_id ?>">
        <?php else: ?>
            <input type="hidden" name="game" value="<?= (int)$game_id ?>">
        <?php endif; ?>

        <label for="body"><?= e(t('msg_field')) ?></label>
        <textarea id="body" name="body" rows="6" required autofocus></textarea>

        <button type="submit" class="btn btn-primary"><?= e(t('msg_send')) ?></button>
        <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
    </form>
</div>
