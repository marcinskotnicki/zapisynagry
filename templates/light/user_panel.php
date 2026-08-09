<?php
/* =============================================================================
 *  templates/light/user_panel.php — the user panel. Presentation only.
 * -----------------------------------------------------------------------------
 *  Two parts: a stats grid (games brought / played, each this-event vs all-time)
 *  and three independent profile forms (display name, email, password). Each
 *  form carries a different hidden "action" so user.php knows which to apply.
 *
 *  RENDER VARS:
 *    $user                         — the logged-in user row (prefills the forms).
 *    $flash                        — one-shot confirmation message, or null.
 *    $brought_event / $brought_all — games-brought counts.
 *    $played_event  / $played_all  — games-played counts.
 *    $csrf                         — hidden CSRF field.
 * ============================================================================= */
?>
<div class="userpanel">
    <h1><?= e(t('up_title')) ?></h1>

    <?php if (!empty($flash)): // result of the last profile change ?>
        <p class="msg msg-ok"><?= e($flash) ?></p>
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
        <form method="post" action="user.php" class="card profile-card">
            <?= $csrf ?>
            <input type="hidden" name="action" value="name">
            <h3><?= e(t('up_change_name')) ?></h3>
            <div class="field field-display_name">
                <label for="display_name"><?= e(t('up_name')) ?></label>
                <input type="text" id="display_name" name="display_name" value="<?= e($user['display_name']) ?>" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
            </div>
        </form>

        <?php // Change email (action=email; uniqueness checked server-side). ?>
        <form method="post" action="user.php" class="card profile-card">
            <?= $csrf ?>
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
        <form method="post" action="user.php" class="card profile-card">
            <?= $csrf ?>
            <input type="hidden" name="action" value="password">
            <h3><?= e(t('up_change_password')) ?></h3>
            <div class="field field-current_password">
                <label for="current_password"><?= e(t('up_current_password')) ?></label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
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
                    <a class="btn btn-primary" href="my_library.php"><?= e(t('lib_open_btn')) ?></a>
                </div>
            </div>
        <?php endif; ?>

        <?php /* New-event notifications. Only offered when the admin has enabled
                 the feature AND the site actually sends mail — a checkbox that
                 silently does nothing is worse than no checkbox. */ ?>
        <?php if (opt_bool('notify_new_event') && opt_bool('send_emails')): ?>
        <form method="post" action="user.php" class="card profile-card">
            <h3><?= e(t('up_notifications')) ?></h3>
            <?= csrf_field() ?>
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

        <?php $upTpl  = tpl_switch_allowed()  && count(tpl_available())  > 1;
              $upLang = lang_switch_allowed() && count(lang_available()) > 1; ?>
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
