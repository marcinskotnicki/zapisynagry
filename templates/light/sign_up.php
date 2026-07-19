<?php
/* =============================================================================
 *  templates/light/sign_up.php — sign-up form. Presentation only.
 * -----------------------------------------------------------------------------
 *  Narrow card: name, optional email (starred when the admin requires it), and
 *  a "do you know the rules" select. The heading + note flip to the reserve
 *  wording when the game is already full. The optional admin "assigning a
 *  player" message shows at the top.
 *
 *  RENDER VARS:
 *    $game  — the game row being joined.
 *    $form  — prefilled values ['name','email','knows'].
 *    $full  — true if the game is full (this signup becomes a reserve).
 *    $error — message above the form, or null.
 *    $csrf  — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e($full ? t('signup_reserve') : t('signup_title')) ?></h1>
    <p class="muted"><?= e($game['name']) ?></p>

    <?php if (opt('msg_assigning_player') !== ''): // optional admin-configured note ?>
        <p class="event-msg"><?= e(opt('msg_assigning_player')) ?></p>
    <?php endif; ?>

    <?php if ($full): // tell them they'll join the reserve list ?>
        <p class="msg muted"><?= e(t('signup_full_note')) ?></p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="sign_up.php?game=<?= (int)$game['id'] ?>">
        <?= $csrf ?>
        <input type="hidden" name="game" value="<?= (int)$game['id'] ?>">

        <label for="name"><?= e(t('signup_name')) ?></label>
        <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required>

        <label for="email"><?= e(t('signup_email')) ?><?= email_required_for_game($game) ? ' *' : '' ?></label>
        <input type="email" id="email" name="email" value="<?= e($form['email']) ?>">
        <?php if (opt('msg_email_field') !== ''): ?>
            <p class="field-note"><?= e(opt('msg_email_field')) ?></p>
        <?php endif; ?>

        <label for="knows"><?= e(t('signup_knows')) ?></label>
        <select id="knows" name="knows">
            <?php foreach ([0 => 'knows_yes', 1 => 'knows_somewhat', 2 => 'knows_no'] as $code => $k): ?>
                <option value="<?= $code ?>"<?= (int)$form['knows'] === $code ? ' selected' : '' ?>><?= e(t($k)) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-primary"><?= e(t('signup_submit')) ?></button>
        <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
    </form>
</div>
