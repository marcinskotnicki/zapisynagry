<?php
/* =============================================================================
 *  delete_game.php — delete a game, three ways.
 * -----------------------------------------------------------------------------
 *  Confirm screen with three buttons (a single form, distinguished by the
 *  submitted "choice"):
 *    back       : do nothing, return to the event.
 *    archive    : soft-delete (is_archived=1) — greyed out, can be brought back.
 *    everything : hard delete — cascades players + comments away.
 *  Verification (for unregistered-added games) is on the confirm screen and is
 *  checked when the action is submitted, mirroring player deletion (single-step).
 *  Signed-up players are emailed BEFORE the delete (so we can still find them).
 *
 *  PURGE MODE: an ALREADY soft-deleted game may also land here — that's the
 *  admin-only "delete permanently" button on the greyed card. The confirm
 *  screen then offers just back / delete everything (no re-archive, no
 *  challenge, no duplicate deletion email).
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/verify.php';
// captcha_html()/captcha_verify(): the optional challenge on the step that
// asks for a code. Loaded here because that step lives on this page.
require __DIR__ . '/inc/captcha.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/notify.php';

$gameId = (int)($_GET['game'] ?? $_POST['game'] ?? 0);
$game   = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
if (!$game) { redirect('index.php'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$game['event_id']]);
$day   = db_one('SELECT day_index, event_id FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// PURGE MODE: a soft-deleted game may be removed PERMANENTLY, but only by an
// admin (the greyed card shows them the button). Regular flow (active game)
// keeps the usual live-event + button-rule + verification checks.
$purge = ((int)$game['is_archived'] === 1);

if ($purge) {
    // Archived game: admin-only, no verification challenge (admins always pass).
    if (!$event || (int)$event['is_archived'] === 1 || !is_admin()) {
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
    }
    $decision = 'allow';
} else {
    // Live event + active game only, and re-check the button rule server-side.
    if (!$event || (int)$event['is_archived'] === 1
        || !verify_can_show_buttons($game['added_by_user_id'])) {
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
    }
    $decision = verify_decision($game['added_by_user_id'], $game['brings_email']);
    if ($decision === 'deny') { redirect(front_url($activeDay, (int)($day['event_id'] ?? 0))); }
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $choice = $_POST['choice'] ?? 'back';

    if ($choice === 'back') {
        // Bail out, no challenge needed — and forget any code step in progress.
        verify_clear_requested('game', $gameId);
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
    }
    antibot_check('click');

    /* ASKING FOR A CODE is its own step, and the ONLY thing that sends mail.
     *
     * It ends the POST here rather than falling through — everything below is
     * the deletion itself, and this step has not asked for one yet. A captcha
     * can be put in front of it, which is worth having only for a site being
     * walked by something that ignores robots.txt, hence off by default. */
    if ($choice === 'send_code') {
        if (captcha_verify('verify')) {
            verify_send_code('game', $gameId, $game['brings_email']);
            verify_mark_requested('game', $gameId);
        } else {
            $error = t('error_captcha');
        }
        $sendStep = true;   // skip the deletion below; render the next screen
    }

    /* Everything from here is the deletion itself, and only runs when this POST
     * was asking for one. Guarded as a block rather than condition by
     * condition: the challenge check below would otherwise run for a request
     * that has not been challenged yet and report a failure that did not
     * happen. */
    if (empty($sendStep)) {

    // The admin's game_deletion setting decides which choices exist at all.
    // Applied HERE rather than only by hiding buttons, since a posted choice is
    // just a form field. An admin purging an already-soft-deleted game is
    // exempt: that is the second half of a soft delete, not a way around the
    // setting.
    $mode = game_deletion_mode();
    if ($mode === 'soft' && $choice === 'everything' && !$purge && !is_admin()) {
        $choice = 'archive';
    } elseif ($mode === 'hard' && $choice === 'archive') {
        $choice = 'everything';
    }

    if (!verify_passes($decision, 'game', $gameId, $game['brings_email'], $_POST)) {
        $error = t('verify_failed');                       // failed challenge -> re-show confirm
    } elseif ($choice === 'archive' && !$purge) {   // meaningless when already archived
        notify_game_deleted($game);                        // notify while players still exist
        db_run('UPDATE games SET is_archived = 1 WHERE id = ?', [$gameId]);   // soft-delete
        // The owner just withdrew the game, so leaving them in its own player
        // list makes no sense. Only removed when they can be identified with
        // confidence — see game_drop_bringer_signup(). Done AFTER the notify,
        // so they still receive the message about their own game.
        $droppedSelf = game_drop_bringer_signup($game);
        log_action('game_archive', $game['name'] . ($droppedSelf ? ' (bringer sign-up removed)' : ''));
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
    } elseif ($choice === 'everything') {
        // Players were already notified when the game was soft-deleted, so a
        // purge of an archived game skips the (duplicate) deletion email.
        if (!$purge) { notify_game_deleted($game); }   // gather + notify before the cascade removes players
        db_run('DELETE FROM games WHERE id = ?', [$gameId]);   // cascades players + comments
        log_action('game_delete', $game['name']);
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
    }

    }   // end: this POST was asking for a deletion
}

// GET = the delete button was clicked: leave a trace right away, so even an
// attempt that's abandoned (or fails the challenge later) shows in the logs.
// The success path still adds its own game_archive / game_delete row.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    log_action('game_delete_attempt', $game['name']);
}
/* NO EMAIL ON A GET, which is why nothing is sent here.
 *
 * This page used to mail the code the moment the delete link was followed —
 * and that link is an <a href>, so anything walking the site sent a
 * verification email to a member who had clicked nothing. A crawler cannot
 * finish the deletion, but it never needed to in order to be a nuisance.
 *
 * GET is defined as SAFE: it must not change or send anything. The first screen
 * now only asks; the code goes out from the POST handler above, on the one
 * branch that requests it, after CSRF, the timing/honeypot check and (if a club
 * switched it on) a captcha. */

tpl_render('header', ['page_title' => t('delgame_title')]);
tpl_render('game_delete_confirm', [
    // Which of the two removals the admin allows; 'choose' offers both.
    'mode'     => game_deletion_mode(),
    'game'     => $game,
    'decision' => $decision,
    /* Which half of the email-code path to show: ask for a code, or take one.
     * Session-backed, so an expired code puts somebody back on the first step
     * rather than at a box they can no longer satisfy. */
    'code_sent' => verify_code_requested('game', $gameId),
    'captcha'   => captcha_html('verify'),
    'error'    => $error,
    'purge'    => $purge,      // archived game -> only back / delete-everything buttons
    'csrf'     => csrf_field(),
]);
tpl_render('footer');
