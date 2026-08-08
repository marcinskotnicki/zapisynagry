<?php
/* =============================================================================
 *  templates/light/login.php — login form. Presentation only.
 * -----------------------------------------------------------------------------
 *  A narrow card with the email/password form and a "forgot password" link.
 *  The hidden `next` field carries the post-login return target through, so a
 *  guard that bounced the user here can send them back afterwards.
 *
 *  RENDER VARS:
 *    $error — message to show above the form, or null.
 *    $next  — sanitised return target (login.php validates it again on submit).
 *    $csrf  — the hidden CSRF field (already-built HTML from csrf_field()).
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('login')) ?></h1>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">
        <?= $csrf ?>
        <?= antibot_field() ?>
        <input type="hidden" name="next" value="<?= e($next ?? '') ?>">

        <div class="field field-email">
            <label for="email"><?= e(t('email')) ?></label>
            <input type="email" id="email" name="email" required autofocus>
        </div>

        <div class="field field-password">
            <label for="password"><?= e(t('password')) ?></label>
            <input type="password" id="password" name="password" required>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('login')) ?></button>
        </div>
    </form>

    <p class="muted"><a href="recover.php"><?= e(t('forgot_password')) ?></a></p>
    <?php if (opt('registration_mode') === 'registration'): // self-registration entry point ?>
        <p class="muted"><a href="register.php"><?= e(t('reg_link')) ?></a></p>
    <?php endif; ?>
</div>
