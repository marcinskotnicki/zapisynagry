<?php
/* =============================================================================
 *  delete_poll.php — delete a poll and its candidates/votes.
 * -----------------------------------------------------------------------------
 *  Shaped like delete_game.php, minus the archive step: games can be soft-
 *  deleted and brought back, but a poll has no is_archived column and nothing to
 *  bring back to — it's a question that either gets answered (resolving into a
 *  real game) or withdrawn. So the confirm screen is just back / delete.
 *
 *  WHO MAY: the same rule as EDITING a poll — verify_can_show_buttons() on the
 *  proposer, i.e. a poll created by a logged-in user can only be deleted by that
 *  user or an admin, while a guest-created one falls into the usual verification
 *  tree (retype the email / enter an emailed code). Deliberately NOT end_poll's
 *  account-only rule: withdrawing your own guest-made poll shouldn't need an
 *  account when editing it doesn't.
 *
 *  Voters are emailed BEFORE the delete — poll_votes cascades away with the
 *  poll (FK ON DELETE CASCADE), so afterwards they'd be unreachable.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/verify.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/notify.php';

$pollId = (int)($_GET['poll'] ?? $_POST['poll'] ?? 0);
$poll   = $pollId ? db_one('SELECT * FROM polls WHERE id = ?', [$pollId]) : null;
if (!$poll) { redirect('index.php'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$poll['event_id']]);
$day   = db_one('SELECT day_index FROM event_days WHERE id = ?', [$poll['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Live event only, and re-check the button rule server-side (the card only
// HIDES the button — the guard has to live here).
if (!$event || (int)$event['is_archived'] === 1
    || !verify_can_show_buttons($poll['proposer_user_id'])) {
    redirect('index.php?day=' . $activeDay);
}

$decision = verify_decision($poll['proposer_user_id'], $poll['proposer_email']);
if ($decision === 'deny') { redirect('index.php?day=' . $activeDay); }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $choice = $_POST['choice'] ?? 'back';

    if ($choice === 'back') {
        redirect('index.php?day=' . $activeDay);       // bail out, no challenge needed
    }
    antibot_check('click');
    if (!verify_passes($decision, 'poll', $pollId, $poll['proposer_email'], $_POST)) {
        $error = t('verify_failed');                    // failed challenge -> re-show confirm
    } elseif ($choice === 'everything') {
        notify_poll_deleted($poll);                     // before the cascade loses the voters
        db_run('DELETE FROM polls WHERE id = ?', [$pollId]);   // cascades candidates + votes
        log_action('poll_delete', 'Poll #' . $pollId
                   . ($poll['proposer_name'] !== '' ? ' by ' . $poll['proposer_name'] : ''));
        // This poll may have been parked as add_poll_game.php's live target;
        // leaving a dangling id there would send that page to a missing poll.
        if ((int)($_SESSION['poll_live_edit'] ?? 0) === $pollId) {
            unset($_SESSION['poll_live_edit']);
        }
        unset($_SESSION['poll_edit_ok'][$pollId]);      // no point remembering a passed gate
        redirect('index.php?day=' . $activeDay);
    }
}

// GET = the delete button was clicked: leave a trace immediately, so an
// abandoned attempt (or one that fails the challenge) still shows in the logs.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_action('poll_delete_attempt', 'Poll #' . $pollId);
}
if ($decision === 'email_code' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    verify_send_code('poll', $pollId, $poll['proposer_email']);
}

tpl_render('header', ['page_title' => t('delpoll_title')]);
tpl_render('poll_delete_confirm', [
    'poll'     => $poll,
    'decision' => $decision,
    'error'    => $error,
    'csrf'     => csrf_field(),
]);
tpl_render('footer');
