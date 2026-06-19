<?php
/* =============================================================================
 *  templates/light/admin_users.php — Users tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  A table of accounts, each row carrying three tiny inline forms: promote/demote
 *  (the button flips based on the current role), change email, and reset
 *  password. Each posts a distinct "action" that inc/admin/users.php branches on.
 *
 *  RENDER VARS:
 *    $csrf  — hidden CSRF field.
 *    $users — list of user rows (id, display_name, email, is_admin, ...).
 * ============================================================================= */
?>
<?php if (empty($users)): ?>
    <p class="muted"><?= e(t('users_none')) ?></p>
<?php else: ?>
<table class="grid">
    <thead>
        <tr>
            <th><?= e(t('users_name')) ?></th>
            <th><?= e(t('users_email')) ?></th>
            <th><?= e(t('users_role')) ?></th>
            <th><?= e(t('users_actions')) ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><?= e($u['display_name']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= (int)$u['is_admin'] === 1 ? e(t('users_admin')) : e(t('users_user')) ?></td>
            <td class="row-actions">
                <?php // Promote / demote (button + action flip on current role; controller blocks last-admin demote). ?>
                <form method="post" action="admin.php?tab=users" class="inline">
                    <?= $csrf ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <?php if ((int)$u['is_admin'] === 1): ?>
                        <input type="hidden" name="action" value="demote">
                        <button class="btn btn-small"><?= e(t('users_demote')) ?></button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="promote">
                        <button class="btn btn-small"><?= e(t('users_promote')) ?></button>
                    <?php endif; ?>
                </form>

                <?php // Change email (uniqueness enforced server-side). ?>
                <form method="post" action="admin.php?tab=users" class="inline">
                    <?= $csrf ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="action" value="email">
                    <input type="email" name="email" placeholder="<?= e(t('users_change_email')) ?>" required>
                    <button class="btn btn-small"><?= e(t('save')) ?></button>
                </form>

                <?php // Reset password (admin reset; no current-password needed). ?>
                <form method="post" action="admin.php?tab=users" class="inline">
                    <?= $csrf ?>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="action" value="password">
                    <input type="password" name="password" placeholder="<?= e(t('users_new_password')) ?>" required>
                    <button class="btn btn-small"><?= e(t('users_reset_password')) ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
