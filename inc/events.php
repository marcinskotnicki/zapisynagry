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
    $token = $_GET['e'] ?? '';
    if ($token !== '') {
        // Archive view: look the event up by its share token; render read-only.
        $ev = db_one('SELECT * FROM events WHERE access_token = ?', [$token]);
        return ['event' => $ev, 'readonly' => true];
    }
    // Default: the live event, fully interactive.
    return ['event' => current_event(), 'readonly' => false];
}

/**
 * All day rows for an event, ordered by their 1-based index.
 * @return array
 */
function event_days($eventId) {
    return db_all('SELECT * FROM event_days WHERE event_id = ? ORDER BY day_index', [$eventId]);
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
        $polls = db_all('SELECT * FROM polls WHERE table_id = ? ORDER BY start_time, id', [$tbl['id']]);
        foreach ($polls as $p) {
            $items[] = ['type' => 'poll', 'start' => $p['start_time'], 'data' => poll_full($p)];
        }

        // Interleave games + polls by start time (string 'HH:MM' sorts correctly).
        usort($items, function ($a, $b) {
            return strcmp($a['start'], $b['start']);
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
 * Default start time for a new game on a table: the day's start if the table is
 * empty, otherwise the latest game's start + its length. Returns 'HH:MM'.
 * Lets the add-game form pre-fill a sensible "next slot" so games line up.
 *
 * @param int    $tableId
 * @param string $dayStart  'HH:MM' fallback for an empty table.
 * @return string
 */
function event_next_start_time($tableId, $dayStart) {
    $last = db_one('SELECT start_time, length_minutes FROM games
                    WHERE table_id = ? ORDER BY start_time DESC, id DESC LIMIT 1', [$tableId]);
    if (!$last) return $dayStart;
    return min_to_hhmm(hhmm_to_min($last['start_time']) + (int)$last['length_minutes']);
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
 *     'tables' => [ ['number'=>1, 'lanes'=> [ [block, block...], [block...] ] ], ... ]]
 *  where a block = ['type'('game'|'poll'),'id','name','start_time','cur','max','full','left','width'].
 * --------------------------------------------------------------------------- */
function timeline_build($dayRow, $tables, $extHours) {
    // The visible window: day start .. day end + extension hours.
    $startMin = hhmm_to_min($dayRow['start_time']);
    $endMin   = hhmm_to_min($dayRow['end_time']) + max(0, (int)$extHours) * 60;

    // First pass: collect drawable items per table, and grow $endMin to fit any
    // item that runs past the nominal window (the spec's "just extend it").
    $hasGames  = false;
    $tableData = [];
    foreach ($tables as $tbl) {
        $games = [];
        foreach ($tbl['items'] as $it) {
            if ($it['type'] === 'poll') {
                // Polls appear as provisional blocks. Nobody knows how long the
                // winning game will run, so they get a flat DEFAULT of 2 hours —
                // roughly the average game — purely for display.
                $p  = $it['data'];
                $ps = hhmm_to_min($p['start_time']);
                $pe = $ps + 120;                           // the 2h display default
                if ($pe > $endMin) $endMin = $pe;          // stretch to fit, like games
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
            $gs = hhmm_to_min($g['start_time']);
            $ge = $gs + max(1, (int)$g['length_minutes']); // ensure non-zero width
            if ($ge > $endMin) $endMin = $ge;              // stretch to fit overruns
            // "current players" = confirmed only (reserves don't occupy a seat).
            $cur = 0;
            foreach ($g['players'] as $p) { if ((int)$p['is_reserve'] === 0) $cur++; }
            $games[] = [
                'type' => 'game',
                'id' => (int)$g['id'], 'name' => $g['name'], 'start_time' => $g['start_time'],
                'start' => $gs, 'end' => $ge, 'cur' => $cur, 'max' => (int)$g['max_players'],
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

    return ['hours' => $hours, 'tables' => $tableData];
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
