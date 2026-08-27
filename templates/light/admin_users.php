<?php
/* =============================================================================
 *  templates/light/admin_users.php — Users tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  A create-account form on top, then a table of accounts. Each row carries the
 *  ACCOUNT controls as tiny inline forms — promote/demote, block/unblock,
 *  verify/unverify (each button flips with the current state) and delete — every
 *  one posting a distinct "action" that inc/admin/users.php branches on.
 *
 *  The account's DETAILS are not edited here: the name is a link to that user's
 *  profile (user.php?user=N), which carries email, password, notifications and
 *  their library. Changing an email or a password used to mean two inline forms
 *  in every row, which made the table tall and put a bare password box on a
 *  list page. The split is "this tab manages an account, that page edits a
 *  person" — inc/admin/users.php still ACCEPTS the email/password actions, so
 *  nothing that already posts them breaks.
 *
 *  RENDER VARS:
 *    $csrf  — hidden CSRF field.
 *    $users — list of user rows (id, display_name, email, is_admin, is_blocked, ...).
 * ============================================================================= */
?>
<?php // Create an account by hand (useful when self-registration is off). ?>
<fieldset class="users-create">
    <legend><?= e(t('users_create_title')) ?></legend>
    <form method="post" action="admin.php?tab=users">
        <?= $csrf ?>
        <input type="hidden" name="action" value="create">
        <div class="field">
            <label for="nu_name"><?= e(t('users_display_name')) ?></label>
            <input type="text" id="nu_name" name="display_name" required>
        </div>
        <div class="field">
            <label for="nu_email"><?= e(t('users_email')) ?></label>
            <input type="email" id="nu_email" name="email" required>
        </div>
        <div class="field">
            <label for="nu_pass"><?= e(t('users_new_password')) ?></label>
            <input type="password" id="nu_pass" name="password" required>
        </div>
        <div class="field field-check">
            <label><input type="checkbox" name="is_admin" value="1"> <?= e(t('users_make_admin')) ?></label>
        </div>
        <button type="submit" class="btn btn-primary"><?= e(t('users_create')) ?></button>
    </form>
</fieldset>

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
            <?php /* The name opens that account's PROFILE (user.php?user=N),
                   * where the email, password, notifications and library live.
                   * They used to be two inline forms in this cell, which made
                   * every row tall and put a bare password box on a list page.
                   * The controls that remain here manage the ACCOUNT — role,
                   * access, deletion — which is a different job from editing
                   * the person's details. */ ?>
            <td data-label="<?= e(t('users_email')) ?>">
                <a href="user.php?user=<?= (int)$u['id'] ?>" class="user-link"><?= e($u['display_name']) ?></a>
            </td>
            <td data-label="<?= e(t('users_name')) ?>"><?= e($u['email']) ?></td>
            <td data-label="<?= e(t('users_role')) ?>">
                <?= (int)$u['is_admin'] === 1 ? e(t('users_admin')) : e(t('users_user')) ?>
                <?php if (!empty($activation_on) && (int)($u['is_verified'] ?? 1) !== 1): ?>
                    <?php // Says WHY they cannot sign in; without it an admin
                          // has no way to see who is waiting. ?>
                    <span class="badge badge-pending"><?= e(t('users_pending_badge')) ?></span>
                <?php endif; ?>
                <?php if ((int)$u['is_blocked'] === 1): // blocked accounts get a badge next to the role ?>
                    <span class="badge badge-blocked"><?= e(t('users_blocked_badge')) ?></span>
                <?php endif; ?>
            </td>
            <td data-label="<?= e(t('users_actions')) ?>" class="row-actions">
                <?php // Role and block. Both are withheld on your OWN row:
                      // either one would remove your own access mid-click. The
                      // controller refuses both as well — hiding a control is a
                      // courtesy, not a guard. ?>
                <?php $isSelf = $me_id > 0 && (int)$u['id'] === $me_id; ?>
                <?php if (!$isSelf): ?>
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

                    <form method="post" action="admin.php?tab=users" class="inline">
                        <?= $csrf ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <?php if ((int)$u['is_blocked'] === 1): ?>
                            <input type="hidden" name="action" value="unblock">
                            <button class="btn btn-small"><?= e(t('users_unblock')) ?></button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="block">
                            <button class="btn btn-small btn-danger"><?= e(t('users_block')) ?></button>
                        <?php endif; ?>
                    </form>

                    <?php /* Approving only means anything while accounts have to
                           * be approved — under 'auto' every account is usable
                           * the moment it exists, so the button would be a
                           * control with nothing to control. */ ?>
                    <?php if (!empty($activation_on)): ?>
                        <form method="post" action="admin.php?tab=users" class="inline">
                            <?= $csrf ?>
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <?php if ((int)($u['is_verified'] ?? 1) === 1): ?>
                                <input type="hidden" name="action" value="unverify">
                                <button class="btn btn-small btn-danger"><?= e(t('users_unverify')) ?></button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="verify">
                                <button class="btn btn-small btn-verify"><?= e(t('users_verify')) ?></button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>

                    <?php /* Deleting is final and the confirm says what survives
                           * it, because "delete account" reads as though the
                           * games and seats go too — they do not. */ ?>
                    <form method="post" action="admin.php?tab=users" class="inline"
                          onsubmit="return confirm('<?= e(t('users_delete_confirm', $u['display_name'])) ?>');">
                        <?= $csrf ?>
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="action" value="delete_user">
                        <button class="btn btn-small btn-danger"><?= e(t('users_delete')) ?></button>
                    </form>
                <?php else: ?>
                    <span class="muted users-self"><?= e(t('users_you')) ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($pages > 1): ?>
    <nav class="pager">
        <?php if ($page > 1): ?>
            <a class="btn btn-small" href="admin.php?tab=users&amp;page=<?= $page - 1 ?>"><?= e(t('pager_prev')) ?></a>
        <?php endif; ?>
        <span class="pager-pos"><?= e(t('pager_position', $page, $pages)) ?></span>
        <?php if ($page < $pages): ?>
            <a class="btn btn-small" href="admin.php?tab=users&amp;page=<?= $page + 1 ?>"><?= e(t('pager_next')) ?></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>
