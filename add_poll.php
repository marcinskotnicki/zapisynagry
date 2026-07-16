<?php
/* =============================================================================
 *  add_poll.php — create a poll on a table.
 * -----------------------------------------------------------------------------
 *  The poll is built up in the SESSION ($_SESSION['poll_draft']) so an
 *  unfinished poll never shows to other visitors, and is only written to the
 *  database on "Finish". This screen carries the poll-level fields
 *  (proposer/time/rules/comment/add-self) plus the candidate games added so far,
 *  with "add game to the poll", per-candidate remove, "cancel", and "Finish".
 *
 *  The 'do' field says which button was pressed:
 *    addgame   -> jump to add_poll_game.php to append a candidate
 *    rem:<idx> -> remove candidate #idx from the draft
 *    cancel    -> discard the draft
 *    finish    -> validate + persist the poll (and seed proposer self-votes)
 *  On every POST we first capture edits to the poll-level fields, so they're
 *  never lost regardless of which button was clicked.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/polls.php';
require __DIR__ . '/inc/notify.php';

$tableId = (int)($_GET['table'] ?? $_POST['table'] ?? 0);
$table   = $tableId ? db_one('SELECT * FROM game_tables WHERE id = ?', [$tableId]) : null;
if (!$table) { http_response_code(404); exit('Unknown table.'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$table['event_id']]);
$day   = db_one('SELECT * FROM event_days WHERE id = ?', [$table['day_id']]);

// Live event + polls enabled + permitted to add.
if (!$event || (int)$event['is_archived'] === 1 || !opt_bool('allow_polls') || !can_add_games()) {
    redirect('index.php');
}
$activeDay = (int)$day['day_index'];

// (Re)initialise the draft when there isn't one for THIS table (switching tables
// starts a fresh poll). Proposer name/email prefill from the logged-in user.
$u = current_user();
if (!isset($_SESSION['poll_draft']) || (int)($_SESSION['poll_draft']['table_id'] ?? 0) !== $tableId) {
    $_SESSION['poll_draft'] = [
        'table_id'      => $tableId,
        'name'          => $u['display_name'] ?? '',
        'email'         => $u['email'] ?? '',
        'comment'       => '',
        'start_time'    => $day['start_time'],
        'explain_rules' => 0,
        'add_self'      => 1,
        // Voting closes this many hours BEFORE the poll's start; admin default,
        // overridable per poll on the form. 0 = no automatic deadline.
        'deadline_hours' => opt_int('poll_default_deadline_hours'),
        'games'         => [],          // candidate list, each an assoc array
    ];
}
$draft = &$_SESSION['poll_draft'];     // reference: edits below mutate the session
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    // Whatever button was pressed, capture edits to the poll-level fields first
    // (so navigating to add a game doesn't drop the proposer's typed details).
    $draft['name']          = trim($_POST['name'] ?? '');
    $draft['email']         = trim($_POST['email'] ?? '');
    $draft['comment']       = trim($_POST['comment'] ?? '');
    $draft['start_time']    = is_valid_time($_POST['start_time'] ?? '') ? $_POST['start_time'] : $draft['start_time'];
    $draft['explain_rules'] = min(2, max(0, (int)($_POST['explain_rules'] ?? 0)));
    $draft['add_self']      = isset($_POST['add_self']) ? 1 : 0;
    $draft['deadline_hours'] = max(0, (int)($_POST['deadline_hours'] ?? $draft['deadline_hours']));

    if ($do === 'addgame') {
        redirect('add_poll_game.php?table=' . $tableId);   // go append a candidate

    } elseif (strpos($do, 'rem:') === 0) {
        // Remove a candidate by index (button value is "rem:<idx>").
        $idx = (int)substr($do, 4);
        if (isset($draft['games'][$idx])) {
            array_splice($draft['games'], $idx, 1);        // re-indexes the array
        }
        redirect('add_poll.php?table=' . $tableId);

    } elseif ($do === 'cancel') {
        unset($_SESSION['poll_draft']);                    // discard the whole draft
        redirect('index.php?day=' . $activeDay);

    } elseif ($do === 'finish') {
        if (empty($draft['games'])) {
            $error = t('poll_need_game');                  // a poll needs at least one candidate
        } elseif ($draft['email'] !== '' && !email_valid($draft['email'])) {
            $error = t('error_email_invalid');             // non-empty but not X@Y.Z-shaped
        } else {
            // Persist poll + candidates (+ optional proposer self-votes) atomically.
            db()->beginTransaction();
            try {
                // Deadline: N hours before the poll's planned start (day date +
                // start time). 0 hours = no automatic deadline (NULL). If the
                // computed moment is already in the past (poll created late),
                // clamp to +1 hour from now so a fresh poll always gets SOME
                // voting window instead of resolving on the very next pageview.
                $deadline = null;
                if ((int)$draft['deadline_hours'] > 0) {
                    $dayDate  = db_val('SELECT day_date FROM event_days WHERE id = ?', [$day['id']]);
                    $startTs  = strtotime(($dayDate ?: date('Y-m-d')) . ' ' . $draft['start_time']);
                    $deadTs   = $startTs - (int)$draft['deadline_hours'] * 3600;
                    if ($deadTs <= time()) $deadTs = time() + 3600;   // the clamp
                    $deadline = date('Y-m-d H:i:s', $deadTs);
                }
                db_run(
                    'INSERT INTO polls
                     (table_id,event_id,day_id,proposer_name,proposer_email,proposer_user_id,
                      comment,start_time,explain_rules,add_self,deadline)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $tableId, $event['id'], $day['id'],
                        $draft['name'] !== '' ? $draft['name'] : null,
                        $draft['email'] !== '' ? $draft['email'] : null,
                        $u['id'] ?? null,
                        $draft['comment'] !== '' ? $draft['comment'] : null,
                        $draft['start_time'], $draft['explain_rules'], $draft['add_self'],
                        $deadline,
                    ]
                );
                $pollId = (int)db()->lastInsertId();

                // Insert each candidate; collect their new ids for self-voting.
                $cg = db()->prepare(
                    'INSERT INTO poll_games
                     (poll_id,name,length_minutes,weight,max_players,thumbnail,bgg_id,language,required_players)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $candIds = [];
                foreach ($draft['games'] as $g) {
                    $cg->execute([
                        $pollId, $g['name'], $g['length_minutes'], $g['weight'], $g['max_players'],
                        $g['thumbnail'] !== '' ? $g['thumbnail'] : null,
                        $g['bgg_id'] ?: null,
                        $g['language'] !== '' ? $g['language'] : null,
                        $g['required_players'],
                    ]);
                    $candIds[] = (int)db()->lastInsertId();
                }

                // "Add yourself as first player" => one proposer vote per candidate.
                if ($draft['add_self'] && $draft['name'] !== '') {
                    $vstmt = db()->prepare(
                        'INSERT INTO poll_votes (poll_game_id,poll_id,name,email,knows_rules,user_id)
                         VALUES (?,?,?,?,?,?)'
                    );
                    foreach ($candIds as $cid) {
                        $vstmt->execute([
                            $cid, $pollId, $draft['name'],
                            $draft['email'] !== '' ? $draft['email'] : null,
                            null, $u['id'] ?? null,
                        ]);
                    }
                }
                db()->commit();
            } catch (Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $error = $ex->getMessage();
            }

            if ($error === null) {
                log_action('poll_create', $draft['name'] . ' (' . count($draft['games']) . ' games)');
                unset($_SESSION['poll_draft']);            // draft persisted -> clear it
                // Seeded votes might already settle a candidate (e.g. required 1),
                // in which case this resolves the poll into a game immediately —
                // then jump to the game card; otherwise jump to the new poll.
                $newGameId = poll_check_resolve($pollId);
                if ($newGameId) {
                    redirect('index.php?day=' . $activeDay . '#game-' . $newGameId);
                }
                redirect('index.php?day=' . $activeDay . '#poll-' . $pollId);
            }
        }
    }
}

tpl_render('header', ['page_title' => t('addpoll_title')]);
tpl_render('poll_main', [
    'table' => $table,
    'draft' => $draft,
    'error' => $error,
    'csrf'  => csrf_field(),
]);
tpl_render('footer');
