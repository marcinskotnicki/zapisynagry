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
 *    $is_guest     — true when the sender has no account: show name/email inputs
 *                    (+ captcha) so recipients know who wrote and can reply.
 *    $sender_name  — repopulated guest name (after a validation error).
 *    $sender_email — repopulated guest email.
 *    $captcha      — captcha HTML for guests, '' otherwise.
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
        <?= antibot_field() ?>
        <?php // Exactly one target id is non-zero; re-emit that one (see message.php). ?>
        <?php if ($player): ?>
            <input type="hidden" name="player" value="<?= (int)$player ?>">
        <?php elseif (!empty($poll_owner)): ?>
            <input type="hidden" name="poll_owner" value="<?= (int)$poll_owner ?>">
        <?php elseif (!empty($poll_id)): ?>
            <input type="hidden" name="poll" value="<?= (int)$poll_id ?>">
        <?php elseif (!empty($library_club)): ?>
            <?php // The club, not a member — see message.php. ?>
            <input type="hidden" name="library_club" value="1">
            <?php if (!empty($library_game)): ?>
                <input type="hidden" name="library_game" value="<?= (int)$library_game ?>">
            <?php endif; ?>
        <?php elseif (!empty($library_member)): ?>
            <?php // A library owner — no parent game or poll, see message.php.
                  // library_game is optional: it names WHICH of their games this
                  // is about, and must survive the POST or the subject falls
                  // back to the generic wording on submit. ?>
            <input type="hidden" name="library_member" value="<?= (int)$library_member ?>">
            <?php if (!empty($library_game)): ?>
                <input type="hidden" name="library_game" value="<?= (int)$library_game ?>">
            <?php endif; ?>
        <?php else: ?>
            <input type="hidden" name="game" value="<?= (int)$game_id ?>">
        <?php endif; ?>

        <?php if (!empty($is_guest)): // guests must identify themselves (see message.php) ?>
            <div class="field field-sender_name">
                <label for="sender_name"><?= e(t('msg_sender_name')) ?> *</label>
                <input type="text" id="sender_name" name="sender_name" value="<?= e($sender_name) ?>" required>
            </div>
            <div class="field field-sender_email">
                <label for="sender_email"><?= e(t('msg_sender_email')) ?> *</label>
                <input type="email" id="sender_email" name="sender_email" value="<?= e($sender_email) ?>" required>
                <?php if (opt_msg('msg_email_field') !== ''): // the shared email-field note ?>
                    <p class="field-note"><?= e(opt_msg('msg_email_field')) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="field field-body">
            <label for="body"><?= e(t('msg_field')) ?></label>
            <textarea id="body" name="body" rows="6" required autofocus></textarea>
        </div>
        <?= $captcha ?? '' ?>

        <?php // Data-protection consent. Renders nothing for a signed-in visitor,
              // or when the admin has left the wording empty. ?>
        <?= consent_field() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('msg_send')) ?></button>
            <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
        </div>
    </form>
</div>
