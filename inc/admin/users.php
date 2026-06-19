<?php
/* =============================================================================
 *  inc/admin/users.php — Users tab controller.
 * -----------------------------------------------------------------------------
 *  Lists registered users and supports four actions: promote/demote admin,
 *  change email, reset password. Each POST carries an 'action' + 'user_id'.
 *
 *  SAFETY: demote refuses to remove the LAST admin, so the site can never be
 *  locked out of its own admin panel.
 *
 *  Runs in admin.php's scope: may set $flash, must set $tab_body; csrf already
 *  checked by admin.php.
 * ============================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
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
                // Don't let the last admin be demoted into a lockout.
                $admins = (int)db_val('SELECT COUNT(*) FROM users WHERE is_admin = 1');
                if ((int)$target['is_admin'] === 1 && $admins <= 1) {
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
        }
    }
}

// User list, alphabetised by display name (case-insensitive).
$users = db_all('SELECT id, email, display_name, is_admin, created_at FROM users ORDER BY display_name COLLATE NOCASE');

$tab_body = tpl_capture('admin_users', [
    'csrf'  => csrf_field(),
    'users' => $users,
]);
