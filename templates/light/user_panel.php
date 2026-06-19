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
            <label for="display_name"><?= e(t('up_name')) ?></label>
            <input type="text" id="display_name" name="display_name" value="<?= e($user['display_name']) ?>" required>
            <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
        </form>

        <?php // Change email (action=email; uniqueness checked server-side). ?>
        <form method="post" action="user.php" class="card profile-card">
            <?= $csrf ?>
            <input type="hidden" name="action" value="email">
            <h3><?= e(t('up_change_email')) ?></h3>
            <label for="email"><?= e(t('up_email')) ?></label>
            <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" required>
            <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
        </form>

        <?php // Change password (action=password; requires the current one). ?>
        <form method="post" action="user.php" class="card profile-card">
            <?= $csrf ?>
            <input type="hidden" name="action" value="password">
            <h3><?= e(t('up_change_password')) ?></h3>
            <label for="current_password"><?= e(t('up_current_password')) ?></label>
            <input type="password" id="current_password" name="current_password" required>
            <label for="new_password"><?= e(t('up_new_password')) ?></label>
            <input type="password" id="new_password" name="new_password" required>
            <label for="new_password2"><?= e(t('up_new_password2')) ?></label>
            <input type="password" id="new_password2" name="new_password2" required>
            <button type="submit" class="btn btn-primary"><?= e(t('up_save')) ?></button>
        </form>
    </div>
</div>
