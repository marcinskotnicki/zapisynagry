<?php
/* =============================================================================
 *  inc/admin/users.php — Users tab controller.
 * -----------------------------------------------------------------------------
 *  Lists registered users and supports these actions: create a new account,
 *  promote/demote admin, change email, reset password, block/unblock,
 *  verify/unverify and delete. Each POST carries an 'action' (+ 'user_id' for
 *  the per-user ones).
 *
 *  The 'email' and 'password' actions no longer have a form on this tab — those
 *  edits moved to the user's own profile page (user.php?user=N, reached by
 *  clicking a name). They are KEPT here, working and guarded exactly as before:
 *  they cost nothing, and removing a working admin action because its button
 *  moved is how a URL somebody bookmarked starts failing silently.
 *
 *  SAFETY: demote refuses to remove the LAST admin, and block refuses both
 *  self-blocking and blocking the last usable admin, so the site can never be
 *  locked out of its own admin panel.
 *
 *  Runs in admin.php's scope: may set $flash, must set $tab_body; csrf already
 *  checked by admin.php.
 * ============================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        // Create an account by hand (e.g. for someone who can't self-register,
        // or when registration is closed). Same validation rules as register.php.
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['display_name'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        $admin = isset($_POST['is_admin']) ? 1 : 0;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = t('users_email_invalid');
        } elseif (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
            $flash = t('users_email_taken');     // email is the login: must be unique
        } elseif ($name === '' || !text_has_content($name) || text_too_long($name, TEXT_NAME_MAX)) {
            $flash = t('users_name_required');
        } elseif (strlen($pass) < 6) {
            $flash = t('users_password_short');
        } else {
            db_run('INSERT INTO users (email, password_hash, display_name, is_admin) VALUES (?,?,?,?)',
                   [$email, password_hash($pass, PASSWORD_DEFAULT), $name, $admin]);
            log_action('user_create', $email);
            $flash = t('users_created');
        }

    } else {
    // Every other action targets an existing user.
    $userId = (int)($_POST['user_id'] ?? 0);
    $target = $userId ? db_one('SELECT * FROM users WHERE id = ?', [$userId]) : null;

    if (!$target) {
        $flash = t('users_not_found');           // bad/stale user id
    } else {
        switch ($action) {

            case 'promote':
                db_run('UPDATE users SET is_admin = 1 WHERE id = ?', [$userId]);
                log_action('user_promote', $target['email']);
                $flash = t('users_updated');
                break;

            case 'demote':
                // Two guards, matching 'block' above. Demoting YOURSELF is
                // refused outright: with a second admin present the last-admin
                // check below would allow it, and you would drop out of the
                // panel mid-click and need someone else to let you back in.
                // Removing another admin's rights is still fine.
                $me = current_user();
                $admins = (int)db_val('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_blocked = 0');
                if ($me && (int)$me['id'] === $userId) {
                    $flash = t('users_cannot_demote_self');
                } elseif ((int)$target['is_admin'] === 1 && (int)$target['is_blocked'] === 0 && $admins <= 1) {
                    // Still needed: the last usable admin may be someone else.
                    $flash = t('users_last_admin');
                } else {
                    db_run('UPDATE users SET is_admin = 0 WHERE id = ?', [$userId]);
                    log_action('user_demote', $target['email']);
                    $flash = t('users_updated');
                }
                break;

            case 'email':
                $newEmail = trim($_POST['email'] ?? '');
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $flash = t('users_email_invalid');
                } elseif (db_one('SELECT id FROM users WHERE email = ? AND id <> ?', [$newEmail, $userId])) {
                    // Email must stay unique across accounts (it's the login).
                    $flash = t('users_email_taken');
                } else {
                    db_run('UPDATE users SET email = ? WHERE id = ?', [$newEmail, $userId]);
                    log_action('user_email', $newEmail);
                    $flash = t('users_updated');
                }
                break;

            case 'password':
                // Admin reset: no need for the user's current password.
                $newPass = (string)($_POST['password'] ?? '');
                if (strlen($newPass) < 6) {
                    $flash = t('users_password_short');
                } else {
                    db_run('UPDATE users SET password_hash = ? WHERE id = ?',
                           [password_hash($newPass, PASSWORD_DEFAULT), $userId]);
                    log_action('user_password', $target['email']);
                    $flash = t('users_updated');
                }
                break;

            case 'block':
                // Two lockout guards: don't block yourself (you'd vanish from the
                // panel mid-click), and don't block the last USABLE admin.
                $me = current_user();
                $usableAdmins = (int)db_val('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_blocked = 0');
                if ($me && (int)$me['id'] === $userId) {
                    $flash = t('users_cannot_block_self');
                } elseif ((int)$target['is_admin'] === 1 && (int)$target['is_blocked'] === 0 && $usableAdmins <= 1) {
                    $flash = t('users_last_admin');
                } else {
                    db_run('UPDATE users SET is_blocked = 1 WHERE id = ?', [$userId]);
                    // Kill their remember-me devices so the block can't be ridden
                    // out on a persistent cookie (sessions die via current_user()).
                    db_run('DELETE FROM auth_tokens WHERE user_id = ?', [$userId]);
                    log_action('user_block', $target['email']);
                    $flash = t('users_updated');
                }
                break;

            case 'unblock':
                db_run('UPDATE users SET is_blocked = 0 WHERE id = ?', [$userId]);
                log_action('user_unblock', $target['email']);
                $flash = t('users_updated');
                break;

            case 'verify':
                // Approving also consumes any outstanding link, so a token left
                // in an old email cannot be used afterwards.
                db_run('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?', [$userId]);
                log_action('user_verify', $target['email']);
                $flash = t('users_updated');
                break;

            case 'unverify':
                /* The same two lockout guards as blocking, for the same reason:
                 * an unverified account cannot sign in, so this is a block by
                 * another name and can strand you or the club just as easily. */
                $me = current_user();
                $usableAdmins = (int)db_val(
                    'SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_blocked = 0 AND is_verified = 1');
                if ($me && (int)$me['id'] === $userId) {
                    $flash = t('users_cannot_block_self');
                } elseif ((int)$target['is_admin'] === 1 && (int)$target['is_verified'] === 1 && $usableAdmins <= 1) {
                    $flash = t('users_last_admin');
                } else {
                    db_run('UPDATE users SET is_verified = 0 WHERE id = ?', [$userId]);
                    // As with blocking: a persistent cookie must not outlive it.
                    db_run('DELETE FROM auth_tokens WHERE user_id = ?', [$userId]);
                    log_action('user_unverify', $target['email']);
                    $flash = t('users_updated');
                }
                break;

            case 'delete_user':
                /* THE ACCOUNT GOES, THE HISTORY STAYS. Games somebody proposed
                 * and seats they took remain on the board under the same name,
                 * as though they had never had an account — deleting a member
                 * must not silently rewrite an event that already happened.
                 *
                 * Same guards again: this is the most final version of losing
                 * access, so it must not be able to remove you or the last
                 * admin. */
                $me = current_user();
                $usableAdmins = (int)db_val(
                    'SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_blocked = 0 AND is_verified = 1');
                if ($me && (int)$me['id'] === $userId) {
                    $flash = t('users_cannot_delete_self');
                } elseif ((int)$target['is_admin'] === 1 && $usableAdmins <= 1) {
                    $flash = t('users_last_admin');
                } else {
                    user_delete_keeping_history($userId);
                    log_action('user_delete', $target['email']);
                    $flash = t('users_deleted');
                }
                break;
        }
    }
    }   // end: actions targeting an existing user
}

// User list, alphabetised by display name (case-insensitive), paginated —
// a club that has been running for years accumulates accounts, and this was
// fetching all of them on every view of the tab.
$perPage = max(1, min(500, opt_int('admin_per_page')));
$total   = (int)db_val('SELECT COUNT(*) FROM users');
$pages   = max(1, (int)ceil($total / $perPage));
$page    = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$users   = db_all(
    // is_verified too, or the template's ?? 1 fallback reads every pending
    // account as approved and shows the wrong button on every row.
    'SELECT id, email, display_name, is_admin, is_blocked, is_verified, created_at
       FROM users ORDER BY display_name COLLATE NOCASE LIMIT ? OFFSET ?',
    [$perPage, ($page - 1) * $perPage]);

$me = current_user();
$tab_body = tpl_capture('admin_users', [
    'csrf'  => csrf_field(),
    // So the template can withhold the self-targeting controls.
    'me_id' => $me ? (int)$me['id'] : 0,
    'users' => $users,
    // Approving is only meaningful while accounts have to be approved.
    'activation_on' => account_activation_mode() !== 'auto',
    'page'  => $page,
    'pages' => $pages,
]);
