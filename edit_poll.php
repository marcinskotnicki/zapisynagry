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
    redirect('index.php?day=' . $activeDay);
}

$decision = verify_decision($poll['proposer_user_id'], $poll['proposer_email']);
if ($decision === 'deny') { redirect('index.php?day=' . $activeDay); }

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
            if ($newGameId) { redirect('index.php?day=' . $activeDay . '#game-' . $newGameId); }
            redirect('edit_poll.php?poll=' . $pollId);
        }

    } elseif ($action === 'save') {
        // Start time only (the candidate list is edited by its own buttons).
        $start = trim($_POST['start_time'] ?? '');
        if (!is_valid_time($start)) {
            $error = t('error_time_invalid');
        } elseif (!start_within_event_hours($start, $day)) {
            $error = t('error_start_outside_hours');
        } else {
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
            if ($start !== $poll['start_time']) {
                db_run('UPDATE polls SET start_time = ? WHERE id = ?', [$start, $pollId]);
                log_action('poll_edit', 'Poll #' . $pollId . ' start -> ' . $start);
                notify_poll_changed($poll, t('ntf_pollchg_time', $start));
            }
            redirect('index.php?day=' . $activeDay . '#poll-' . $pollId);
        }
    }
}

// Park this poll as the target for add_poll_game.php's live mode (cleared once
// a candidate is added, or when a new poll draft is started).
$_SESSION['poll_live_edit'] = $pollId;

// Fresh read: a removal above may have changed the candidate list.
$cands = db_all('SELECT * FROM poll_games WHERE poll_id = ? ORDER BY id', [$pollId]);

tpl_render('header', ['page_title' => t('poll_edit_title')]);
tpl_render('edit_poll', [
    'poll'   => db_one('SELECT * FROM polls WHERE id = ?', [$pollId]),
    'cands'  => $cands,
    'day'    => $day,
    'bounds' => start_time_bounds($day),   // clamp the time input when the option says so
    'error'  => $error,
    'csrf'   => csrf_field(),
]);
tpl_render('footer');
