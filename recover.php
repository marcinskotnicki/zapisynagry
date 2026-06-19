<?php
/* =============================================================================
 *  recover.php — password recovery.
 * -----------------------------------------------------------------------------
 *  Step 1 (request): enter your email; if it matches an account we email a
 *  one-time link. We ALWAYS show the same "sent" message so the form can't be
 *  used to probe which emails have accounts.
 *  Step 2 (reset): the link carries a token; enter a new password.
 *
 *  This is transactional mail (the user asked for it), so send_mail() is called
 *  directly — it isn't gated by the send_emails notification toggle.
 *
 *  ROUTING (first match wins): POST action=request -> send link; POST
 *  action=reset -> set new password; GET ?token= -> show reset form; else the
 *  request form.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/mail.php';

// Already logged in? No need to recover.
if (is_logged_in()) redirect('index.php');

/**
 * Build an absolute URL to recover.php (for the email link). Derived from the
 * current request so it works regardless of the install's domain/subfolder.
 * @return string
 */
function recover_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return $scheme . '://' . $host . $dir . '/recover.php';
}

$action = $_POST['action'] ?? '';
$token  = $_GET['token'] ?? $_POST['token'] ?? '';

/* ---- Step 1: request a reset link ---------------------------------------- */
if ($action === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $user  = $email !== '' ? db_one('SELECT * FROM users WHERE email = ?', [$email]) : null;
    if ($user) {
        // Only generate + email a token if the account exists — but we DON'T
        // reveal that to the visitor (same "sent" screen either way).
        $tok     = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + 3600);   // 1 hour (UTC, matches DB)
        db_run('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)',
               [$user['id'], $tok, $expires]);
        $link = recover_base_url() . '?token=' . $tok;
        send_mail($user['email'], t('recover_email_subject'), t('recover_email_body', $link));
    }
    tpl_render('header', ['page_title' => t('recover_title')]);
    tpl_render('recover', ['step' => 'sent']);   // identical message whether or not it existed
    tpl_render('footer');
    exit;
}

/* ---- Step 2: handle a submitted new password ----------------------------- */
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Look up a non-expired token. NULL row -> invalid/expired link.
    $row = $token !== '' ? db_one(
        'SELECT * FROM password_resets WHERE token = ? AND expires_at >= ?',
        [$token, gmdate('Y-m-d H:i:s')]
    ) : null;

    $new  = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');

    if (!$row) {
        tpl_render('header', ['page_title' => t('recover_title')]);
        tpl_render('recover', ['step' => 'invalid']);
        tpl_render('footer');
        exit;
    }
    if (strlen($new) < 6 || $new !== $new2) {
        // Re-show the reset form with the relevant error, keeping the token.
        $err = strlen($new) < 6 ? t('up_password_short') : t('up_password_mismatch');
        tpl_render('header', ['page_title' => t('recover_title')]);
        tpl_render('recover', ['step' => 'reset', 'token' => $token, 'error' => $err, 'csrf' => csrf_field()]);
        tpl_render('footer');
        exit;
    }
    // Apply the new password and consume ALL of this user's tokens.
    db_run('UPDATE users SET password_hash = ? WHERE id = ?',
           [password_hash($new, PASSWORD_DEFAULT), $row['user_id']]);
    db_run('DELETE FROM password_resets WHERE user_id = ?', [$row['user_id']]);   // consume all
    tpl_render('header', ['page_title' => t('recover_title')]);
    tpl_render('recover', ['step' => 'done']);
    tpl_render('footer');
    exit;
}

/* ---- A reset link was opened (GET ?token=) ------------------------------- */
if ($token !== '') {
    // Validate the token before showing the reset form (else show "invalid").
    $row = db_one('SELECT id FROM password_resets WHERE token = ? AND expires_at >= ?',
                  [$token, gmdate('Y-m-d H:i:s')]);
    tpl_render('header', ['page_title' => t('recover_title')]);
    if ($row) {
        tpl_render('recover', ['step' => 'reset', 'token' => $token, 'error' => null, 'csrf' => csrf_field()]);
    } else {
        tpl_render('recover', ['step' => 'invalid']);
    }
    tpl_render('footer');
    exit;
}

/* ---- Default: the request form ------------------------------------------- */
tpl_render('header', ['page_title' => t('recover_title')]);
tpl_render('recover', ['step' => 'request', 'csrf' => csrf_field()]);
tpl_render('footer');
