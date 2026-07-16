<?php
/* =============================================================================
 *  vote.php — vote for a poll candidate (or cancel a vote).
 * -----------------------------------------------------------------------------
 *  Voting reuses the assign-player fields (name / email / knows-rules). After a
 *  vote is recorded we check the poll: if the candidate reached its threshold,
 *  poll_check_resolve() turns it into a real game and the poll vanishes, and we
 *  redirect straight to the new game card.
 *
 *  The 'action' field is 'vote' (default) or 'cancel'. Cancelling is only
 *  offered to logged-in users, since we can only identify a vote to remove when
 *  it's tied to an account.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/polls.php';
require __DIR__ . '/inc/notify.php';   // poll_check_resolve() may send the conclusion email

$pgId = (int)($_GET['poll_game'] ?? $_POST['poll_game'] ?? 0);   // the candidate id
$cand = $pgId ? db_one('SELECT * FROM poll_games WHERE id = ?', [$pgId]) : null;
if (!$cand) { redirect('index.php'); }

$poll  = db_one('SELECT * FROM polls WHERE id = ?', [$cand['poll_id']]);
$event = $poll ? db_one('SELECT * FROM events WHERE id = ?', [$poll['event_id']]) : null;
$day   = $poll ? db_one('SELECT day_index FROM event_days WHERE id = ?', [$poll['day_id']]) : null;
$activeDay = (int)($day['day_index'] ?? 1);

// Live event + permitted to sign up (voting == joining a potential game).
if (!$poll || !$event || (int)$event['is_archived'] === 1 || !can_signup()) {
    redirect('index.php');
}

$u = current_user();
$uid = $u['id'] ?? null;
$form = [
    'name'  => $_POST['name']  ?? ($u['display_name'] ?? ''),
    'email' => $_POST['email'] ?? ($u['email'] ?? ''),
    'knows' => isset($_POST['knows']) ? (int)$_POST['knows'] : 0,
];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'vote';

    if ($action === 'cancel') {
        // Only logged-in users can cancel (we can identify their vote by user_id).
        if ($uid) {
            db_run('DELETE FROM poll_votes WHERE poll_game_id = ? AND user_id = ?', [$pgId, $uid]);
            log_action('poll_unvote', $cand['name']);
        }
        redirect('index.php?day=' . $activeDay . '#poll-' . (int)$cand['poll_id']);
    }

    // Recording a vote: a logged-in user can't vote twice for the same candidate.
    if ($uid && poll_user_voted($pgId, $uid)) {
        redirect('index.php?day=' . $activeDay . '#poll-' . (int)$cand['poll_id']);   // already voted; no duplicate
    }

    $form['name']  = trim((string)$form['name']);
    $form['email'] = trim((string)$form['email']);
    $form['knows'] = min(2, max(0, (int)$form['knows']));

    if ($form['name'] === '') {
        $error = t('error_signup_name');
    } elseif (opt_bool('require_email') && $form['email'] === '') {
        $error = t('error_email_required');
    } elseif ($form['email'] !== '' && !email_valid($form['email'])) {
        $error = t('error_email_invalid');   // non-empty but not X@Y.Z-shaped
    } elseif ($form['email'] !== '' && db_val(
            'SELECT 1 FROM poll_votes WHERE poll_game_id = ? AND email = ? COLLATE NOCASE',
            [$pgId, $form['email']])) {
        // Anti-rigging: one vote per email per OPTION (case-insensitive). Guests
        // without an email can't be deduplicated this way — that's as far as a
        // no-registration flow can reasonably go.
        $error = t('vote_email_dup');
    } else {
        db_run(
            'INSERT INTO poll_votes (poll_game_id, poll_id, name, email, knows_rules, user_id)
             VALUES (?,?,?,?,?,?)',
            [$pgId, $cand['poll_id'], $form['name'],
             $form['email'] !== '' ? $form['email'] : null, $form['knows'], $uid]
        );
        log_action('poll_vote', $form['name'] . ' -> ' . $cand['name']);

        // Did this vote push the candidate over its threshold? If so it's now a game.
        $newGameId = poll_check_resolve($cand['poll_id']);
        if ($newGameId) {
            redirect('index.php?day=' . $activeDay . '#game-' . $newGameId);
        }
        redirect('index.php?day=' . $activeDay . '#poll-' . (int)$cand['poll_id']);
    }
}

tpl_render('header', ['page_title' => t('vote_title')]);
tpl_render('vote_form', [
    'cand'  => $cand,
    'form'  => $form,
    'error' => $error,
    'csrf'  => csrf_field(),
]);
tpl_render('footer');
