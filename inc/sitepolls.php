<?php
/* =============================================================================
 *  inc/sitepolls.php — the admin's own opinion polls.
 * -----------------------------------------------------------------------------
 *  "Which evening suits you best?", "Should we buy Brass?" — questions an admin
 *  puts to the club, shown on the front page. NOT the game polls (inc/polls.php),
 *  which belong to an event, collect players and turn into a game. These decide
 *  nothing and belong to nothing; they just count.
 *
 *  Everything about who may vote, what counts as their vote, and when a poll is
 *  over lives HERE, so the admin tab and the front page cannot disagree.
 *
 *  WHO A VOTER IS: their account when logged in, otherwise the address the
 *  request came from. An address is a weak identity — a household or a café's
 *  wi-fi shares one, so a second person there will find "you already voted" —
 *  which is exactly why a poll can be limited to logged-in voters.
 * ============================================================================= */

/** Is the feature on at all? Off by default. */
function site_polls_enabled() {
    return opt_bool('site_polls_enabled');
}

/** 'top' or 'bottom' of the front page. */
function site_polls_position() {
    return opt('site_polls_position', 'top') === 'bottom' ? 'bottom' : 'top';
}

/**
 * Has this poll ended? Only a timed poll can: an unlimited one runs until an
 * admin switches it off. Compared in SQL's own clock, the same one that wrote
 * ends_at, so no timezone can come between them.
 *
 * @param array $poll
 * @return bool
 */
function sp_concluded($poll) {
    if (empty($poll['ends_at'])) return false;
    return (int)db_val("SELECT datetime('now') >= ?", [$poll['ends_at']]) === 1;
}

/**
 * Seconds left, or null for an unlimited poll. 0 once it has ended.
 *
 * @param array $poll
 * @return int|null
 */
function sp_seconds_left($poll) {
    if (empty($poll['ends_at'])) return null;
    $left = (int)db_val("SELECT CAST(strftime('%s', ?) AS INTEGER) - CAST(strftime('%s', 'now') AS INTEGER)",
                        [$poll['ends_at']]);
    return max(0, $left);
}

/** One poll, or null. */
function sp_get($pollId) {
    return db_one('SELECT * FROM site_polls WHERE id = ?', [(int)$pollId]);
}

/** The polls the front page shows: switched on, newest first. */
function sp_front_polls() {
    return db_all('SELECT * FROM site_polls WHERE is_enabled = 1 ORDER BY id DESC');
}

/**
 * A poll's answers.
 *
 * @param int  $pollId
 * @param bool $withPending  Include voters' answers still awaiting approval —
 *                           for the admin, never for the public.
 * @return array
 */
function sp_options($pollId, $withPending = false) {
    $where = $withPending ? '' : ' AND is_approved = 1';
    return db_all("SELECT * FROM site_poll_options WHERE poll_id = ?$where ORDER BY sort_order, id",
                  [(int)$pollId]);
}

/** Votes per option id: [option_id => count]. */
function sp_tally($pollId) {
    $out = [];
    foreach (db_all('SELECT option_id, COUNT(*) AS n FROM site_poll_votes WHERE poll_id = ? GROUP BY option_id',
                    [(int)$pollId]) as $r) {
        $out[(int)$r['option_id']] = (int)$r['n'];
    }
    return $out;
}

/**
 * The option ids with the most votes — more than one when they tie, since a
 * tie is a real result and picking one of them arbitrarily would misreport it.
 * Empty when nobody has voted: with no votes there is no winner to highlight.
 *
 * @param array $options  Rows from sp_options().
 * @param array $tally
 * @return int[]
 */
function sp_winners($options, $tally) {
    $best = 0;
    foreach ($options as $o) $best = max($best, $tally[(int)$o['id']] ?? 0);
    if ($best === 0) return [];
    $out = [];
    foreach ($options as $o) if (($tally[(int)$o['id']] ?? 0) === $best) $out[] = (int)$o['id'];
    return $out;
}

/**
 * Who is voting right now.
 *
 * @return array  ['user_id' => int|null, 'ip' => string|null] — exactly one set.
 */
function sp_voter() {
    $me = current_user();
    if ($me) return ['user_id' => (int)$me['id'], 'ip' => null];
    return ['user_id' => null, 'ip' => (string)client_ip()];
}

/**
 * The option ids THIS voter has chosen in a poll.
 *
 * @param int $pollId
 * @return int[]
 */
function sp_my_votes($pollId) {
    $v = sp_voter();
    $rows = $v['user_id'] !== null
        ? db_all('SELECT option_id FROM site_poll_votes WHERE poll_id = ? AND user_id = ?', [(int)$pollId, $v['user_id']])
        : db_all('SELECT option_id FROM site_poll_votes WHERE poll_id = ? AND user_id IS NULL AND voter_ip = ?',
                 [(int)$pollId, $v['ip']]);
    return array_map('intval', array_column($rows, 'option_id'));
}

/** May the current visitor vote in this poll at all? */
function sp_can_vote($poll) {
    if ((int)$poll['vote_login_only'] === 1 && !current_user()) return false;
    return true;
}

/** May the current visitor add an answer of their own? */
function sp_can_add_custom($poll) {
    if ((int)$poll['allow_custom'] !== 1) return false;
    if ((int)$poll['custom_login_only'] === 1 && !current_user()) return false;
    return sp_can_vote($poll);
}

/**
 * Create a poll with its answers.
 *
 * @param array    $f        Settings (see the form).
 * @param string[] $answers  Answer texts; blanks already removed.
 * @return int  The new poll's id.
 */
function sp_create($f, $answers) {
    $hours = max(0, (int)$f['length_hours']);
    // ends_at worked out ONCE, here, so a later change to the length (were one
    // ever allowed) could not quietly move a poll that people are voting in.
    $endsAt = $hours > 0 ? db_val("SELECT datetime('now', ?)", ['+' . $hours . ' hours']) : null;
    db_run('INSERT INTO site_polls (title, length_hours, ends_at, show_results, multi, vote_login_only,
                                    allow_custom, custom_login_only, custom_needs_approval)
            VALUES (?,?,?,?,?,?,?,?,?)',
           [$f['title'], $hours, $endsAt,
            (int)!empty($f['show_results']), (int)!empty($f['multi']), (int)!empty($f['vote_login_only']),
            (int)!empty($f['allow_custom']),
            // The two custom-answer refinements mean nothing without custom
            // answers, so they are stored off rather than left half-set.
            (int)(!empty($f['allow_custom']) && !empty($f['custom_login_only'])),
            (int)(!empty($f['allow_custom']) && !empty($f['custom_needs_approval']))]);
    $id = (int)db()->lastInsertId();
    foreach (array_values($answers) as $i => $text) {
        db_run('INSERT INTO site_poll_options (poll_id, text, sort_order) VALUES (?,?,?)', [$id, $text, $i]);
    }
    return $id;
}

/**
 * Cast this visitor's vote.
 *
 * SINGLE-answer polls take one vote per voter, and a second attempt is refused
 * rather than replacing the first — "one person, one vote" is the point of a
 * single-answer poll. MULTI-answer polls replace the voter's previous choices
 * with the new set, which is what lets the form come back pre-ticked and be
 * changed.
 *
 * @param array    $poll
 * @param int[]    $optionIds  Chosen existing answers.
 * @param string   $custom     A new answer typed in, or ''.
 * @return string  '' on success, otherwise a language key for the reason.
 */
function sp_cast($poll, $optionIds, $custom = '') {
    if ((int)$poll['is_enabled'] !== 1 || sp_concluded($poll)) return 'sp_err_closed';
    if (!sp_can_vote($poll))                                  return 'sp_err_login';

    $pollId = (int)$poll['id'];
    $multi  = (int)$poll['multi'] === 1;
    $already = sp_my_votes($pollId);
    if (!$multi && $already) return 'sp_err_already';

    /* Only this poll's own, APPROVED answers can be chosen. A posted id from
     * another poll, or one still awaiting approval that somebody else added,
     * is dropped rather than counted. */
    $valid = array_map('intval', array_column(sp_options($pollId), 'id'));
    $chosen = array_values(array_unique(array_intersect(array_map('intval', (array)$optionIds), $valid)));

    $custom = trim((string)$custom);
    if ($custom !== '') {
        if (!sp_can_add_custom($poll))                                   return 'sp_err_custom';
        if (!text_has_content($custom) || text_too_long($custom, TEXT_NAME_MAX)) return 'sp_err_custom_text';
        /* Typed something that is already an answer? Count it for that answer
         * instead of adding a duplicate that would split its votes. */
        $existing = null;
        foreach (sp_options($pollId, true) as $o) {
            if (mb_strtolower(trim($o['text'])) === mb_strtolower($custom)) { $existing = $o; break; }
        }
        if ($existing) {
            $newId = (int)$existing['id'];
        } else {
            $v = sp_voter();
            $next = (int)db_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM site_poll_options WHERE poll_id = ?', [$pollId]);
            db_run('INSERT INTO site_poll_options (poll_id, text, is_approved, added_by_user_id, added_by_ip, sort_order)
                    VALUES (?,?,?,?,?,?)',
                   [$pollId, $custom, (int)$poll['custom_needs_approval'] === 1 ? 0 : 1,
                    $v['user_id'], $v['ip'], $next]);
            $newId = (int)db()->lastInsertId();
            log_action('site_poll_custom', 'poll #' . $pollId . ' — ' . $custom);
        }
        $chosen[] = $newId;
        $chosen = array_values(array_unique($chosen));
    }

    if (!$chosen)                         return 'sp_err_nothing';
    if (!$multi && count($chosen) !== 1)  return 'sp_err_one';

    $v = sp_voter();
    db()->beginTransaction();
    try {
        // Multi-answer: the new set REPLACES the old one.
        if ($multi && $already) {
            if ($v['user_id'] !== null) {
                db_run('DELETE FROM site_poll_votes WHERE poll_id = ? AND user_id = ?', [$pollId, $v['user_id']]);
            } else {
                db_run('DELETE FROM site_poll_votes WHERE poll_id = ? AND user_id IS NULL AND voter_ip = ?',
                       [$pollId, $v['ip']]);
            }
        }
        foreach ($chosen as $oid) {
            db_run('INSERT INTO site_poll_votes (poll_id, option_id, user_id, voter_ip) VALUES (?,?,?,?)',
                   [$pollId, $oid, $v['user_id'], $v['ip']]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return '';
}
