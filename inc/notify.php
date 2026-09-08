<?php
/* =============================================================================
 *  inc/notify.php — email notifications.
 * -----------------------------------------------------------------------------
 *  One function per spec trigger. Every one is a no-op unless the 'send_emails'
 *  toggle is on, so callers can fire them unconditionally (no `if` at the call
 *  site). Bodies come from the language file so notifications are localized to
 *  the app's default language.
 *
 *  These are NOTIFICATIONS (gated by send_emails). Contrast with transactional
 *  mail — verification codes (inc/verify.php) and password resets (recover.php) —
 *  which always send because the user explicitly asked for them.
 *
 *  ADDING A NEW NOTIFICATION: write a notify_xxx() here that early-returns unless
 *  notify_enabled(), add ntf_* subject/body keys to every language file, then
 *  call it from the relevant controller (require inc/notify.php there first).
 * ============================================================================= */
require_once __DIR__ . '/mail.php';   // send_mail() — require_once so double-include is safe

/**
 * Are notifications switched on at all? The single gate every trigger checks.
 * @return bool
 */
/* The mode helpers — notify_mode(), notify_enabled(), notify_user_choice() and
 * notify_default_on() — live in inc/helpers.php, not here. They are asked from
 * auth.php and mailing.php, which load on every request, and pulling this file
 * (and mail.php behind it) into those just to read one option would cost every
 * page a pair of includes it has no other use for. */


/**
 * Does THIS person want the mail, given what the club allows?
 *
 * Under 'always' the stored flag is ignored entirely — the club has said
 * everyone is written to, and a flag left over from a spell of letting people
 * choose must not quietly silence them. Under 'user_yes'/'user_no' the flag is
 * the answer. ('never' never reaches here; the triggers stop earlier.)
 *
 * @param mixed $flag  The row's notify column.
 * @return bool
 */
function notify_wants($flag) {
    if (!notify_user_choice()) return true;
    return (int)$flag === 1;
}

/**
 * The value to store for a newly submitted form.
 *
 * Under 'always'/'never' nothing is asked and 1 is stored, so that a club which
 * later starts letting people choose finds everyone opted IN rather than
 * everyone silently opted out.
 *
 * @param array  $post   The submitted form.
 * @param string $field  Checkbox name.
 * @return int  0 or 1.
 */
/**
 * How the opt-in box should start out for the person filling in the form.
 *
 * The club's default, unless this member has answered before — in which case
 * their last answer is the better guess, and being asked the same question
 * every single time is what makes a checkbox annoying.
 *
 * REMEMBERED FOR ACCOUNTS ONLY. A guest has nowhere to keep it: a cookie would
 * be a fourth thing to reason about, and guests already re-type their name each
 * time. They get the club's default every time, which is the honest fallback.
 *
 * @return bool
 */
function notify_optin_default() {
    $me = current_user();
    if ($me && isset($me['pref_notify']) && $me['pref_notify'] !== null) {
        return (int)$me['pref_notify'] === 1;
    }
    return notify_default_on();
}

/**
 * Remember this answer for next time, for a logged-in person.
 *
 * Called after a successful submission only, so a form that was refused never
 * teaches the site the wrong preference.
 *
 * @param int $flag  0 or 1, as stored on the row.
 * @return void
 */
function notify_remember_choice($flag) {
    if (!notify_user_choice()) return;   // nothing was asked
    $me = current_user();
    if (!$me) return;                    // a guest has nowhere to keep it
    db_run('UPDATE users SET pref_notify = ? WHERE id = ?', [(int)$flag === 1 ? 1 : 0, (int)$me['id']]);
}

function notify_flag_from_post($post, $field = 'notify_me') {
    if (!notify_user_choice()) return 1;
    return !empty($post[$field]) ? 1 : 0;
}

/**
 * Distinct, non-empty player emails for a game.
 * Used by the "everyone signed up" triggers. DISTINCT so a duplicated address
 * isn't mailed twice; the IS NOT NULL / <> "" filter skips players with no email.
 * @param int $gameId
 * @return string[]
 */
function notify_player_emails($gameId) {
    /* The flag comes back with the address and is applied in PHP rather than in
     * the WHERE clause: whether it matters at all depends on the club's mode,
     * and one query that always returns the same rows is easier to reason about
     * than a condition that appears and disappears. */
    $rows = db_all(
        'SELECT DISTINCT email, notify FROM players
         WHERE game_id = ? AND email IS NOT NULL AND email <> ""', [$gameId]
    );
    $out = [];
    foreach ($rows as $r) {
        if (notify_wants($r['notify'])) $out[] = $r['email'];
    }
    return $out;
}

/* ---- Triggers ------------------------------------------------------------ *
 * Each maps one event in the app to one outgoing email (or a batch). The owner
 * triggers go to the game's bringer; the player triggers go to everyone signed
 * up. All early-return when notifications are off or there's no recipient.
 * --------------------------------------------------------------------------- */

/**
 * Someone signed up for a game you're bringing. -> the game's bringer.
 * @param array  $game        Game row (needs ['brings_email'], ['name']).
 * @param string $playerName  Who just signed up.
 */
function notify_signup($game, $playerName) {
    // notify_owner: the bringer's own answer, when the club lets people choose.
    if (!notify_enabled() || empty($game['brings_email'])
        || !notify_wants($game['notify_owner'] ?? 1)) return;
    send_mail($game['brings_email'],
        t('ntf_signup_subject', $game['name']),
        t('ntf_signup_body', $playerName, $game['name']));
}

/**
 * Someone resigned from a game you're bringing. -> the game's bringer.
 */
function notify_resign($game, $playerName) {
    // notify_owner: the bringer's own answer, when the club lets people choose.
    if (!notify_enabled() || empty($game['brings_email'])
        || !notify_wants($game['notify_owner'] ?? 1)) return;
    send_mail($game['brings_email'],
        t('ntf_resign_subject', $game['name']),
        t('ntf_resign_body', $playerName, $game['name']));
}

/**
 * A game you signed up for was deleted. -> every signed-up player with an email.
 * CALL THIS BEFORE the delete: once the game row is gone (delete-everything
 * cascades players away), notify_player_emails() would find nobody.
 */
function notify_game_deleted($game) {
    if (!notify_enabled()) return;
    foreach (notify_player_emails($game['id']) as $to) {
        send_mail($to, t('ntf_deleted_subject', $game['name']), t('ntf_deleted_body', $game['name']));
    }
}

/**
 * A game you signed up for was brought back. -> every signed-up player.
 */
function notify_game_undeleted($game) {
    if (!notify_enabled()) return;
    foreach (notify_player_emails($game['id']) as $to) {
        send_mail($to, t('ntf_undeleted_subject', $game['name']), t('ntf_undeleted_body', $game['name']));
    }
}

/**
 * A game you signed up for had its start time changed. -> every signed-up player.
 * @param string $newStart  New "HH:MM" start (shown in the body).
 */
function notify_starttime_changed($game, $newStart) {
    if (!notify_enabled()) return;
    foreach (notify_player_emails($game['id']) as $to) {
        send_mail($to, t('ntf_time_subject', $game['name']), t('ntf_time_body', $game['name'], $newStart));
    }
}

/**
 * You were promoted from the reserve list to the confirmed list. -> that player.
 * Called once per promoted player by the resign handler.
 * @param string|null $playerEmail
 * @param string      $gameName
 */
function notify_promoted($playerEmail, $gameName) {
    if (!notify_enabled() || !$playerEmail) return;
    send_mail($playerEmail, t('ntf_promoted_subject', $gameName), t('ntf_promoted_body', $gameName));
}

/**
 * A poll you voted for (or proposed) concluded. -> voters + proposer.
 *
 * $emails is collected by the caller BEFORE the poll (and its votes) are deleted,
 * because the data is gone afterwards. We de-duplicate and drop blanks here so
 * the caller can pass a raw list.
 *
 * @param string[] $emails    Voter emails + proposer email (may contain dups/blanks).
 * @param string   $gameName  The game the poll resolved into.
 */
function notify_poll_concluded($emails, $gameName, $when = '') {
    if (!notify_enabled()) return;
    /* WHEN, not just what. Somebody at a club running several events may have
     * voted in more than one poll, and "the game X is now scheduled" tells them
     * nothing about which evening to turn up for.
     *
     * Falls back to the shorter wording when the caller has no date to give,
     * so an older call site cannot produce a sentence with a hole in it. */
    $body = $when !== ''
        ? t('ntf_poll_body_when', $gameName, $when)
        : t('ntf_poll_body', $gameName);
    foreach (array_unique(array_filter($emails)) as $to) {   // drop blanks, de-dup
        send_mail($to, t('ntf_poll_subject', $gameName), $body);
    }
}

/**
 * Everyone who has voted in a poll, by email. Blanks and duplicates removed —
 * guests who voted without an email simply can't be reached.
 *
 * @param int $pollId
 * @return string[]
 */
function notify_poll_voter_emails($pollId) {
    $rows = db_all('SELECT DISTINCT email, notify FROM poll_votes
                     WHERE poll_id = ? AND email IS NOT NULL AND email <> ""', [$pollId]);
    $out = [];
    foreach ($rows as $r) {
        if (notify_wants($r['notify'])) $out[] = $r['email'];
    }
    return $out;
}

/**
 * Tell current voters that the poll they're in has changed — a candidate was
 * added or removed, or the start time moved. $what is an already-translated
 * one-line description of the change.
 *
 * Called AFTER the change is written, but note the caller must gather the
 * emails BEFORE a removal if the removed candidate's voters should hear about
 * it (deleting a candidate cascades its votes away).
 *
 * @param array    $poll   The polls row.
 * @param string   $what   Human-readable description of what changed.
 * @param string[] $emails Recipients (defaults to the poll's current voters).
 * @return void
 */
/**
 * A poll you voted in was deleted. -> everyone who voted in it.
 *
 * MUST be called BEFORE the delete: the votes cascade away with the poll, so
 * afterwards there is no way left to find who to tell.
 * @param array $poll
 * @return void
 */
function notify_poll_deleted($poll) {
    if (!notify_enabled()) return;
    foreach (notify_poll_voter_emails((int)$poll['id']) as $to) {
        send_mail($to, t('ntf_polldel_subject'), t('ntf_polldel_body'));
    }
}

/**
 * Somebody commented on a game you are involved in. -> everyone signed up, plus
 * the person bringing it.
 *
 * WHY THE BRINGER IS ADDED SEPARATELY: they are not necessarily on the player
 * list. Plenty of people bring a game to teach it and never take a seat, and
 * they are exactly who a comment like "can you bring the expansion too?" is
 * aimed at.
 *
 * NOT SENT TO THE AUTHOR. Being emailed your own comment reads as a bug, and it
 * is the one address we can identify with any confidence. Matched on the
 * address, not the name: names are free text and two people called Marek would
 * otherwise silence each other.
 *
 * The comment text is deliberately NOT quoted in the email. It is written for
 * the people around that table, it can be edited or removed afterwards, and a
 * copy sitting in an inbox cannot be. The mail says where to look.
 *
 * @param array  $game         Game row (needs 'id', 'name', 'brings_email').
 * @param string $authorName   Who wrote it, for the body.
 * @param string $authorEmail  Their address, so they are not told about
 *                             themselves; '' when they left none.
 * @return void
 */
function notify_comment_added($game, $authorName, $authorEmail = '') {
    if (!notify_enabled()) return;
    foreach (notify_comment_recipients($game, $authorEmail) as $addr) {
        send_mail($addr,
            t('ntf_comment_subject', $game['name']),
            t('ntf_comment_body', $authorName, $game['name']));
    }
}

/**
 * WHO hears about a comment on a game — separated from the sending so the rule
 * can be checked directly. Mail itself leaves no trace a test can inspect (it
 * goes to SMTP or nowhere), and "did the right people get told" is the part
 * worth being sure of.
 *
 * @param array  $game
 * @param string $authorEmail  Skipped; '' when the author left no address.
 * @return string[]  Distinct addresses, in no particular order.
 */
function notify_comment_recipients($game, $authorEmail = '') {
    $to = notify_player_emails((int)$game['id']);
    if (!empty($game['brings_email']) && notify_wants($game['notify_owner'] ?? 1)) {
        $to[] = $game['brings_email'];
    }

    $out = [];
    foreach (array_unique(array_filter($to)) as $addr) {
        if ($authorEmail !== '' && strcasecmp($addr, $authorEmail) === 0) continue;
        $out[] = $addr;
    }
    return $out;
}

/**
 * Somebody commented on a poll you voted in. -> every voter, plus the proposer.
 *
 * Same shape and same reasoning as notify_comment_added() above: the proposer
 * may not have voted, the author is skipped, and the text itself is not
 * carried into the email.
 *
 * @param array  $poll         Poll row (needs 'id', 'proposer_email').
 * @param string $authorName
 * @param string $authorEmail
 * @return void
 */
function notify_poll_comment_added($poll, $authorName, $authorEmail = '') {
    if (!notify_enabled()) return;
    foreach (notify_poll_comment_recipients($poll, $authorEmail) as $addr) {
        send_mail($addr,
            t('ntf_pollcomment_subject'),
            t('ntf_pollcomment_body', $authorName));
    }
}

/**
 * WHO hears about a comment on a poll. Same split, same reason as
 * notify_comment_recipients() above.
 *
 * @param array  $poll
 * @param string $authorEmail
 * @return string[]
 */
function notify_poll_comment_recipients($poll, $authorEmail = '') {
    $to = notify_poll_voter_emails((int)$poll['id']);
    if (!empty($poll['proposer_email']) && notify_wants($poll['notify_owner'] ?? 1)) {
        $to[] = $poll['proposer_email'];
    }

    $out = [];
    foreach (array_unique(array_filter($to)) as $addr) {
        if ($authorEmail !== '' && strcasecmp($addr, $authorEmail) === 0) continue;
        $out[] = $addr;
    }
    return $out;
}

function notify_poll_changed($poll, $what, $emails = null) {
    if (!notify_enabled()) return;
    if ($emails === null) $emails = notify_poll_voter_emails((int)$poll['id']);
    foreach (array_unique(array_filter($emails)) as $to) {
        send_mail($to, t('ntf_pollchg_subject'), t('ntf_pollchg_body', $what));
    }
}
