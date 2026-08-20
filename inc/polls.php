<?php
/* =============================================================================
 *  inc/polls.php — poll vote tallying and resolution.
 * -----------------------------------------------------------------------------
 *  A poll holds candidate games (poll_games); people vote (poll_votes) for one
 *  or more candidates. Unless the poll opted to wait for its deadline (the
 *  wait_for_deadline column), the moment a candidate's votes reach its required_players
 *  threshold, the poll RESOLVES: that candidate becomes a real game on the table,
 *  everyone who voted for it is assigned as a player (overflow past max_players
 *  goes to the reserve list), and the whole poll is deleted.
 *
 *  DATA MODEL: polls 1—* poll_games (candidates) 1—* poll_votes. Deleting the
 *  poll cascades both children away (FK ON DELETE CASCADE in database.sql),
 *  which is how resolution "cleans up" in one statement.
 *
 *  WHO CALLS WHAT: vote.php records a vote then calls poll_check_resolve();
 *  add_poll.php also calls it on "Finish" in case seeded proposer self-votes
 *  already meet a threshold. poll_full() is the read side, used by the templates.
 * ============================================================================= */

/**
 * Vote count for a candidate.
 * @param int $pollGameId  A poll_games row id.
 * @return int
 */
function poll_candidate_votes($pollGameId) {
    return (int)db_val('SELECT COUNT(*) FROM poll_votes WHERE poll_game_id = ?', [$pollGameId]);
}

/**
 * Has the given (logged-in) user already voted for this candidate?
 * Guests (no user id) always return false — guest double-voting isn't tracked
 * by account, and the UI only offers vote-cancel to logged-in users.
 * @return bool
 */
function poll_user_voted($pollGameId, $userId) {
    if (!$userId) return false;
    return (bool)db_val('SELECT 1 FROM poll_votes WHERE poll_game_id = ? AND user_id = ?',
                        [$pollGameId, $userId]);
}

/**
 * Check every candidate in a poll; if one has reached its threshold, resolve it.
 * Returns the new game id on resolution, or null if nothing met the threshold.
 *
 * Candidates are checked in id order, so on the rare turn where two cross the
 * line at once, the earliest-created candidate wins.
 *
 * @param int $pollId
 * @return int|null  New game id, or null.
 */
function poll_check_resolve($pollId) {
    $poll = db_one('SELECT * FROM polls WHERE id = ?', [$pollId]);
    if (!$poll) return null;

    // The proposer can ask for the poll to run its full course, so that every
    // candidate keeps collecting votes instead of the first one to fill ending
    // it for everybody. Then only the deadline sweep (or a manual "end voting
    // now") resolves it.
    //
    // DELIBERATELY conditional on there BEING a deadline: honouring the flag
    // without one would leave a poll with no way to ever finish by itself. With
    // no deadline set we fall through to the normal threshold trigger, which is
    // the behaviour the poll would have had anyway.
    if ((int)($poll['wait_for_deadline'] ?? 0) === 1 && !empty($poll['deadline'])) {
        return null;
    }

    $cands = db_all('SELECT * FROM poll_games WHERE poll_id = ? ORDER BY id', [$pollId]);
    foreach ($cands as $c) {
        if (poll_candidate_votes($c['id']) >= (int)$c['required_players']) {
            return poll_resolve_candidate($poll, $c);
        }
    }
    return null;
}

/**
 * Turn a winning candidate into a real game, assign its voters, delete the poll.
 * Poll-level attributes (proposer, time, rules, comment) carry onto the game.
 * Returns the new game id, or null on failure.
 *
 * Runs in a TRANSACTION so the three steps (create game, assign players, delete
 * poll) are all-or-nothing — a failure rolls back and leaves the poll intact.
 *
 * @param array $poll  The polls row.
 * @param array $cand  The winning poll_games row.
 * @return int|null
 */
function poll_resolve_candidate($poll, $cand) {
    db()->beginTransaction();
    try {
        // 1) Create the real game from candidate details + poll-level attributes.
        //    The proposer becomes both the bringer and the added_by owner.
        db_run(
            'INSERT INTO games
             (table_id,event_id,day_id,name,length_minutes,weight,max_players,start_time,
              thumbnail,bgg_id,language,brings_name,brings_email,brings_user_id,explain_rules,require_email,comment,added_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $poll['table_id'], $poll['event_id'], $poll['day_id'],
                $cand['name'], $cand['length_minutes'], $cand['weight'], $cand['max_players'],
                $poll['start_time'],
                $cand['thumbnail'] ?: null, $cand['bgg_id'] ?: null,
                $cand['language'] ?: null,
                $poll['proposer_name'] ?: null, $poll['proposer_email'] ?: null,
                $poll['proposer_user_id'], $poll['explain_rules'],
                (int)($poll['require_email'] ?? 0),   // the email rule survives resolution
                $poll['comment'] ?: null, $poll['proposer_user_id'],
            ]
        );
        $gameId = (int)db()->lastInsertId();

        // 2) Assign voters in vote order; overflow past capacity -> reserve.
        $voters = db_all('SELECT * FROM poll_votes WHERE poll_game_id = ? ORDER BY id', [$cand['id']]);
        $max = (int)$cand['max_players'];
        $i = 0;
        $stmt = db()->prepare(
            'INSERT INTO players (game_id,name,email,knows_rules,is_reserve,user_id) VALUES (?,?,?,?,?,?)'
        );
        $notifyEmails = [];                       // gather before the poll (and its votes) vanish
        foreach ($voters as $v) {
            if (!empty($v['email'])) $notifyEmails[] = $v['email'];   // for the conclusion email
            $isReserve = ($i >= $max) ? 1 : 0;    // first $max voters confirmed, rest reserve
            $i++;
            $stmt->execute([$gameId, $v['name'], $v['email'], $v['knows_rules'], $isReserve, $v['user_id']]);
        }
        if (!empty($poll['proposer_email'])) $notifyEmails[] = $poll['proposer_email'];

        // 3) Removing the poll cascades its candidates and votes away.
        /* THE FINAL TALLY, as the first comment on the game.
         *
         * Written as a comment rather than into the game card because every one
         * of the fourteen themes forks that card: a new field would mean
         * fourteen edits and fourteen chances to get one wrong, for something
         * that is a few lines of text. The comment thread already renders
         * everywhere, in every theme, and already survives resolution.
         *
         * FIRST, before the discussion is moved across, so it heads the thread
         * rather than being buried under whatever was said while voting.
         *
         * Read BEFORE the delete below: removing the poll cascades its
         * candidates and votes away, so this is the last moment the numbers
         * exist at all. */
        if (!empty($poll['show_results'])) {
            $tally = db_all(
                'SELECT g.name AS name, COUNT(v.id) AS votes
                   FROM poll_games g
                   LEFT JOIN poll_votes v ON v.poll_game_id = g.id
                  WHERE g.poll_id = ?
                  GROUP BY g.id
                  ORDER BY votes DESC, g.name COLLATE NOCASE ASC',
                [$poll['id']]
            );
            if ($tally) {
                $lines = [];
                foreach ($tally as $row) {
                    $lines[] = $row['name'] . ' — ' . (int)$row['votes'];
                }
                /* No user_id, and the name left blank: nobody said this, and
                 * attributing it to the proposer would put words in their
                 * mouth. The templates already cope with an empty name. */
                db_run('INSERT INTO comments (game_id, name, user_id, comment) VALUES (?,?,?,?)',
                       [$gameId, '', null, t('poll_results_heading') . "\n" . implode("\n", $lines)]);
            }
        }

        // Move the poll's discussion onto the game it became, so the thread
        // isn't lost when the poll row (and its cascade) goes away.
        foreach (db_all('SELECT * FROM poll_comments WHERE poll_id = ? ORDER BY id', [$poll['id']]) as $pc) {
            db_run('INSERT INTO comments (game_id, name, user_id, comment, created_at) VALUES (?,?,?,?,?)',
                   [$gameId, $pc['name'], $pc['user_id'], $pc['comment'], $pc['created_at']]);
        }
        db_run('DELETE FROM polls WHERE id = ?', [$poll['id']]);
        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();   // leave everything as it was
        return null;
    }

    log_action('poll_resolved', $cand['name']);
    // Notify outside the transaction (sending mail shouldn't be able to roll back
    // a committed resolution). function_exists guard: notify.php may not be loaded
    // in every entry point that can resolve a poll.
    if (function_exists('notify_poll_concluded')) {
        notify_poll_concluded($notifyEmails, $cand['name']);
    }
    return $gameId;
}

/**
 * Load a poll with its candidates for display. Each candidate gets 'votes' and
 * 'voted' (whether the current logged-in user voted for it).
 *
 * This is the READ side used by the poll templates; it does no writes.
 *
 * @param array $pollRow  A polls row.
 * @return array  Same row plus ['games'] = candidates each with votes/voted.
 */
/**
 * May the current visitor add a candidate game to this poll?
 *
 * Three ways in:
 *   - they're the proposer's account or an admin (verify_decision 'allow'),
 *   - they've already passed the poll's verification gate this session
 *     (edit_poll.php sets that), or
 *   - the proposer ticked "let others add games" AND the visitor is allowed to
 *     add games at all.
 *
 * The opt-in is deliberately one-way: others may ADD, only the proposer (or an
 * admin) may REMOVE, so the poll's owner keeps the final say over its contents.
 *
 * @param array $poll  A polls row.
 * @return bool
 */
/**
 * Is the "let others add games" opt-in worth showing?
 *
 * It only means something when somebody would otherwise be BLOCKED from adding.
 * Two cases where nobody is:
 *   - the admin set verification to 'none', so verify_decision() says 'allow'
 *     to everyone for all guest-created content, and
 *   - this particular poll has no owner AND no email to verify against, so
 *     there's nothing to check the visitor against — anyone may already edit or
 *     delete it, exactly like an ownerless game.
 * In both cases the checkbox would promise a restriction the site isn't
 * enforcing, so it's hidden rather than lying to the proposer.
 *
 * @param array|null $poll  A polls row, or null on the creation form (no row yet).
 * @return bool
 */
function poll_optin_relevant($poll = null) {
    if (opt('verification_method') === 'none') return false;
    if ($poll !== null
        && ($poll['proposer_user_id'] === null || $poll['proposer_user_id'] === '')
        && empty($poll['proposer_email'])) {
        return false;   // ownerless: nothing to verify against
    }
    return true;
}

/**
 * Is this poll restricted to the proposer's own library?
 *
 * Three things have to hold, and each removes a way for the setting to be a
 * dead end rather than a restriction:
 *
 *   - the proposer opted in to letting others add at all. Restricting a door
 *     nobody may use says nothing.
 *   - they asked for it.
 *   - they still have an ACCOUNT with a library that has games in it. A poll
 *     proposed by a guest has no library to draw on, and one whose owner
 *     emptied theirs since would leave everybody else with nothing to pick —
 *     which reads as the site being broken rather than as a rule.
 *
 * The last is checked live rather than trusted from the poll row, because a
 * library changes long after the poll is made.
 *
 * @param array $poll
 * @return bool
 */
function poll_others_from_library($poll) {
    if ((int)($poll['allow_others_add'] ?? 0) !== 1)    return false;
    if ((int)($poll['others_from_library'] ?? 0) !== 1) return false;

    require_once __DIR__ . '/library.php';
    $owner = (int)($poll['proposer_user_id'] ?? 0);
    return $owner > 0 && library_own_pick_enabled($owner);
}

/**
 * May the proposer be OFFERED that restriction?
 *
 * Same conditions minus the setting itself — used by the forms, so the
 * checkbox appears only where ticking it would mean something.
 *
 * @param array|null $poll     Existing poll, or null while creating one.
 * @param int        $userId   Who is proposing.
 * @return bool
 */
function poll_can_restrict_to_library($poll, $userId) {
    require_once __DIR__ . '/library.php';
    $owner = $poll !== null ? (int)($poll['proposer_user_id'] ?? 0) : (int)$userId;
    return $owner > 0 && library_own_pick_enabled($owner);
}

function poll_can_add_candidate($poll) {
    require_once __DIR__ . '/verify.php';   // verify_decision()
    require_once __DIR__ . '/events.php';   // can_add_games()
    if (verify_decision($poll['proposer_user_id'], $poll['proposer_email']) === 'allow') return true;
    if (!empty($_SESSION['poll_edit_ok'][(int)$poll['id']])) return true;
    return (int)($poll['allow_others_add'] ?? 0) === 1 && can_add_games();
}

function poll_full($pollRow) {
    $uid = current_user()['id'] ?? null;
    $cands = db_all('SELECT * FROM poll_games WHERE poll_id = ? ORDER BY id', [$pollRow['id']]);
    $showVoters = !empty($pollRow['show_results']);
    foreach ($cands as &$c) {
        $c['votes'] = poll_candidate_votes($c['id']);
        $c['voted'] = poll_user_voted($c['id'], $uid);
        /* Only fetched when the proposer asked for it — otherwise the names
         * would sit in the render data for a template to leak by accident, and
         * they are the one part of a poll that is nobody else's business by
         * default. Names only; the email a guest gave when voting is not the
         * club's to publish. */
        $c['voters'] = $showVoters
            ? array_column(db_all('SELECT name FROM poll_votes WHERE poll_game_id = ? ORDER BY id',
                                  [$c['id']]), 'name')
            : [];
    }
    unset($c);                                    // break the reference from the loop
    $pollRow['games'] = $cands;
    // Discussion thread, oldest first — mirrors how a game card shows comments.
    $pollRow['comments'] = db_all('SELECT * FROM poll_comments WHERE poll_id = ? ORDER BY id',
                                  [$pollRow['id']]);
    return $pollRow;
}

/* =============================================================================
 *  DEADLINE RESOLUTION — polls that must conclude even without a full table.
 * -----------------------------------------------------------------------------
 *  Each poll may carry a `deadline` ('Y-m-d H:i:s', server time), computed at
 *  creation as N hours BEFORE the poll's planned start (admin default in the
 *  'poll_default_deadline_hours' option, overridable per poll). Once it passes,
 *  the poll resolves to the CURRENT LEADER instead of waiting for a threshold:
 *    winner = highest fill ratio (votes / required_players);
 *    tie    -> the earlier-created candidate (lower id) wins.
 *  There is no cron on shared hosting, so the sweep runs opportunistically on
 *  page visits (index.php calls poll_resolve_expired()).
 * ============================================================================= */

/**
 * Pick the deadline winner of a poll: best fill ratio, ties broken by lower id.
 * Returns the winning poll_games row, or null if the poll has no candidates.
 * @param int $pollId
 * @return array|null
 */
function poll_pick_winner($pollId) {
    $cands = db_all('SELECT * FROM poll_games WHERE poll_id = ? ORDER BY id', [$pollId]);
    $best = null;
    $bestRatio = -1.0;
    foreach ($cands as $c) {
        // required_players >= 1 is enforced on input, but guard the division anyway.
        $ratio = poll_candidate_votes($c['id']) / max(1, (int)$c['required_players']);
        if ($ratio > $bestRatio) {                 // strictly better only: on a tie the
            $bestRatio = $ratio;                   // earlier candidate (lower id, seen
            $best = $c;                            // first in this ORDER BY id loop) stays
        }
    }
    return $best;
}

/**
 * Resolve a poll RIGHT NOW to its current leader (deadline hit, or the proposer
 * ended it early). Reuses poll_resolve_candidate(), so voters become players and
 * the poll is cleaned up exactly like a threshold resolution.
 * @param int $pollId
 * @return int|null  The new game id, or null (no such poll / no candidates / failure).
 */
function poll_force_resolve($pollId) {
    $poll = db_one('SELECT * FROM polls WHERE id = ?', [$pollId]);
    if (!$poll) return null;
    $winner = poll_pick_winner($pollId);
    if (!$winner) return null;                     // a poll with zero candidates can't resolve
    return poll_resolve_candidate($poll, $winner);
}

/**
 * Sweep: resolve every poll whose deadline has passed. Called on normal page
 * visits (poor man's cron) — cheap when nothing is due (one indexed-ish SELECT).
 * @return int  How many polls were resolved.
 */
function poll_resolve_expired() {
    $due = db_all(
        'SELECT id FROM polls WHERE deadline IS NOT NULL AND deadline <= ?',
        [date('Y-m-d H:i:s')]                      // server-local time, same clock the deadline was written with
    );
    $n = 0;
    foreach ($due as $row) {
        if (poll_force_resolve((int)$row['id'])) $n++;
    }
    return $n;
}

/* ---- Deadlines ------------------------------------------------------------
 *  A poll's deadline is stored as an absolute moment, but everywhere a human
 *  touches it it's expressed as "N hours before the poll starts" — so these
 *  three helpers are the single place that converts between the two. add_poll.php
 *  (creation) and edit_poll.php (later changes) both go through them, which is
 *  what keeps a poll's deadline following its start time when that start moves.
 *
 *  All of them need day_rel_min()/hhmm_to_min() from inc/events.php; every
 *  caller already requires it.
 * ------------------------------------------------------------------------- */

/**
 * The absolute moment a poll starts, as a unix timestamp.
 *
 * NOT simply "day_date + start_time": on an overnight day (18:00 -> 03:00) a
 * 01:00 start belongs to the NEXT calendar date, and day_rel_min() is the same
 * helper the timeline and the event-hours check use, so all three agree.
 *
 * @param array  $dayRow     The event_days row (day_date / start_time).
 * @param string $startTime  The poll's 'HH:MM' start.
 * @return int               Unix timestamp.
 */
function poll_start_ts($dayRow, $startTime) {
    $base = strtotime((($dayRow['day_date'] ?? '') ?: date('Y-m-d')) . ' 00:00');
    return $base + day_rel_min($startTime, hhmm_to_min($dayRow['start_time'])) * 60;
}

/**
 * Turn "N hours before the start" into the datetime string stored in
 * polls.deadline. 0 (or less) means no automatic deadline at all -> null.
 *
 * THE CLAMP: if the computed moment has already passed (a poll created — or
 * re-timed — late), it becomes an hour from now, so a poll always gets SOME
 * voting window instead of resolving on the very next page view.
 *
 * @param array  $dayRow
 * @param string $startTime
 * @param int    $hours
 * @return string|null  'Y-m-d H:i:s', or null for "no deadline".
 */
function poll_deadline_from_hours($dayRow, $startTime, $hours) {
    $hours = (int)$hours;
    if ($hours <= 0) return null;
    $ts = poll_start_ts($dayRow, $startTime) - $hours * 3600;
    if ($ts <= time()) $ts = time() + 3600;        // the clamp
    return date('Y-m-d H:i:s', $ts);
}

/**
 * The inverse: how many hours before its start is this poll's deadline? Used to
 * fill the edit form's number field from a stored absolute deadline.
 *
 * Rounded to whole hours (the field only takes whole hours) and never negative.
 * A poll with no deadline reports 0, which is exactly what the field means.
 *
 * @param array $poll
 * @param array $dayRow
 * @return int
 */
function poll_deadline_hours($poll, $dayRow) {
    if (empty($poll['deadline'])) return 0;
    $diff = poll_start_ts($dayRow, $poll['start_time']) - strtotime($poll['deadline']);
    return max(0, (int)round($diff / 3600));
}
