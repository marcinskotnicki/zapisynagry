<?php
/* =============================================================================
 *  templates/light/csrf_error.php — the "this form expired" page.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Shown by csrf_check() (inc/auth.php) when a submitted
 *  form carries a token that no longer matches the session's. Since the token
 *  now survives session garbage collection via a mirror cookie, landing here
 *  should be rare — but when it happens the visitor gets a real page with a way
 *  onward instead of a blank screen and a bare sentence.
 *
 *  RENDER VARS:
 *    $back — URL of the form the visitor came from, ALREADY validated as being
 *            on this host by csrf_check(); '' when there is no usable referrer.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('csrf_error_title')) ?></h1>

    <p class="msg msg-error"><?= e(t('csrf_error_body')) ?></p>

    <p>
        <?php if (!empty($back)): ?>
            <a class="btn btn-primary" href="<?= e($back) ?>"><?= e(t('back')) ?></a>
        <?php endif; ?>
        <a class="btn" href="index.php"><?= e(t('csrf_error_home')) ?></a>
    </p>
</div>
