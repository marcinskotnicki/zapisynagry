<?php
/* =============================================================================
 *  templates/light/user_panel.php — the user panel. Presentation only.
 * -----------------------------------------------------------------------------
 *  Two parts: a stats grid (games brought / played, each this-event vs all-time)
 *  and three independent profile forms (display name, email, password). Each
 *  form carries a different hidden "action" so user.php knows which to apply.
 *
 *  ADMIN MODE: with $as_admin the same markup renders an admin's view of
 *  SOMEBODY ELSE'S profile (user.php?user=N). Three differences, each explained
 *  at its own site below: a heading naming whose profile this is, no
 *  current-password field, and no theme/language card at all.
 *
 *  RENDER VARS:
 *    $user                         — the account being edited (prefills the forms).
 *    $as_admin                     — true when that account is not your own.
 *    $ask_current                  — whether to ask for the current password.
 *    $self_url                     — where the forms post (carries ?user=N).
 *    $flash                        — one-shot confirmation message, or null.
 *    $brought_event / $brought_all — games-brought counts.
 *    $played_event  / $played_all  — games-played counts.
 *    $csrf                         — hidden CSRF field.
 * ============================================================================= */

/* Defaults so the panel still renders if a caller (or a forked theme) omits the
 * admin-mode vars: "my own profile", which is what this page has always been. */
$as_admin    = !empty($as_admin);
$ask_current = isset($ask_current) ? (bool)$ask_current : true;
$self_url    = $self_url ?? 'user.php';
// Carried inside every form, so the POST edits the same account the GET showed.
$uid_field   = $as_admin
    ? '<input type="hidden" name="user" value="' . (int)$user['id'] . '">'
    : '';
?>
<div class="userpanel">
    <?php // Whose profile this is. On your own it is the plain panel title; an
          // admin gets the account named, plus the way back to the tab they
          // came from — without it the only route back is the browser button. ?>
    <?php if ($as_admin): ?>
        <h1><?= e(t('up_title_admin', $user['display_name'])) ?></h1>
        <p class="muted userpanel-back">
            <a href="admin.php?tab=users"><?= e(t('up_back_to_users')) ?></a>
            &middot; <?= e($user['email']) ?>
        </p>
    <?php else: ?>
        <h1><?= e(t('up_title')) ?></h1>
    <?php endif; ?>

    <?php if (!empty($flash)): // result of the last profile change ?>
        <?php // A refusal ("that password is wrong") drawn green reads as success. ?>
        <p class="msg msg-<?= e($flash_kind ?? 'ok') ?>"><?= e($flash) ?></p>
    <?php endif; ?>

    <h2><?= e(t('up_stats')) ?></h2>
    <div class="stats-grid">
        <div class="stat">
            <div class="stat-label"><?= e(t('up_brought')) ?></div>
            <div class="stat-nums">
                <span><?= (int)$brought_event ?> <small><?= e(t('up_this_event')) ?></small></span>
                <span><?= (int)$brought_all ?> <small><?= e(t('up_all_time')) ?></small></span>
            </div>
        </div>
        <div class="stat">
            <div class="stat-label"><?= e(t('up_played')) ?></div>
            <div class="stat-nums">
                <span><?= (int)$played_event ?> <small><?= e(t('up_this_event')) ?></small></span>
                <span><?= (int)$played_all ?> <small><?= e(t('up_all_time')) ?></small></span>
            </div>
        </div>
    </div>

    <div class="profile-forms">
        <?php // Change display name (action=name). ?>
        <form method="post" action="<?= e($self_url) ?>" class="card profile-card">
            <?= $csrf ?><?= $uid_field ?>
            <input type="hidden" name="action" value="name">
            <h3><?= e(t('up_change_name')) ?></h3>
            <div class="field field-display_name">
                <label for="display_name"><?= e(t('up_name')) ?></label>
                <input type="text" id="display_name" name="display_name" value="<?= e($user['display_name']) ?>" maxlength="<?= TEXT_PERSON_MAX ?>" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
            </div>
        </form>

        <?php // Change email (action=email; uniqueness checked server-side). ?>
        <form method="post" action="<?= e($self_url) ?>" class="card profile-card">
            <?= $csrf ?><?= $uid_field ?>
            <input type="hidden" name="action" value="email">
            <h3><?= e(t('up_change_email')) ?></h3>
            <div class="field field-email">
                <label for="email"><?= e(t('up_email')) ?></label>
                <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
            </div>
        </form>

        <?php // Change password (action=password; requires the current one). ?>
        <form method="post" action="<?= e($self_url) ?>" class="card profile-card">
            <?= $csrf ?><?= $uid_field ?>
            <input type="hidden" name="action" value="password">
            <h3><?= e(t('up_change_password')) ?></h3>
            <?php /* A GENTLE NUDGE, not a nag: somebody who signs in with
                   * Google has no password here, and setting one is optional.
                   * It is worth saying once, because it is their way back in
                   * if they ever lose the Google account — or if the club
                   * switches Google sign-in off. */ ?>
            <?php if (!empty($no_password)): ?>
                <p class="field-note"><?= e(t('google_set_password_hint')) ?></p>
            <?php endif; ?>
            <?php // Nothing to ask for when there is no password yet, and
                  // asking would make setting a first one impossible. An ADMIN
                  // resetting somebody else's is not asked either — they cannot
                  // know it, which is the point of a reset (user.php enforces
                  // this too; hiding the field alone would not). ?>
            <?php if (empty($no_password) && $ask_current): ?>
            <div class="field field-current_password">
                <label for="current_password"><?= e(t('up_current_password')) ?></label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <?php endif; ?>
            <div class="field field-new_password">
                <label for="new_password"><?= e(t('up_new_password')) ?></label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <div class="field field-new_password2">
                <label for="new_password2"><?= e(t('up_new_password2')) ?></label>
                <input type="password" id="new_password2" name="new_password2" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
            </div>
        </form>

        <?php // Theme / language preference (cookie-based, like the guest picker).
              // Each select renders only if the admin allows switching for ACCOUNTS
              // (allow_user_template / allow_user_language); the whole card hides
              // when neither is allowed or there is nothing to choose from. ?>
        <?php // The club library, when the admin has switched it on. A link rather
              // than an inline editor: the library has three add methods and a
              // destructive sync, which would swamp this panel. ?>
        <?php if (library_enabled()): ?>
            <div class="card profile-card lib-panel-card">
                <h3><?= e(t('lib_my_title')) ?></h3>
                <p class="muted"><?= e(t('lib_panel_hint')) ?></p>
                <div class="form-actions">
                    <?php // Admins open THAT member's library; my_library.php
                          // applies the same admin-only rule to its ?user=. ?>
                    <a class="btn btn-primary" href="<?= $as_admin
                        ? 'my_library.php?user=' . (int)$user['id'] : 'my_library.php' ?>"><?= e($as_admin
                        ? t('lib_open_btn_other') : t('lib_open_btn')) ?></a>
                </div>
            </div>
        <?php endif; ?>

        <?php /* New-event notifications. Only offered when the admin has enabled
                 the feature AND the site actually sends mail — a checkbox that
                 silently does nothing is worse than no checkbox. */ ?>
        <?php if (opt_bool('notify_new_event') && opt_bool('send_emails')): ?>
        <form method="post" action="<?= e($self_url) ?>" class="card profile-card">
            <h3><?= e(t('up_notifications')) ?></h3>
            <?= csrf_field() ?><?= $uid_field ?>
            <input type="hidden" name="action" value="notify">
            <div class="field field-check field-notify_new_event">
                <label>
                    <input type="checkbox" name="notify_new_event" value="1"<?=
                        (int)($user['notify_new_event'] ?? 0) === 1 ? ' checked' : '' ?>>
                    <?= e(t('up_notify_new_event')) ?>
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
            </div>
        </form>
        <?php endif; ?>

        <?php /* THEME / LANGUAGE — your own panel only.
                 Both preferences live in the VISITOR'S OWN COOKIE (prefs.php,
                 tpl_current()), not on the user row, so on somebody else's
                 profile there is nothing here to read or write: the values
                 would be the ADMIN'S, shown under another person's name. So the
                 card is omitted rather than rendered read-only. */ ?>
        <?php /* CLOSING THE ACCOUNT — last on the page, and only on your own.
                 An admin gets the Users tab for this instead, where the delete
                 carries the last-admin guard and does not log the admin out.

                 Behind a <details>, so it is not a button sitting next to the
                 ordinary ones, and the confirmation is TYPING YOUR OWN ADDRESS
                 rather than a second click: one mis-tap on a phone should not
                 be able to do something irreversible. */ ?>
        <?php if (!$as_admin && opt_bool('allow_self_delete')): ?>
            <details class="card profile-card danger-zone">
                <summary><?= e(t('up_delete_title')) ?></summary>
                <p><?= e(t('up_delete_warning')) ?></p>
                <p class="muted"><?= e(t('up_delete_history_note')) ?></p>
                <form method="post" action="<?= e($self_url) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_self">
                    <div class="field">
                        <label for="confirm_email"><?= e(t('up_delete_confirm_label', $user['email'])) ?></label>
                        <input type="text" id="confirm_email" name="confirm_email"
                               autocomplete="off" required>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-danger"><?= e(t('up_delete_btn')) ?></button>
                    </div>
                </form>
            </details>
        <?php endif; ?>

        <?php $upTpl  = !$as_admin && tpl_switch_allowed()  && count(tpl_available())  > 1;
              $upLang = !$as_admin && lang_switch_allowed() && count(lang_available()) > 1; ?>
        <?php if ($upTpl || $upLang): ?>
        <form method="post" action="prefs.php" class="card profile-card">
            <h3><?= e(t('up_prefs')) ?></h3>
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="user.php">
            <?php if ($upTpl): ?>
                <div class="field field-pref_tpl">
                    <label for="pref_tpl"><?= e(t('pref_template')) ?></label>
                    <select id="pref_tpl" name="template">
                        <?php foreach (tpl_available() as $tn): ?>
                            <option value="<?= e($tn) ?>"<?= $tn === tpl_current() ? ' selected' : '' ?>><?= e(ucfirst($tn)) ?></option>
                        <?php endforeach; ?>
                        <?php // Last, and only when there is an override to undo.
                              // Empty value = "no preference of my own". ?>
                        <?php if (tpl_overridden()): ?>
                            <option value=""><?= e(t('pref_reset')) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php if ($upLang): ?>
                <div class="field field-pref_lang">
                    <label for="pref_lang"><?= e(t('pref_language')) ?></label>
                    <select id="pref_lang" name="lang">
                        <?php foreach (lang_available() as $lc): ?>
                            <option value="<?= e($lc) ?>"<?= $lc === ($GLOBALS['LANG_CODE'] ?? '') ? ' selected' : '' ?>><?= e(strtoupper($lc)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
