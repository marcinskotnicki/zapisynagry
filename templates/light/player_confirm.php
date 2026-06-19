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
        <input type="hidden" name="player" value="<?= (int)$player['id'] ?>">

        <?php if ($decision === 'email_match'): // retype the stored email ?>
            <label for="vemail"><?= e(t('verify_email_label')) ?></label>
            <input type="email" id="vemail" name="vemail" required autofocus>
        <?php elseif ($decision === 'email_code'): // enter the emailed code ?>
            <p class="muted"><?= e(t('verify_code_sent')) ?></p>
            <label for="vcode"><?= e(t('verify_code_label')) ?></label>
            <input type="text" id="vcode" name="vcode" inputmode="numeric" required autofocus>
        <?php endif; // 'allow' -> no challenge inputs, just the buttons ?>

        <button type="submit" class="btn btn-danger"><?= e(t('delplayer_yes')) ?></button>
        <a class="btn" href="index.php"><?= e(t('delplayer_no')) ?></a>
    </form>
</div>
