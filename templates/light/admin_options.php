<?php
/* =============================================================================
 *  templates/light/admin_options.php — the Options tab form. Presentation only.
 * -----------------------------------------------------------------------------
 *  Renders every admin-editable setting, grouped into three fieldsets:
 *    1. Settings  — venue/email/SMTP, caps, BGG + captcha keys, event messages.
 *    2. Defaults  — defaults for new events, plus the default language/theme.
 *    3. Toggles   — on/off features + the registration & verification mode pickers.
 *
 *  Reads current values via opt() and the available languages/themes via the
 *  loader helpers. The SAVE logic lives in inc/admin/options.php; this file only
 *  displays. The two local closures keep the repetitive markup tidy (they are
 *  rendering helpers, not business logic).
 *
 *  RENDER VARS:
 *    $csrf — hidden CSRF field.
 *
 *  ADDING A SETTING: add a $text()/$toggle() call (or a select) here, an
 *  'opt_<key>' label to the language files, and the key to the relevant list in
 *  inc/admin/options.php so it gets saved.
 * ============================================================================= */

// Text input row: <label> + <input> for option $key, prefilled from opt($key).
// $type lets the same helper emit number/time/password inputs.
$text = function($key, $type = 'text') {
    echo '<div class="field"><label for="' . e($key) . '">' . e(t('opt_' . $key)) . '</label>';
    echo '<input type="' . e($type) . '" id="' . e($key) . '" name="' . e($key) . '" value="' . e(opt($key)) . '"></div>';
};
// Multi-line textarea row: for options where each LINE is one entry (e.g.
// the game-language choices). Prefilled from opt($key), saved verbatim.
$textarea = function($key, $rows = 4) {
    echo '<div class="field"><label for="' . e($key) . '">' . e(t('opt_' . $key)) . '</label>';
    echo '<textarea id="' . e($key) . '" name="' . e($key) . '" rows="' . (int)$rows . '">' . e(opt($key)) . '</textarea></div>';
};
// Checkbox toggle row (value "1"): checked when the stored value is exactly "1".
// An unchecked box is simply absent from POST — the controller treats that as "0".
$toggle = function($key) {
    $on = opt($key) === '1' ? ' checked' : '';
    echo '<div class="field field-check"><label>';
    echo '<input type="checkbox" name="' . e($key) . '" value="1"' . $on . '> ' . e(t('opt_' . $key));
    echo '</label></div>';
};
?>
<form method="post" action="admin.php?tab=options" class="options-form">
    <?= $csrf ?>

    <fieldset>
        <legend><?= e(t('opt_group_settings')) ?></legend>
        <?php
        // Venue + outgoing-mail/SMTP credentials, then caps, integration keys,
        // and the three optional on-page messages.
        $text('venue_name');
        $text('email_address');
        $text('email_login');
        $text('email_password', 'password');
        $text('email_smtp_server');
        $text('email_smtp_port', 'number');
        $text('max_tables', 'number');           // 0 = unlimited
        $text('bgg_api_code');
        $text('captcha_site_key');
        $text('captcha_secret_key');
        $text('timeline_extension', 'number');   // hours added past the day's end
        $text('msg_below_event');
        $text('msg_adding_game');
        $text('msg_assigning_player');
        $textarea('game_languages');             // one dropdown choice per line
        $text('poll_default_deadline_hours', 'number');   // polls close N hours before start
        $text('login_days', 'number');                    // persistent-login lifetime; 0 = session only
        $text('msg_adding_poll');
        $text('msg_voting');
        $text('msg_email_field');
        ?>
    </fieldset>

    <fieldset>
        <legend><?= e(t('opt_group_defaults')) ?></legend>
        <?php
        // Prefills offered when creating a new event.
        $text('default_event_name');
        $text('default_start_time', 'time');
        $text('default_end_time', 'time');
        ?>

        <div class="field">
            <label for="default_language"><?= e(t('opt_default_language')) ?></label>
            <select id="default_language" name="default_language">
                <?php foreach (lang_available() as $code): // languages found on disk ?>
                    <option value="<?= e($code) ?>"<?= opt('default_language') === $code ? ' selected' : '' ?>><?= e($code) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="default_template"><?= e(t('opt_default_template')) ?></label>
            <select id="default_template" name="default_template">
                <?php foreach (tpl_available() as $name): // themes found on disk ?>
                    <option value="<?= e($name) ?>"<?= opt('default_template') === $name ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <fieldset>
        <legend><?= e(t('opt_group_toggles')) ?></legend>
        <?php
        // Guest permissions (only relevant in 'registration' mode below).
        $toggle('allow_unregistered_add_games');
        $toggle('allow_unregistered_signup');
        ?>

        <div class="field">
            <label for="registration_mode"><?= e(t('opt_registration_mode')) ?></label>
            <select id="registration_mode" name="registration_mode">
                <?php foreach (['registration', 'guest_only'] as $mode): ?>
                    <option value="<?= e($mode) ?>"<?= opt('registration_mode') === $mode ? ' selected' : '' ?>>
                        <?= e(t('opt_registration_mode_' . $mode)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php
        $toggle('send_emails');       // master switch for notifications
        $toggle('require_email');
        ?>

        <div class="field">
            <label for="verification_method"><?= e(t('opt_verification_method')) ?></label>
            <select id="verification_method" name="verification_method">
                <?php foreach (['none', 'registered', 'email_code', 'email_match'] as $m): ?>
                    <option value="<?= e($m) ?>"<?= opt('verification_method') === $m ? ' selected' : '' ?>>
                        <?= e(t('opt_verification_' . $m)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php
        $toggle('allow_polls');
        $toggle('allow_discussions');
        $toggle('use_captcha');
        $toggle('allow_messaging');
        $toggle('allow_guest_messaging');      // messaging open to guests too, not just accounts
        $toggle('allow_custom_game_links');
        ?>
    </fieldset>

    <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
</form>
