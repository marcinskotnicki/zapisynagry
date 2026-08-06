<?php
/* =============================================================================
 *  templates/light/admin_options.php — the Options tab form. Presentation only.
 * -----------------------------------------------------------------------------
 *  Renders every admin-editable setting as an ACCORDION of nine groups, with
 *  the first open and the rest collapsed. Ordered so a new admin meets the
 *  handful of settings they must set before the dozens they probably should
 *  not:
 *      1 Basic   2 Appearance   3 Custom texts   4 Archive   5 Chat
 *      6 Accounts & permissions   7 Email   8 Security   9 Advanced
 *
 *  Groups 4 and 5 configure optional features; their titles say so rather than
 *  hiding when the feature is off, so a setting an admin remembers is still
 *  where they left it.
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

// A credential. Renders EMPTY, always — the stored value is never written into
// the HTML, because `value="..."` is readable from View Source no matter what
// `type` the input has. Blank on submit means "unchanged"; the checkbox is the
// only way to actually clear it, so an admin saving the form for some other
// reason cannot wipe a key by accident.
$secret = function($key) {
    $isSet = opt($key) !== '';
    echo '<div class="field"><label for="' . e($key) . '">' . e(t('opt_' . $key)) . '</label>';
    // autocomplete="new-password" stops a browser helpfully pasting the
    // admin's own saved credentials into a field meant for a service key.
    // A row of stars when something is stored, so the field does not read as
    // empty — the real value is still never sent to the browser. Submitted
    // back unchanged it means "leave it alone" (see inc/admin/options.php).
    // Empty when nothing is stored, so "not set yet" stays visibly different
    // from "set, and hidden".
    echo '<input type="password" class="secret-field" id="' . e($key) . '" name="' . e($key)
       . '" value="' . ($isSet ? '*********' : '') . '" autocomplete="new-password">';
    echo '<p class="field-note">' . e(t('opt_secret_note')) . ' '
       . e($isSet ? t('opt_secret_is_set') : t('opt_secret_not_set')) . '</p>';
    if ($isSet) {
        echo '<label class="checkbox"><input type="checkbox" name="' . e($key) . '__clear" value="1"> '
           . e(t('opt_secret_clear')) . '</label>';
    }
    echo '</div>';
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

// One accordion section. <details name=...> makes the browser close the others
// natively; the JS in scripts.js does the same for browsers that predate that
// attribute, so the behaviour is identical either way.
$group = function ($titleKey, $open = false, $noteKey = null) {
    echo '<details class="opt-group" name="opt-group"' . ($open ? ' open' : '') . '>';
    echo '<summary class="opt-group-head">' . e(t($titleKey));
    if ($noteKey !== null) echo ' <span class="opt-group-note">' . e(t($noteKey)) . '</span>';
    echo '</summary><div class="opt-group-body">';
};
$groupEnd = function () { echo '</div></details>'; };
?>

<form method="post" action="admin.php?tab=options" class="options-form" enctype="multipart/form-data">
    <?= $csrf ?>

    <?php /* 1. BASIC — everything a new club must set to open its doors. */ ?>
    <?php $group('opt_group_basic', true); ?>
        <?php
        $text('venue_name');
        $text('max_tables', 'number');           // 0 = unlimited
        $text('site_url');                       // used for the link at the foot of emails
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
        <?php // The site's clock. Event times and poll deadlines are wall-clock
              // values, so this has to be the venue's real timezone or polls
              // resolve at the wrong moment. Grouped by region to stay usable. ?>
        <div class="field">
            <label for="timezone"><?= e(t('opt_timezone')) ?></label>
            <select id="timezone" name="timezone">
                <?php
                $tzNow = opt('timezone', 'UTC');
                $groups = [];
                foreach (DateTimeZone::listIdentifiers() as $tzId) {
                    $region = strpos($tzId, '/') !== false ? strstr($tzId, '/', true) : 'Other';
                    $groups[$region][] = $tzId;
                }
                // A stored value PHP no longer knows about would otherwise vanish
                // from the list and silently reset on the next save.
                if ($tzNow !== '' && !in_array($tzNow, DateTimeZone::listIdentifiers(), true)) {
                    $groups['Other'][] = $tzNow;
                }
                foreach ($groups as $region => $ids): ?>
                    <optgroup label="<?= e($region) ?>">
                        <?php foreach ($ids as $tzId): ?>
                            <option value="<?= e($tzId) ?>"<?= $tzId === $tzNow ? ' selected' : '' ?>><?= e($tzId) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_timezone_note', date('H:i'))) ?></p>
        </div>
        <?php
        $text('timeline_extension', 'number');    // hours added past the day's end
        $text('overnight_grace_hours', 'number'); // early-setup window before opening (see day_rel_min)
        $toggle('allow_start_outside_hours');     // off -> clamp game/poll start to the day's hours
        $textarea('game_languages');              // one dropdown choice per line
        $toggle('allow_polls');
        $text('poll_default_deadline_hours', 'number');   // polls close N hours before start
        $toggle('allow_discussions');
        ?>
        <?php // This one changes an INVARIANT, not just a view: with it off the
              // app holds exactly one live event and creating a new one archives
              // the previous automatically. Worth saying on the screen, because
              // an admin who wants two events at once has no way to guess that
              // this is the setting that allows it. ?>
        <?php $toggle('public_archives'); ?>
        <p class="field-note"><?= e(t('opt_public_archives_note')) ?></p>
        <?php
        $toggle('chat_enabled');
        $toggle('mailing_list');
        $toggle('send_emails');       // master switch for notifications
        $toggle('allow_messaging');
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
        $toggle('allow_custom_game_links');
        $toggle('allow_manual_links');
        ?>
        <div class="field">
            <label for="table_names_mode"><?= e(t('opt_table_names_mode')) ?></label>
            <select id="table_names_mode" name="table_names_mode">
                <?php foreach (['off', 'admin', 'add_any', 'any'] as $m): // who may set/edit table names ?>
                    <option value="<?= e($m) ?>"<?= opt('table_names_mode') === $m ? ' selected' : '' ?>>
                        <?= e(t('opt_table_names_' . $m)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="require_email"><?= e(t('opt_require_email')) ?></label>
            <select id="require_email" name="require_email">
                <?php foreach ([0, 1, 2] as $m): // 0=never, 1=always, 2=proposer decides per game ?>
                    <option value="<?= $m ?>"<?= opt_int('require_email') === $m ? ' selected' : '' ?>>
                        <?= e(t('opt_require_email_' . $m)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php $groupEnd(); ?>

    <?php /* 2. APPEARANCE */ ?>
    <?php $group('opt_group_appearance'); ?>
        <div class="field">
            <label for="default_template"><?= e(t('opt_default_template')) ?></label>
            <select id="default_template" name="default_template">
                <?php foreach (tpl_available() as $name): // themes found on disk ?>
                    <option value="<?= e($name) ?>"<?= opt('default_template') === $name ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="home_layout"><?= e(t('opt_home_layout')) ?></label>
            <select id="home_layout" name="home_layout">
                <?php foreach (['tables_first', 'timeline_first'] as $layoutOpt): ?>
                    <option value="<?= e($layoutOpt) ?>"<?= opt('home_layout') === $layoutOpt ? ' selected' : '' ?>>
                        <?= e(t('opt_home_layout_' . $layoutOpt)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php // Where the theme / language switchers appear. Separate from the
              // allow_* toggles in group 6, which decide WHETHER each audience
              // may switch at all. ?>
        <?php foreach (['template', 'language'] as $sw): ?>
            <div class="field">
                <label for="switcher_pos_<?= e($sw) ?>"><?= e(t('opt_switcher_pos_' . $sw)) ?></label>
                <select id="switcher_pos_<?= e($sw) ?>" name="switcher_pos_<?= e($sw) ?>">
                    <?php foreach (['header', 'footer', 'both', 'none'] as $posOpt): ?>
                        <option value="<?= e($posOpt) ?>"<?= opt('switcher_pos_' . $sw) === $posOpt ? ' selected' : '' ?>>
                            <?= e(t('opt_switcher_pos_' . $posOpt)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
        <div class="field">
            <label for="header_button_style"><?= e(t('opt_header_button_style')) ?></label>
            <select id="header_button_style" name="header_button_style">
                <?php foreach (['text', 'icon', 'both'] as $m): // top-bar nav rendering ?>
                    <option value="<?= e($m) ?>"<?= opt('header_button_style') === $m ? ' selected' : '' ?>>
                        <?= e(t('opt_header_button_style_' . $m)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php // Optional per-day labels. Off by default; switching it off later
              // hides existing names without deleting them, so it is safe to try. ?>
        <?php $toggle('use_day_names'); ?>
        <div class="field">
            <label for="day_tab_format"><?= e(t('opt_day_tab_format')) ?></label>
            <select id="day_tab_format" name="day_tab_format">
                <?php foreach (day_tab_formats() as $fmt): ?>
                    <option value="<?= e($fmt) ?>"<?= opt('day_tab_format') === $fmt ? ' selected' : '' ?>>
                        <?= e(t('opt_day_tab_format_' . $fmt)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_day_tab_format_note')) ?></p>
        </div>

        <?php // Rendered UNESCAPED at the bottom of every page — the point is to
              // allow markup for copyright lines, sponsor logos and links.
              // Safe because only an admin can reach this form, and anyone who
              // can edit it can already change every other setting here. ?>
        <div class="field">
            <label for="footer_custom_text"><?= e(t('opt_footer_custom_text')) ?></label>
            <textarea id="footer_custom_text" name="footer_custom_text" rows="4"><?= e(opt('footer_custom_text')) ?></textarea>
            <p class="field-note"><?= e(t('opt_footer_custom_text_note')) ?></p>
        </div>

        <?php // Hide the top-left venue name — used when the venue and event
              // names are the same, and when a site logo replaces it. ?>
        <div class="field field-check">
            <label>
                <input type="checkbox" name="show_venue_name" value="1"<?= opt_bool('show_venue_name') ? ' checked' : '' ?>>
                <?= e(t('opt_show_venue_name')) ?>
            </label>
        </div>
    <?php $groupEnd(); ?>

    <?php /* 3. CUSTOM TEXTS */ ?>
    <?php $group('opt_group_texts'); ?>
        <?php
        // The six optional custom texts, in the order a visitor meets them:
        // homepage banner, add-game form, signup form, add-poll form, vote form,
        // and the note above every email field. Empty = not rendered.
        //
        // One input PER LANGUAGE, generated from lang_available() rather than a
        // hardcoded pl/en pair — languages are auto-discovered from the
        // languages/ folder, so dropping a de.php in there gets a German box
        // here for free, with no change needed on this screen.
        $langs = lang_available();
        sort($langs);
        foreach (custom_msg_keys() as $msgKey): ?>
            <div class="field">
                <label><?= e(t('opt_' . $msgKey)) ?></label>
                <?php foreach ($langs as $lc):
                    $optKey = custom_msg_option($msgKey, $lc); ?>
                    <div class="msg-lang-row">
                        <label class="msg-lang-code" for="<?= e($optKey) ?>"><?= e(strtoupper($lc)) ?></label>
                        <input type="text" id="<?= e($optKey) ?>" name="<?= e($optKey) ?>"
                               value="<?= e(opt($optKey)) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php $groupEnd(); ?>

    <?php /* 4. ARCHIVE — the title says when these matter, rather than the
             group vanishing and leaving an admin hunting for a setting. */ ?>
    <?php $group('opt_group_archive', false, 'opt_group_archive_note'); ?>
        <?php
        $text('archive_per_page', 'number');   // events per page on the public list
        $text('auto_archive_days', 'number');  // 0 = never auto-archive
        ?>
    <?php $groupEnd(); ?>

    <?php /* 5. CHAT */ ?>
    <?php $group('opt_group_chat', false, 'opt_group_chat_note'); ?>
        <div class="field">
            <label for="chat_scope"><?= e(t('opt_chat_scope')) ?></label>
            <select id="chat_scope" name="chat_scope">
                <?php foreach (['event', 'global'] as $scopeOpt): ?>
                    <option value="<?= e($scopeOpt) ?>"<?= opt('chat_scope') === $scopeOpt ? ' selected' : '' ?>>
                        <?= e(t('opt_chat_scope_' . $scopeOpt)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_chat_scope_note')) ?></p>
            <?php // Only shown when it can actually bite: with public archives
                  // on, several events are live at once, so creating any one of
                  // them wipes a log that belongs to all of them. ?>
            <?php if (public_archives_enabled() && opt('chat_scope') === 'event'): ?>
                <p class="field-warn"><?= e(t('opt_chat_scope_warning')) ?></p>
            <?php endif; ?>
        </div>
        <?php
        $text('chat_max_messages', 'number');      // hard cap; oldest trimmed past this
        $text('chat_initial_messages', 'number');  // shown when the panel opens
        $text('chat_refresh_seconds', 'number');   // poll interval while open
        $text('chat_send_delay', 'number');        // pause after sending
        ?>
    <?php $groupEnd(); ?>

    <?php /* 6. ACCOUNTS AND PERMISSIONS */ ?>
    <?php $group('opt_group_accounts'); ?>
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
        $toggle('allow_guest_messaging');      // messaging open to guests too, not just accounts
        $toggle('allow_user_template');        // accounts may pick a theme (user panel)
        $toggle('allow_guest_template');       // guests may pick a theme (topbar)
        $toggle('allow_user_language');        // accounts may pick a language (user panel)
        $toggle('allow_guest_language');       // guests may pick a language (topbar)
        ?>
        <?php foreach (['language', 'template'] as $sw): ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="switcher_show_user_<?= e($sw) ?>" value="1"
                           <?= opt_bool('switcher_show_user_' . $sw) ? 'checked' : '' ?>>
                    <?= e(t('opt_switcher_show_user_' . $sw)) ?>
                </label>
            </div>
        <?php endforeach; ?>
        <p class="field-note"><?= e(t('opt_switcher_show_user_note')) ?></p>
    <?php $groupEnd(); ?>

    <?php /* 7. EMAIL */ ?>
    <?php $group('opt_group_email'); ?>
        <?php
        $text('email_address');
        $text('email_login');
        $text('email_smtp_server');
        $secret('email_password');
        $text('email_smtp_port', 'number');
        ?>
        <?php // Which name every outgoing subject is prefixed with. Venues that
              // run frequent events usually want the event name, so recipients
              // can tell one event's mail from another's. ?>
        <div class="field">
            <label for="email_subject_prefix"><?= e(t('opt_email_prefix')) ?></label>
            <select id="email_subject_prefix" name="email_subject_prefix">
                <?php foreach (['venue', 'event'] as $mode): ?>
                    <option value="<?= e($mode) ?>"<?= opt('email_subject_prefix') === $mode ? ' selected' : '' ?>>
                        <?= e(t('opt_email_prefix_' . $mode)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_email_prefix_note', mail_subject_prefix())) ?></p>
        </div>
        <?php // Mailing-list consent wording. Leaving it empty means no checkbox
              // is shown and none is required. ?>
        <div class="field">
            <label for="mailing_gdpr_text"><?= e(t('opt_mailing_gdpr')) ?></label>
            <textarea id="mailing_gdpr_text" name="mailing_gdpr_text" rows="4"><?= e(opt('mailing_gdpr_text')) ?></textarea>
            <p class="field-note"><?= e(t('opt_mailing_gdpr_note')) ?></p>
        </div>
        <?php // Only meaningful once wording exists, so it sits directly under it. ?>
        <?php $toggle('gdpr_prefill'); ?>
        <p class="field-note"><?= e(t('opt_gdpr_prefill_note')) ?></p>
    <?php $groupEnd(); ?>

    <?php /* 8. SECURITY */ ?>
    <?php $group('opt_group_security'); ?>
        <?php $toggle('use_captcha'); ?>
        <?php // A lightweight companion to captcha: reject a form that comes back
              // faster than a human plausibly could have filled it in. Each
              // guarded form carries a hidden render timestamp; 0 disables that
              // bucket's check, and a logged-in visitor is always exempt. ?>
        <div class="field">
            <label for="antibot_delay_form"><?= e(t('opt_antibot_delay_form')) ?></label>
            <input type="number" id="antibot_delay_form" name="antibot_delay_form" min="0"
                   value="<?= (int)opt('antibot_delay_form') ?>">
            <p class="field-note"><?= e(t('opt_antibot_delay_form_note')) ?></p>
        </div>
        <div class="field">
            <label for="antibot_delay_click"><?= e(t('opt_antibot_delay_click')) ?></label>
            <input type="number" id="antibot_delay_click" name="antibot_delay_click" min="0"
                   value="<?= (int)opt('antibot_delay_click') ?>">
            <p class="field-note"><?= e(t('opt_antibot_delay_click_note')) ?></p>
        </div>
        <div class="field field-check">
            <label>
                <input type="checkbox" name="antibot_honeypot" value="1" <?= opt_bool('antibot_honeypot') ? 'checked' : '' ?>>
                <?= e(t('opt_antibot_honeypot')) ?>
            </label>
            <p class="field-note"><?= e(t('opt_antibot_honeypot_note')) ?></p>
        </div>
    <?php $groupEnd(); ?>

    <?php /* 9. ADVANCED */ ?>
    <?php $group('opt_group_advanced', false, 'opt_group_advanced_note'); ?>
        <?php
        $secret('bgg_api_code');
        $text('captcha_site_key');
        $secret('captcha_secret_key');
        ?>
        <div class="field">
            <label for="captcha_version"><?= e(t('opt_captcha_version')) ?></label>
            <select id="captcha_version" name="captcha_version">
                <?php foreach (['v2', 'v3'] as $v): // must match the key type you created ?>
                    <option value="<?= e($v) ?>"<?= captcha_version() === $v ? ' selected' : '' ?>>
                        <?= e(t('opt_captcha_version_' . $v)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_captcha_version_note')) ?></p>
        </div>
        <div class="field">
            <label for="captcha_v3_threshold"><?= e(t('opt_captcha_v3_threshold')) ?></label>
            <input type="number" id="captcha_v3_threshold" name="captcha_v3_threshold"
                   min="0.1" max="1" step="0.1" value="<?= e(opt('captcha_v3_threshold')) ?>">
            <p class="field-note"><?= e(t('opt_captcha_v3_threshold_note')) ?></p>
        </div>
        <?php
        $text('login_days', 'number');        // persistent-login lifetime; 0 = session only
        $text('admin_per_page', 'number');    // rows per page on admin lists
        ?>
        <?php // Update source. Pre-filled with the EFFECTIVE repo URL, which is
              // config.php's GITHUB_* coords until an admin overrides it here —
              // so a fresh install shows where it actually updates from rather
              // than an empty box. Stored empty = keep inheriting. ?>
        <div class="field">
            <label for="github_url"><?= e(t('opt_github_url')) ?></label>
            <input type="url" id="github_url" name="github_url"
                   value="<?= e(opt('github_url') !== '' ? opt('github_url') : update_repo_url()) ?>">
            <p class="field-note"><?= e(t('opt_github_url_note')) ?></p>
        </div>
        <?php // Pre-filled with the EFFECTIVE branch for the same reason as the
              // URL above: a fresh install should show where it actually pulls
              // from rather than an empty box. Empty stays storable and means
              // "keep inheriting config.php". ?>
        <div class="field">
            <label for="github_branch"><?= e(t('opt_github_branch')) ?></label>
            <input type="text" id="github_branch" name="github_branch"
                   value="<?= e(opt('github_branch') !== '' ? opt('github_branch') : update_branch()) ?>">
            <p class="field-note"><?= e(t('opt_github_branch_note')) ?></p>
        </div>

        <?php // Settings transfer. Both buttons submit THIS form with a different
              // action, so no nested <form> is needed — nesting is invalid HTML
              // and browsers drop the inner one. The file input is associated
              // with the same form for the import case. ?>
        <hr class="opt-sep">
        <h4 class="opt-subhead"><?= e(t('opt_transfer_title')) ?></h4>
        <p class="field-note"><?= e(t('opt_transfer_note')) ?></p>
        <div class="field">
            <button type="submit" name="action" value="export" class="btn btn-small"><?= e(t('opt_export')) ?></button>
        </div>

        <?php // Whole-database backup. Separate from the settings export above:
              // that one is deliberately portable and strips credentials, this
              // one is a complete copy of THIS site meant for restoring it. ?>
        <hr class="opt-sep">
        <h4 class="opt-subhead"><?= e(t('opt_backup_title')) ?></h4>
        <p class="field-note"><?= e(t('opt_backup_note')) ?></p>
        <div class="field">
            <button type="submit" name="action" value="backup" class="btn btn-small"><?= e(t('opt_backup')) ?></button>
        </div>
        <div class="field">
            <label for="import_file"><?= e(t('opt_import_file')) ?></label>
            <input type="file" id="import_file" name="import_file" accept="application/json,.json">
        </div>
        <div class="field">
            <label for="import_json"><?= e(t('opt_import_paste')) ?></label>
            <textarea id="import_json" name="import_json" rows="4"></textarea>
            <p class="field-note"><?= e(t('opt_import_paste_note')) ?></p>
        </div>
        <div class="field">
            <button type="submit" name="action" value="import" class="btn btn-small btn-danger"
                    onclick="return confirm('<?= e(t('opt_import_confirm')) ?>');"><?= e(t('opt_import')) ?></button>
        </div>
    <?php $groupEnd(); ?>

    <?php // The button carries the action so the controller can tell a save from
          // an export or an import, all of which post this same form. ?>
    <button type="submit" name="action" value="save" class="btn btn-primary"><?= e(t('save')) ?></button>
</form>
