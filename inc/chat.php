<?php
/* =============================================================================
 *  inc/chat.php — the shoutbox.
 * -----------------------------------------------------------------------------
 *  A single global message log rendered in a slide-out panel, polled over AJAX
 *  while the panel is open. Deliberately NOT per-event/per-table: it is one
 *  room for the whole site, which is what a shoutbox is.
 *
 *  Every function here assumes NOTHING about who is calling. chat_enabled() is
 *  checked by the endpoint, not by these, so the trim/wipe helpers stay usable
 *  from admin paths regardless of whether the feature is currently switched on.
 * ============================================================================= */

/** Longest accepted chat line. Shorter than TEXT_BODY_MAX on purpose: a
 *  shoutbox line is a remark, not an essay, and the panel is narrow. */
const CHAT_BODY_MAX = 500;

/** How many rows to delete per trim. Trimming in chunks rather than one row at
 *  a time keeps this to one DELETE on most posts instead of one per message
 *  forever — see chat_trim(). */
const CHAT_TRIM_CHUNK = 10;

/**
 * Is the chat switched on?
 * @return bool
 */
function chat_enabled() {
    return opt_bool('chat_enabled');
}

/**
 * How many messages the panel shows when first opened. Clamped: a zero would
 * open an empty panel that looks broken, and an unbounded value would let a
 * typo in the admin field dump the entire log into one response.
 * @return int
 */
function chat_initial_count() {
    return max(1, min(200, opt_int('chat_initial_messages')));
}

/**
 * Hard cap on stored messages. Clamped low-side so a stray 0 can't mean
 * "delete everything on every post".
 * @return int
 */
function chat_max_messages() {
    return max(10, min(100000, opt_int('chat_max_messages')));
}

/**
 * Poll interval in seconds. Floored at 2 so a mistyped 0 cannot turn every
 * open panel into a request loop against the host.
 * @return int
 */
function chat_refresh_seconds() {
    return max(2, min(3600, opt_int('chat_refresh_seconds')));
}

/**
 * Newest $limit messages, oldest-first (ready to append to the panel).
 *
 * Ordered DESC in the subquery to use the index and take the NEWEST rows, then
 * flipped — ordering ASC with an offset would have to count the whole table.
 *
 * @param int      $limit
 * @param int|null $beforeId  Only messages older than this id (load-earlier).
 * @return array
 */
function chat_recent($limit, $beforeId = null) {
    $limit = max(1, (int)$limit);
    if ($beforeId !== null) {
        $rows = db_all(
            'SELECT * FROM (SELECT * FROM chat_messages WHERE id < ? ORDER BY id DESC LIMIT ?)
             ORDER BY id ASC', [(int)$beforeId, $limit]);
    } else {
        $rows = db_all(
            'SELECT * FROM (SELECT * FROM chat_messages ORDER BY id DESC LIMIT ?)
             ORDER BY id ASC', [$limit]);
    }
    return $rows;
}

/**
 * Everything newer than $afterId, oldest-first. The polling query.
 *
 * Capped independently of the admin's "initial" setting: if a panel is left
 * open overnight on a busy install, the catch-up response must not be the
 * entire log.
 *
 * @param int $afterId
 * @return array
 */
function chat_since($afterId) {
    return db_all('SELECT * FROM chat_messages WHERE id > ? ORDER BY id ASC LIMIT 200',
                  [(int)$afterId]);
}

/**
 * The id of the newest message, or 0 when the log is empty.
 * @return int
 */
function chat_last_id() {
    return (int)db_val('SELECT COALESCE(MAX(id), 0) FROM chat_messages');
}

/**
 * Trim the log back under the cap, deleting in chunks.
 *
 * THE RULE: do nothing until the table is a full chunk OVER the cap, then
 * delete everything above the cap in one statement.
 *
 * That means the log sits between max and max+9 rows and needs a DELETE on
 * roughly one post in ten, instead of one on every single post forever once
 * full. The deliberate trade is a small overshoot — never FEWER messages than
 * the admin asked to keep, occasionally a few more. Trimming to exactly the cap
 * would be a delete per post; trimming a chunk BELOW it would store less than
 * was configured, which is the worse of the two errors.
 *
 * Deletes by explicit id list rather than `ORDER BY ... LIMIT` inside DELETE:
 * SQLite only supports that when compiled with an optional flag, which is not
 * safe to assume across shared hosts.
 *
 * @return int  Rows deleted.
 */
function chat_trim() {
    $max   = chat_max_messages();
    $count = (int)db_val('SELECT COUNT(*) FROM chat_messages');
    if ($count < $max + CHAT_TRIM_CHUNK) return 0;

    $excess = $count - $max;
    $ids = [];
    foreach (db_all('SELECT id FROM chat_messages ORDER BY id ASC LIMIT ?', [$excess]) as $r) {
        $ids[] = (int)$r['id'];
    }
    if (!$ids) return 0;
    $place = implode(',', array_fill(0, count($ids), '?'));
    db_run("DELETE FROM chat_messages WHERE id IN ($place)", $ids);
    return count($ids);
}

/**
 * Reduce a chat line to plain text by removing anything tag-shaped.
 *
 * NOT strip_tags(). That function scans from a "<" to the next ">" and drops
 * everything between — so "I <3 board games" becomes "I ", and "unclosed <tag"
 * becomes "unclosed". Silently eating the rest of someone's sentence because
 * they typed a less-than sign is a worse bug than the markup this is meant to
 * remove. The pattern below only matches a "<" followed by an actual tag NAME,
 * which leaves "<3", "a < b" and "price < 5 zl" intact.
 *
 * This is NOT the XSS defence — output is escaped at render time by the client
 * (textContent, never innerHTML), and that remains the real boundary. This is
 * about the messages being plain text as specified, so nobody can post
 * something that merely LOOKS like formatting to a reader.
 *
 * Known trade: an address written as <someone@example.com> is tag-shaped and
 * goes too. Rare in a shoutbox, and the alternative is a looser pattern that
 * lets real tags through.
 *
 * @param string $s
 * @return string
 */
function chat_plain_text($s) {
    $s = (string)$s;
    // HTML/XML comments and processing instructions first: they are not
    // tag-NAME shaped, so the main pattern would leave them behind.
    $s = preg_replace('#<!--.*?-->#s', '', $s);
    $s = preg_replace('#<[!?][^>]*>#s', '', $s);
    // Opening, closing and self-closing tags: "<" + optional "/" + a name.
    $s = preg_replace('#</?[a-z][a-z0-9]*\b[^>]*>#i', '', $s);
    return trim($s);
}

/**
 * Does this chat line carry anything at all?
 *
 * Deliberately looser than text_has_content(), which demands a letter or digit
 * and so rejects ":)", "?!", "^^" and even a lone emoji — all perfectly real
 * things to say in a shoutbox. That stricter rule exists to keep junk out of
 * LISTINGS, where a punctuation-only game or table name is meaningless and
 * sticks around; a chat line is casual and ephemeral, so the only thing worth
 * refusing is a message with no visible characters whatsoever.
 *
 * Whitespace here includes Unicode blanks, so a line of non-breaking spaces
 * cannot be passed off as content.
 *
 * @param string $s  Already-trimmed text.
 * @return bool
 */
function chat_has_content($s) {
    return preg_replace('/[\s\p{Z}\x{FEFF}]+/u', '', (string)$s) !== '';
}

/**
 * Post a message. Returns the new row, or a string error code.
 *
 * Validation mirrors the rest of the app's free-text rules (text_has_content /
 * text_too_long) rather than inventing its own, so a shoutbox line is held to
 * the same standard as a game comment.
 *
 * @param string $name     Display name (ignored for logged-in users).
 * @param string $message
 * @return array|string    The inserted row, or 'name'|'empty'|'long'.
 */
function chat_post($name, $message) {
    $user = current_user();
    if ($user) {
        $name    = (string)$user['display_name'];
        $isAdmin = !empty($user['is_admin']) ? 1 : 0;
        $userId  = (int)$user['id'];
    } else {
        // Stripped BEFORE validation, so a name made only of tags is caught by
        // the content check rather than stored as an empty string.
        $name    = chat_plain_text($name);
        $isAdmin = 0;
        $userId  = null;
        // The NAME keeps the strict rule: it is shown beside every line the
        // person posts, so it is closer to a listing entry than to a remark.
        if (!text_has_content($name) || text_too_long($name, TEXT_NAME_MAX)) return 'name';
    }

    // Same ordering for the body: strip, then judge what is actually left.
    // Length is checked AFTER stripping, so markup cannot pad a message over
    // the limit and get it rejected for text the visitor never sees.
    $message = chat_plain_text($message);
    if (!chat_has_content($message)) return 'empty';
    if (text_too_long($message, CHAT_BODY_MAX)) return 'long';

    db_run('INSERT INTO chat_messages (user_id, name, is_admin, message) VALUES (?,?,?,?)',
           [$userId, $name, $isAdmin, $message]);
    $id = (int)db()->lastInsertId();

    chat_trim();

    return db_one('SELECT * FROM chat_messages WHERE id = ?', [$id]);
}

/**
 * Wipe the whole log. Called when a new event is created AND the chat is
 * scoped to the event — see inc/admin/new_event.php.
 *
 * Unconditional by design: the caller decides whether the scope applies, so
 * this stays a plain "empty the table" that an admin path can also use.
 *
 * @return void
 */
function chat_wipe() {
    db_run('DELETE FROM chat_messages');
}

/**
 * Shape a row for JSON. Keeps the wire format in ONE place so the initial
 * render and the poll response cannot drift apart.
 *
 * The message is sent as raw text and escaped by the client when it builds the
 * DOM node — never assembled into an HTML string. See renderMessage() in
 * scripts.js.
 *
 * @param array $row
 * @return array
 */
function chat_row_public($row) {
    return [
        'id'      => (int)$row['id'],
        'name'    => (string)$row['name'],
        'admin'   => (int)$row['is_admin'] === 1,
        'user'    => $row['user_id'] !== null,
        'message' => (string)$row['message'],
        'at'      => chat_format_time($row['created_at']),
    ];
}

/**
 * created_at is stored UTC (SQLite datetime('now')); show it in the event's
 * configured timezone, which is what every other timestamp in the app does.
 *
 * @param string $utc
 * @return string
 */
function chat_format_time($utc) {
    try {
        $dt = new DateTime((string)$utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('d.m H:i');
    } catch (Throwable $e) {
        return (string)$utc;
    }
}
