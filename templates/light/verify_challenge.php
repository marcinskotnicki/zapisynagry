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
        <?= $csrf ?>
        <input type="hidden" name="action" value="verify">

        <?php if ($decision === 'email_match'): // retype the stored email ?>
            <label for="vemail"><?= e(t('verify_email_label')) ?></label>
            <input type="email" id="vemail" name="vemail" required autofocus>
        <?php elseif ($decision === 'email_code'): // enter the emailed code ?>
            <p class="muted"><?= e(t('verify_code_sent')) ?></p>
            <label for="vcode"><?= e(t('verify_code_label')) ?></label>
            <input type="text" id="vcode" name="vcode" inputmode="numeric" required autofocus>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary"><?= e(t('verify_continue')) ?></button>
        <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
    </form>
</div>
