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
 *
 *  ADMIN MODE (?user=N): an admin may open ANOTHER account's panel — the Users
 *  tab links each name here. Same screen, same actions, same code path, with
 *  three differences, all of them consequences of it not being your own account:
 *    - changing the password does NOT ask for the current one. The check exists
 *      to protect a session left open; an admin resetting somebody else's has
 *      no current password to give and is already authenticated as an admin.
 *    - the theme/language card is not rendered. Both preferences live in the
 *      VISITOR'S OWN COOKIE (see prefs.php / tpl_current()), not on the user
 *      row, so from here they can be neither read nor written — the values
 *      would be the admin's own, mislabelled as somebody else's.
 *    - the library button targets that member's library (my_library.php?user=N).
 *  Everything else (block/promote/verify/delete) stays on the Users tab: this
 *  page edits a PROFILE, the tab manages an ACCOUNT.
 *
 *  ONE CODE PATH, deliberately: $target is "whose profile this is" and $isSelf
 *  says whether that is you. A separate admin_user.php would be this file with
 *  the guards removed, which is exactly the copy that drifts.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
// user_has_password(): an account that signs in with Google may have none.
require __DIR__ . '/inc/google.php';
require_login();                       // panel is for logged-in users only

$me = current_user();

/* WHOSE panel this is. ?user=N is honoured for ADMINS ONLY; for anyone else the
 * parameter is ignored entirely rather than refused, so a member who edits the
 * URL simply lands on their own panel instead of learning that the id exists.
 * An unknown id falls back the same way. */
$wantId = (int)($_GET['user'] ?? $_POST['user'] ?? 0);
$target = null;
if ($wantId > 0 && is_admin() && $wantId !== (int)$me['id']) {
    $target = db_one('SELECT * FROM users WHERE id = ?', [$wantId]);
}
if (!$target) $target = $me;

$isSelf = (int)$target['id'] === (int)$me['id'];
// Where PRG returns to, and what the forms post back to: the same panel.
$selfUrl = $isSelf ? 'user.php' : 'user.php?user=' . (int)$target['id'];

$u     = $target;                      // every action below edits THIS account
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
            // Same reasoning as the password reset below: an admin editing
            // somebody else's account leaves a trail, your own edits do not.
            if (!$isSelf) log_action('user_email', $newEmail);
            flash_set(t('up_email_updated'));
        }

    } elseif ($action === 'name') {
        $newName = trim($_POST['display_name'] ?? '');
        if ($newName !== '') {                      // silently ignore a blank name
            db_run('UPDATE users SET display_name = ? WHERE id = ?', [$newName, $u['id']]);
            if (!$isSelf) log_action('user_name', $u['email'] . ' -> ' . $newName);
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
         * which is the same thing the check is standing in for.
         *
         * AND EXCEPT when an admin is resetting SOMEBODY ELSE'S password. The
         * check guards your own account against whoever walks up to your open
         * session; an admin resetting a member's password cannot possibly know
         * the current one, which is the whole point of a reset. $isSelf, not
         * is_admin(): an admin editing their OWN profile is still protected. */
        if (!$isSelf) {
            $needCurrent = false;
        } else {
            $needCurrent = user_has_password($u);
        }
        if ($needCurrent && !password_verify($cur, $u['password_hash'])) {
            flash_set(t('up_password_wrong'), 'error');
        } elseif (strlen($new) < 6) {
            flash_set(t('up_password_short'), 'error');
        } elseif ($new !== $new2) {
            flash_set(t('up_password_mismatch'), 'error');
        } else {
            db_run('UPDATE users SET password_hash = ? WHERE id = ?',
                   [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            /* Logged when it was not your own account: an admin changing a
             * member's password is an administrative act, and the Users tab's
             * other actions all leave a trail. */
            if (!$isSelf) log_action('user_password', $u['email']);
            flash_set(t('up_password_updated'));
        }
    }
    redirect($selfUrl);   // PRG; message comes back via the session flash
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

tpl_render('header', ['page_title' => $isSelf ? t('up_title') : t('up_title_admin', $u['display_name'])]);
tpl_render('user_panel', [
    'user'          => $u,
    // No password here at all — signs in with Google. The form asks for no
    // current password, and offers a quiet word about setting one as a backup.
    'no_password'   => !user_has_password($u),
    /* ADMIN MODE flags, all three derived from the one $isSelf above so they
     * cannot disagree with each other:
     *   as_admin   — draw the "you are editing X" heading and the way back.
     *   ask_current— whether the password form asks for the current password.
     *   self_url   — where every form posts (carries ?user=N when applicable). */
    'as_admin'      => !$isSelf,
    'ask_current'   => $isSelf,
    'self_url'      => $selfUrl,
    'flash'         => flash_get(),
    // Read after flash_get(), which clears the text — see flash_kind().
    'flash_kind'    => flash_kind(),
    'brought_event' => $broughtEvent, 'brought_all' => $broughtAll,
    'played_event'  => $playedEvent,  'played_all'  => $playedAll,
    'csrf'          => csrf_field(),
]);
tpl_render('footer');
