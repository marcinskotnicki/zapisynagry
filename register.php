<?php
/* =============================================================================
 *  register.php — self-service account creation.
 * -----------------------------------------------------------------------------
 *  Available while the 'registration_mode' option is 'registration' (the admin
 *  panel's "Allow registration"); in guest-only mode this page bounces home,
 *  same as the hidden topbar links. Already-logged-in visitors bounce too.
 *
 *  Validation: display name required; email must look real (email_valid) and be
 *  UNUSED (case-insensitive — logins are by email); password min length matches
 *  the user panel's rule; captcha applies like on every other public form.
 *
 *  On success the account is created (bcrypt via password_hash) and the person
 *  is logged in immediately — including a persistent-login token, so the very
 *  first session already behaves like any other login.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/captcha.php';

// Feature gate + no re-registering while logged in.
if (opt('registration_mode') === 'guest_only') redirect('index.php');
if (is_logged_in()) redirect('index.php');

$form = [
    'name'  => trim($_POST['name']  ?? ''),
    'email' => trim($_POST['email'] ?? ''),
];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pass1 = (string)($_POST['password']  ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if ($form['name'] === '') {
        $error = t('error_signup_name');
    } elseif (!text_has_content($form['name']) || text_too_long($form['name'], TEXT_NAME_MAX)) {
        $error = t('error_name_meaningless');
    } elseif ($form['email'] === '') {
        $error = t('error_email_required');
    } elseif (!email_valid($form['email'])) {
        $error = t('error_email_invalid');
    } elseif (db_val('SELECT 1 FROM users WHERE email = ? COLLATE NOCASE', [$form['email']])) {
        $error = t('reg_email_taken');
    } elseif (strlen($pass1) < 6) {
        $error = t('up_password_short');           // same 6-char rule as the user panel
    } elseif ($pass1 !== $pass2) {
        $error = t('up_password_mismatch');
    } elseif (!captcha_verify()) {
        $error = t('error_captcha');               // no-op when captcha is off
    } else {
        db_run('INSERT INTO users (email, password_hash, display_name, is_admin) VALUES (?,?,?,0)',
               [$form['email'], password_hash($pass1, PASSWORD_DEFAULT), $form['name']]);
        $newId = (int)db()->lastInsertId();
        log_action('register', $form['name'] . ' <' . $form['email'] . '>');
        // Log the fresh account straight in (same steps as auth_login).
        session_regenerate_id(true);
        $_SESSION['user_id'] = $newId;
        auth_remember_issue($newId);               // persistent login, option-gated
        flash_set(t('reg_done'));
        redirect('index.php');
    }
}

tpl_render('header', ['page_title' => t('reg_title')]);
tpl_render('register_form', [
    'form'    => $form,
    'error'   => $error,
    'captcha' => captcha_html(),
    'csrf'    => csrf_field(),
]);
tpl_render('footer');
