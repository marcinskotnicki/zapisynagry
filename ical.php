<?php
/* =============================================================================
 *  ical.php — the club's events as an iCalendar file.
 * -----------------------------------------------------------------------------
 *    ical.php?event=N   one event, as a download (every day an entry)
 *    ical.php?game=N    one game, as a download (its own option, off by default)
 *    ical.php           every public event — the SUBSCRIPTION feed
 *
 *  The feed is what makes "add all the club's events" keep itself current: a
 *  calendar that subscribes re-reads this address on its own schedule, so a
 *  newly created event appears there without anybody doing anything. It is
 *  therefore public and needs no login — a calendar app cannot log in.
 *
 *  Only what the site already shows: active events, plus archived ones when
 *  the club makes its archive public. See calendar_public_events().
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/calendar.php';

$public = calendar_public_events();

/* ---- One GAME ----------------------------------------------------------------
 * Governed by its own option, which is independent of the event links: a club
 * may well want the footer links and not two more buttons on every card, or
 * the reverse. Each is off at the address as well as in the page. */
$gameId = (int)($_GET['game'] ?? 0);
if ($gameId > 0) {
    /* Either option can have handed out this address: the card buttons link
     * to it, and so do the calendar emails. A club may use the emails without
     * the buttons — and a link it emailed must not lead to a dead page. */
    if (!calendar_game_enabled() && !calendar_email_enabled()) { http_response_code(404); exit; }
    $g = db_one('SELECT * FROM games WHERE id = ? AND is_archived = 0', [$gameId]);
    // Only a game on an event this visitor could open — same rule as the feed.
    $publicIds = array_map('intval', array_column($public, 'id'));
    if (!$g || !in_array((int)$g['event_id'], $publicIds, true)) { http_response_code(404); exit; }
    $ev = db_one('SELECT * FROM events WHERE id = ?', [(int)$g['event_id']]);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="gra-' . $gameId . '.ics"');
    header('Cache-Control: private, max-age=300');
    echo calendar_game_ics($g, $ev);
    exit;
}

// Off means off: not merely a hidden link, but no feed at the address either.
if (!calendar_enabled()) {
    http_response_code(404);
    exit;
}

$eventId = (int)($_GET['event'] ?? 0);

if ($eventId > 0) {
    // One event — but only one this visitor could have opened on the site.
    $events = array_values(array_filter($public, function ($e) use ($eventId) {
        return (int)$e['id'] === $eventId;
    }));
    if (!$events) {
        http_response_code(404);
        exit;
    }
    $filename = 'wydarzenie-' . $eventId . '.ics';
    $disposition = 'attachment';     // a download to open in a calendar app
} else {
    $events = $public;
    $filename = 'wydarzenia.ics';
    /* INLINE for the feed: a calendar subscribing to it reads the body, and an
     * "attachment" disposition makes some of them treat the address as a
     * one-off file rather than something to keep polling. */
    $disposition = 'inline';
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
/* Short-lived caching: long enough to spare the server when many members'
 * calendars poll at once, short enough that a new event is not hidden for
 * hours. Calendar apps impose their own, much longer, refresh interval anyway. */
header('Cache-Control: public, max-age=900');
echo calendar_ics($events);
