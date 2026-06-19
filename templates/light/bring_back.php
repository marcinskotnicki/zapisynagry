<?php
/* =============================================================================
 *  templates/light/bring_back.php — restore an archived game. Presentation.
 * -----------------------------------------------------------------------------
 *  Name (+ optional email) of whoever is restoring the game; on submit they
 *  become its new owner/bringer. No verification challenge — anyone may restore.
 *
 *  RENDER VARS:
 *    $game  — the archived game being restored.
 *    $form  — prefilled ['name','email'] (from the logged-in user, if any).
 *    $error — message above the form, or null.
 *    $csrf  — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('bringback_title')) ?></h1>
    <p><?= e(t('bringback_intro', $game['name'])) ?></p>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="bring_back.php?game=<?= (int)$game['id'] ?>">
        <?= $csrf ?>
        <input type="hidden" name="game" value="<?= (int)$game['id'] ?>">

        <label for="name"><?= e(t('bringback_name')) ?></label>
        <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required>

        <label for="email"><?= e(t('bringback_email')) ?><?= opt_bool('require_email') ? ' *' : '' ?></label>
        <input type="email" id="email" name="email" value="<?= e($form['email']) ?>">

        <button type="submit" class="btn btn-primary"><?= e(t('bringback_submit')) ?></button>
        <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
    </form>
</div>
