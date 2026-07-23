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
function notify_enabled() {
    return opt_bool('send_emails');
}

/**
 * Distinct, non-empty player emails for a game.
 * Used by the "everyone signed up" triggers. DISTINCT so a duplicated address
 * isn't mailed twice; the IS NOT NULL / <> "" filter skips players with no email.
 * @param int $gameId
 * @return string[]
 */
function notify_player_emails($gameId) {
    $rows = db_all(
        'SELECT DISTINCT email FROM players
         WHERE game_id = ? AND email IS NOT NULL AND email <> ""', [$gameId]
    );
    return array_column($rows, 'email');   // flatten [['email'=>x],...] -> [x,...]
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
    if (!notify_enabled() || empty($game['brings_email'])) return;
    send_mail($game['brings_email'],
        t('ntf_signup_subject', $game['name']),
        t('ntf_signup_body', $playerName, $game['name']));
}

/**
 * Someone resigned from a game you're bringing. -> the game's bringer.
 */
function notify_resign($game, $playerName) {
    if (!notify_enabled() || empty($game['brings_email'])) return;
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
function notify_poll_concluded($emails, $gameName) {
    if (!notify_enabled()) return;
    foreach (array_unique(array_filter($emails)) as $to) {   // drop blanks, de-dup
        send_mail($to, t('ntf_poll_subject', $gameName), t('ntf_poll_body', $gameName));
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
    $rows = db_all('SELECT DISTINCT email FROM poll_votes WHERE poll_id = ? AND email IS NOT NULL AND email <> ""',
                   [$pollId]);
    return array_column($rows, 'email');
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
function notify_poll_changed($poll, $what, $emails = null) {
    if (!notify_enabled()) return;
    if ($emails === null) $emails = notify_poll_voter_emails((int)$poll['id']);
    foreach (array_unique(array_filter($emails)) as $to) {
        send_mail($to, t('ntf_pollchg_subject'), t('ntf_pollchg_body', $what));
    }
}
