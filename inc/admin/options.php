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
require_once __DIR__ . '/../update.php';    // update_repo_url(), pre-fills the update-source field
require_once __DIR__ . '/../events.php';    // day_tab_formats(), for the tab-layout picker
require_once __DIR__ . '/../htaccess.php'; // the managed HTTPS-redirect block

// Which keys are plain values vs on/off toggles. Adding a setting later means
// adding it here (+ a label in the language files + a field in the template).
// Credentials. These are NEVER rendered back into the form — several clubs run
// on subdomains of one host and share a BGG key, and a value echoed into
// `value="..."` is readable by anyone who can open the page or View Source
// (type="password" only masks it on screen, it does not remove it from the
// HTML). Submitting the field empty therefore means "leave it alone" rather
// than "clear it", so saving the form for any other reason cannot wipe them.
// Clearing is possible, but has to be asked for explicitly — see the
// '<key>__clear' checkbox below.
$OPTION_SECRETS = ['email_password', 'bgg_api_code', 'captcha_secret_key'];

$OPTION_VALUES = [
    'venue_name', 'email_address', 'email_login',
    'email_smtp_server', 'email_smtp_port', 'max_tables',
    'overnight_grace_hours',
    'site_url',
    'captcha_site_key', 'captcha_version', 'captcha_v3_threshold',
    'timeline_extension',
    'game_languages',
    'poll_default_deadline_hours', 'login_days',
    'default_event_name', 'default_start_time', 'default_end_time',
    'default_language', 'default_template', 'registration_mode',
    'verification_method', 'table_names_mode', 'require_email', 'header_button_style',
    'timezone',            // the venue's clock; validated below
    'email_subject_prefix',// 'venue' | 'event'; validated below
    'mailing_gdpr_text',   // '' means "don't ask for consent at all"
    'antibot_delay_form', 'antibot_delay_click',   // seconds; 0 = off; validated below
    'switcher_pos_template', 'switcher_pos_language',  // header|footer|both|none; validated below
    'home_layout',  // tables_first|timeline_first; validated below
    'github_url',   // '' = inherit config.php's GITHUB_* coords; validated below
    'day_tab_format',   // one of day_tab_formats(); validated below
    'log_retention_days',   // 0 = keep everything
    'game_deletion',        // choose|soft|hard; validated below
    'deleted_games_display',// name|full; validated below
    'footer_custom_text',   // raw HTML, admin-only; see the note in the template
    'github_branch',        // '' = inherit config.php's GITHUB_BRANCH
    'header_brand',         // auto|both|none; validated below
    'chat_scope', 'chat_max_messages', 'chat_initial_messages', 'chat_refresh_seconds',
    'chat_send_delay',
    'archive_per_page', 'admin_per_page', 'auto_archive_days',
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
    'use_captcha', 'allow_messaging', 'allow_guest_messaging', 'allow_custom_game_links', 'allow_manual_links',
    'allow_user_template', 'allow_guest_template', 'allow_user_language', 'allow_guest_language',
    'allow_start_outside_hours', 'mailing_list', 'antibot_honeypot',
    // show_venue_name is NOT here any more: the header_brand dropdown replaced
    // it, and leaving it in the toggle list would have every save rewrite it to
    // 0 (an absent checkbox reads as off), destroying the legacy value that
    // header_brand_mode() falls back to.
    'chat_enabled', 'public_archives', 'use_day_names', 'gdpr_prefill',
    'switcher_show_user_template', 'switcher_show_user_language',
];

/**
 * Options that must NEVER travel between sites in a settings file.
 *
 * Two reasons, both of which would be a real problem in the field:
 *   - CREDENTIALS ($OPTION_SECRETS) — several clubs run on subdomains of one
 *     host, so a file quietly carrying an SMTP password or a BGG key from one
 *     to another is a leak, not a convenience.
 *   - IDENTITY — a site's own name, its public URL and the name it prefills for
 *     new events. Copying these makes the second site claim to be the first:
 *     emails would point at the wrong domain.
 *
 * Everything else is behaviour, which is exactly what an admin wants to clone.
 * github_url is deliberately NOT here: it is the same upstream on every site
 * unless someone forks, so copying it is right.
 *
 * @return string[]
 */
function options_not_portable() {
    global $OPTION_SECRETS;
    return array_merge($OPTION_SECRETS, ['site_url', 'venue_name', 'default_event_name']);
}

/**
 * The current settings as a portable array, ready to be JSON-encoded.
 *
 * @return array
 */
function options_export_data() {
    global $OPTION_VALUES, $OPTION_TOGGLES;
    $skip = options_not_portable();
    $out  = [];
    foreach (array_merge($OPTION_VALUES, $OPTION_TOGGLES) as $key) {
        if (in_array($key, $skip, true)) continue;
        $out[$key] = (string)opt($key);
    }
    // Custom texts are per-language option keys generated at runtime, so they
    // are not in either whitelist — but they ARE portable, and re-typing six
    // texts in two languages on eighty sites is exactly the work this avoids.
    foreach (custom_msg_keys() as $msgKey) {
        foreach (lang_available() as $lc) {
            $optKey = custom_msg_option($msgKey, $lc);
            $out[$optKey] = (string)opt($optKey);
        }
    }
    ksort($out);
    return [
        'format'   => 'zapisynagry-options',
        'version'  => 1,
        'exported' => gmdate('c'),
        'options'  => $out,
    ];
}

/**
 * Apply a decoded settings file.
 *
 * Every value goes through option_sanitize() — the SAME validation the form
 * uses — so a hand-edited or older file cannot write something the form would
 * have refused. Unknown keys are ignored rather than stored, and non-portable
 * keys are refused even if the file contains them, so an older export that
 * still carried a password cannot overwrite this site's.
 *
 * @param array $data  Decoded JSON.
 * @return array ['applied' => int, 'skipped' => int, 'error' => string|null]
 */
function options_import_data($data) {
    global $OPTION_VALUES, $OPTION_TOGGLES;
    if (!is_array($data) || ($data['format'] ?? '') !== 'zapisynagry-options'
        || !isset($data['options']) || !is_array($data['options'])) {
        return ['applied' => 0, 'skipped' => 0, 'error' => 'format'];
    }

    $skip     = options_not_portable();
    $toggles  = array_flip($OPTION_TOGGLES);
    $values   = array_flip($OPTION_VALUES);
    $custom   = [];
    foreach (custom_msg_keys() as $msgKey) {
        foreach (lang_available() as $lc) $custom[custom_msg_option($msgKey, $lc)] = true;
    }

    $applied = 0;
    $skipped = 0;
    foreach ($data['options'] as $key => $val) {
        if (!is_string($key) || is_array($val)) { $skipped++; continue; }
        if (in_array($key, $skip, true)) { $skipped++; continue; }

        if (isset($toggles[$key])) {
            // Anything but an exact "1" is off, matching how the form saves.
            opt_set($key, (string)$val === '1' ? '1' : '0');
            $applied++;
        } elseif (isset($values[$key])) {
            $clean = option_sanitize($key, (string)$val);
            if ($clean === null) { $skipped++; continue; }
            opt_set($key, $clean);
            $applied++;
        } elseif (isset($custom[$key])) {
            opt_set($key, (string)$val);
            $applied++;
        } else {
            // A key this version does not know — a newer export, or junk.
            $skipped++;
        }
    }
    return ['applied' => $applied, 'skipped' => $skipped, 'error' => null];
}

/**
 * Validate and coerce ONE option value, exactly as the Options form does.
 *
 * Extracted so the form and the settings IMPORT share a single implementation:
 * an import that validated differently would be a second, quieter way to write
 * a value the form would have refused.
 *
 * @param string $key
 * @param string $val  Raw incoming value.
 * @return string|null Sanitised value, or null if it must be rejected (the
 *                     caller leaves the stored value alone).
 */
function option_sanitize($key, $val) {
    $val = trim((string)$val);
    switch ($key) {                       // coerce / validate the few constrained fields
        case 'max_tables':
        case 'timeline_extension':
        case 'email_smtp_port':
        case 'poll_default_deadline_hours':
        case 'login_days':
        case 'archive_per_page':
        case 'log_retention_days':
        case 'admin_per_page':
        case 'auto_archive_days':
            // Clamped again where they are USED, so a value written
            // straight into the options table cannot produce a zero-row
            // page or an unbounded query.
            $val = (string)max(0, (int)$val);
            break;
        case 'chat_max_messages':
        case 'chat_initial_messages':
        case 'chat_refresh_seconds':
        case 'chat_send_delay':
            // Coerced here, then clamped again at READ time by the helpers
            // in inc/chat.php — a value that predates this validation, or
            // one written straight into the table, must not be able to make
            // the panel hammer the server or dump the whole log.
            $val = (string)max(0, (int)$val);
            break;
        case 'day_tab_format':
            // Anything else would fall through to the default layout inside
            // day_tab_parts() anyway; reject it so the stored value always
            // names a real layout.
            if (!in_array($val, day_tab_formats(), true)) return null;
            break;
        case 'home_layout':
            // Only the four current arrangements are storable. The two legacy
            // values still RESOLVE (see home_layout_order) so an unsaved site
            // keeps its arrangement, but the form cannot write them back.
            if (!in_array($val, home_layouts(), true)) return null;
            break;
        case 'game_deletion':
            if (!in_array($val, game_deletion_modes(), true)) return null;
            break;
        case 'deleted_games_display':
            if (!in_array($val, deleted_games_displays(), true)) return null;
            break;
        case 'header_brand':
            if (!in_array($val, ['auto', 'both', 'none'], true)) return null;
            break;
        case 'chat_scope':
            if (!in_array($val, ['event', 'global'], true)) return null;
            break;
        case 'antibot_delay_form':
        case 'antibot_delay_click':
            $val = (string)max(0, (int)$val);          // non-negative integers only
            break;
        case 'overnight_grace_hours':
            // 0 = pivot exactly at opening; 12 is far more than any sane
            // setup window and keeps the pivot from wrapping a full day.
            $val = (string)min(12, max(0, (int)$val));
            break;
        case 'default_language':
            if (!lang_exists($val)) return null;        // ignore an unknown code (skip this field)
            break;
        case 'default_template':
            if (!tpl_exists($val)) return null;         // ignore an unknown theme
            break;
        case 'registration_mode':
            // Whitelist: only these two modes are valid.
            if (!in_array($val, ['registration', 'guest_only'], true)) return null;
            break;
        case 'verification_method':
            // Whitelist of the verification tree's method names.
            if (!in_array($val, ['none', 'registered', 'email_code', 'email_match'], true)) return null;
            break;
        case 'table_names_mode':
            // Whitelist of the table-name permission modes.
            if (!in_array($val, ['off', 'admin', 'add_any', 'any'], true)) return null;
            break;
        case 'require_email':
            // Integer codes: 0 = never, 1 = always, 2 = proposer decides per game.
            if (!in_array($val, ['0', '1', '2'], true)) return null;
            break;
        case 'header_button_style':
            // How the top-bar nav renders: text only / icon only / both.
            if (!in_array($val, ['text', 'icon', 'both'], true)) return null;
            break;
        case 'switcher_pos_template':
        case 'switcher_pos_language':
            // Anything else would fall through to "not this slot" in
            // switcher_visible() and the switcher would silently vanish.
            if (!in_array($val, ['header', 'footer', 'both', 'none'], true)) return null;
            break;
        case 'home_layout':
            // Anything else would fall through to the CSS default (tables
            // first) anyway, but reject it so the stored value always says
            // what it means rather than quietly meaning "something else".
            if (!in_array($val, ['tables_first', 'timeline_first'], true)) return null;
            break;
        case 'github_url':
            // Empty is meaningful: it means "inherit whatever install.php
            // configured", so it must be storable. Anything non-empty has
            // to be a real http(s) URL — the updater fetches it, and a
            // malformed value would break self-updating with a download
            // error rather than anything that points at the cause.
            $val = trim($val);
            if ($val !== '' && !preg_match('#^https?://#i', $val)) return null;
            if ($val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) return null;
            $val = rtrim($val, '/');
            break;
        case 'email_subject_prefix':
            // Anything else would silently fall through to the venue branch
            // in mail_subject_prefix(); reject it so the stored value always
            // says what it means.
            if (!in_array($val, ['venue', 'event'], true)) return null;
            break;
        case 'timezone':
            // Must be a real PHP timezone: app_timezone_init() falls back to
            // UTC on anything else, so storing junk would silently move the
            // whole site's clock. Reject instead and keep the old value.
            try { new DateTimeZone($val); } catch (Throwable $e) { return null; }
            break;
        case 'captcha_version':
            // Which reCAPTCHA the keys belong to (types aren't interchangeable).
            if (!in_array($val, ['v2', 'v3'], true)) return null;
            break;
        case 'captcha_v3_threshold':
            // Score cutoff 0.1-1.0; anything outside that falls back to 0.5.
            $f = (float)str_replace(',', '.', $val);      // tolerate a comma decimal
            if ($f <= 0 || $f > 1) $f = 0.5;
            $val = rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
            break;
    }
    return $val;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    // Straight to a download, before any page output — this response is a file,
    // not a screen, so it must not go through the admin shell.
    $json = json_encode(options_export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $name = 'zapisynagry-options-' . gmdate('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($json));
    log_action('options_export', 'Settings exported');
    echo $json;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    // Written to the system temp directory, never inside the webroot: this file
    // contains every password hash, email address and stored credential on the
    // site, and a copy left under the document root would be downloadable by
    // anyone who guessed the name.
    $tmp = tempnam(sys_get_temp_dir(), 'zng');
    // tempnam() creates the file; VACUUM INTO refuses an existing target.
    if ($tmp !== false) @unlink($tmp);

    if ($tmp !== false && db_snapshot($tmp)) {
        $name = 'zapisynagry-backup-' . gmdate('Ymd-His') . '.sqlite';
        log_action('db_backup', $name);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($tmp));
        // Straight to the browser, then removed — the snapshot never lingers.
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    @unlink($tmp);
    $flash = t('opt_backup_failed');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    // A pasted file body OR an uploaded file. Paste is offered because it works
    // everywhere and is the path that can actually be tested end to end;
    // is_uploaded_file() only ever returns true for a real multipart request.
    $raw = trim((string)($_POST['import_json'] ?? ''));
    if ($raw === '' && !empty($_FILES['import_file']['tmp_name'])
        && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
        $raw = (string)file_get_contents($_FILES['import_file']['tmp_name']);
    }

    if ($raw === '') {
        $flash = t('opt_import_empty');
    } else {
        $decoded = json_decode($raw, true);
        $res = options_import_data($decoded);
        if ($res['error'] !== null) {
            $flash = t('opt_import_bad');
        } else {
            // Reload so the form below renders the values just written, rather
            // than the ones cached at the top of this request.
            options_load();
            log_action('options_import', $res['applied'] . ' applied, ' . $res['skipped'] . ' skipped');
            $flash = t('opt_import_done', $res['applied'], $res['skipped']);
        }
    }
}

// Anything that is not an explicit export or import is a save, including a POST
// carrying no action at all — that was the contract before those two buttons
// existed, and keeping it means nothing else that posts here has to be updated.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($_POST['action'] ?? '', ['export', 'import', 'backup'], true)) {
    // Force-HTTPS lives in .htaccess, not in the options table, so the checkbox
    // can never disagree with what the server is actually doing. Handled here
    // rather than in the whitelists for the same reason: there is no option to
    // save. An absent checkbox means "off", matching every other toggle.
    if (htaccess_writable()) {
        $wantSsl = !empty($_POST['force_ssl']);
        if ($wantSsl !== htaccess_ssl_enabled()) {
            if (htaccess_ssl_set($wantSsl)) {
                log_action('force_ssl', $wantSsl ? 'enabled' : 'disabled');
            } else {
                $flash = t('opt_force_ssl_failed');
            }
        }
    }

    // --- Plain value fields ---
    // Credentials first, by their own rule: blank means "unchanged".
    foreach ($OPTION_SECRETS as $key) {
        if (!empty($_POST[$key . '__clear'])) {
            opt_set($key, '');            // explicitly asked for, so honour it
            continue;
        }
        $secretVal = trim($_POST[$key] ?? '');
        // Blank OR the asterisk placeholder the form shows for a stored value
        // means "unchanged". The placeholder is a real input value rather than
        // an HTML placeholder attribute, so it comes back on every save; without
        // this it would overwrite the credential with a row of stars.
        //
        // The cost is that a secret consisting only of '*' cannot be set. For an
        // API key or SMTP password that is not a real value, and silently
        // wiping a working credential would be far worse.
        if ($secretVal !== '' && !preg_match('/^\*+$/', $secretVal)) {
            opt_set($key, $secretVal);
        }
        // Otherwise leave the stored value untouched.
    }

    foreach ($OPTION_VALUES as $key) {
        $val = option_sanitize($key, $_POST[$key] ?? '');
        // null = refused by validation; leave the stored value as it was.
        if ($val === null) continue;
        opt_set($key, $val);
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
