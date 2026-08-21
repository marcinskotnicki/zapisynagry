<?php
/* =============================================================================
 *  templates/light/verify_challenge.php — verification gate. Presentation only.
 * -----------------------------------------------------------------------------
 *  The "prove you may edit this" gate shown before editing unregistered-added
 *  content. The input shown depends on the decision: retype the email
 *  (email_match) or enter the emailed code (email_code). Posts back to $action
 *  with action=verify; the controller checks it via verify_passes().
 *
 *  RENDER VARS:
 *    $decision — 'email_match' or 'email_code' (which input to render).
 *    $action   — form target (the controller URL that re-checks the challenge).
 *    $title    — heading (e.g. "Edit game").
 *    $error    — message above the form, or null.
 *    $csrf     — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e($title) ?></h1>
    <p class="muted"><?= e(t('verify_needed')) ?></p>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>">
        <?php // The gate had CSRF only; this is the timing/honeypot pair every
              // other public form carries. ?>
        <?= antibot_field() ?>
        <?= $csrf ?>
        <input type="hidden" name="action" value="verify">

        <?php if ($decision === 'email_match'): // retype the stored email ?>
            <div class="field field-vemail">
                <label for="vemail"><?= e(t('verify_email_label')) ?></label>
                <input type="email" id="vemail" name="vemail" required autofocus>
            </div>
        <?php elseif ($decision === 'email_code'): // enter the emailed code ?>
            <p class="muted"><?= e(t('verify_code_sent')) ?></p>
            <?php if (empty($code_sent)): ?>
                <?php /* STEP ONE: nothing has been emailed yet. Reaching this
                       * gate must not send anything — it is reached by
                       * following a link, and a crawler follows every link it
                       * finds. */ ?>
                <p class="muted"><?= e(t('verify_code_intro')) ?></p>
                <?= $captcha ?? '' ?>
            <?php else: ?>
                <div class="field field-vcode">
                    <label for="vcode"><?= e(t('verify_code_label')) ?></label>
                    <input type="text" id="vcode" name="vcode" inputmode="numeric" required autofocus>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="form-actions">
            <?php if (($decision ?? '') === 'email_code' && empty($code_sent)): ?>
                <button type="submit" name="choice" value="send_code" class="btn btn-primary">
                    <?= e(t('verify_code_send')) ?>
                </button>
            <?php else: ?>
                <button type="submit" class="btn btn-primary"><?= e(t('verify_continue')) ?></button>
            <?php endif; ?>
            <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
        </div>
    </form>
</div>
