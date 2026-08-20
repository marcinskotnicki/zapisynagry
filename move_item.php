<?php
/* =============================================================================
 *  move_item.php — move a game or a poll to a different table.
 * -----------------------------------------------------------------------------
 *  Admin-only, deliberately plain: a dropdown of the OTHER tables on the same
 *  day and a confirm button. Clubs report needing this rarely (a table gets
 *  double-booked, or a game is entered against the wrong one), so a drag-and-
 *  drop rig would be a lot of machinery for something used a handful of times
 *  a year.
 *
 *  ONE endpoint for both kinds because the operation is genuinely identical —
 *  reassign a row's table_id. Splitting it into move_game.php / move_poll.php
 *  would duplicate every guard for no gain.
 *
 *  SAME DAY ONLY. A table belongs to a day, so restricting the choices to the
 *  current day's tables means day_id stays correct without the caller having to
 *  keep the two columns in step — moving across days would silently leave a
 *  game pointing at a table from a different date.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';

// Which kind are we moving? 'poll' when a poll id is given, otherwise a game.
$gameId = (int)($_GET['game'] ?? $_POST['game'] ?? 0);
$pollId = (int)($_GET['poll'] ?? $_POST['poll'] ?? 0);

if ($pollId) {
    $kind = 'poll';
    $row  = db_one('SELECT * FROM polls WHERE id = ?', [$pollId]);
    $id   = $pollId;
} else {
    $kind = 'game';
    $row  = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
    $id   = $gameId;
}
if (!$row) { redirect('index.php'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$row['event_id']]);
$day   = db_one('SELECT day_index FROM event_days WHERE id = ?', [$row['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);

// Admin only, live event only. Checked server-side rather than trusting that
// the button is merely hidden for everyone else.
if (!is_admin() || !$event || (int)$event['is_archived'] === 1) {
    redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
}

// Candidate destinations: every OTHER table on the same day.
$tables = db_all(
    'SELECT * FROM game_tables WHERE day_id = ? AND id <> ? ORDER BY table_number',
    [$row['day_id'], $row['table_id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['choice'] ?? '') === 'back') {
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0)));
    }
    antibot_check('click');

    $target = (int)($_POST['table'] ?? 0);
    // Re-validate the target against the SAME query that built the dropdown —
    // a posted id must not be able to move an item onto another day's table,
    // or another event's.
    $ok = false;
    foreach ($tables as $t) {
        if ((int)$t['id'] === $target) { $ok = true; break; }
    }
    if ($ok) {
        $table = $kind === 'poll' ? 'polls' : 'games';
        db_run("UPDATE $table SET table_id = ? WHERE id = ?", [$target, $id]);
        log_action($kind . '_move', ($row['name'] ?? ('#' . $id)) . ' -> table #' . $target);
        redirect(front_url($activeDay, (int)($day['event_id'] ?? 0), '#') . $kind . '-' . $id);
    }
    $error = t('move_invalid');
}

tpl_render('header', ['page_title' => t('move_title')]);
tpl_render('move_confirm', [
    'kind'       => $kind,
    'row'        => $row,
    'tables'     => $tables,
    'active_day' => $activeDay,
    'error'      => $error ?? '',
    'csrf'       => csrf_field(),
    'antibot'    => antibot_field(),
]);
tpl_render('footer');
