<?php
/* =============================================================================
 *  templates/light/register_form.php — self-registration form. Presentation.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $form    — ['name','email'] repopulated after a validation error.
 *    $error   — validation message or null.
 *    $captcha — captcha HTML ('' when the feature is off).
 *    $csrf    — hidden CSRF field.
 *  Passwords are never repopulated (standard practice).
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('reg_title')) ?></h1>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="register.php">
        <?= $csrf ?>
        <div class="field">
            <label for="name"><?= e(t('reg_name')) ?> *</label>
            <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required autofocus>
        </div>
        <div class="field">
            <label for="email"><?= e(t('signup_email')) ?> *</label>
            <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" required>
            <?php if (opt('msg_email_field') !== ''): // the shared email-field note ?>
                <p class="field-note"><?= e(opt('msg_email_field')) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="password"><?= e(t('password')) ?> *</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="field">
            <label for="password2"><?= e(t('reg_password2')) ?> *</label>
            <input type="password" id="password2" name="password2" required>
        </div>
        <?= $captcha ?>
        <button type="submit" class="btn btn-primary"><?= e(t('reg_button')) ?></button>
    </form>

    <p class="muted"><a href="login.php"><?= e(t('reg_have_account')) ?></a></p>
</div>
