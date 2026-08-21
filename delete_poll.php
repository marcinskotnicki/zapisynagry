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
// captcha_html()/captcha_verify() for the optional challenge on the ask step.
require_once __DIR__ . '/inc/captcha.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/notify.php';

$pollId = (int)($_GET['poll'] ?? $_POST['poll'] ?? 0);
$poll   = $pollId ? db_one('SELECT * FROM polls WHERE id = ?', [$pollId]) : null;
if (!$poll) { redirect('index.php'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$poll['event_id']]);
$day   = db_one('SELECT day_index, event_id FROM event_days WHERE id = ?', [$poll['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Live event only, and re-check the button rule server-side (the card only
// HIDES the button — the guard has to live here).
if (!$event || (int)$event['is_archived'] === 1
    || !verify_can_show_buttons($poll['proposer_user_id'])) {
    redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
}

$decision = verify_decision($poll['proposer_user_id'], $poll['proposer_email']);
if ($decision === 'deny') { redirect(front_url($activeDay, (int)($day['event_id'] ?? 0))); }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $choice = $_POST['choice'] ?? 'back';

    if ($choice === 'back') {
        verify_clear_requested('poll', $pollId);   // forget any code step in progress
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));       // bail out, no challenge needed
    }
    antibot_check('click');

    /* ASKING FOR A CODE is its own step, and the only thing here that sends
     * mail. It ends the POST: the screen re-renders with the code box and
     * nothing is deleted. Same reason as the other two — the delete control is
     * an <a href>, so anything walking the site used to send a verification
     * email to somebody who had clicked nothing. */
    if ($choice === 'send_code') {
        if (captcha_verify('verify')) {
            verify_send_code('poll', $pollId, $poll['proposer_email']);
            verify_mark_requested('poll', $pollId);
        } else {
            $error = t('error_captcha');
        }
    } elseif (!verify_passes($decision, 'poll', $pollId, $poll['proposer_email'], $_POST)) {
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
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
    }
}

// GET = the delete button was clicked: leave a trace immediately, so an
// abandoned attempt (or one that fails the challenge) still shows in the logs.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_action('poll_delete_attempt', 'Poll #' . $pollId);
}
/* NOTHING IS SENT HERE. GET is defined as SAFE: it must not send anything. The
 * code goes out from the POST branch above, on the one choice that asks. */

tpl_render('header', ['page_title' => t('delpoll_title')]);
tpl_render('poll_delete_confirm', [
    'poll'     => $poll,
    'decision' => $decision,
    'error'    => $error,
    // Which half of the code path to render: ask for one, or take one.
    'code_sent' => verify_code_requested('poll', $pollId),
    'captcha'   => captcha_html('verify'),
    'csrf'      => csrf_field(),
]);
tpl_render('footer');
