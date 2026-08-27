<?php
/* =============================================================================
 *  inc/events.php — front-end data + rules helpers.
 * -----------------------------------------------------------------------------
 *  Read helpers that assemble the event view, plus the permission and small
 *  formatting utilities the front end needs. Logic only; no HTML.
 *
 *  ROUGH MAP of this file:
 *    - event_resolve / event_days / event_day  : which event + days to show
 *    - event_tables_full                       : the big "assemble a day" reader
 *    - event_next_* / event_table_count        : helpers for the add-table/-game UI
 *    - can_add_games / can_signup              : front-end permissions
 *    - hhmm_to_min / min_to_hhmm / weight_*     : tiny formatting/maths helpers
 *    - *_rules_label                           : code -> localized label
 *    - timeline_build                          : the per-day timeline data model
 *    - game_confirmed_count / promote_reserves : signup + reserve bookkeeping
 * ============================================================================= */

/* ---- Resolving which event to show --------------------------------------- *
 *  Normal visit (index.php)         -> the current event, interactive.
 *  Archive link (index.php?e=token) -> that event by token, READ-ONLY.
 *  Returns ['event'=>row|null, 'readonly'=>bool]. The token is an unguessable
 *  per-event access_token, so archive links can be shared without exposing ids.
 * --------------------------------------------------------------------------- */
function event_resolve() {
    // Public archives on: an explicit ?event=<id> may name ANY event, and
    // whether it is editable follows its own archived flag rather than the way
    // it was reached. That is the whole point of the feature — a past event
    // that was never archived stays live and editable.
    if (public_archives_enabled()) {
        $id = (int)($_GET['event'] ?? 0);
        if ($id > 0) {
            $ev = db_one('SELECT * FROM events WHERE id = ? AND is_deleted = 0', [$id]);
            if ($ev) {
                return ['event' => $ev, 'readonly' => (int)$ev['is_archived'] === 1];
            }
        }
    }

    $token = $_GET['e'] ?? '';
    if ($token !== '') {
        // Share-token view. Always read-only regardless of the event's own
        // flag: the token is handed out precisely so someone can LOOK without
        // an account, and it must not become an edit link if an admin later
        // un-archives that event.
        $ev = db_one('SELECT * FROM events WHERE access_token = ? AND is_deleted = 0', [$token]);
        return ['event' => $ev, 'readonly' => true];
    }
    // Default: the live event, fully interactive.
    return ['event' => current_event(), 'readonly' => false];
}


/**
 * An event's first and last day dates, as ['from' => 'YYYY-MM-DD', 'to' => ...].
 * Either may be null when an event has no days yet.
 *
 * @param int $eventId
 * @return array
 */
function event_date_range($eventId) {
    $r = db_one('SELECT MIN(day_date) AS f, MAX(day_date) AS t FROM event_days WHERE event_id = ?',
                [(int)$eventId]);
    return ['from' => $r['f'] ?? null, 'to' => $r['t'] ?? null];
}

/**
 * A human label for an event's dates: one date, or "from – to" for a
 * multi-day event. Empty string when the event has no days.
 *
 * @param array $ev  An event row already carrying date_from/date_to, or an id.
 * @return string
 */
function event_date_label($ev) {
    $from = $ev['date_from'] ?? null;
    $to   = $ev['date_to']   ?? null;
    if ($from === null) {
        $r = event_date_range($ev['id'] ?? 0);
        $from = $r['from'];
        $to   = $r['to'];
    }
    if (!$from) return '';
    $fmt = function ($d) { return date('d.m.Y', strtotime($d)); };
    return ($to && $to !== $from) ? $fmt($from) . ' – ' . $fmt($to) : $fmt($from);
}

/**
 * A page of events for a listing, newest first by their FIRST day.
 *
 * Sorted on the event's own dates rather than its id, because an admin can
 * create events out of chronological order and the list is for readers, not
 * for the insert history. Events with no days at all sort last — they are
 * drafts and have no date to place them by.
 *
 * @param int  $limit
 * @param int  $offset
 * @param bool $archivedOnly  Restrict to archived events.
 * @return array  Event rows with date_from / date_to attached.
 */
function events_page($limit, $offset = 0, $archivedOnly = false, $includeDeleted = false) {
    // Deleted events are hidden from every listing except the admin Archive
    // tab, which is the only place they can be restored from.
    $where = $archivedOnly ? 'WHERE e.is_archived = 1' : 'WHERE 1=1';
    if (!$includeDeleted) $where .= ' AND e.is_deleted = 0';
    return db_all(
        "SELECT e.*,
                (SELECT MIN(day_date) FROM event_days d WHERE d.event_id = e.id) AS date_from,
                (SELECT MAX(day_date) FROM event_days d WHERE d.event_id = e.id) AS date_to
           FROM events e
           $where
          ORDER BY date_from IS NULL, date_from DESC, e.id DESC
          LIMIT ? OFFSET ?", [(int)$limit, max(0, (int)$offset)]);
}

/**
 * How many events a listing would page through.
 * @param bool $archivedOnly
 * @return int
 */
function events_count($archivedOnly = false, $includeDeleted = false) {
    $where = $archivedOnly ? 'WHERE is_archived = 1' : 'WHERE 1=1';
    if (!$includeDeleted) $where .= ' AND is_deleted = 0';
    return (int)db_val("SELECT COUNT(*) FROM events $where");
}

/**
 * Every ACTIVE event, soonest first — the front page switcher tabs, and the
 * admin's "which event's list?" pickers.
 *
 * Active means exactly what the admin screens mean by it: not archived, not
 * deleted. It deliberately does NOT filter on dates.
 *
 * This used to select "anything whose last day is yesterday or later", from
 * back when a passing date was the only thing that retired an event. Two
 * features since made that wrong:
 *   - auto_archive_days retires finished events explicitly, so the date test
 *     duplicated a job something else already does; and
 *   - an admin can bring an archived event BACK, which sets is_archived = 0
 *     but cannot move its dates into the future.
 * A brought-back event was therefore live everywhere else — the admin panel
 * listed it, the archive page opened it, current_event() could even select it
 * as THE event — while having no tab to reach it by. Whether an event is over
 * is now one question with one answer: is_archived.
 *
 * Ordering matches current_event() exactly, NULLs last included: the first tab
 * has to be the event the front page actually lands on, and an event with no
 * days yet must not jump ahead of real ones (plain ASC sorts NULL FIRST in
 * SQLite, which would do precisely that).
 *
 * @return array  Event rows with date_from / date_to attached.
 */
/**
 * Are the optional event details (location name, address, picture) in use?
 *
 * Off by default. When off, the fields stay on the events table but are never
 * asked for and never rendered — an admin who tries the feature, fills a few
 * in and turns it back off keeps what they typed rather than losing it.
 *
 * @return bool
 */
function event_details_enabled() {
    return opt_bool('event_details');
}

/**
 * With no event chosen, should the front page list the active events instead of
 * opening one? Off by default, which is the behaviour every existing club has.
 * @return bool
 */
function home_event_list_enabled() {
    return opt_bool('home_event_list');
}

/**
 * Should the event switcher bar be left off the page entirely? For clubs that
 * navigate via the event list and want the event page itself uncluttered.
 * @return bool
 */
function event_tabs_hidden() {
    return opt_bool('hide_event_tabs');
}

/**
 * The location details worth rendering for an event, or [] when there are none.
 *
 * One place decides this, because four templates ask the same question and
 * "details are on AND this event actually filled something in" is the kind of
 * condition that drifts when it is written out four times. Returns the pieces
 * separately rather than pre-rendered markup — the tabs, the list and the
 * calendar each present them differently.
 *
 * @param array $ev  An events row.
 * @return array  ['name' => string, 'address' => string, 'thumbnail' => string]
 *                with empty strings for the parts this event has not got; [] if
 *                the feature is off or the event has nothing at all.
 */
function event_details($ev) {
    if (!event_details_enabled()) return [];
    $out = [
        'name'      => trim((string)($ev['location_name'] ?? '')),
        'address'   => trim((string)($ev['location_address'] ?? '')),
        'thumbnail' => trim((string)($ev['thumbnail'] ?? '')),
    ];
    if ($out['name'] === '' && $out['address'] === '' && $out['thumbnail'] === '') return [];
    return $out;
}

/**
 * Should the event lists carry a games/players summary? Off by default.
 * @return bool
 */
function event_stats_enabled() {
    return opt_bool('event_stats');
}

/**
 * How many games and how many players, for a whole page of events at once.
 *
 * BATCHED DELIBERATELY. The lists that use this are paginated, and asking per
 * event would mean three queries per row — on an archive page of fifty events
 * that is a hundred and fifty round trips for a decorative line of text. Takes
 * the ids the caller is about to render and answers for all of them.
 *
 * WHAT COUNTS, and why:
 *  - A POLL counts as ONE game. It is a game-shaped slot on a table; that the
 *    club has not settled which game yet does not make it half a slot.
 *  - A poll's PLAYERS are the votes on its most-voted option — the closest
 *    honest guess at how many people that slot would seat, since the winning
 *    option is the one it will become.
 *  - RESERVES ARE NOT COUNTED. They are people who wanted a seat and did not
 *    get one, so counting them would overstate how many actually played.
 *  - The same person in three games counts three times. Guests are free text
 *    with no reliable identity, so two "Marek"s cannot be told apart from one
 *    Marek in two games — a per-person count would be a guess dressed as a
 *    number. This is a count of SEATS FILLED, which is exactly answerable.
 *
 * @param int[] $eventIds
 * @return array<int, array{games:int, players:int}>  Keyed by event id; every
 *         requested id is present, zeroed when the event has nothing.
 */
function events_stats(array $eventIds) {
    $ids = array_values(array_unique(array_map('intval', $eventIds)));
    $out = [];
    foreach ($ids as $id) $out[$id] = ['games' => 0, 'players' => 0];
    if (!$ids) return $out;

    // Safe to interpolate: every element was cast to int just above.
    $in = implode(',', $ids);

    // Real games, and the seats filled in them.
    foreach (db_all(
        "SELECT g.event_id AS eid,
                COUNT(DISTINCT g.id) AS games,
                COUNT(pl.id)         AS players
           FROM games g
           LEFT JOIN players pl ON pl.game_id = g.id AND pl.is_reserve = 0
          WHERE g.event_id IN ($in)
          GROUP BY g.event_id") as $r) {
        $out[(int)$r['eid']]['games']   += (int)$r['games'];
        $out[(int)$r['eid']]['players'] += (int)$r['players'];
    }

    // Each unresolved poll is one more game. (A poll that already resolved IS a
    // game by now and was counted above, so there is no double counting.)
    foreach (db_all("SELECT event_id AS eid, COUNT(*) AS n FROM polls
                      WHERE event_id IN ($in) GROUP BY event_id") as $r) {
        $out[(int)$r['eid']]['games'] += (int)$r['n'];
    }

    // And its players: the vote count of its best-supported option.
    foreach (db_all(
        "SELECT eid, SUM(mx) AS n FROM (
             SELECT p.event_id AS eid, MAX(x.cnt) AS mx
               FROM (SELECT pg.poll_id, pg.id, COUNT(v.id) AS cnt
                       FROM poll_games pg
                       LEFT JOIN poll_votes v ON v.poll_game_id = pg.id
                      GROUP BY pg.id) x
               JOIN polls p ON p.id = x.poll_id
              WHERE p.event_id IN ($in)
              GROUP BY p.id
         ) y GROUP BY eid") as $r) {
        $out[(int)$r['eid']]['players'] += (int)$r['n'];
    }

    return $out;
}

/**
 * Should the landing-page list show one row per DAY rather than per event?
 * Off by default; only meaningful when the list itself is switched on.
 * @return bool
 */
function event_list_by_day_enabled() {
    return opt_bool('event_list_by_day');
}

/**
 * Every day of every active event, one row each, in date order.
 *
 * For the landing page's "a row per day" mode: a club running several short
 * events would otherwise see a list ordered by event, where the next thing
 * happening is somewhere in the middle. Ordered by DATE across all events, so
 * the top of the list is the next session whichever event it belongs to.
 *
 * Each row is the EVENT's columns (so a caller can render it exactly like the
 * per-event rows — same name, location, picture) plus the day's own id, index
 * and date. date_from and date_to are both set to that day, which makes
 * event_date_label() print the single date without needing a second formatter.
 *
 * @return array
 */
function events_active_days() {
    return db_all(
        "SELECT e.*,
                d.id         AS day_id,
                d.day_index  AS day_index,
                d.day_date   AS date_from,
                d.day_date   AS date_to
           FROM event_days d
           JOIN events e ON e.id = d.event_id
          WHERE e.is_archived = 0
            AND e.is_deleted  = 0
          ORDER BY d.day_date IS NULL, d.day_date ASC, e.id ASC, d.day_index ASC");
}

/**
 * events_stats() for single DAYS instead of whole events.
 *
 * Same rules, same batching, same shape of answer — see events_stats() for why
 * each of them is what it is. Kept as its own function rather than a flag on
 * that one: the two group by different columns, and a function that changes
 * what its keys MEAN depending on an argument is the kind that gets called
 * wrongly later.
 *
 * @param int[] $dayIds
 * @return array<int, array{games:int, players:int}>  Keyed by event_days.id.
 */
function event_days_stats(array $dayIds) {
    $ids = array_values(array_unique(array_map('intval', $dayIds)));
    $out = [];
    foreach ($ids as $id) $out[$id] = ['games' => 0, 'players' => 0];
    if (!$ids) return $out;

    $in = implode(',', $ids);   // every element cast to int just above

    foreach (db_all(
        "SELECT g.day_id AS did,
                COUNT(DISTINCT g.id) AS games,
                COUNT(pl.id)         AS players
           FROM games g
           LEFT JOIN players pl ON pl.game_id = g.id AND pl.is_reserve = 0
          WHERE g.day_id IN ($in)
          GROUP BY g.day_id") as $r) {
        $out[(int)$r['did']]['games']   += (int)$r['games'];
        $out[(int)$r['did']]['players'] += (int)$r['players'];
    }

    foreach (db_all("SELECT day_id AS did, COUNT(*) AS n FROM polls
                      WHERE day_id IN ($in) GROUP BY day_id") as $r) {
        $out[(int)$r['did']]['games'] += (int)$r['n'];
    }

    foreach (db_all(
        "SELECT did, SUM(mx) AS n FROM (
             SELECT p.day_id AS did, MAX(x.cnt) AS mx
               FROM (SELECT pg.poll_id, pg.id, COUNT(v.id) AS cnt
                       FROM poll_games pg
                       LEFT JOIN poll_votes v ON v.poll_game_id = pg.id
                      GROUP BY pg.id) x
               JOIN polls p ON p.id = x.poll_id
              WHERE p.day_id IN ($in)
              GROUP BY p.id
         ) y GROUP BY did") as $r) {
        $out[(int)$r['did']]['players'] += (int)$r['n'];
    }

    return $out;
}

function events_active() {
    return db_all(
        "SELECT e.*,
                (SELECT MIN(day_date) FROM event_days d WHERE d.event_id = e.id) AS date_from,
                (SELECT MAX(day_date) FROM event_days d WHERE d.event_id = e.id) AS date_to
           FROM events e
          WHERE e.is_archived = 0
            AND e.is_deleted = 0
          ORDER BY date_from IS NULL, date_from ASC, e.id ASC");
}

/**
 * Every event day falling inside a calendar month, keyed by 'YYYY-MM-DD'.
 *
 * Returns a map of date => list of ['id','name','location_name'] so the calendar
 * can mark a cell and link it without a query per day. A multi-day event appears
 * under EVERY day it covers, which is what a calendar reader expects — an event
 * running Fri-Sun should be visible on all three.
 *
 * location_name rides along for the calendar's second line. Selected
 * unconditionally: whether it is SHOWN is the template's business (via
 * event_details()), and making the query depend on the option would mean the
 * calendar quietly returning different columns depending on a setting.
 *
 * Archived events are included: the calendar lives in the archive section and
 * looking back at what happened is its whole purpose.
 *
 * @param int $year
 * @param int $month  1-12
 * @return array<string, array<int, array>>
 */
function events_by_day($year, $month) {
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last  = date('Y-m-t', strtotime($first));
    $rows  = db_all(
        "SELECT d.day_date, d.day_index, e.id, e.name, e.location_name
           FROM event_days d
           JOIN events e ON e.id = d.event_id
          WHERE d.day_date BETWEEN ? AND ?
            AND e.is_deleted = 0
          ORDER BY d.day_date, e.id", [$first, $last]);
    $out = [];
    foreach ($rows as $r) {
        // day_index comes from the row the cell was built from, so a click on
        // the 14th lands on the 14th rather than on the event's first day.
        // Taken from the joined row rather than derived from the date, so a
        // legacy event whose indexes are not yet in date order still links to
        // the right one.
        $out[$r['day_date']][] = [
            'id'   => (int)$r['id'],
            'name' => $r['name'],
            'day'  => (int)$r['day_index'],
            // Second line under the name; the template decides whether to use it.
            'location_name' => (string)($r['location_name'] ?? ''),
        ];
    }
    return $out;
}

/**
 * The grid a month calendar needs: whole weeks, Monday-first, including the
 * trailing days of the previous month and the leading days of the next so the
 * table is always rectangular.
 *
 * Monday-first because the app's audience is Polish and a week starting Sunday
 * would read wrong; the weekday labels come from the same assumption.
 *
 * @param int $year
 * @param int $month  1-12
 * @return array  Weeks, each a list of ['date','day','current'].
 */
function calendar_weeks($year, $month) {
    $firstTs = mktime(0, 0, 0, $month, 1, $year);
    // 'N' is 1 (Mon) .. 7 (Sun); shift so Monday starts the row.
    $lead    = (int)date('N', $firstTs) - 1;
    $days    = (int)date('t', $firstTs);
    $cells   = [];

    for ($i = $lead; $i > 0; $i--) {
        $ts = mktime(0, 0, 0, $month, 1 - $i, $year);
        $cells[] = ['date' => date('Y-m-d', $ts), 'day' => (int)date('j', $ts), 'current' => false];
    }
    for ($d = 1; $d <= $days; $d++) {
        $ts = mktime(0, 0, 0, $month, $d, $year);
        $cells[] = ['date' => date('Y-m-d', $ts), 'day' => $d, 'current' => true];
    }
    // Pad to a whole number of weeks.
    while (count($cells) % 7 !== 0) {
        $ts = mktime(0, 0, 0, $month, ++$days, $year);
        $cells[] = ['date' => date('Y-m-d', $ts), 'day' => (int)date('j', $ts), 'current' => false];
    }
    return array_chunk($cells, 7);
}

/**
 * Clamp a year/month pair, rolling the month into the year when it goes out of
 * range. Lets the navigation just do month +/- 1 without special-casing
 * December and January.
 *
 * @return array [year, month]
 */
function calendar_normalise($year, $month) {
    $year  = (int)$year;
    $month = (int)$month;
    while ($month > 12) { $month -= 12; $year++; }
    while ($month < 1)  { $month += 12; $year--; }
    // A sane window: far enough either way for any real club, tight enough that
    // a hand-edited URL cannot walk the calendar into year 900000.
    $year = max(1970, min(2200, $year));
    return [$year, $month];
}

/**
 * Archive events whose last day ended more than 'auto_archive_days' ago.
 *
 * Only meaningful with public archives on — without it, creating an event
 * already archives the previous one. Zero means never. Called from the front
 * page alongside the poll sweep (the app has no scheduler).
 *
 * @return int  Events archived.
 */
/**
 * Would the auto-archive sweep pick this event up right now?
 *
 * Deliberately sits next to events_auto_archive() and asks the same question of
 * one event, so a change to the rule cannot leave the admin warning describing
 * the old one. Un-archiving an event older than the threshold otherwise looks
 * like it worked and is quietly undone by the next visitor's page load.
 *
 * @param int $eventId
 * @return bool  False whenever the sweep is off, so callers need no extra test.
 */
function event_auto_archive_due($eventId) {
    if (!public_archives_enabled()) return false;
    $days = opt_int('auto_archive_days');
    if ($days <= 0) return false;

    $last = db_val('SELECT MAX(day_date) FROM event_days WHERE event_id = ?', [(int)$eventId]);
    // No days yet: the sweep skips it (IS NOT NULL), so nothing to warn about.
    if ($last === null || $last === '') return false;

    return $last < date('Y-m-d', strtotime('-' . $days . ' day'));
}

function events_auto_archive() {
    if (!public_archives_enabled()) return 0;
    $days = opt_int('auto_archive_days');
    if ($days <= 0) return 0;

    $cutoff = date('Y-m-d', strtotime('-' . $days . ' day'));
    $due = db_all(
        "SELECT e.id FROM events e
          WHERE e.is_archived = 0
            AND e.is_deleted = 0
            AND (SELECT MAX(day_date) FROM event_days d WHERE d.event_id = e.id) IS NOT NULL
            AND (SELECT MAX(day_date) FROM event_days d WHERE d.event_id = e.id) < ?", [$cutoff]);
    if (!$due) return 0;
    $now = gmdate('Y-m-d H:i:s');
    foreach ($due as $r) {
        db_run('UPDATE events SET is_archived = 1, archived_at = ? WHERE id = ?', [$now, (int)$r['id']]);
    }
    return count($due);
}

/**
 * All day rows for an event, ordered by their 1-based index.
 * @return array
 */
function event_days($eventId) {
    // Ordered by DATE, not by day_index: an admin who adds a day after the fact
    // gets it in the right place on the front page without the stored indexes
    // having to be rewritten first. event_days_renumber() then brings the
    // indexes back in line whenever the day list actually changes, so the two
    // orders agree from that point on.
    return db_all('SELECT * FROM event_days WHERE event_id = ? ORDER BY day_date, day_index',
                  [$eventId]);
}

/**
 * Renumber an event's days 1..N in date order and sync events.num_days.
 *
 * day_index is the identity the front end uses (?day=N) and index.php clamps it
 * to num_days, so the indexes MUST stay contiguous — a gap left by a deleted
 * day would make that day's slot unreachable and push the last day past the
 * clamp.
 *
 * @param int $eventId
 * @return int  The new number of days.
 */
function event_days_renumber($eventId) {
    $rows = db_all('SELECT id FROM event_days WHERE event_id = ? ORDER BY day_date, day_index',
                   [(int)$eventId]);
    $i = 0;
    foreach ($rows as $r) {
        $i++;
        db_run('UPDATE event_days SET day_index = ? WHERE id = ?', [$i, (int)$r['id']]);
    }
    // num_days drives the clamp, so it has to move with the actual count.
    db_run('UPDATE events SET num_days = ? WHERE id = ?', [max(1, $i), (int)$eventId]);
    return $i;
}

/**
 * Add a day to an existing event.
 *
 * @return bool  False if the date is missing or already used by this event.
 */
function event_day_add($eventId, $date, $start, $end) {
    $eventId = (int)$eventId;
    $date    = trim((string)$date);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    // Two days on one date would give the event two identical tabs.
    $clash = db_val('SELECT COUNT(*) FROM event_days WHERE event_id = ? AND day_date = ?',
                    [$eventId, $date]);
    if ((int)$clash > 0) return false;

    $start = preg_match('/^\d{1,2}:\d{2}$/', (string)$start) ? $start : opt('default_start_time');
    $end   = preg_match('/^\d{1,2}:\d{2}$/', (string)$end)   ? $end   : opt('default_end_time');

    // Appended with a temporary index; renumber puts it in date order.
    $next = (int)db_val('SELECT COALESCE(MAX(day_index), 0) + 1 FROM event_days WHERE event_id = ?',
                        [$eventId]);
    db_run('INSERT INTO event_days (event_id, day_index, day_date, start_time, end_time)
            VALUES (?,?,?,?,?)', [$eventId, $next, $date, $start, $end]);
    event_days_renumber($eventId);
    return true;
}

/**
 * Remove a day, with everything scheduled on it.
 *
 * Refuses to remove the LAST day: an event with no days has no page to show and
 * no way back through this screen, so deleting the event itself is the right
 * action at that point.
 *
 * @return bool
 */
function event_day_delete($dayId) {
    $day = db_one('SELECT * FROM event_days WHERE id = ?', [(int)$dayId]);
    if (!$day) return false;
    $left = (int)db_val('SELECT COUNT(*) FROM event_days WHERE event_id = ?', [$day['event_id']]);
    if ($left <= 1) return false;

    // game_tables cascades to games, players and comments from here.
    db_run('DELETE FROM event_days WHERE id = ?', [(int)$dayId]);
    event_days_renumber((int)$day['event_id']);
    return true;
}

/**
 * How much is scheduled on a day — shown before deleting it, so an admin can
 * see what would go with it.
 *
 * @return array ['tables' => int, 'games' => int, 'players' => int]
 */
function event_day_contents($dayId) {
    $dayId = (int)$dayId;
    return [
        'tables'  => (int)db_val('SELECT COUNT(*) FROM game_tables WHERE day_id = ?', [$dayId]),
        'games'   => (int)db_val('SELECT COUNT(*) FROM games WHERE day_id = ?', [$dayId]),
        'players' => (int)db_val(
            'SELECT COUNT(*) FROM players p JOIN games g ON g.id = p.game_id WHERE g.day_id = ?', [$dayId]),
    ];
}

/**
 * A single day row by event + 1-based index, or null.
 * @return array|null
 */
function event_day($eventId, $dayIndex) {
    return db_one('SELECT * FROM event_days WHERE event_id = ? AND day_index = ?', [$eventId, $dayIndex]);
}

/**
 * Fully assemble a day's tables for rendering. Each table gets an 'items' list:
 * games AND polls interleaved, sorted by start time (games carry their players;
 * polls carry their candidates with vote counts). Each item is
 * ['type'=>'game'|'poll', 'start'=>'HH:MM', 'data'=>row]. The template just
 * iterates and renders the right card per type.
 *
 * This is the single read that backs the main event page, so it does several
 * queries; it's only ever called once per request for the active day.
 *
 * @param int $dayId
 * @return array  Table rows, each augmented with ['items'].
 */
function event_tables_full($dayId) {
    require_once __DIR__ . '/polls.php';   // poll_full() (require_once: safe if already loaded)
    // The day's opening hour: needed so items sort on the DAY'S clock (a game
    // at 00:30 on an 18:00->03:00 day comes after the evening, not before it).
    $dayRow      = db_one('SELECT start_time FROM event_days WHERE id = ?', [$dayId]);
    $dayStartMin = hhmm_to_min($dayRow['start_time'] ?? '00:00');
    $tables = db_all('SELECT * FROM game_tables WHERE day_id = ? ORDER BY table_number', [$dayId]);
    foreach ($tables as &$tbl) {           // &$tbl: we mutate the row in place to add ['items']
        $items = [];

        // --- Games on this table (each with its players, and comments if on). ---
        $games = db_all('SELECT * FROM games WHERE table_id = ? ORDER BY start_time, id', [$tbl['id']]);
        $discussions = opt_bool('allow_discussions');
        foreach ($games as $g) {
            // Players ordered confirmed-first (is_reserve 0 before 1), then by id.
            // account_name rides along so admins can SEE which entries are bound
            // to an account (the @ marker in the cards) — NULL for guest entries.
            $g['players'] = db_all(
                'SELECT p.*, u.display_name AS account_name
                 FROM players p LEFT JOIN users u ON u.id = p.user_id
                 WHERE p.game_id = ? ORDER BY p.is_reserve, p.id', [$g['id']]);
            // Only pay for the comments query when discussions are enabled.
            $g['comments'] = $discussions
                ? db_all('SELECT * FROM comments WHERE game_id = ? ORDER BY id', [$g['id']])
                : [];
            $items[] = ['type' => 'game', 'start' => $g['start_time'], 'data' => $g];
        }

        // --- Polls on this table (loaded with candidates + vote tallies). ---
        // Skipped entirely when polls are switched off: the option already
        // blocks CREATING one, but without this an admin who turns the feature
        // off still sees every poll already in flight, which is not "disabled"
        // in any sense a visitor would recognise. Nothing is deleted — the rows
        // stay put and reappear if the option is turned back on.
        $polls = opt_bool('allow_polls')
            ? db_all('SELECT * FROM polls WHERE table_id = ? ORDER BY start_time, id', [$tbl['id']])
            : [];
        foreach ($polls as $p) {
            $items[] = ['type' => 'poll', 'start' => $p['start_time'], 'data' => poll_full($p)];
        }

        // Interleave games + polls by start time ON THE DAY'S CLOCK: times
        // before the opening hour count as next-morning (see day_rel_min), so
        // after-midnight games land at the end of the evening, where they belong.
        usort($items, function ($a, $b) use ($dayStartMin) {
            return day_rel_min($a['start'], $dayStartMin) <=> day_rel_min($b['start'], $dayStartMin);
        });

        $tbl['items'] = $items;
    }
    unset($tbl);                           // break the foreach reference
    return $tables;
}

/**
 * Next table number for a day (1-based, continues from existing).
 * @return int
 */
function event_next_table_number($dayId) {
    $max = (int)db_val('SELECT MAX(table_number) FROM game_tables WHERE day_id = ?', [$dayId]);
    return $max + 1;
}

/**
 * How many tables a day already has (used to enforce the max_tables cap).
 * @return int
 */
function event_table_count($dayId) {
    return (int)db_val('SELECT COUNT(*) FROM game_tables WHERE day_id = ?', [$dayId]);
}

/**
 * Default start time for a new game (or poll) on a table: the day's start if
 * the table has nothing on it yet, otherwise the latest END on the day's clock
 * of everything already there — games AND open polls alike (day_rel_min, so
 * after-midnight items count as latest, and a long earlier item that ends last
 * wins over a later short one). Returns 'HH:MM', wrapped past midnight so the
 * form's time input can display it. Lets the add-game/add-poll forms pre-fill
 * a sensible "next slot" so the table's schedule lines up.
 *
 * A poll's own "end" is an ESTIMATE (see poll_length_minutes()) — nobody knows
 * which candidate will win yet — so a club running poll_game_length_mode=fixed
 * gets a flat 2h reservation after any open poll, while max/avg modes derive it
 * from whatever candidates have been added to that poll so far.
 *
 * @param int    $tableId
 * @param string $dayStart  'HH:MM' fallback for a table with nothing on it.
 * @return string
 */
function event_next_start_time($tableId, $dayStart) {
    // Latest item ON THE DAY'S CLOCK: a plain ORDER BY start_time would put a
    // '00:30' after-midnight item first, not last, so pick the max via
    // day_rel_min in PHP instead (tables hold a handful of items at most).
    $dayStartMin = hhmm_to_min($dayStart);
    $lastEnd = null;                                   // null = nothing on this table yet

    $rows = db_all('SELECT start_time, length_minutes FROM games WHERE table_id = ?', [$tableId]);
    foreach ($rows as $r) {
        $end = day_rel_min($r['start_time'], $dayStartMin) + (int)$r['length_minutes'];
        if ($lastEnd === null || $end > $lastEnd) $lastEnd = $end;
    }

    // Open polls on the table reserve their estimated length too. Skipped
    // entirely when polls are switched off, same as event_tables_full()'s own
    // guard — nothing to add if the feature is off. Lazily required: several
    // callers of this function (e.g. add_game.php) never load inc/polls.php
    // themselves.
    if (opt_bool('allow_polls')) {
        require_once __DIR__ . '/polls.php';
        foreach (db_all('SELECT id, start_time FROM polls WHERE table_id = ?', [$tableId]) as $p) {
            $cands = db_all('SELECT length_minutes FROM poll_games WHERE poll_id = ?', [$p['id']]);
            $end = day_rel_min($p['start_time'], $dayStartMin) + poll_length_minutes($cands);
            if ($lastEnd === null || $end > $lastEnd) $lastEnd = $end;
        }
    }

    if ($lastEnd === null) return $dayStart;           // table has nothing on it yet
    // Wrap past midnight for display ('25:30' -> '01:30'): the form's
    // type="time" input only accepts 00:00-23:59, and with day_rel_min doing
    // the ordering everywhere, the wrapped form is unambiguous on this day.
    return min_to_hhmm($lastEnd % 1440);
}

/* ---- Permissions --------------------------------------------------------- *
 *  Two front-end gates. Both share the same shape: a "guest only" event lets
 *  everyone act (there are no accounts to distinguish), a logged-in user can
 *  always act, and otherwise a guest can act only if the admin opted in.
 * --------------------------------------------------------------------------- */

/**
 * May the current visitor ADD games (and tables)?
 * @return bool
 */
function can_add_games() {
    if (opt('registration_mode') === 'guest_only') return true;
    if (is_logged_in()) return true;
    return opt_bool('allow_unregistered_add_games');
}

/**
 * May the current visitor SIGN UP for games? (Same shape as adding.)
 * @return bool
 */
function can_signup() {
    if (opt('registration_mode') === 'guest_only') return true;
    if (is_logged_in()) return true;
    return opt_bool('allow_unregistered_signup');
}

/**
 * Are table names in use at all? Driven by the 'table_names_mode' option:
 *   'off'     — feature disabled entirely.
 *   'admin'   — only admins may set and edit names.
 *   'add_any' — anyone may set a name when ADDING a table; only admins edit.
 *   'any'     — anyone may set and edit names.
 * @return bool
 */
function table_names_enabled() {
    return opt('table_names_mode') !== 'off';
}

/**
 * May the current visitor SET a table name while adding a table?
 * (The add itself is separately gated by can_add_games(); this only decides
 * whether the optional name input appears / is honoured.)
 * @return bool
 */
function table_names_can_set() {
    switch (opt('table_names_mode')) {
        case 'admin':   return is_admin();
        case 'add_any':
        case 'any':     return true;
        default:        return false;   // 'off' (or an unknown value)
    }
}

/**
 * May the current visitor EDIT (rename/clear) an existing table's name?
 * @return bool
 */
function table_names_can_edit() {
    switch (opt('table_names_mode')) {
        case 'admin':
        case 'add_any': return is_admin();
        case 'any':     return true;
        default:        return false;   // 'off' (or an unknown value)
    }
}

/* ---- Small formatting helpers -------------------------------------------- */

/**
 * 'HH:MM' -> minutes since midnight. Returns 0 on anything that doesn't match,
 * so callers don't have to validate first.
 * @return int
 */
function hhmm_to_min($s) {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string)$s, $m)) return 0;
    return (int)$m[1] * 60 + (int)$m[2];
}

/**
 * 'HH:MM' -> minutes on the DAY'S OWN clock. The pivot sits GRACE hours before
 * the day's opening hour (option 'overnight_grace_hours', default 1): times at
 * or after the pivot belong to this day — including a bit BEFORE opening, so
 * someone can schedule a 17:30 setup slot for an 18:00 day — while anything
 * earlier than the pivot is taken to mean the next morning (+24h).
 *
 * This is what lets a single event day run past midnight (18:00 -> 03:00):
 * a game entered at '00:30' on such a day is half past midnight AFTER the
 * evening games, not half past midnight before them. On a normal day
 * (10:00 -> 22:00) every sensible time is >= the pivot, so this is a no-op.
 * All ordering/plotting goes through this; raw 'HH:MM' strings are what's
 * stored and displayed. With a missing option (pre-update DB) grace is 0,
 * which is exactly the old pivot-at-opening behaviour.
 *
 * @param string $hhmm         A stored 'HH:MM' time.
 * @param int    $dayStartMin  The day's opening hour, in minutes (hhmm_to_min).
 * @return int                 Minutes; >= the pivot for in-day times.
 */
function day_rel_min($hhmm, $dayStartMin) {
    $pivot = $dayStartMin - opt_int('overnight_grace_hours') * 60;
    $m = hhmm_to_min($hhmm);
    return $m < $pivot ? $m + 1440 : $m;
}

/**
 * Is a proposed start time inside the day's own opening..closing window?
 *
 * Both ends are read on the day's clock (day_rel_min), so this is correct for
 * overnight days too: on 18:00 -> 03:00, a 01:00 start is inside (it maps past
 * midnight to 25:00, which is <= the 03:00 = 27:00 close) while 05:00 is not.
 * The window edges are the ACTUAL opening/closing hours — the overnight grace
 * (early-setup) window does NOT widen them, so with this rule ON a pre-opening
 * setup slot is rejected, which is the point of the rule.
 *
 * Only meaningful when option 'allow_start_outside_hours' is 0; callers guard
 * on that. Returns true when the option is on (nothing to enforce).
 *
 * @param string $hhmm     Proposed 'HH:MM' start.
 * @param array  $dayRow   The event_days row (needs start_time / end_time).
 * @return bool
 */
function start_within_event_hours($hhmm, $dayRow) {
    if (opt_bool('allow_start_outside_hours')) return true;   // rule off -> always fine
    $startMin = hhmm_to_min($dayRow['start_time']);
    $endMin   = day_rel_min($dayRow['end_time'], $startMin);
    $t        = day_rel_min($hhmm, $startMin);
    return $t >= $startMin && $t <= $endMin;
}

/**
 * The min/max attribute pair for a start-time <input>, or null when the option
 * lets starts fall anywhere (no clamp). On an overnight day max < min, which
 * HTML time inputs read as a wrap-around range (valid = >= min OR <= max) —
 * exactly the overnight window we want.
 *
 * @param array $dayRow  The event_days row (start_time / end_time), or null.
 * @return array|null    ['min' => 'HH:MM', 'max' => 'HH:MM'] or null.
 */
function start_time_bounds($dayRow) {
    if (!$dayRow || opt_bool('allow_start_outside_hours')) return null;
    return ['min' => $dayRow['start_time'], 'max' => $dayRow['end_time']];
}

/**
 * A day's opening hours as a single label, e.g. "10:00 – 22:00".
 *
 * Shown on the home page under the date, because events at the same venue
 * often start at different times from one week to the next and that isn't
 * otherwise visible without opening a game.
 *
 * An OVERNIGHT day reads back exactly as stored — "18:00 – 03:00" — which is
 * precisely the fact worth surfacing, so it needs no special marking. The
 * separator lives in the language files rather than being hardcoded, since not
 * every language punctuates a range the same way.
 *
 * @param array|null $dayRow  The event_days row (start_time / end_time).
 * @return string  '' when either end is missing, so callers can skip the element.
 */
function day_hours_label($dayRow) {
    $from = trim((string)($dayRow['start_time'] ?? ''));
    $to   = trim((string)($dayRow['end_time'] ?? ''));
    if ($from === '' || $to === '') return '';
    return t('day_hours', $from, $to);
}

/**
 * Drop the bringer's own sign-up when their game is soft-deleted.
 *
 * A game whose owner has withdrawn should not leave that owner sitting in its
 * player list — they are the one who just cancelled. The row only goes when it
 * can be matched CONFIDENTLY, since two different people can share a first
 * name at a club:
 *
 *   - by account, when both the game and the sign-up carry the same user_id;
 *   - by email, when both sides have one and they match;
 *   - by name, ONLY when the game has no email to match on AND exactly one
 *     player has that name — an ambiguous name is left alone rather than
 *     guessed at, because removing the wrong person's sign-up is worse than
 *     leaving a stale one.
 *
 * @param array $game  The game row, before archiving.
 * @return int  How many sign-ups were removed (0 or 1).
 */
function game_drop_bringer_signup($game) {
    $gameId = (int)$game['id'];
    $uid    = $game['brings_user_id'] ?? null;
    $email  = trim((string)($game['brings_email'] ?? ''));
    $name   = trim((string)($game['brings_name'] ?? ''));

    if ($uid) {
        $row = db_one('SELECT id FROM players WHERE game_id = ? AND user_id = ?', [$gameId, (int)$uid]);
        if ($row) { db_run('DELETE FROM players WHERE id = ?', [(int)$row['id']]); return 1; }
    }
    if ($email !== '') {
        $row = db_one('SELECT id FROM players WHERE game_id = ? AND LOWER(email) = LOWER(?)',
                      [$gameId, $email]);
        if ($row) { db_run('DELETE FROM players WHERE id = ?', [(int)$row['id']]); return 1; }
        // The game HAS an email and no sign-up matched it, so a name match here
        // would be a different person who happens to share the name.
        return 0;
    }
    if ($name !== '') {
        $rows = db_all('SELECT id FROM players WHERE game_id = ? AND LOWER(name) = LOWER(?)',
                       [$gameId, $name]);
        if (count($rows) === 1) { db_run('DELETE FROM players WHERE id = ?', [(int)$rows[0]['id']]); return 1; }
    }
    return 0;
}

/** How a game may be removed. */
function game_deletion_modes() {
    return ['choose', 'soft', 'hard'];
}

/**
 * The configured deletion mode.
 * @return string 'choose'|'soft'|'hard'
 */
function game_deletion_mode() {
    $m = trim((string)opt('game_deletion'));
    return in_array($m, game_deletion_modes(), true) ? $m : 'choose';
}

/** How a soft-deleted game is shown. */
function deleted_games_displays() {
    return ['name', 'full'];
}

/**
 * @return string 'name'|'full'
 */
function deleted_games_display() {
    $m = trim((string)opt('deleted_games_display'));
    return in_array($m, deleted_games_displays(), true) ? $m : 'name';
}

/** The tab layouts an admin can choose between, in the order they are offered. */
function day_tab_formats() {
    return ['num_date_hours', 'num_weekday_date_hours', 'weekday_date_hours_name',
            'name_date_hours', 'name', 'date', 'date_hours'];
}

/**
 * Are optional per-day labels switched on?
 * @return bool
 */
function day_names_enabled() {
    return opt_bool('use_day_names');
}

/**
 * A day's weekday name in the current language, or '' without a date.
 *
 * @param array $dayRow
 * @return string
 */
function day_weekday_label($dayRow) {
    $date = trim((string)($dayRow['day_date'] ?? ''));
    if ($date === '') return '';
    $ts = strtotime($date);
    return $ts === false ? '' : t('weekday_' . (int)date('w', $ts));
}

/**
 * The pieces of one day tab, in display order, for the configured format.
 *
 * Returns a list of ['class' => ..., 'text' => ...]. Building it here rather
 * than branching inside the template keeps the six layouts in one readable
 * place, and lets them be tested without rendering a page.
 *
 * Empty pieces are dropped, so a format that asks for a name on an event that
 * has none simply shows less rather than an empty line. If a format would
 * leave NOTHING, the day number is used — a blank tab would be unclickable in
 * practice.
 *
 * @param array  $dayRow
 * @param string $format  One of day_tab_formats(); anything else falls back.
 * @return array
 */
function day_tab_parts($dayRow, $format = null) {
    $format = $format ?? opt('day_tab_format');
    if (!in_array($format, day_tab_formats(), true)) $format = 'num_date_hours';

    $num     = t('day_n', (int)($dayRow['day_index'] ?? 1));
    $date    = trim((string)($dayRow['day_date'] ?? ''));
    $hours   = day_hours_label($dayRow);
    $weekday = day_weekday_label($dayRow);
    // The label is only ever shown when the feature is on, so switching it off
    // hides existing names without deleting them.
    $name    = day_names_enabled() ? trim((string)($dayRow['day_name'] ?? '')) : '';

    $order = [];
    switch ($format) {
        case 'num_weekday_date_hours':
            $order = [['num', $num], ['weekday', $weekday], ['date', $date], ['hours', $hours]];
            break;
        case 'weekday_date_hours_name':
            $order = [['weekday', $weekday], ['date', $date], ['hours', $hours], ['name', $name]];
            break;
        case 'name_date_hours':
            // The DATE leads here, with the label and hours as supporting
            // lines — the opposite emphasis to 'weekday_date_hours_name',
            // where the name is the afterthought.
            $order = [['name', $name], ['date', $date], ['hours', $hours]];
            break;
        case 'name':
            $order = [['name', $name]];
            break;
        case 'date':
            $order = [['date', $date]];
            break;
        case 'date_hours':
            $order = [['date', $date], ['hours', $hours]];
            break;
        default:   // num_date_hours — the original layout
            $order = [['num', $num], ['date', $date], ['hours', $hours]];
            break;
    }

    $parts = [];
    foreach ($order as $o) {
        if (trim((string)$o[1]) === '') continue;
        $parts[] = ['class' => 'day-tab-' . $o[0], 'text' => $o[1]];
    }
    // Never render an empty tab: "name only" on an unnamed day would otherwise
    // produce a control with no label at all.
    if (!$parts) $parts[] = ['class' => 'day-tab-num', 'text' => $num];
    return $parts;
}

/**
 * Minutes since midnight -> 'HH:MM'. Negative input is floored to 0.
 * NOTE: does NOT wrap past 24h (90 min past midnight -> '25:00'); the timeline
 * handles its own modulo-24 display where it needs wrapped hour labels.
 * @return string
 */
function min_to_hhmm($min) {
    $min = max(0, (int)$min);
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/**
 * Map a 1..5 weight to a bucket 1..5 (for the colour-coded class).
 * Rounds to the nearest integer and clamps, so e.g. 2.3 -> bucket 2.
 * @return int
 */
function weight_bucket($w) {
    $b = (int)round((float)$w);
    return max(1, min(5, $b));
}

/**
 * The admin-configured game-language choices (the 'game_languages' option,
 * one per line). Blank lines are skipped; order is preserved. Used by the
 * add/edit game and poll-candidate forms to build the language dropdown.
 * @return string[]
 */
function game_language_options() {
    $out = [];
    foreach (preg_split('/\R/', opt('game_languages')) as $line) {   // \R = any newline style
        $line = trim($line);
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

/**
 * Visual tone for the 0/1/2 rules codes, shared by BOTH code families
 * (explain_rules and knows_rules — 0 is the positive answer in each):
 *   0 -> 'good' (green: will explain / knows), 1 -> 'mid' (neutral: summary /
 *   somewhat), 2 -> 'bad' (red: won't explain / doesn't know).
 * Templates append it to a class prefix (e.g. "rules-good"); the colours live
 * in each theme's CSS.
 * @param int|null $code
 * @return string  'good' | 'mid' | 'bad'
 */
function rules_tone($code) {
    $code = (int)$code;
    return $code === 0 ? 'good' : ($code === 1 ? 'mid' : 'bad');
}

/**
 * Clean a user-supplied game link: trim, auto-prepend https:// when the person
 * typed a bare domain, then validate. Returns '' when the input is empty or
 * doesn't survive validation (so callers can store NULL).
 * @param string $raw
 * @return string
 */
/**
 * Is this table completely empty — nothing on it at all?
 *
 * Counts rows, INCLUDING soft-deleted games and any polls, rather than asking
 * whether the rendered card list looks empty. Deleting a table cascades to its
 * games, so a table still holding a deleted game is not empty: removing it
 * would destroy that history silently. The rendered list hides those, which is
 * exactly why it is the wrong thing to ask.
 *
 * @param int $tableId
 * @return bool
 */
function table_is_empty($tableId) {
    $tableId = (int)$tableId;
    if ($tableId <= 0) return false;
    if ((int)db_val('SELECT COUNT(*) FROM games WHERE table_id = ?', [$tableId]) > 0) return false;
    if ((int)db_val('SELECT COUNT(*) FROM polls WHERE table_id = ?', [$tableId]) > 0) return false;
    return true;
}

function game_link_sanitize($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (!preg_match('#^https?://#i', $raw)) $raw = 'https://' . $raw;   // bare domain convenience
    return filter_var($raw, FILTER_VALIDATE_URL) ? $raw : '';
}

/**
 * The external link for a game, or null when there is none to offer:
 *   - BGG games link to their boardgamegeek.com page (always allowed);
 *   - manual games may carry a user-supplied URL in games.link, which is only
 *     honoured while the 'allow_custom_game_links' option is on and the stored
 *     value still looks like an http(s) URL (defence in depth: it's validated
 *     on save too, but the option can be toggled after links were saved).
 * @param array $g  A games row.
 * @return string|null
 */
/**
 * The rules / manual URL attached to a game or poll candidate, or null.
 *
 * Separate from game_link(): that one points at the GAME (its BGG page or a
 * custom page), this one points at how to PLAY it — a rules PDF, a how-to-play
 * video. A game can legitimately have both, and they are different buttons.
 *
 * Gated on its own option so an admin can switch off user-supplied links
 * without losing what is already stored — turning it back on restores the
 * buttons. Re-validates the stored value on the way out rather than trusting
 * the database: a row written before a sanitiser existed, or by hand, must not
 * be able to emit a javascript: URL into an href.
 *
 * @param array $row  A games or poll_games row.
 * @return string|null
 */
function manual_link($row) {
    if (!opt_bool('allow_manual_links')) return null;
    $raw = trim((string)($row['manual_link'] ?? ''));
    if ($raw === '') return null;
    return preg_match('#^https?://#i', $raw) ? $raw : null;
}

function game_link($g) {
    if (!empty($g['bgg_id'])) {
        return 'https://boardgamegeek.com/boardgame/' . (int)$g['bgg_id'];
    }
    if (!empty($g['link']) && opt_bool('allow_custom_game_links')
        && preg_match('#^https?://#i', $g['link'])) {
        return $g['link'];
    }
    return null;
}

/**
 * Localized label for the "do you explain the rules" code (0/1/2).
 * Stored as an int code; the human text lives in the language files.
 * @return string
 */
function explain_rules_label($code) {
    $map = [0 => 'rules_explain', 1 => 'rules_summary', 2 => 'rules_known'];
    return t($map[(int)$code] ?? 'rules_explain');
}

/**
 * Localized label for the "do you know the rules" code (0/1/2 or null).
 * Null/'' (not asked) -> empty string.
 * @return string
 */
function knows_rules_label($code) {
    if ($code === null || $code === '') return '';
    $map = [0 => 'knows_yes', 1 => 'knows_somewhat', 2 => 'knows_no'];
    return t($map[(int)$code] ?? 'knows_yes');
}

/* ---- Timeline ------------------------------------------------------------ *
 *  Build the data for the per-day timeline from already-loaded tables. The
 *  span runs from the day's start to its end + the admin's extension, stretched
 *  further if any game ends later. Games on a table are packed into "lanes" so
 *  overlapping ones sit on separate rows. Positions are percentages of the span
 *  (the template turns them into inline left/width — a justified dynamic style).
 *  Polls appear as provisional 2-hour blocks (nobody knows the winner's real
 *  length yet); once resolved they show as normal game blocks.
 *  Returns null when the day has nothing to draw.
 *
 *  OUTPUT SHAPE (consumed by templates/light/timeline.php):
 *    ['hours'  => [ ['label'=>'14:00','left'=>33.3], ... ],
 *     'bands'  => [ ['left'=>0,'width'=>8.3], ... ],   // one per hour, for the stripes
 *     'tables' => [ ['number'=>1, 'lanes'=> [ [block, block...], [block...] ] ], ... ]]
 *  where a block = ['type'('game'|'poll'),'id','name','start_time','cur','max','full','left','width'].
 * --------------------------------------------------------------------------- */
function timeline_build($dayRow, $tables, $extHours) {
    // The visible window: day start .. day end + extension hours, on the DAY'S
    // OWN clock (day_rel_min): an end of '03:00' on an 18:00 day means 3 a.m.
    // the next morning (27:00), so a single day can run past midnight.
    $dayStartMin = hhmm_to_min($dayRow['start_time']);
    $startMin = $dayStartMin;
    $endMin   = day_rel_min($dayRow['end_time'], $dayStartMin) + max(0, (int)$extHours) * 60;

    // First pass: collect drawable items per table, and grow $endMin to fit any
    // item that runs past the nominal window (the spec's "just extend it").
    // $startMin symmetrically shrinks to fit pre-opening items (the grace
    // window lets e.g. a 17:30 setup slot exist on an 18:00 day — it must plot
    // on-canvas, so the window's left edge follows the earliest item).
    $hasGames  = false;
    $tableData = [];
    foreach ($tables as $tbl) {
        $games = [];
        foreach ($tbl['items'] as $it) {
            if ($it['type'] === 'poll') {
                // Polls appear as provisional blocks. Nobody knows which
                // candidate will win, so the width is an ESTIMATE governed by
                // poll_game_length_mode (fixed 2h, or derived from whatever
                // candidates have been added so far — see poll_length_minutes()).
                $p  = $it['data'];
                $ps = day_rel_min($p['start_time'], $dayStartMin);
                $pe = $ps + poll_length_minutes($p['games'] ?? []);
                if ($pe > $endMin)   $endMin   = $pe;      // stretch to fit, like games
                if ($ps < $startMin) $startMin = $ps;      // pre-opening (grace) item
                $games[] = [
                    'type' => 'poll',
                    'id' => (int)$p['id'], 'name' => t('poll_label'), 'start_time' => $p['start_time'],
                    'start' => $ps, 'end' => $pe, 'cur' => 0, 'max' => 0,
                ];
                $hasGames = true;
                continue;
            }
            if ($it['type'] !== 'game') continue;
            $g  = $it['data'];
            if ((int)$g['is_archived'] === 1) continue;    // soft-deleted games are hidden here
            $gs = day_rel_min($g['start_time'], $dayStartMin);
            $ge = $gs + max(1, (int)$g['length_minutes']); // ensure non-zero width
            if ($ge > $endMin)   $endMin   = $ge;          // stretch to fit overruns
            if ($gs < $startMin) $startMin = $gs;          // pre-opening (grace) item
            /* EVERYONE SIGNED UP, reserves included — the timeline is a glance
             * at how busy a table is, and somebody waiting on the reserve list
             * is still a person expecting to play. A game showing 5/4 is
             * telling you exactly what it should: it is over-subscribed.
             *
             * The reserve count travels separately so the view can say WHY the
             * number exceeds the maximum, rather than looking like an error. */
            $cur = count($g['players']);
            $reserves = 0;
            foreach ($g['players'] as $p) { if ((int)$p['is_reserve'] === 1) $reserves++; }
            $games[] = [
                'type' => 'game',
                'id' => (int)$g['id'], 'name' => $g['name'], 'start_time' => $g['start_time'],
                'start' => $gs, 'end' => $ge, 'cur' => $cur, 'max' => (int)$g['max_players'],
                'reserves' => $reserves,
            ];
            $hasGames = true;
        }
        $tableData[] = ['number' => (int)$tbl['table_number'], 'games' => $games];
    }
    if (!$hasGames) return null;                           // nothing to draw -> no timeline

    $total = $endMin - $startMin;                          // width of the whole span, in minutes
    if ($total <= 0) return null;                          // guard against a zero/negative span

    // Hour brackets across the top. We mark each whole hour inside the span; the
    // label wraps past midnight (26:00 shown as 02:00) while 'left' stays linear.
    $hours = [];
    for ($h = (int)(ceil($startMin / 60) * 60); $h <= $endMin; $h += 60) {
        $hours[] = ['label' => sprintf('%02d:00', intdiv($h, 60) % 24),
                    'left'  => round(($h - $startMin) / $total * 100, 3)];   // % from left edge
    }

    /* Hour BANDS: one box per hour for the alternating background stripes.
     *
     * Emitted as real positioned boxes rather than a repeating gradient because
     * the hour edges are percentages of a span that changes with the day — a
     * gradient would need its tile width and its starting offset both fed in,
     * and background-position percentages do not mean what you would want here
     * (they align image-% to container-%, which is a no-op when the two are the
     * same width). Boxes just land where the labels land, by construction.
     *
     * The first and last bands are CLAMPED: a day starting at 17:30 opens with
     * a half-hour band, and the span rarely ends exactly on the hour either.
     * Without that the stripes would run past the edge and misalign everything
     * after the first partial hour. */
    $bands  = [];
    $firstH = (int)(ceil($startMin / 60) * 60);
    if ($firstH > $startMin) {                       // leading part-hour
        $bands[] = ['left' => 0.0,
                    'width' => round(($firstH - $startMin) / $total * 100, 3)];
    }
    for ($h = $firstH; $h < $endMin; $h += 60) {
        $bands[] = ['left'  => round(($h - $startMin) / $total * 100, 3),
                    'width' => round(min(60, $endMin - $h) / $total * 100, 3)];
    }

    // Second pass: pack each table's games into lanes (greedy interval
    // partitioning). Sort by start; place each game in the first lane whose last
    // game has already ended by this game's start — otherwise open a new lane.
    // Result: sequential games share a lane (compact); overlapping games stack.
    foreach ($tableData as &$td) {
        usort($td['games'], function ($a, $b) { return $a['start'] <=> $b['start']; });
        $lanes = [];
        foreach ($td['games'] as $g) {
            // Convert start/length to left/width as percentages of the span. The
            // 0.5% min width keeps a very short game clickable. 'type' rides
            // along so the template can link #game-N vs #poll-N and style polls.
            $block = [
                'type' => $g['type'],
                'id' => $g['id'], 'name' => $g['name'], 'start_time' => $g['start_time'],
                'cur' => $g['cur'], 'max' => $g['max'],
                'full' => ($g['max'] > 0 && $g['cur'] >= $g['max']),
                // Somebody is queueing: worth marking apart from merely full.
                'reserves' => (int)($g['reserves'] ?? 0),
                'left'  => round(max(0, ($g['start'] - $startMin) / $total * 100), 3),
                'width' => round(max(0.5, ($g['end'] - $g['start']) / $total * 100), 3),
            ];
            $placed = false;
            foreach ($lanes as &$lane) {
                if ($g['start'] >= $lane['end']) {        // fits after the lane's last game
                    $lane['blocks'][] = $block;
                    $lane['end'] = $g['end'];
                    $placed = true;
                    break;
                }
            }
            unset($lane);                                  // break the inner reference
            if (!$placed) $lanes[] = ['end' => $g['end'], 'blocks' => [$block]];  // new lane
        }
        // Drop the bookkeeping 'end' field; the template only wants the blocks.
        $td['lanes'] = array_map(function ($l) { return $l['blocks']; }, $lanes);
        unset($td['games']);
    }
    unset($td);

    return ['hours' => $hours, 'bands' => $bands, 'tables' => $tableData];
}

/* ---- Signups / reserve --------------------------------------------------- */

/**
 * Count of confirmed (non-reserve) players on a game.
 * @return int
 */
function game_confirmed_count($gameId) {
    return (int)db_val('SELECT COUNT(*) FROM players WHERE game_id = ? AND is_reserve = 0', [$gameId]);
}

/**
 * Is the game full (confirmed players >= max)?
 * @return bool
 */
function game_is_full($gameId, $maxPlayers) {
    return game_confirmed_count($gameId) >= (int)$maxPlayers;
}

/**
 * Promote reserve players into freed confirmed seats, earliest first, until the
 * game is full again or no reserves remain. Returns the ids promoted (so the
 * caller can notify them by email later).
 *
 * Called after a confirmed player resigns: one resignation may free one seat,
 * but the loop also covers the case where capacity grew, etc.
 *
 * @param int $gameId
 * @return int[]  Ids of players moved from reserve to confirmed.
 */
function promote_reserves($gameId) {
    $game = db_one('SELECT max_players FROM games WHERE id = ?', [$gameId]);
    if (!$game) return [];
    $max = (int)$game['max_players'];

    $promoted = [];
    while (game_confirmed_count($gameId) < $max) {
        // Earliest reserve (lowest id) goes first — fair "first in, first promoted".
        $next = db_one('SELECT id FROM players WHERE game_id = ? AND is_reserve = 1 ORDER BY id LIMIT 1',
                       [$gameId]);
        if (!$next) break;                                 // no reserves left to promote
        db_run('UPDATE players SET is_reserve = 0 WHERE id = ?', [$next['id']]);
        $promoted[] = (int)$next['id'];
    }
    return $promoted;
}
