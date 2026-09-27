<?php
/* =============================================================================
 *  inc/calendar.php — events into other people's calendars.
 * -----------------------------------------------------------------------------
 *  Three things, all from the same data:
 *    - a Google Calendar "add this event" link,
 *    - an .ics file for one event, for any other calendar app,
 *    - a subscribable feed of every event, which a calendar re-reads on its own
 *      so a new event appears there without anybody doing anything.
 *
 *  TIMES GO OUT IN UTC. The database holds wall-clock times in the club's own
 *  timezone. Writing them as floating local times would make a calendar in
 *  another timezone put them at the wrong hour, and writing a TZID means
 *  shipping a VTIMEZONE definition of the club's zone inside every file. UTC,
 *  converted here from the site timezone, is unambiguous everywhere and needs
 *  neither.
 *
 *  A DAY CAN CROSS MIDNIGHT (18:00-02:00 is one evening, as it is everywhere
 *  else in this app), so an end time earlier than its start belongs to the
 *  following date.
 * ============================================================================= */

/* The footer loads this on EVERY page, including ones (login, register) that
 * never pull in the event helpers — and the functions below need event_days().
 * So the module brings its own dependency rather than assuming a caller did. */
require_once __DIR__ . '/events.php';

/** Is the feature switched on at all? On by default. */
function calendar_enabled() {
    return opt_bool('calendar_export');
}

/**
 * One day of an event as a pair of UTC timestamps.
 *
 * @param array $day  An event_days row (day_date, start_time, end_time).
 * @return array|null  [startUtc, endUtc] as DateTimeImmutable, or null if the
 *                     day has no usable date (nothing sensible to export).
 */
function calendar_day_span($day) {
    $date = (string)($day['day_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $tz = new DateTimeZone(date_default_timezone_get());   // the site timezone
    $start = (string)($day['start_time'] ?? '') ?: '00:00';
    $end   = (string)($day['end_time'] ?? '')   ?: '23:59';
    try {
        $s = new DateTimeImmutable($date . ' ' . $start, $tz);
        $e = new DateTimeImmutable($date . ' ' . $end, $tz);
    } catch (Exception $ex) {
        return null;
    }
    // Past midnight: the end is on the next date, not before the start.
    if ($e <= $s) $e = $e->modify('+1 day');
    $utc = new DateTimeZone('UTC');
    return [$s->setTimezone($utc), $e->setTimezone($utc)];
}

/**
 * Escape a value for an iCalendar TEXT field.
 *
 * Backslash first — it is the escape character, so doing it later would double
 * the escapes the other rules add. Commas and semicolons are separators in
 * iCalendar and would split the field; a newline becomes the literal \n.
 *
 * @param string $s
 * @return string
 */
function ical_escape($s) {
    $s = str_replace('\\', '\\\\', (string)$s);
    $s = str_replace([';', ','], ['\\;', '\\,'], $s);
    return str_replace(["\r\n", "\r", "\n"], '\\n', $s);
}

/**
 * Fold one content line at 75 octets, as the format requires.
 *
 * OCTETS, not characters: a Polish letter is two bytes, and folding by
 * character count produces lines over the limit that strict parsers reject.
 * Nor may a fold land inside a multi-byte character, which would corrupt it —
 * so the cut backs off to the start of the character it would split.
 *
 * @param string $line
 * @return string  With CRLF + space continuation.
 */
function ical_fold($line) {
    $out = '';
    $limit = 75;
    while (strlen($line) > $limit) {
        $cut = $limit;
        // Back off past UTF-8 continuation bytes (10xxxxxx).
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
        $limit = 74;   // continuation lines carry the leading space
    }
    return $out . $line;
}

/** The address of an event's page, absolute, for the calendar entry. */
function calendar_event_url($event, $dayIndex = 1) {
    $base = site_base_url();
    return $base . 'index.php?event=' . (int)$event['id'] . '&day=' . (int)$dayIndex;
}

/**
 * One VEVENT per day of each event.
 *
 * Per DAY rather than one block for the whole event: a weekend convention has
 * opening hours each day and is closed overnight, and a single 60-hour entry
 * would claim otherwise.
 *
 * @param array $events  Event rows.
 * @return string  The whole VCALENDAR, CRLF-terminated.
 */
function calendar_ics($events) {
    $host = parse_url(site_base_url(), PHP_URL_HOST) ?: 'zapisynagry';
    $now  = gmdate('Ymd\THis\Z');
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//zapisynagry//' . ical_escape($host) . '//PL',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . ical_escape(trim((string)opt('venue_name')) ?: $host),
    ];
    foreach ($events as $ev) {
        $details = function_exists('event_details') ? event_details($ev) : [];
        foreach (event_days((int)$ev['id']) as $day) {
            $span = calendar_day_span($day);
            if (!$span) continue;
            $summary = $ev['name'];
            if (trim((string)($day['day_name'] ?? '')) !== '' && function_exists('day_names_enabled') && day_names_enabled()) {
                $summary .= ' — ' . $day['day_name'];
            }
            $url = calendar_event_url($ev, (int)$day['day_index']);
            $lines[] = 'BEGIN:VEVENT';
            /* A UID that stays the same for the same day of the same event, so
             * a calendar that re-reads the feed UPDATES the entry instead of
             * adding a duplicate every time. Tied to the day row, not its date:
             * moving a day then moves the entry rather than creating another. */
            $lines[] = 'UID:event-' . (int)$ev['id'] . '-day-' . (int)$day['id'] . '@' . $host;
            $lines[] = 'DTSTAMP:' . $now;
            $lines[] = 'DTSTART:' . $span[0]->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'   . $span[1]->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:' . ical_escape($summary);
            if (!empty($details['name']) || !empty($details['address'])) {
                $loc = trim(($details['name'] ?? '') . "\n" . ($details['address'] ?? ''));
                $lines[] = 'LOCATION:' . ical_escape(str_replace("\n", ', ', $loc));
            }
            $lines[] = 'DESCRIPTION:' . ical_escape($url);
            $lines[] = 'URL:' . $url;
            $lines[] = 'END:VEVENT';
        }
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('ical_fold', $lines)) . "\r\n";
}

/**
 * A Google Calendar "add this event" link.
 *
 * Google's link can describe ONE time range. For a one-day event that is
 * exact. For a multi-day event it spans from the first day's opening to the
 * last day's close — which is how people expect a convention to look in their
 * calendar. The .ics download is the precise version, one entry per day.
 *
 * @param array $event
 * @return string  '' when the event has no usable dates.
 */
function calendar_google_link($event) {
    $days = event_days((int)$event['id']);
    if (!$days) return '';
    $first = calendar_day_span($days[0]);
    $last  = calendar_day_span($days[count($days) - 1]);
    if (!$first || !$last) return '';
    $params = [
        'action'  => 'TEMPLATE',
        'text'    => $event['name'],
        'dates'   => $first[0]->format('Ymd\THis\Z') . '/' . $last[1]->format('Ymd\THis\Z'),
        'details' => calendar_event_url($event, (int)$days[0]['day_index']),
    ];
    $details = function_exists('event_details') ? event_details($event) : [];
    if (!empty($details['name']) || !empty($details['address'])) {
        $params['location'] = trim(($details['name'] ?? '') . ', ' . str_replace("\n", ', ', $details['address'] ?? ''), ', ');
    }
    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
}

/**
 * A Google Calendar link that SUBSCRIBES to the whole feed.
 *
 * Google takes the feed's address in the cid parameter and re-reads it on its
 * own schedule. It must be the webcal:// form of an absolute address — Google
 * cannot fetch a relative one, and will not treat an https link as a feed here.
 *
 * @return string  '' when the site's own address cannot be determined.
 */
function calendar_google_subscribe_link() {
    $base = site_base_url();
    if ($base === '') return '';
    $feed = preg_replace('#^https?://#', 'webcal://', $base) . 'ical.php';
    return 'https://calendar.google.com/calendar/r?cid=' . rawurlencode($feed);
}

/**
 * Which events a visitor may export: the ones they could open anyway.
 *
 * Active events always; archived ones only when the club shows its archive
 * publicly. Deleted events never — a calendar is not a back door into what the
 * site itself no longer shows.
 *
 * @return array
 */
function calendar_public_events() {
    $where = 'is_deleted = 0' . (public_archives_enabled() ? '' : ' AND is_archived = 0');
    return db_all("SELECT * FROM events WHERE $where ORDER BY id");
}


/* =============================================================================
 *  SINGLE GAMES — one game into a calendar, from its card.
 * -----------------------------------------------------------------------------
 *  A separate option from the event links above, and OFF by default: those are
 *  one line in the footer, whereas these are two more buttons on every card,
 *  which is a real change to how a crowded board looks.
 * ============================================================================= */

/** Are the per-game links switched on? Off by default. */
function calendar_game_enabled() {
    return opt_bool('calendar_game_export');
}

/**
 * One game as a pair of UTC timestamps.
 *
 * The game's own start time on its day's date — EXCEPT when it is earlier than
 * the day's opening, which means it starts after midnight: a 00:30 game on an
 * evening that opens at 18:00 belongs to the next date, the same rule the
 * timetable uses everywhere else.
 *
 * Length from the game; a game with none recorded is given an hour, so the
 * calendar entry has some duration rather than none.
 *
 * The day row is cached per request: a board shows every game of a day, and a
 * query per card to fetch the same row each time would be pure waste.
 *
 * @param array $g  A games row.
 * @return array|null  [startUtc, endUtc], or null without a usable date.
 */
function calendar_game_span($g) {
    static $days = [];
    $dayId = (int)($g['day_id'] ?? 0);
    if (!array_key_exists($dayId, $days)) {
        $days[$dayId] = db_one('SELECT * FROM event_days WHERE id = ?', [$dayId]);
    }
    $day = $days[$dayId];
    if (!$day || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day['day_date'])) return null;

    $tz = new DateTimeZone(date_default_timezone_get());
    try {
        $s = new DateTimeImmutable($day['day_date'] . ' ' . $g['start_time'], $tz);
    } catch (Exception $ex) {
        return null;
    }
    // Earlier than the day opens = after midnight, on the following date.
    if ((string)$g['start_time'] < (string)$day['start_time']) $s = $s->modify('+1 day');
    $len = (int)($g['length_minutes'] ?? 0);
    $e = $s->modify('+' . ($len > 0 ? $len : 60) . ' minutes');
    $utc = new DateTimeZone('UTC');
    return [$s->setTimezone($utc), $e->setTimezone($utc)];
}

/**
 * One game as a calendar file.
 *
 * Titled with the game, and the event it is part of in the description — in a
 * calendar full of other things, "Brass: Birmingham" alone does not say where.
 *
 * @param array $g
 * @param array $event
 * @return string
 */
function calendar_game_ics($g, $event) {
    $span = calendar_game_span($g);
    $host = parse_url(site_base_url(), PHP_URL_HOST) ?: 'zapisynagry';
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0',
        'PRODID:-//zapisynagry//' . ical_escape($host) . '//PL',
        'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    ];
    if ($span) {
        $url = site_base_url() . 'index.php?event=' . (int)$event['id'] . '#game-' . (int)$g['id'];
        $details = event_details($event);
        $lines[] = 'BEGIN:VEVENT';
        // Per game, so downloading it again replaces the entry, not duplicates it.
        $lines[] = 'UID:game-' . (int)$g['id'] . '@' . $host;
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART:' . $span[0]->format('Ymd\THis\Z');
        $lines[] = 'DTEND:'   . $span[1]->format('Ymd\THis\Z');
        $lines[] = 'SUMMARY:' . ical_escape($g['name']);
        if (!empty($details['name']) || !empty($details['address'])) {
            $loc = trim(($details['name'] ?? '') . "\n" . ($details['address'] ?? ''));
            $lines[] = 'LOCATION:' . ical_escape(str_replace("\n", ', ', $loc));
        }
        $lines[] = 'DESCRIPTION:' . ical_escape($event['name'] . "\n" . $url);
        $lines[] = 'URL:' . $url;
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('ical_fold', $lines)) . "\r\n";
}

/**
 * The Google "add this game" link, or '' without a usable time.
 *
 * @param array $g
 * @param array $event
 * @return string
 */
function calendar_game_google_link($g, $event) {
    $span = calendar_game_span($g);
    if (!$span) return '';
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action'  => 'TEMPLATE',
        'text'    => $g['name'],
        'dates'   => $span[0]->format('Ymd\THis\Z') . '/' . $span[1]->format('Ymd\THis\Z'),
        'details' => $event['name'] . "\n" . site_base_url() . 'index.php?event=' . (int)$event['id'],
    ]);
}

/**
 * The two calendar controls for one game, as small icons.
 *
 * ICONS, not labelled buttons, and in the corner with the edit/delete controls
 * rather than beside the rules link: as full buttons they sat in the middle of
 * every card and drew more attention than a convenience deserves. They go in
 * the same place in every theme — wherever that theme keeps its edit/delete
 * controls — but OUTSIDE the owner-only condition, since anyone may want to
 * add a game to their own calendar.
 *
 * The calendar glyph is the header's own (nav_icon_svg('calendar')), so the
 * two read as the same idea. The .ics one is the text ".ics" drawn as a small
 * badge — at this size a document icon would be an unreadable smudge, and the
 * three letters are exactly what someone looking for "the file" recognises.
 *
 * @param array $g
 * @return string  '' when the option is off or the game has no usable time.
 */
function game_calendar_html($g) {
    if (!calendar_game_enabled()) return '';
    static $events = [];
    $eid = (int)($g['event_id'] ?? 0);
    if (!array_key_exists($eid, $events)) {
        $events[$eid] = db_one('SELECT * FROM events WHERE id = ?', [$eid]);
    }
    $event = $events[$eid];
    if (!$event) return '';
    $google = calendar_game_google_link($g, $event);
    if ($google === '') return '';
    $ics = 'ical.php?game=' . (int)$g['id'];

    $glyph = function_exists('nav_icon_svg') ? nav_icon_svg('calendar') : '';
    return '<span class="game-cal-icons">'
         . '<a class="game-cal-icon" href="' . e($google) . '" target="_blank" rel="noopener"'
         . ' title="' . e(t('cal_game_google')) . '" aria-label="' . e(t('cal_game_google')) . '">'
         . ($glyph !== '' ? $glyph : '&#128197;') . '</a>'
         . '<a class="game-cal-icon game-cal-icon-ics" href="' . e($ics) . '"'
         . ' title="' . e(t('cal_download_ics')) . '" aria-label="' . e(t('cal_download_ics')) . '">.ics</a>'
         . '</span>';
}

/* =============================================================================
 *  CALENDAR LINKS BY EMAIL — sent when somebody commits to a game.
 * -----------------------------------------------------------------------------
 *  Off by default. When on, adding a game or taking a seat sends that person a
 *  short email with the two links, and the links are also added to the emails
 *  the site already sends when a poll resolves or a reserve is promoted — the
 *  other two moments somebody finds out they are definitely playing.
 * ============================================================================= */

/** Are the calendar-link emails switched on? Off by default. */
function calendar_email_enabled() {
    return opt_bool('calendar_email');
}

/**
 * The block of text with the two links for one game, or '' when there is
 * nothing to add.
 *
 * Plain text, because that is what the site's emails are. ABSOLUTE addresses:
 * a link in an email is opened from an inbox, not from the site, so a relative
 * one would go nowhere. Without a known site address the .ics link cannot be
 * made absolute and is left out; the Google one is Google's own and always works.
 *
 * @param int $gameId
 * @return string  Starts with a blank line, so it can be appended to any body.
 */
function calendar_email_block($gameId) {
    if (!calendar_email_enabled()) return '';
    $g = db_one('SELECT * FROM games WHERE id = ?', [(int)$gameId]);
    if (!$g) return '';
    $ev = db_one('SELECT * FROM events WHERE id = ?', [(int)$g['event_id']]);
    if (!$ev) return '';
    $google = calendar_game_google_link($g, $ev);
    if ($google === '') return '';          // no usable date: no links to offer

    $block = "\n\n" . t('cal_email_intro') . "\n"
           . t('cal_email_google') . ' ' . $google;
    $base = site_base_url();
    if ($base !== '') {
        $block .= "\n" . t('cal_email_ics') . ' ' . $base . 'ical.php?game=' . (int)$g['id'];
    }
    return $block;
}

/**
 * WHETHER the calendar links would go to this person — the decision on its own,
 * so it can be checked directly. The sending leaves no trace a test can inspect
 * (it goes to SMTP or nowhere), and "does the right person get it, and not the
 * wrong one" is the part worth being certain of.
 *
 * @param string $to
 * @param int    $gameId
 * @param mixed  $optIn  The row's notify flag.
 * @return bool
 */
function calendar_email_should_send($to, $gameId, $optIn = 1) {
    if (trim((string)$to) === '' || !calendar_email_enabled()) return false;
    require_once __DIR__ . '/notify.php';
    if (!notify_enabled() || !notify_wants($optIn)) return false;
    return calendar_email_block($gameId) !== '';
}

/**
 * Send the calendar links for a game to one person who has just committed to it.
 *
 * Asks the same questions every other email does: is the site sending mail at
 * all (notify_enabled), and did this person agree to mail about this game (their
 * own opt-in flag, when the club lets people choose). Someone who unticked
 * "email me about this" did not ask for this one either.
 *
 * @param string $to       Address.
 * @param int    $gameId
 * @param mixed  $optIn    The row's notify flag (1 when there is none).
 * @return void
 */
function calendar_email_send($to, $gameId, $optIn = 1) {
    if (!calendar_email_should_send($to, $gameId, $optIn)) return;
    $block = calendar_email_block($gameId);
    $g = db_one('SELECT name FROM games WHERE id = ?', [(int)$gameId]);
    send_mail($to, t('cal_email_subject', (string)($g['name'] ?? '')),
              t('cal_email_body', (string)($g['name'] ?? '')) . $block);
}
