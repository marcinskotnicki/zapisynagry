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
        <?= antibot_field() ?>
        <div class="field field-name">
            <label for="name"><?= e(t('reg_name')) ?> *</label>
            <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" maxlength="<?= TEXT_PERSON_MAX ?>" required autofocus>
        </div>
        <div class="field field-email">
            <label for="email"><?= e(t('signup_email')) ?> *</label>
            <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" required>
            <?php if (opt_msg('msg_email_field') !== ''): // the shared email-field note ?>
                <p class="field-note"><?= e(opt_msg('msg_email_field')) ?></p>
            <?php endif; ?>
        </div>
        <div class="field field-password">
            <label for="password"><?= e(t('password')) ?> *</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="field field-password2">
            <label for="password2"><?= e(t('reg_password2')) ?> *</label>
            <input type="password" id="password2" name="password2" required>
        </div>
        <?= $captcha ?>
        <?php // Data-protection consent. Renders nothing for a signed-in visitor,
              // or when the admin has left the wording empty. ?>
        <?= consent_field() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('reg_button')) ?></button>
        </div>
    </form>

    <?php /* Same placement and the same gate as on the login page: below the
           * form, because filling it in is still the main route, and only when
           * google_login_enabled() says a click would actually work.
           *
           * The endpoint creates the account when no such address exists, so
           * this really is "register with Google" and not just a sign-in link
           * on the wrong page. */ ?>
    <?php if (function_exists('google_login_enabled') && google_login_enabled()): ?>
        <p class="login-alt-sep muted"><?= e(t('google_or')) ?></p>
        <p class="form-actions">
            <a class="btn btn-google" href="google_auth.php"><?= e(t('google_sign_in')) ?></a>
        </p>
    <?php endif; ?>

    <p class="muted"><a href="login.php"><?= e(t('reg_have_account')) ?></a></p>
</div>
