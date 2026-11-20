<?php
/* =============================================================================
 *  reopen_poll.php — turn a game back into the poll it came from, by hand.
 * -----------------------------------------------------------------------------
 *  The "reopen the poll" button on a game card (see poll_reopen_check() in
 *  inc/polls.php for when it is offered; the club switches it on with the
 *  allow_poll_reopen option).
 *
 *  Who: the same people, and the same proof, as the game's edit button — the
 *  game's owner is the poll's author. Passing the check for one unlocks the
 *  other (they share $_SESSION['edit_ok']).
 *
 *  How: the poll's settings form, filled in from the poll as it was plus what
 *  the game says now, BEFORE anything changes — the deadline especially. A
 *  deadline that has already passed arrives already moved (see
 *  poll_reopen_deadline_hours()), with a note saying so. Submitting restores
 *  the poll with those settings (poll_restore_from_game()), tells its voters,
 *  and lands on it.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/polls.php';
require_once __DIR__ . '/inc/verify.php';
require_once __DIR__ . '/inc/captcha.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/notify.php';

$gameId = (int)($_GET['game'] ?? $_POST['game'] ?? 0);
$game   = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
if (!$game) { redirect('index.php'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$game['event_id']]);
$day   = db_one('SELECT * FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);
$back = front_url($activeDay, (int)($day['event_id'] ?? 0), '#game-') . $gameId;

// Live event, a game that may still go back, and the edit button's rule.
$reopen = (!$event || (int)$event['is_archived'] === 1) ? null : poll_reopen_check($game);
if (!$reopen || !verify_can_show_buttons($game['added_by_user_id'], $game['brings_email'] ?? null)) {
    redirect($back);
}
$decision = verify_decision($game['added_by_user_id'], $game['brings_email']);
if ($decision === 'deny') { redirect($back); }

/* ---- 1. The same check as editing the game ------------------------------- */
$unlocked = ($decision === 'allow') || !empty($_SESSION['edit_ok'][$gameId]);
$error = null;
if (!$unlocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    csrf_check();
    antibot_check('form');
    if (($_POST['choice'] ?? '') === 'send_code') {          // the only step that sends mail
        if (captcha_verify('verify')) {
            verify_send_code('game', $gameId, $game['brings_email']);
            verify_mark_requested('game', $gameId);
        } else {
            $error = t('error_captcha');
        }
    } elseif (verify_passes($decision, 'game', $gameId, $game['brings_email'], $_POST)) {
        $_SESSION['edit_ok'][$gameId] = true;
        $unlocked = true;
    } else {
        $error = t('verify_failed');
    }
}
if (!$unlocked) {
    tpl_render('header', ['page_title' => t('poll_reopen_title')]);
    tpl_render('verify_challenge', [
        'decision'  => $decision,
        'title'     => t('poll_reopen_title'),
        'error'     => $error,
        'code_sent' => verify_code_requested('game', $gameId),
        'captcha'   => captcha_html('verify'),
        'action'    => 'reopen_poll.php?game=' . $gameId,
        'csrf'      => csrf_field(),
    ]);
    tpl_render('footer');
    exit;
}

/* ---- 2. The form, filled in ---------------------------------------------- */
$snap    = $reopen['snap'];
$pollRow = poll_row_from_game($snap['poll'], $game);
$now     = time();
[$hoursNow, $moved] = poll_reopen_deadline_hours($pollRow, $day, $reopen['start_ts'], $now);
// What the form shows, and what goes back if it is submitted unchanged.
$form = $pollRow;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen') {
    csrf_check();
    antibot_check('form');
    /* The same fields, checks and "absent is not unticked" rules as saving
     * the poll's edit form (edit_poll.php), gathered into one set of values. */
    $start  = trim($_POST['start_time'] ?? '');
    $pName  = trim((string)($_POST['name'] ?? ($pollRow['proposer_name'] ?? '')));
    $pEmail = trim((string)($_POST['email'] ?? ($pollRow['proposer_email'] ?? '')));
    $reqEmail = email_require_mode() === 2 ? (isset($_POST['require_email']) ? 1 : 0) : (int)($pollRow['require_email'] ?? 0);
    $notifyVotes = notify_enabled() ? (isset($_POST['notify_votes']) ? 1 : 0) : (int)($pollRow['notify_votes'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    $hours   = max(0, (int)($_POST['deadline_hours'] ?? $hoursNow));

    $form = array_merge($pollRow, [
        'proposer_name' => $pName, 'proposer_email' => $pEmail, 'start_time' => $start !== '' ? $start : $pollRow['start_time'],
        'comment' => $comment, 'require_email' => $reqEmail, 'notify_votes' => $notifyVotes,
        'explain_rules' => array_key_exists('explain_rules', $_POST) ? min(2, max(0, (int)$_POST['explain_rules'])) : (int)$pollRow['explain_rules'],
        'allow_others_add' => poll_optin_relevant($pollRow) ? (isset($_POST['allow_others']) ? 1 : 0) : (int)$pollRow['allow_others_add'],
        'others_from_library' => poll_can_restrict_to_library($pollRow, (int)($pollRow['proposer_user_id'] ?? 0))
            ? (isset($_POST['others_library']) ? 1 : 0) : (int)($pollRow['others_from_library'] ?? 0),
        'show_results' => isset($_POST['show_results']) ? 1 : 0,
        'wait_for_deadline' => poll_wait_allowed() ? (isset($_POST['wait_deadline']) ? 1 : 0) : (int)($pollRow['wait_for_deadline'] ?? 0),
        'revert_mode' => array_key_exists('revert_mode', $_POST) ? poll_revert_mode_clean($_POST['revert_mode']) : poll_revert_mode_clean($pollRow['revert_mode'] ?? 'off'),
    ]);
    $hoursNow = $hours;

    if (!is_valid_time($start)) {
        $error = t('error_time_invalid');
    } elseif (!start_within_event_hours($start, $day)) {
        $error = t('error_start_outside_hours');
    } elseif ($pName !== '' && !text_has_content($pName)) {
        $error = t('error_name_meaningless');
    } elseif (text_too_long($pName, TEXT_NAME_MAX)) {
        $error = t('error_too_long', TEXT_NAME_MAX);
    } elseif ((email_require_mode() === 1 || $reqEmail === 1) && $pEmail === '') {
        $error = t('error_email_required');
    } elseif ($notifyVotes === 1 && $pEmail === '') {
        $error = t('error_notify_votes_email');
    } elseif ($pEmail !== '' && !email_valid($pEmail)) {
        $error = t('error_email_invalid');
    } elseif ($comment !== '' && (!text_has_content($comment) || text_too_long($comment, TEXT_BODY_MAX))) {
        $error = t('error_too_long', TEXT_BODY_MAX);
    } else {
        /* The deadline: kept exactly when it is still ahead and nothing that
         * decides it changed (the field works in whole hours, so recomputing
         * it would nudge it); otherwise from the hours, which also clamps one
         * that would already be past to an hour from now. */
        $keep = !$moved && !empty($pollRow['deadline']) && $start === $pollRow['start_time']
                && $hours === poll_deadline_hours($pollRow, $day);
        $form['deadline'] = $keep ? $pollRow['deadline'] : poll_deadline_from_hours($day, $start, $hours);
        $form['proposer_name']  = $pName !== '' ? $pName : null;
        $form['proposer_email'] = $pEmail !== '' ? $pEmail : null;
        $form['comment']        = $comment !== '' ? $comment : null;

        $pollId = poll_restore_from_game($game, $snap, $form, null);
        if (!$pollId) {
            $error = t('poll_reopen_failed');
        } else {
            log_action('poll_reopened', $game['name'] . ' -> poll #' . $pollId
                . ' (deadline ' . ($form['deadline'] ?? 'none') . ')');
            // The author may go straight on to edit it, without proving it again.
            $_SESSION['poll_edit_ok'][$pollId] = true;
            $poll = db_one('SELECT * FROM polls WHERE id = ?', [$pollId]);
            notify_poll_reopened($poll, $game['name']);
            // Settings changed on the way back (a lower minimum, waiting
            // switched off) may decide it again at once, like any edit.
            $newGameId = poll_check_resolve($pollId);
            redirect(front_url($activeDay, (int)($day['event_id'] ?? 0), $newGameId ? '#game-' : '#poll-')
                     . ($newGameId ?: $pollId));
        }
    }
}

tpl_render('header', ['page_title' => t('poll_reopen_title')]);
tpl_render('edit_poll', [
    'can_restrict_library' => poll_can_restrict_to_library($pollRow, 0),
    'poll'   => $form,
    'cands'  => poll_reopen_options($game, $snap),
    'day'    => $day,
    'bounds' => start_time_bounds($day),
    'deadline_hours' => $hoursNow,
    'error'  => $error,
    'csrf'   => csrf_field(),
    // Reopen mode: posts here, the option list is read-only, its own wording.
    'reopen' => ['game_id' => $gameId, 'deadline_moved' => $moved, 'back' => $back],
]);
tpl_render('footer');
