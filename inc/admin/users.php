<?php
/* =============================================================================
 *  inc/admin/users.php — Users tab controller.
 * -----------------------------------------------------------------------------
 *  Lists registered users and supports these actions: create a new account,
 *  promote/demote admin, change email, reset password, block/unblock. Each POST
 *  carries an 'action' (+ 'user_id' for the per-user ones).
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
        } elseif ($name === '') {
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
                // Don't let the last USABLE admin be demoted into a lockout
                // (blocked admins can't reach the panel, so they don't count).
                $admins = (int)db_val('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_blocked = 0');
                if ((int)$target['is_admin'] === 1 && (int)$target['is_blocked'] === 0 && $admins <= 1) {
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
        }
    }
    }   // end: actions targeting an existing user
}

// User list, alphabetised by display name (case-insensitive).
$users = db_all('SELECT id, email, display_name, is_admin, is_blocked, created_at FROM users ORDER BY display_name COLLATE NOCASE');

$tab_body = tpl_capture('admin_users', [
    'csrf'  => csrf_field(),
    'users' => $users,
]);
