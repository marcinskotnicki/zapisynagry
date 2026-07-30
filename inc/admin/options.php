<?php
/* =============================================================================
 *  inc/admin/options.php — Options tab controller.
 * -----------------------------------------------------------------------------
 *  Runs INSIDE admin.php's scope (admin.php includes this file), so it inherits
 *  that script's variables and conventions:
 *    - it may set $flash    (a one-line confirmation shown by the shell), and
 *    - it MUST set $tab_body (the rendered HTML for the tab body).
 *  admin.php has already run csrf_check() for POSTs before including us.
 *
 *  All settings are stored in the options key/value table via opt_set(). Saving
 *  is split into "plain value" fields and "on/off toggle" fields, because a
 *  toggle is simply absent from $_POST when unchecked (so we can't loop them
 *  the same way as text fields).
 * ============================================================================= */

// The tab renders captcha settings (version select), so it needs the captcha
// helpers. Pages load inc/captcha.php themselves; admin.php doesn't, hence the
// require_once here — it's this tab's own dependency.
require_once __DIR__ . '/../captcha.php';
require_once __DIR__ . '/../mail.php';      // mail_subject_prefix(), shown in the form's note

// Which keys are plain values vs on/off toggles. Adding a setting later means
// adding it here (+ a label in the language files + a field in the template).
$OPTION_VALUES = [
    'venue_name', 'email_address', 'email_login', 'email_password',
    'email_smtp_server', 'email_smtp_port', 'max_tables', 'bgg_api_code',
    'overnight_grace_hours',
    'site_url',
    'captcha_site_key', 'captcha_secret_key', 'captcha_version', 'captcha_v3_threshold',
    'timeline_extension',
    'game_languages',
    'poll_default_deadline_hours', 'login_days',
    'default_event_name', 'default_start_time', 'default_end_time',
    'default_language', 'default_template', 'registration_mode',
    'verification_method', 'table_names_mode', 'require_email', 'header_button_style',
    'timezone',            // the venue's clock; validated below
    'email_subject_prefix',// 'venue' | 'event'; validated below
    'mailing_gdpr_text',   // '' means "don't ask for consent at all"
];
/* The six custom messages are stored one row PER LANGUAGE (msg_voting_en, …),
 * so their keys can't be a fixed list — they depend on which language files
 * exist. Generated from the same two helpers the Options screen renders from,
 * so a field that appears there is always one the save handler will accept.
 * The bare legacy keys are deliberately NOT accepted any more: they are read
 * as a fallback (see opt_msg) but no longer editable, so there is exactly one
 * place to change any given message. */
foreach (custom_msg_keys() as $__msgKey) {
    foreach (lang_available() as $__lang) {
        $OPTION_VALUES[] = custom_msg_option($__msgKey, $__lang);
    }
}
unset($__msgKey, $__lang);

$OPTION_TOGGLES = [
    'allow_unregistered_add_games', 'allow_unregistered_signup',
    'send_emails', 'allow_polls', 'allow_discussions',
    'use_captcha', 'allow_messaging', 'allow_guest_messaging', 'allow_custom_game_links',
    'allow_user_template', 'allow_guest_template', 'allow_user_language', 'allow_guest_language',
    'allow_start_outside_hours', 'show_venue_name', 'mailing_list',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Plain value fields ---
    foreach ($OPTION_VALUES as $key) {
        $val = trim($_POST[$key] ?? '');
        switch ($key) {                       // coerce / validate the few constrained fields
            case 'max_tables':
            case 'timeline_extension':
            case 'email_smtp_port':
            case 'poll_default_deadline_hours':
            case 'login_days':
                $val = (string)max(0, (int)$val);          // non-negative integers only
                break;
            case 'overnight_grace_hours':
                // 0 = pivot exactly at opening; 12 is far more than any sane
                // setup window and keeps the pivot from wrapping a full day.
                $val = (string)min(12, max(0, (int)$val));
                break;
            case 'default_language':
                if (!lang_exists($val)) continue 2;        // ignore an unknown code (skip this field)
                break;
            case 'default_template':
                if (!tpl_exists($val)) continue 2;         // ignore an unknown theme
                break;
            case 'registration_mode':
                // Whitelist: only these two modes are valid.
                if (!in_array($val, ['registration', 'guest_only'], true)) continue 2;
                break;
            case 'verification_method':
                // Whitelist of the verification tree's method names.
                if (!in_array($val, ['none', 'registered', 'email_code', 'email_match'], true)) continue 2;
                break;
            case 'table_names_mode':
                // Whitelist of the table-name permission modes.
                if (!in_array($val, ['off', 'admin', 'add_any', 'any'], true)) continue 2;
                break;
            case 'require_email':
                // Integer codes: 0 = never, 1 = always, 2 = proposer decides per game.
                if (!in_array($val, ['0', '1', '2'], true)) continue 2;
                break;
            case 'header_button_style':
                // How the top-bar nav renders: text only / icon only / both.
                if (!in_array($val, ['text', 'icon', 'both'], true)) continue 2;
                break;
            case 'email_subject_prefix':
                // Anything else would silently fall through to the venue branch
                // in mail_subject_prefix(); reject it so the stored value always
                // says what it means.
                if (!in_array($val, ['venue', 'event'], true)) continue 2;
                break;
            case 'timezone':
                // Must be a real PHP timezone: app_timezone_init() falls back to
                // UTC on anything else, so storing junk would silently move the
                // whole site's clock. Reject instead and keep the old value.
                try { new DateTimeZone($val); } catch (Throwable $e) { continue 2; }
                break;
            case 'captcha_version':
                // Which reCAPTCHA the keys belong to (types aren't interchangeable).
                if (!in_array($val, ['v2', 'v3'], true)) continue 2;
                break;
            case 'captcha_v3_threshold':
                // Score cutoff 0.1-1.0; anything outside that falls back to 0.5.
                $f = (float)str_replace(',', '.', $val);      // tolerate a comma decimal
                if ($f <= 0 || $f > 1) $f = 0.5;
                $val = rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
                break;
        }
        opt_set($key, $val);
        // NB: `continue 2` above targets THIS foreach (level 2 = the switch is
        // level 1), so an invalid constrained field is left at its old value.
    }
    // --- Toggle fields: present in POST = "1", absent = "0". ---
    foreach ($OPTION_TOGGLES as $key) {
        opt_set($key, isset($_POST[$key]) ? '1' : '0');
    }
    log_action('options_save', 'Admin updated options');
    $flash = t('saved_ok');
}

// Render the form. opt() reads the (now possibly updated) in-memory settings.
$tab_body = tpl_capture('admin_options', ['csrf' => csrf_field()]);
