<?php
/* =============================================================================
 *  chat.php — the shoutbox's JSON endpoint (fetch + post).
 * -----------------------------------------------------------------------------
 *  The only JSON endpoint in the app. Every other controller renders a page or
 *  redirects; this one is polled by scripts.js while the chat panel is open, so
 *  it answers in JSON and never renders chrome.
 *
 *  ACTIONS (all POST except 'fetch', which is idempotent and may be GET):
 *    fetch  — messages newer than ?after=<id>, or the newest N when after is
 *             absent. Used for both the initial load and the poll.
 *    older  — the N messages immediately before ?before=<id> ("load earlier").
 *    post   — add a message. POST only, CSRF-checked, anti-bot checked.
 *
 *  WHY 'fetch' IS NOT CSRF-CHECKED: it is a read. CSRF protects against a third
 *  party causing a state CHANGE with the visitor's credentials; a read that
 *  returns already-public messages is not a state change, and requiring a token
 *  would break the poll after a session expires. 'post' is checked, because
 *  that writes.
 *
 *  OUTPUT IS ALWAYS JSON, including on failure — a client that gets an HTML
 *  error page back mid-poll cannot report anything useful, so every exit path
 *  goes through chat_json().
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
// NOT a plain require: bootstrap.php already loads inc/chat.php (chat_enabled()
// is asked on every page render), so requiring it again here is a fatal
// "cannot redeclare". require_once is the codebase's rule for exactly this.

/**
 * Emit JSON and stop. Sets no-store so a proxy or the browser cannot serve a
 * stale poll response — the whole point of the poll is freshness.
 */
function chat_json(array $payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// A disabled chat answers the same way to every action: there is no chat here.
// Checked before anything else so switching it off genuinely closes the door,
// not just the panel's entry point in the UI.
if (!chat_enabled()) {
    chat_json(['ok' => false, 'error' => 'disabled'], 403);
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'fetch';

if ($action === 'post') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        chat_json(['ok' => false, 'error' => 'method'], 405);
    }
    // Checked HERE, not only in the template. Hiding the form stops an honest
    // visitor; this endpoint is reachable directly, so the rule has to live on
    // the side that writes. 'fetch' and 'older' stay open: guests may still
    // READ, which is the whole shape of this option.
    if (!chat_can_post()) {
        chat_json(['ok' => false, 'error' => 'login_required'], 403);
    }
    csrf_check();
    antibot_check('form');

    $row = chat_post($_POST['name'] ?? '', $_POST['message'] ?? '');
    if (is_string($row)) {
        // A validation code, not a row. Reported so the panel can say WHICH
        // thing was wrong rather than failing silently the way the redirecting
        // comment endpoint has to.
        chat_json(['ok' => false, 'error' => $row], 422);
    }

    // Remember a guest's name so they don't retype it every visit — the same
    // cookie the sign-up and comment forms already use.
    if (!is_logged_in()) {
        guest_identity_remember($row['name'], guest_identity()['email']);
    }

    // Writing in the chat means looking at it: the author's own marker moves
    // on, so they are neither shown their own line as new nor emailed in
    // 'once' mode about a conversation they are in the middle of.
    chat_mark_seen((int)$row['id']);
    /* Email the members who asked for it. A failure here must not turn a
     * message that WAS posted into an error for the person who posted it. */
    try { chat_notify_new($row); } catch (Throwable $ex) { /* logged by send_mail's own path */ }

    chat_json(['ok' => true, 'message' => chat_row_public($row)]);
}

/* ---- The "new messages" marker --------------------------------------------
 * 'seen'   (POST): the panel showed everything up to ?id — remember it on the
 *                  account. Guests keep their marker in the browser only.
 * 'unread' (GET) : how many are newer than ?after (or the account's marker).
 * Both answer even when the marker is switched off: 'seen' is also what
 * re-arms the 'once' emails, which do not depend on the marker. */
if ($action === 'seen') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') chat_json(['ok' => false, 'error' => 'method'], 405);
    csrf_check();
    chat_json(['ok' => true, 'seen' => chat_mark_seen((int)($_POST['id'] ?? 0))]);
}
if ($action === 'unread') {
    chat_json(['ok' => true, 'count' => chat_unread_count((int)($_GET['after'] ?? 0)), 'lastId' => chat_last_id()]);
}

if ($action === 'older') {
    $before = (int)($_GET['before'] ?? $_POST['before'] ?? 0);
    if ($before <= 0) chat_json(['ok' => true, 'messages' => [], 'more' => false]);
    // Ten at a time, per the spec — a fixed step, not the admin's initial count.
    $rows = chat_recent(10, $before);
    $out  = array_map('chat_row_public', $rows);
    // Is there anything older still? Lets the client hide the button at the end
    // rather than leaving a control that does nothing.
    $more = $out
        ? (int)db_val('SELECT COUNT(*) FROM chat_messages WHERE id < ?', [$out[0]['id']]) > 0
        : false;
    chat_json(['ok' => true, 'messages' => $out, 'more' => $more]);
}

// ---- default: fetch ---------------------------------------------------------
$after = isset($_GET['after']) ? (int)$_GET['after'] : (isset($_POST['after']) ? (int)$_POST['after'] : -1);
if ($after >= 0) {
    $rows = chat_since($after);
} else {
    $rows = chat_recent(chat_initial_count());
}
$out = array_map('chat_row_public', $rows);
$more = $out
    ? (int)db_val('SELECT COUNT(*) FROM chat_messages WHERE id < ?', [$out[0]['id']]) > 0
    : false;

chat_json([
    'ok'       => true,
    'messages' => $out,
    'more'     => $more,
    'lastId'   => chat_last_id(),
]);
