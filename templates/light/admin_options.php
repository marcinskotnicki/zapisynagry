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
/**
 * One checkbox. $noteKey adds a small line under it — used to mark the switches
 * that behave differently in guest-only mode, so an admin finds that out while
 * reading the option rather than after wondering why it did nothing.
 */
$toggle = function($key, $noteKey = null) {
    $on = opt($key) === '1' ? ' checked' : '';
    echo '<div class="field field-check"><label>';
    echo '<input type="checkbox" name="' . e($key) . '" value="1"' . $on . '> ' . e(t('opt_' . $key));
    echo '</label>';
    if ($noteKey !== null) echo '<p class="field-note field-note-mode">' . e(t($noteKey)) . '</p>';
    echo '</div>';
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
        ?>
        <?php // How long an OPEN poll is treated as running, on the timeline and for
              // "next free slot" on its table — nobody knows the winner yet, so this
              // is always an estimate. See poll_length_minutes(). ?>
        <div class="field">
            <label for="poll_game_length_mode"><?= e(t('opt_poll_game_length_mode')) ?></label>
            <select id="poll_game_length_mode" name="poll_game_length_mode">
                <?php foreach (poll_game_length_modes() as $plm): ?>
                    <option value="<?= e($plm) ?>"<?= poll_game_length_mode() === $plm ? ' selected' : '' ?>>
                        <?= e(t('opt_poll_game_length_mode_' . $plm)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_poll_game_length_mode_note')) ?></p>
        </div>
        <?php
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
        // The two libraries. Their master switches belong HERE, with the other
        // feature switches, rather than at the top of their own group: this is
        // where an admin looks to find out what the site has switched on. The
        // library group keeps everything that only tunes them.
        $toggle('club_library');
        $toggle('club_shelf');
        ?>
        <p class="field-note"><?= e(t('opt_club_shelf_note')) ?></p>
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
        <?php // Who decides how a game is removed. Enforced in delete_game.php as
              // well, since a posted choice is only a form field. ?>
        <div class="field">
            <label for="game_deletion"><?= e(t('opt_game_deletion')) ?></label>
            <select id="game_deletion" name="game_deletion">
                <?php foreach (game_deletion_modes() as $gdm): ?>
                    <option value="<?= e($gdm) ?>"<?= game_deletion_mode() === $gdm ? ' selected' : '' ?>>
                        <?= e(t('opt_game_deletion_' . $gdm)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_game_deletion_note')) ?></p>
        </div>

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
        <?php // The three home-page blocks in any of four orders. The selected
              // entry is derived from home_layout_order() rather than compared
              // to the stored string, so a site still on one of the two legacy
              // values shows the arrangement it is actually using. ?>
        <?php $curOrder = implode(',', home_layout_order()); ?>
        <div class="field">
            <label for="home_layout"><?= e(t('opt_home_layout')) ?></label>
            <select id="home_layout" name="home_layout">
                <?php foreach (home_layouts() as $layoutOpt): ?>
                    <?php $optOrder = implode(',', home_layout_order_of($layoutOpt)); ?>
                    <option value="<?= e($layoutOpt) ?>"<?= $optOrder === $curOrder ? ' selected' : '' ?>>
                        <?= e(t('opt_home_layout_' . $layoutOpt)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_home_layout_note')) ?></p>
        </div>
        <?php /* Panel housekeeping rather than site appearance — but this is
                 where an admin looks to change what is on their screen, and a
                 group of its own for a single checkbox would be worse. */ ?>
        <?php $toggle('show_help_tab'); ?>
        <p class="field-note"><?= e(t('opt_show_help_tab_note')) ?></p>

        <?php $toggle('show_help_front'); ?>
        <p class="field-note"><?= e(t('opt_show_help_front_note')) ?></p>

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
        <?php // How a soft-deleted game appears. Only reachable when soft deletes
              // happen at all, which the note spells out rather than hiding the
              // setting — an admin who later allows them should find it where
              // they expect. ?>
        <div class="field">
            <label for="deleted_games_display"><?= e(t('opt_deleted_games_display')) ?></label>
            <select id="deleted_games_display" name="deleted_games_display">
                <?php foreach (deleted_games_displays() as $dgd): ?>
                    <option value="<?= e($dgd) ?>"<?= deleted_games_display() === $dgd ? ' selected' : '' ?>>
                        <?= e(t('opt_deleted_games_display_' . $dgd)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_deleted_games_display_note')) ?></p>
        </div>

        <?php // Optional per-day labels. Off by default; switching it off later
              // hides existing names without deleting them, so it is safe to try. ?>
        <?php $toggle('use_day_names'); ?>
        <?php // "Choose version" beside the game name when proposing a BGG game,
              // so a club running a localised copy can label the table with the
              // title that is actually on the box. ?>
        <?php $toggle('game_version_pick'); ?>
        <p class="field-note"><?= e(t('opt_game_version_pick_note')) ?></p>
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

        <?php // What the header's left slot shows. Replaced an on/off checkbox,
              // which could not express "logo AND name". The current value comes
              // from header_brand_mode(), so a site that has not saved since the
              // change shows what it is actually doing rather than a default. ?>
        <div class="field">
            <label for="header_brand"><?= e(t('opt_header_brand')) ?></label>
            <select id="header_brand" name="header_brand">
                <?php foreach (['auto', 'both', 'none'] as $bm): ?>
                    <option value="<?= e($bm) ?>"<?= header_brand_mode() === $bm ? ' selected' : '' ?>>
                        <?= e(t('opt_header_brand_' . $bm)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_header_brand_note')) ?></p>
        </div>

        <?php /* Whether the nav drops the link for the page you are on. On by
               * default — it saves a slot on a narrow screen — but some clubs
               * would rather the nav kept the same shape everywhere. */ ?>
        <?php $toggle('nav_hide_current'); ?>
        <p class="field-note"><?= e(t('opt_nav_hide_current_note')) ?></p>

        <?php /* Custom CSS. Sits at the tail of Appearance now, not Advanced —
                 it IS an appearance setting, and Advanced is meant for things
                 that can break the site if touched carelessly, which this
                 mostly cannot: it is deliberately NOT applied to this panel, so
                 a rule that hides everything can always be undone from here.
                 The point of the feature is styling a site without FTP, and
                 without that exclusion a bad paste would need FTP to fix. */ ?>
        <div class="field field-custom_css">
            <label for="custom_css"><?= e(t('opt_custom_css')) ?></label>
            <textarea id="custom_css" name="custom_css" rows="10" spellcheck="false"
                      class="code-area"><?= e(opt('custom_css')) ?></textarea>
            <p class="field-note"><?= e(t('opt_custom_css_note')) ?></p>
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

        <?php // Ready-made instructions for clubs who would rather not write
              // their own. Fills only EMPTY boxes, so pressing it cannot lose
              // anything already typed — which is why it needs no confirmation. ?>
        <div class="field field-load-texts">
            <button type="submit" name="action" value="load_texts" class="btn btn-small"
                    formnovalidate><?= e(t('opt_load_texts')) ?></button>
            <p class="field-note"><?= e(t('opt_load_texts_note')) ?></p>
        </div>
    <?php $groupEnd(); ?>

    <?php /* 4. ARCHIVE — the title says when these matter, rather than the
             group vanishing and leaving an admin hunting for a setting. */ ?>
    <?php $group('opt_group_archive', false, 'opt_group_archive_note'); ?>
        <?php /* WHICH SHAPE, or both. The list and the calendar are the same
               * events presented differently, so a club that only wants one
               * need not carry a nav link and a page for the other. */ ?>
        <div class="field field-archive_views">
            <label for="archive_views"><?= e(t('opt_archive_views')) ?></label>
            <select id="archive_views" name="archive_views">
                <?php foreach (['both', 'archive', 'calendar'] as $avMode): ?>
                    <option value="<?= e($avMode) ?>"<?= archive_views_mode() === $avMode ? ' selected' : '' ?>>
                        <?= e(t('opt_archive_views_' . $avMode)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_archive_views_note')) ?></p>
        </div>
        <?php
        $text('archive_per_page', 'number');   // events per page on the public list
        $text('auto_archive_days', 'number');  // 0 = never auto-archive
        ?>

        <?php /* HOW EVENTS ARE PRESENTED. All three default off, so a club that
                 never opens this group sees exactly what it always has. */ ?>
        <?php $toggle('home_event_list'); ?>
        <p class="field-note"><?= e(t('opt_home_event_list_note')) ?></p>

        <?php // Directly under the option it depends on, since it does nothing
              // on its own — the note says as much. ?>
        <?php $toggle('event_list_by_day'); ?>
        <p class="field-note"><?= e(t('opt_event_list_by_day_note')) ?></p>

        <?php $toggle('hide_past_days'); ?>
        <p class="field-note"><?= e(t('opt_hide_past_days_note')) ?></p>

        <?php $toggle('hide_event_tabs'); ?>
        <p class="field-note"><?= e(t('opt_hide_event_tabs_note')) ?></p>

        <?php $toggle('event_details'); ?>
        <p class="field-note"><?= e(t('opt_event_details_note')) ?></p>

        <?php $toggle('event_stats'); ?>
        <p class="field-note"><?= e(t('opt_event_stats_note')) ?></p>

        <?php /* Prefills for a NEW event's location. Rendered unconditionally,
                 even though they only do anything once the details above are on:
                 every option this form can SAVE has to be visible on it, or it
                 becomes a value an admin can neither see nor correct. The note
                 says when they apply instead. */ ?>
        <?php $text('default_location_name'); ?>
        <div class="field field-default_location_address">
            <label for="default_location_address"><?= e(t('opt_default_location_address')) ?></label>
            <?php // A textarea, because an address is several lines and is
                  // rendered with its newlines kept. ?>
            <textarea id="default_location_address" name="default_location_address"
                      rows="3"><?= e(opt('default_location_address')) ?></textarea>
        </div>
        <p class="field-note"><?= e(t('opt_default_location_note')) ?></p>
    <?php $groupEnd(); ?>

    <?php /* 4b. CLUB LIBRARY */ ?>
    <?php $group('opt_group_library', false, 'opt_group_library_note'); ?>
        <?php
        // The two master switches are in Basic, with the other feature
        // switches — everything here only tunes them, and does nothing at all
        // until one of them is on, which the group note says.
        $toggle('library_show_members');
        $toggle('library_allow_contact');
        ?>
        <p class="field-note"><?= e(t('opt_library_contact_note')) ?></p>
        <?php $toggle('library_mail_venue'); ?>
        <p class="field-note"><?= e(t('opt_library_mail_venue_note')) ?></p>

        <?php // Depends on the club's shelf rather than on the members' library,
              // so it gets its own note rather than the generic one. ?>
        <?php $toggle('club_shelf_pick'); ?>
        <p class="field-note"><?= e(t('opt_club_shelf_pick_note')) ?></p>

        <?php $toggle('library_show_member_games'); ?>
        <p class="field-note"><?= e(t('opt_library_show_member_games_note')) ?></p>
        <?php $toggle('library_show_common'); ?>
        <p class="field-note"><?= e(t('opt_library_show_common_note')) ?></p>

        <?php $toggle('library_prefer_club'); ?>
        <p class="field-note"><?= e(t('opt_library_prefer_club_note')) ?></p>
        <?php // No address means no contact button for the club: there is no
              // per-member opt-in to fall back on here, so the address IS it. ?>
        <?php $text('library_club_email', 'email'); ?>
        <p class="field-note"><?= e(t('opt_library_club_email_note')) ?></p>

        <?php // How the shared game list is broken up once it outgrows a screen. ?>
        <div class="field">
            <label for="library_pagination"><?= e(t('opt_library_pagination')) ?></label>
            <select id="library_pagination" name="library_pagination">
                <?php foreach (library_pagination_modes() as $libMode): ?>
                    <option value="<?= e($libMode) ?>"<?= opt('library_pagination') === $libMode ? ' selected' : '' ?>>
                        <?= e(t('opt_library_pagination_' . $libMode)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php // Only does anything in 'pages' mode; the note says so rather than
              // the field disappearing, so an admin can set it before switching. ?>
        <?php $text('library_per_page', 'number'); ?>
        <p class="field-note"><?= e(t('opt_library_per_page_note')) ?></p>
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
        <?php $toggle('chat_close_outside'); ?>
        <?php
        ?>
    <?php $groupEnd(); ?>

    <?php /* 6. ACCOUNTS AND PERMISSIONS */ ?>
    <?php $group('opt_group_accounts'); ?>
        <?php /* The mode comes FIRST: it decides whether accounts exist at all,
                 and several switches below it are inert in guest-only mode. */ ?>
        <div class="field">
            <label for="registration_mode"><?= e(t('opt_registration_mode')) ?></label>
            <select id="registration_mode" name="registration_mode">
                <?php foreach (['registration', 'guest_only'] as $mode): ?>
                    <option value="<?= e($mode) ?>"<?= opt('registration_mode') === $mode ? ' selected' : '' ?>>
                        <?= e(t('opt_registration_mode_' . $mode)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_registration_mode_note')) ?></p>
        </div>

        <?php /* Under the registration mode, because it answers the next
               * question: having decided people may register, this decides
               * whether registering is enough. Inert in guest-only mode, where
               * nobody registers at all. */ ?>
        <div class="field field-account_activation">
            <label for="account_activation"><?= e(t('opt_account_activation')) ?></label>
            <select id="account_activation" name="account_activation">
                <?php foreach (['auto', 'email', 'admin'] as $aMode): ?>
                    <option value="<?= e($aMode) ?>"<?= account_activation_mode() === $aMode ? ' selected' : '' ?>>
                        <?= e(t('opt_account_activation_' . $aMode)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('opt_account_activation_note')) ?></p>
        </div>

        <?php
        /* Guest permissions. Two kinds are marked below:
         *   INERT   — guest-only mode short-circuits the check entirely, so the
         *             box does nothing whichever way it is set.
         *   ACCOUNTS— the switch is fine, but it only ever applies to someone
         *             logged in, and guest-only mode offers visitors no way to
         *             be. (An admin who signs in directly still gets it, which
         *             is why the wording is not "stops working".)
         * allow_guest_messaging is deliberately unmarked: far from stopping, it
         * becomes the ONLY thing keeping messaging alive in guest-only mode. */
        /* Closing your own account. Marked ACCOUNTS: it only ever applies to
         * somebody logged in, so it does nothing for visitors in guest-only
         * mode. Placed with the other account-level permissions rather than
         * with the guest ones, since it is about what a MEMBER may do to their
         * own account. */
        $toggle('allow_self_delete', 'opt_note_accounts_only');
        ?>
        <p class="field-note"><?= e(t('opt_allow_self_delete_note')) ?></p>
        <?php
        $toggle('allow_unregistered_add_games', 'opt_note_guest_only_inert');
        $toggle('allow_unregistered_signup',    'opt_note_guest_only_inert');
        // Sign somebody else up (a parent entering a child). Belongs here
        // rather than with the game settings: it is about WHO may appear at a
        // table, which is what this group governs.
        $toggle('signup_proxy_name');
        ?>
        <p class="field-note"><?= e(t('opt_signup_proxy_name_note')) ?></p>
        <?php
        // Directly under the switch it depends on, and marked inert without it.
        $toggle('signup_proxy_members', 'opt_note_proxy_off');
        $toggle('chat_logged_in_only',          'opt_note_guest_only_inert');
        $toggle('allow_guest_messaging');      // messaging open to guests too, not just accounts
        $toggle('allow_user_template', 'opt_note_guest_only_accounts');   // theme via user panel
        $toggle('allow_guest_template');       // guests may pick a theme (topbar)
        $toggle('allow_user_language', 'opt_note_guest_only_accounts');   // language via user panel
        $toggle('allow_guest_language');       // guests may pick a language (topbar)
        ?>
        <?php foreach (['language', 'template'] as $sw): ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="switcher_show_user_<?= e($sw) ?>" value="1"
                           <?= opt_bool('switcher_show_user_' . $sw) ? 'checked' : '' ?>>
                    <?= e(t('opt_switcher_show_user_' . $sw)) ?>
                </label>
                <p class="field-note field-note-mode"><?= e(t('opt_note_guest_only_accounts')) ?></p>
            </div>
        <?php endforeach; ?>
        <p class="field-note"><?= e(t('opt_switcher_show_user_note')) ?></p>

        <?php /* MEMBERS ONLY. Above the sign-in options because it decides
               * whether anyone who is not signed in sees the site at all.
               * Everything needed to GET an account stays reachable — see the
               * allow-list in inc/bootstrap.php. */ ?>
        <?php $toggle('require_login'); ?>
        <p class="field-note"><?= e(t('opt_require_login_note')) ?></p>

        <?php /* SIGNING IN WITH GOOGLE, last in the group: the other answer to
               * "how do people get in" belongs with the rest of this section,
               * but at the bottom rather than straight under the registration
               * mode — everything above it is about permissions for the
               * accounts this creates, and Google is about how one gets
               * created or reached in the first place. */ ?>
        <?php $toggle('google_login'); ?>
        <p class="field-note"><?= e(t('opt_google_login_note')) ?></p>
        <?php /* Directly under the switch it depends on, as the other paired
               * options are. Off by default — the address is verified by
               * Google either way, but an admin account is worth more than a
               * member's, so linking one deliberately is the safe default. */ ?>
        <?php $toggle('google_admin_autolink'); ?>
        <p class="field-note"><?= e(t('opt_google_admin_autolink_note')) ?></p>
        <?php /* WARNS BEFORE IT BITES. Switching this off locks out anybody who
               * has no password here, and nothing else on this screen would say
               * so. Only shown when there is somebody to lose. */ ?>
        <?php if (!empty($google_only_count)): ?>
            <p class="field-note field-warn"><?= e(t('opt_google_warn_lockout', (int)$google_only_count)) ?></p>
        <?php endif; ?>
        <div class="field field-google_client_id">
            <label for="google_client_id"><?= e(t('opt_google_client_id')) ?></label>
            <input type="text" id="google_client_id" name="google_client_id"
                   value="<?= e(opt('google_client_id')) ?>" autocomplete="off" spellcheck="false">
        </div>
        <?php /* Rendered by the same helper as every other secret, so it is
               * masked and gets its own "clear" checkbox rather than being
               * echoed back into the page. */ ?>
        <?php $secret('google_client_secret'); ?>
        <?php /* The redirect URI, spelled out. Google matches it literally —
               * no wildcards, not even across subdomains — so an admin needs
               * the exact string rather than a description of it. */ ?>
        <?php $gRedirect = google_redirect_uri(); ?>
        <?php if ($gRedirect !== ''): ?>
            <?php /* The two halves are escaped SEPARATELY so the address can be
                   * bold: the note text through e(), then the already-escaped
                   * address substituted into its %s wrapped in <strong>.
                   * Escaping the finished sentence instead would print the
                   * tags; not escaping it would trust an admin-overridable
                   * string with raw HTML. */ ?>
            <p class="field-note"><?= sprintf(e(t('opt_google_setup_note')),
                                              '<strong>' . e($gRedirect) . '</strong>') ?></p>
        <?php else: ?>
            <?php // No site address to build it from, so there is no exact
                  // string to hand over — say what to fix rather than printing
                  // a note with a hole in it. ?>
            <p class="field-note"><?= e(t('opt_google_need_site_url')) ?></p>
        <?php endif; ?>
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
            <?php // Says which tags survive, so an admin does not discover the
                  // allowlist by pasting something and watching it appear as
                  // literal text on a public page. ?>
            <p class="field-note"><?= e(t('opt_mailing_gdpr_html')) ?></p>
        </div>
        <?php // Only meaningful once wording exists, so it sits directly under it. ?>
        <?php $toggle('gdpr_prefill'); ?>
        <p class="field-note"><?= e(t('opt_gdpr_prefill_note')) ?></p>

        <?php // Beside the consent wording, since it answers the same question
              // from the other side: consent says what they agreed to, this
              // says whether the address was ever shown to be theirs. ?>
        <?php $toggle('mailing_double_optin'); ?>
        <p class="field-note"><?= e(t('opt_mailing_double_optin_note')) ?></p>
        <?php /* Offers the checkbox in the user panel. Independent of the
                 mailing list above: this one goes to ACCOUNTS that asked for
                 it, so a club can run it without running a public list. */ ?>
        <?php $toggle('notify_new_event'); ?>
        <p class="field-note"><?= e(t('opt_notify_new_event_note')) ?></p>
    <?php $groupEnd(); ?>

    <?php /* 8. SECURITY */ ?>
    <?php $group('opt_group_security'); ?>
        <?php // Reflects the .htaccess file rather than a stored option, so it
              // always shows what the server is really doing — editing that file
              // over FTP is a normal thing to do on shared hosting.
              //
              // Offered only when the file can actually be written, and only
              // when THIS request arrived over https: switching it on from a
              // plain-http page would make the site immediately unreachable,
              // including this panel. ?>
        <?php if (!htaccess_writable()): ?>
            <div class="field">
                <label><?= e(t('opt_force_ssl')) ?></label>
                <p class="field-note"><?= e(t('opt_force_ssl_readonly')) ?></p>
            </div>
        <?php elseif (!request_is_https() && !htaccess_ssl_enabled()): ?>
            <div class="field">
                <label><?= e(t('opt_force_ssl')) ?></label>
                <p class="field-note"><?= e(t('opt_force_ssl_need_https')) ?></p>
            </div>
        <?php else: ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="force_ssl" value="1"<?= htaccess_ssl_enabled() ? ' checked' : '' ?>>
                    <?= e(t('opt_force_ssl')) ?>
                </label>
                <p class="field-note"><?= e(t('opt_force_ssl_note')) ?></p>
            </div>
        <?php endif; ?>

        <?php $toggle('use_captcha'); ?>
        <?php /* WHERE it appears. One switch used to mean everywhere, which
               * forced a club wanting a captcha on registration to put one in
               * front of signing up for a game too — the action people take
               * most often, and where friction costs the most.
               *
               * All on by default, so switching the feature on still protects
               * everything and a club unticks what it finds too much. Rendered
               * from captcha_contexts() so this screen and the save handler
               * cannot disagree about which places exist. */ ?>
        <?php foreach (captcha_contexts() as $cCtx): ?>
            <?php $toggle('captcha_on_' . $cCtx, 'opt_note_captcha_off'); ?>
        <?php endforeach; ?>
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
        <?php /* TEXT OVERRIDES, one field per installed language.
               *
               * In Advanced because a typo here changes wording across the
               * site — but only the wording: overrides are escaped on output
               * like every other string, and lang_parse_overrides() refuses a
               * key that does not exist or one whose %-placeholders do not
               * match, so a mistake shows wrong words rather than breaking a
               * page.
               *
               * Each field links to the language file it overrides, because
               * the keys are only discoverable by reading it. */ ?>
        <?php foreach (lang_available() as $oLang): ?>
            <?php $oKey = 'lang_override_' . $oLang; ?>
            <div class="field field-<?= e($oKey) ?>">
                <label for="<?= e($oKey) ?>"><?= e(t('opt_lang_override', strtoupper($oLang))) ?></label>
                <textarea id="<?= e($oKey) ?>" name="<?= e($oKey) ?>" rows="6"
                          class="code-area" spellcheck="false"><?= e(opt($oKey)) ?></textarea>
                <p class="field-note"><?= e(t('opt_lang_override_note')) ?></p>
                <p class="field-note">
                    <a href="<?= e(update_repo_url()) ?>/blob/main/languages/<?= e($oLang) ?>.php"
                       target="_blank" rel="noopener"><?= e(t('opt_lang_override_link', $oLang . '.php')) ?></a>
                </p>
            </div>
        <?php endforeach; ?>
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
        <?php // Audit entries carry names and IP addresses, so they are not kept
              // indefinitely by default. Size is not the concern — a log row is
              // around 114 bytes — it is that there is no reason to hold
              // personal data forever. ?>
        <div class="field">
            <label for="log_retention_days"><?= e(t('opt_log_retention_days')) ?></label>
            <input type="number" id="log_retention_days" name="log_retention_days" min="0"
                   value="<?= (int)opt('log_retention_days') ?>">
            <p class="field-note"><?= e(t('opt_log_retention_days_note')) ?></p>
        </div>
        <?php
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
