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

// Which keys are plain values vs on/off toggles. Adding a setting later means
// adding it here (+ a label in the language files + a field in the template).
$OPTION_VALUES = [
    'venue_name', 'email_address', 'email_login', 'email_password',
    'email_smtp_server', 'email_smtp_port', 'max_tables', 'bgg_api_code',
    'captcha_site_key', 'captcha_secret_key', 'timeline_extension',
    'msg_below_event', 'msg_adding_game', 'msg_assigning_player', 'game_languages',
    'msg_adding_poll', 'msg_voting', 'msg_email_field', 'poll_default_deadline_hours', 'login_days',
    'default_event_name', 'default_start_time', 'default_end_time',
    'default_language', 'default_template', 'registration_mode',
    'verification_method',
];
$OPTION_TOGGLES = [
    'allow_unregistered_add_games', 'allow_unregistered_signup',
    'send_emails', 'require_email', 'allow_polls', 'allow_discussions',
    'use_captcha', 'allow_messaging', 'allow_guest_messaging', 'allow_custom_game_links',
    'allow_user_template', 'allow_guest_template', 'allow_user_language', 'allow_guest_language',
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
