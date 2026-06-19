<?php
/* =============================================================================
 *  templates/light/recover.php — password recovery. Presentation only.
 * -----------------------------------------------------------------------------
 *  A single card that renders one of five steps, chosen by $step (the recover.php
 *  controller decides which):
 *    request — the "enter your email" form.
 *    sent    — neutral confirmation (shown whether or not the email existed).
 *    reset   — the "set a new password" form (carries the $token).
 *    invalid — the link was wrong or expired.
 *    done    — password changed; link to log in.
 *
 *  RENDER VARS:
 *    $step  — which of the above to show.
 *    $token — reset token (reset step only; posted back hidden).
 *    $error — message above the reset form (reset step only), or null.
 *    $csrf  — hidden CSRF field (request/reset steps).
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('recover_title')) ?></h1>

    <?php if ($step === 'request'): // step 1: ask for the email ?>
        <p class="muted"><?= e(t('recover_intro')) ?></p>
        <form method="post" action="recover.php">
            <?= $csrf ?>
            <input type="hidden" name="action" value="request">
            <label for="email"><?= e(t('recover_email')) ?></label>
            <input type="email" id="email" name="email" required autofocus>
            <button type="submit" class="btn btn-primary"><?= e(t('recover_send')) ?></button>
        </form>

    <?php elseif ($step === 'sent'): // step 1 done: neutral "sent" (no account probing) ?>
        <p class="msg msg-ok"><?= e(t('recover_sent')) ?></p>
        <p><a href="login.php"><?= e(t('recover_back_login')) ?></a></p>

    <?php elseif ($step === 'reset'): // step 2: choose a new password ?>
        <h2 class="reset-sub"><?= e(t('recover_reset_title')) ?></h2>
        <?php if (!empty($error)): ?>
            <p class="msg msg-error"><?= e($error) ?></p>
        <?php endif; ?>
        <form method="post" action="recover.php">
            <?= $csrf ?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label for="new_password"><?= e(t('recover_new_password')) ?></label>
            <input type="password" id="new_password" name="new_password" required autofocus>
            <label for="new_password2"><?= e(t('recover_new_password2')) ?></label>
            <input type="password" id="new_password2" name="new_password2" required>
            <button type="submit" class="btn btn-primary"><?= e(t('recover_reset_submit')) ?></button>
        </form>

    <?php elseif ($step === 'invalid'): // bad/expired token ?>
        <p class="msg msg-error"><?= e(t('recover_invalid')) ?></p>
        <p><a href="recover.php"><?= e(t('recover_title')) ?></a></p>

    <?php elseif ($step === 'done'): // success ?>
        <p class="msg msg-ok"><?= e(t('recover_done')) ?></p>
        <p><a href="login.php"><?= e(t('login')) ?></a></p>
    <?php endif; ?>
</div>
