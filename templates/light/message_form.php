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
 *    $game_id      — game id (whole-game mode) or 0. Exactly one of these is set.
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
        <?php if ($player): // single-player mode ?>
            <input type="hidden" name="player" value="<?= (int)$player ?>">
        <?php else: // whole-game mode ?>
            <input type="hidden" name="game" value="<?= (int)$game_id ?>">
        <?php endif; ?>

        <label for="body"><?= e(t('msg_field')) ?></label>
        <textarea id="body" name="body" rows="6" required autofocus></textarea>

        <button type="submit" class="btn btn-primary"><?= e(t('msg_send')) ?></button>
        <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
    </form>
</div>
