<?php
/* =============================================================================
 *  edit_poll.php — change a live poll: start time, and which games are in it.
 * -----------------------------------------------------------------------------
 *  Mirrors edit_game.php's shape:
 *    1. VERIFICATION GATE — unregistered-created polls are protected by the
 *       usual verification tree (email match / emailed code). Passing once is
 *       remembered for this poll in the session, so the follow-up actions
 *       (removing a candidate, adding one via add_poll_game.php) don't
 *       re-challenge on every click.
 *    2. EDIT FORM — start time + the candidate list with remove buttons, plus a
 *       link into add_poll_game.php's live mode to add another game.
 *
 *  Everyone who has already voted is emailed about each change, since a poll
 *  they joined shifting under them is exactly the thing they'd want to know.
 *  Removing a candidate cascades its votes away, so those voters are gathered
 *  BEFORE the delete or they'd be unreachable afterwards.
 *
 *  Resolution is deliberately re-checked after edits: lowering the field or
 *  moving votes around can leave a candidate already past its threshold.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/polls.php';
require __DIR__ . '/inc/verify.php';
require __DIR__ . '/inc/notify.php';

$pollId = (int)($_GET['poll'] ?? $_POST['poll'] ?? 0);
$poll   = $pollId ? db_one('SELECT * FROM polls WHERE id = ?', [$pollId]) : null;
if (!$poll) { redirect('index.php'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$poll['event_id']]);
$day   = db_one('SELECT * FROM event_days WHERE id = ?', [$poll['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Live event only, and the same button rule the poll card uses.
if (!$event || (int)$event['is_archived'] === 1
    || !verify_can_show_buttons($poll['proposer_user_id'])) {
    redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
}

$decision = verify_decision($poll['proposer_user_id'], $poll['proposer_email']);
if ($decision === 'deny') { redirect(front_url($activeDay, (int)($day['event_id'] ?? 0))); }

// Clicking edit is logged straight away, so an abandoned or failed attempt
// still leaves a trace (mirrors game/player edit attempts).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_action('poll_edit_attempt', 'Poll #' . $pollId);
}

/* ---- 1. Verification gate ------------------------------------------------- */
$unlocked = ($decision === 'allow') || !empty($_SESSION['poll_edit_ok'][$pollId]);
$error    = null;

if (!$unlocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    csrf_check();
    if (verify_passes($decision, 'poll', $pollId, $poll['proposer_email'], $_POST)) {
        $_SESSION['poll_edit_ok'][$pollId] = true;   // remember for the follow-up actions
        $unlocked = true;
    } else {
        $error = t('verify_failed');
    }
}

if (!$unlocked) {
    if ($decision === 'email_code' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        verify_send_code('poll', $pollId, $poll['proposer_email']);   // email the code on first view
    }
    tpl_render('header', ['page_title' => t('poll_edit_title')]);
    tpl_render('verify_challenge', [
        'decision' => $decision,
        'title'    => t('poll_edit_title'),
        'error'    => $error,
        // The poll id rides in the query string, so the POST keeps it.
        'action'   => 'edit_poll.php?poll=' . $pollId,
        'csrf'     => csrf_field(),
    ]);
    tpl_render('footer');
    exit;
}

/* ---- 2. Edits (all POSTs below are past the gate) ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'remove') {
        antibot_check('click');
        // Drop one candidate. Gather the voters FIRST — the delete cascades
        // their votes away, and a poll needs at least one option to survive.
        $cgId = (int)($_POST['cand'] ?? 0);
        $cand = $cgId ? db_one('SELECT * FROM poll_games WHERE id = ? AND poll_id = ?', [$cgId, $pollId]) : null;
        $count = (int)db_val('SELECT COUNT(*) FROM poll_games WHERE poll_id = ?', [$pollId]);
        if (!$cand) {
            $error = t('poll_cand_missing');
        } elseif ($count <= 1) {
            $error = t('poll_need_game');            // removing the last option would orphan the poll
        } else {
            $emails = notify_poll_voter_emails($pollId);   // BEFORE the cascade
            db_run('DELETE FROM poll_games WHERE id = ?', [$cgId]);
            log_action('poll_cand_removed', $cand['name'] . ' (poll #' . $pollId . ')');
            notify_poll_changed($poll, t('ntf_pollchg_removed', $cand['name']), $emails);
            // Fewer options can leave a survivor already over its threshold.
            $newGameId = poll_check_resolve($pollId);
            if ($newGameId) { redirect(front_url($activeDay, (int)($day['event_id'] ?? 0), '#game-') . $newGameId); }
            redirect('edit_poll.php?poll=' . $pollId);
        }

    } elseif ($action === 'save') {
        antibot_check('form');
        // Poll-level settings (the candidate list is edited by its own buttons).
        $start = trim($_POST['start_time'] ?? '');
        // Read these ONLY when the form actually submitted them. An absent key is
        // not an empty value: a POST from an older/partial form must not wipe the
        // proposer's name or email. (Same principle as the checkboxes below —
        // absence means "not offered", not "cleared".)
        $pName  = array_key_exists('name', $_POST)
            ? trim($_POST['name']) : (string)($poll['proposer_name'] ?? '');
        $pEmail = array_key_exists('email', $_POST)
            ? trim($_POST['email']) : (string)($poll['proposer_email'] ?? '');
        // Per-poll email rule (option mode 2 only): the box is on the form and
        // can be toggled here. In the other modes it isn't rendered, so a save
        // must keep the stored value rather than read the absence as "off".
        $reqEmail = email_require_mode() === 2
            ? (isset($_POST['require_email']) ? 1 : 0)
            : (int)($poll['require_email'] ?? 0);

        if (!is_valid_time($start)) {
            $error = t('error_time_invalid');
        } elseif (!start_within_event_hours($start, $day)) {
            $error = t('error_start_outside_hours');
        } elseif ($pName !== '' && !text_has_content($pName)) {
            $error = t('error_name_meaningless');    // punctuation-only, not a name
        } elseif (text_too_long($pName, TEXT_NAME_MAX)) {
            $error = t('error_too_long', TEXT_NAME_MAX);
        } elseif ((email_require_mode() === 1 || $reqEmail === 1) && $pEmail === '') {
            // Globally required, or the proposer demands emails from voters — in
            // which case their own is mandatory too, same rule as add_poll.php.
            $error = t('error_email_required');
        } elseif ($pEmail !== '' && !email_valid($pEmail)) {
            $error = t('error_email_invalid');       // non-empty but not X@Y.Z-shaped
        } else {
            // Proposer details and the rules-explanation choice, mirroring the
            // game edit form. Written together since they're one logical edit.
            $explain = array_key_exists('explain_rules', $_POST)
                ? min(2, max(0, (int)$_POST['explain_rules']))
                : (int)$poll['explain_rules'];
            if ($pName !== ($poll['proposer_name'] ?? '')
                || $pEmail !== ($poll['proposer_email'] ?? '')
                || $explain !== (int)$poll['explain_rules']
                || $reqEmail !== (int)($poll['require_email'] ?? 0)) {
                db_run('UPDATE polls SET proposer_name = ?, proposer_email = ?, explain_rules = ?, require_email = ? WHERE id = ?',
                       [$pName !== '' ? $pName : null, $pEmail !== '' ? $pEmail : null,
                        $explain, $reqEmail, $pollId]);
                log_action('poll_edit', 'Poll #' . $pollId . ' details updated');
            }
            // "Let others add games" rides along on the same form; only a real
            // change is logged/emailed, so re-saving the form is quiet.
            // Only honour the checkbox when it was actually on the form — an
            // unchecked box and a hidden one look identical in $_POST, and a
            // hidden one must NOT wipe a value the proposer set earlier.
            $allowOthers = poll_optin_relevant($poll)
                ? (isset($_POST['allow_others']) ? 1 : 0)
                : (int)$poll['allow_others_add'];
            if ($allowOthers !== (int)$poll['allow_others_add']) {
                db_run('UPDATE polls SET allow_others_add = ? WHERE id = ?', [$allowOthers, $pollId]);
                log_action('poll_edit', 'Poll #' . $pollId . ' allow_others_add -> ' . $allowOthers);
            }
            /* Showing the votes. Always on the form, so an absent key genuinely
             * means unticked — unlike the box above, which is hidden in some
             * states and therefore needs the poll_optin_relevant() dance. */
            /* Only meaningful while others may add at all, and only while the
             * box was on the form — hidden and unticked look identical in
             * POST, and a hidden one must not wipe what the proposer set. */
            $othersLib = poll_can_restrict_to_library($poll, (int)($poll['proposer_user_id'] ?? 0))
                ? (isset($_POST['others_library']) ? 1 : 0)
                : (int)($poll['others_from_library'] ?? 0);
            if ($othersLib !== (int)($poll['others_from_library'] ?? 0)) {
                db_run('UPDATE polls SET others_from_library = ? WHERE id = ?', [$othersLib, $pollId]);
                log_action('poll_edit', 'Poll #' . $pollId . ' others_from_library -> ' . $othersLib);
            }
            $showResults = isset($_POST['show_results']) ? 1 : 0;
            if ($showResults !== (int)($poll['show_results'] ?? 0)) {
                db_run('UPDATE polls SET show_results = ? WHERE id = ?', [$showResults, $pollId]);
                log_action('poll_edit', 'Poll #' . $pollId . ' show_results -> ' . $showResults);
            }
            // "Run to the deadline" — same treatment: this checkbox is always on
            // the form, so an absent key genuinely means unticked. Changing it
            // can END the poll on the spot: switching it OFF while a candidate is
            // already full means the threshold now applies, which the resolve
            // check below acts on.
            $waitDeadline = isset($_POST['wait_deadline']) ? 1 : 0;
            if ($waitDeadline !== (int)($poll['wait_for_deadline'] ?? 0)) {
                db_run('UPDATE polls SET wait_for_deadline = ? WHERE id = ?', [$waitDeadline, $pollId]);
                log_action('poll_edit', 'Poll #' . $pollId . ' wait_for_deadline -> ' . $waitDeadline);
                notify_poll_changed($poll, $waitDeadline
                    ? t('ntf_pollchg_wait_on')
                    : t('ntf_pollchg_wait_off'));
            }
            // The poll's own description. Junk/oversize is refused rather than
            // silently dropped, since this form can show an error.
            $comment = trim($_POST['comment'] ?? '');
            if ($comment !== '' && (!text_has_content($comment) || text_too_long($comment, TEXT_BODY_MAX))) {
                $error = t('error_too_long', TEXT_BODY_MAX);
            } elseif ($comment !== (string)$poll['comment']) {
                db_run('UPDATE polls SET comment = ? WHERE id = ?', [$comment !== '' ? $comment : null, $pollId]);
                log_action('poll_edit', 'Poll #' . $pollId . ' comment changed');
                notify_poll_changed($poll, t('ntf_pollchg_comment'));
            }
            if ($error === null && $start !== $poll['start_time']) {
                db_run('UPDATE polls SET start_time = ? WHERE id = ?', [$start, $pollId]);
                log_action('poll_edit', 'Poll #' . $pollId . ' start -> ' . $start);
                notify_poll_changed($poll, t('ntf_pollchg_time', $start));
            }
            // The deadline is expressed as "N hours before the start", so it has
            // to be recomputed when EITHER the hours or the start time moved —
            // that's the whole point of moving a poll: its voting window should
            // travel with it.
            //
            // Only when the field was actually submitted, for the same reason
            // the checkbox above is guarded: a form without it must not be read
            // as "0 hours" and silently wipe a deadline someone else set. And
            // only when the stored value really changes, so re-saving a comment
            // edit can't re-clamp (and thereby quietly extend) a deadline.
            if ($error === null && array_key_exists('deadline_hours', $_POST)) {
                $dlHours  = max(0, (int)$_POST['deadline_hours']);
                $curHours = poll_deadline_hours($poll, $day);
                if ($dlHours !== $curHours || $start !== $poll['start_time']) {
                    $newDeadline = poll_deadline_from_hours($day, $start, $dlHours);
                    if ($newDeadline !== $poll['deadline']) {
                        db_run('UPDATE polls SET deadline = ? WHERE id = ?', [$newDeadline, $pollId]);
                        log_action('poll_edit', 'Poll #' . $pollId . ' deadline -> ' . ($newDeadline ?? 'none'));
                        notify_poll_changed($poll, $newDeadline === null
                            ? t('ntf_pollchg_deadline_off')
                            : t('ntf_pollchg_deadline', substr($newDeadline, 0, 16)));
                    }
                }
            }
            // Both edits above can un-freeze a poll that was being held open:
            // clearing "run to the deadline", or removing the deadline that made
            // the flag effective at all. Either way the threshold applies again
            // and a candidate may already be over it, so re-check before
            // leaving — otherwise the poll would sit full until the next vote.
            if ($error === null) {
                $newGameId = poll_check_resolve($pollId);
                if ($newGameId) { redirect(front_url($activeDay, (int)($day['event_id'] ?? 0), '#game-') . $newGameId); }
            }
            redirect(front_url($activeDay, (int)($day['event_id'] ?? 0), '#poll-') . $pollId);
        }
    }
}

// Park this poll as the target for add_poll_game.php's live mode (cleared once
// a candidate is added, or when a new poll draft is started).
$_SESSION['poll_live_edit'] = $pollId;

// Fresh read: a removal above may have changed the candidate list.
$cands = db_all('SELECT * FROM poll_games WHERE poll_id = ? ORDER BY id', [$pollId]);
$pollNow = db_one('SELECT * FROM polls WHERE id = ?', [$pollId]);

tpl_render('header', ['page_title' => t('poll_edit_title')]);
tpl_render('edit_poll', [
    // Same gate as on the add form; keyed on the POLL's proposer, not the viewer.
    'can_restrict_library' => poll_can_restrict_to_library($poll, 0),
    'poll'   => $pollNow,
    'cands'  => $cands,
    'day'    => $day,
    'bounds' => start_time_bounds($day),   // clamp the time input when the option says so
    // The stored deadline is absolute; the form edits it as hours-before-start.
    'deadline_hours' => poll_deadline_hours($pollNow, $day),
    'error'  => $error,
    'csrf'   => csrf_field(),
]);
tpl_render('footer');
