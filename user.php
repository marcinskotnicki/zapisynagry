<?php
/* =============================================================================
 *  user.php — the logged-in user's panel.
 * -----------------------------------------------------------------------------
 *  Shows brought/played stats (this event vs all-time) and lets the user change
 *  their email, display name, and password. All-time stats join on the account
 *  id only (as agreed); guest activity isn't attributed to anyone.
 *
 *  The three edit forms each carry a different 'action' and use PRG: on POST we
 *  apply the change, stash a flash message, and redirect back so a refresh
 *  doesn't resubmit.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
// user_has_password(): an account that signs in with Google may have none.
require __DIR__ . '/inc/google.php';
require_login();                       // panel is for logged-in users only

$u     = current_user();
$event = current_event();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'email') {
        $newEmail = trim($_POST['email'] ?? '');
        if (!email_valid($newEmail)) {         // shared X@Y.Z check (inc/helpers.php)
            flash_set(t('up_email_invalid'), 'error');
        } elseif (db_one('SELECT id FROM users WHERE email = ? AND id <> ?', [$newEmail, $u['id']])) {
            flash_set(t('up_email_taken'), 'error');   // email is the login -> must stay unique
        } else {
            db_run('UPDATE users SET email = ? WHERE id = ?', [$newEmail, $u['id']]);
            flash_set(t('up_email_updated'));
        }

    } elseif ($action === 'name') {
        $newName = trim($_POST['display_name'] ?? '');
        if ($newName !== '') {                      // silently ignore a blank name
            db_run('UPDATE users SET display_name = ? WHERE id = ?', [$newName, $u['id']]);
            flash_set(t('up_name_updated'));
        }

    } elseif ($action === 'notify') {
        /* An unticked checkbox posts nothing, so absence IS the "off" answer
         * here — unlike the admin Options form, where a missing key means
         * "not submitted". This form carries exactly one field, so there is no
         * ambiguity to guard against.
         *
         * Written even when the feature is switched off site-wide, so an admin
         * turning it back on restores everyone's previous choice rather than
         * silently resetting it. */
        db_run('UPDATE users SET notify_new_event = ? WHERE id = ?',
               [empty($_POST['notify_new_event']) ? 0 : 1, $u['id']]);
        flash_set(t('up_notify_saved'));

    } elseif ($action === 'password') {
        $cur  = (string)($_POST['current_password'] ?? '');
        $new  = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');
        /* Require the current password — defence if a session is left open.
         *
         * EXCEPT when there is no password to require. An account created
         * through Google has none, and password_verify() against '' is always
         * false, so this check would make setting a first password impossible
         * — and switching Google login off would then lock that person out
         * with no way back. They are already authenticated by the session,
         * which is the same thing the check is standing in for. */
        if (user_has_password($u) && !password_verify($cur, $u['password_hash'])) {
            flash_set(t('up_password_wrong'), 'error');
        } elseif (strlen($new) < 6) {
            flash_set(t('up_password_short'), 'error');
        } elseif ($new !== $new2) {
            flash_set(t('up_password_mismatch'), 'error');
        } else {
            db_run('UPDATE users SET password_hash = ? WHERE id = ?',
                   [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            flash_set(t('up_password_updated'));
        }
    }
    redirect('user.php');   // PRG; message comes back via the session flash
}

/* ---- Stats --------------------------------------------------------------- *
 * "Brought"  = games where this account is the bringer (is_archived=0).
 * "Played"   = distinct games this account is a CONFIRMED player in (reserves
 *              don't count as played). "This event" adds an event_id filter;
 *              "all time" spans every event via the account id.
 * --------------------------------------------------------------------------- */
$uid     = (int)$u['id'];
$eventId = $event ? (int)$event['id'] : 0;

$broughtAll   = (int)db_val('SELECT COUNT(*) FROM games WHERE brings_user_id = ? AND is_archived = 0', [$uid]);
$broughtEvent = $eventId ? (int)db_val('SELECT COUNT(*) FROM games WHERE brings_user_id = ? AND is_archived = 0 AND event_id = ?', [$uid, $eventId]) : 0;

$playedAll   = (int)db_val(
    'SELECT COUNT(DISTINCT p.game_id) FROM players p
     JOIN games g ON g.id = p.game_id
     WHERE p.user_id = ? AND p.is_reserve = 0 AND g.is_archived = 0', [$uid]);
$playedEvent = $eventId ? (int)db_val(
    'SELECT COUNT(DISTINCT p.game_id) FROM players p
     JOIN games g ON g.id = p.game_id
     WHERE p.user_id = ? AND p.is_reserve = 0 AND g.is_archived = 0 AND g.event_id = ?', [$uid, $eventId]) : 0;

tpl_render('header', ['page_title' => t('up_title')]);
tpl_render('user_panel', [
    'user'          => $u,
    // No password here at all — signs in with Google. The form asks for no
    // current password, and offers a quiet word about setting one as a backup.
    'no_password'   => !user_has_password($u),
    'flash'         => flash_get(),
    // Read after flash_get(), which clears the text — see flash_kind().
    'flash_kind'    => flash_kind(),
    'brought_event' => $broughtEvent, 'brought_all' => $broughtAll,
    'played_event'  => $playedEvent,  'played_all'  => $playedAll,
    'csrf'          => csrf_field(),
]);
tpl_render('footer');
