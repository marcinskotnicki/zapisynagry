<?php
/* =============================================================================
 *  templates/light/mailing_form.php — "tell me about new games" signup box.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Rendered by index.php into the FOOTER's $after_content
 *  slot, immediately after the timeline, so it sits below it at full page width
 *  rather than inside the width-capped content column.
 *
 *  The consent checkbox appears ONLY when an admin has configured wording for
 *  it; with no text there is nothing to agree to, so no box is shown and
 *  subscribe.php doesn't demand one.
 *
 *  RENDER VARS:
 *    $active_day — day index to return to after the redirect.
 *    $gdpr       — consent wording, or '' for none.
 *    $flash      — result of the previous attempt, or null.
 *    $flash_kind — 'ok' | 'error' | 'warn' (optional; neutral when absent).
 *    $csrf       — hidden CSRF field.
 * ============================================================================= */
?>
<section class="mailing-box" id="mailing">
    <h2><?= e(t('ml_heading')) ?></h2>
    <p class="muted"><?= e(t('ml_intro')) ?></p>

    <?php // Neutral by default, but a refusal (a failed captcha) says so. ?>
    <?php if (!empty($flash)): ?>
        <p class="msg<?= !empty($flash_kind) && $flash_kind !== 'ok' ? ' msg-' . e($flash_kind) : '' ?>"><?= e($flash) ?></p>
    <?php endif; ?>

    <form method="post" action="subscribe.php" class="mailing-form">
        <?= $csrf ?>
        <?php // As on every other public form — this one signs up an address
              // the visitor types, so it is the last place to leave open. ?>
        <?= antibot_field() ?>
        <input type="hidden" name="day" value="<?= (int)$active_day ?>">
        <div class="mailing-row">
            <label class="sr-only" for="ml_email"><?= e(t('ml_email_label')) ?></label>
            <input type="email" id="ml_email" name="ml_email" required
                   placeholder="<?= e(t('ml_email_label')) ?>">
            <button type="submit" class="btn btn-primary"><?= e(t('ml_submit')) ?></button>
        </div>

        <?php if ($gdpr !== ''): ?>
            <div class="field field-check mailing-consent">
                <label>
                    <?php // required: the browser blocks submission, and
                          // subscribe.php re-checks server-side. ?>
                    <input type="checkbox" name="ml_consent" value="1" required>
                    <?php // Allows bold and a policy link — see rich_text_html(),
                          // which escapes everything it does not explicitly permit. ?>
                    <span><?= rich_text_html($gdpr) ?></span>
                </label>
            </div>
        <?php endif; ?>

        <?php if (captcha_required()): ?>
            <div class="mailing-captcha"><?= captcha_html() ?></div>
        <?php endif; ?>
    </form>
</section>
