<?php
/* =============================================================================
 *  templates/light/edit_player.php — correct a sign-up. Presentation only.
 * -----------------------------------------------------------------------------
 *  Reached through the small pen next to a player's name, once the visitor is
 *  allowed (edit_player.php does all the checking). The same three things the
 *  sign-up form asks: name, email, rules knowledge.
 *
 *  RENDER VARS:
 *    $player — the players row being edited.
 *    $game   — its game (for the heading and the email rule).
 *    $form   — ['name','email','knows'] current / re-submitted values.
 *    $error  — message above the form, or null.
 *    $back   — where Cancel goes (the game on the event page).
 *    $csrf   — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('editplayer_title')) ?></h1>
    <p class="muted"><?= e($game['name']) ?></p>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="edit_player.php?player=<?= (int)$player['id'] ?>">
        <?= $csrf ?>
        <?= antibot_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="player" value="<?= (int)$player['id'] ?>">

        <div class="field field-name">
            <label for="name"><?= e(t('signup_name')) ?></label>
            <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="<?= TEXT_PERSON_MAX ?>" required>
        </div>

        <div class="field field-email">
            <label for="email"><?= e(t('signup_email')) ?><?= email_required_for_game($game) ? ' *' : '' ?></label>
            <input type="email" id="email" name="email" value="<?= e($form['email']) ?>">
        </div>

        <div class="field field-knows">
            <label for="knows"><?= e(t('signup_knows')) ?></label>
            <?php // Same rule as signing up: no answer is picked for them. ?>
            <select id="knows" name="knows" required>
                <option value="" disabled<?= $form['knows'] === null ? ' selected' : '' ?>><?= e(t('knows_choose')) ?></option>
                <?php foreach ([0 => 'knows_yes', 1 => 'knows_somewhat', 2 => 'knows_no'] as $code => $k): ?>
                    <option value="<?= $code ?>"<?= $form['knows'] === $code ? ' selected' : '' ?>><?= e(t($k)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
            <a class="btn" href="<?= e($back) ?>"><?= e(t('cancel')) ?></a>
        </div>
    </form>
</div>
