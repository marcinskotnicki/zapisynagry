<?php
/* =============================================================================
 *  templates/light/player_confirm.php — confirm removing a player.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. A single-step confirm: the verification challenge (if the
 *  decision needs one) sits in the same form as the yes/no buttons, so the
 *  controller checks it when "yes" is submitted.
 *
 *  RENDER VARS:
 *    $player   — the player (signup) row to remove.
 *    $game     — the game they're signed up for (for the confirm sentence).
 *    $decision — 'allow' (no challenge) | 'email_match' | 'email_code'.
 *    $error    — message above the form, or null.
 *    $csrf     — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('delplayer_title')) ?></h1>
    <p><?= e(t('delplayer_confirm', $player['name'], $game['name'])) ?></p>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="delete_player.php?player=<?= (int)$player['id'] ?>">
        <?= $csrf ?>
        <?= antibot_field() ?>
        <input type="hidden" name="player" value="<?= (int)$player['id'] ?>">

        <?php if ($decision === 'email_match'): // retype the stored email ?>
            <div class="field field-vemail">
                <label for="vemail"><?= e(t('verify_email_label')) ?></label>
                <input type="email" id="vemail" name="vemail" required autofocus>
            </div>
        <?php elseif ($decision === 'email_code'): ?>
            <?php if (empty($code_sent)): ?>
                <?php /* STEP ONE: nothing sent yet. Arriving here must not email
                       * anybody — following a link is not a decision, and a
                       * crawler follows every link it finds. */ ?>
                <p class="muted"><?= e(t('verify_code_intro')) ?></p>
                <?= $captcha ?>
            <?php else: ?>
                <p class="muted"><?= e(t('verify_code_sent')) ?></p>
                <div class="field field-vcode">
                    <label for="vcode"><?= e(t('verify_code_label')) ?></label>
                    <input type="text" id="vcode" name="vcode" inputmode="numeric" required autofocus>
                </div>
            <?php endif; ?>
        <?php endif; // 'allow' -> no challenge inputs, just the buttons ?>

        <div class="form-actions">
            <?php /* On step one the only way forward is asking for the code —
                   * there is nothing to authorise the removal with yet. */ ?>
            <?php if ($decision === 'email_code' && empty($code_sent)): ?>
                <button type="submit" name="choice" value="send_code" class="btn btn-primary">
                    <?= e(t('verify_code_send')) ?>
                </button>
            <?php else: ?>
                <button type="submit" class="btn btn-danger"><?= e(t('delplayer_yes')) ?></button>
            <?php endif; ?>
            <a class="btn" href="index.php"><?= e(t('delplayer_no')) ?></a>
        </div>
    </form>
</div>
