<?php
/* =============================================================================
 *  inc/library.php — the club game library.
 * -----------------------------------------------------------------------------
 *  Members list the games they own; the public Library page aggregates every
 *  member's list into one game view ("who has this?") and one member view
 *  ("what does this person have?").
 *
 *  ONE GATE, library_enabled(), decides whether any of it exists — the member
 *  panel button, the header link, the public page and the admin sub-options all
 *  ask it, so there is no way to reach a half-enabled state.
 *
 *  WHOSE GAMES SHOW PUBLICLY is library_public_owner_sql(): blocked accounts are
 *  excluded everywhere. That rule lives in one place precisely because it is the
 *  kind of thing that gets applied to the list view and forgotten on the detail
 *  view.
 *
 *  NETWORK CODE IS SPLIT FROM PARSING, as in inc/bgg.php: every parser here is a
 *  pure function taking a string, so the BGG collection sync can be tested
 *  without touching the network (and this container has no outbound route at
 *  all, so anything else would be untested).
 * ============================================================================= */

/* bgg.php is required LAZILY, inside the two functions that talk to BGG,
 * rather than at the top. This file is loaded on every request (the header asks
 * library_enabled() to decide whether to show the nav link), and the library is
 * off by default — so a feature nobody has switched on should not pull the BGG
 * client into every page load. */

/**
 * Is the club library switched on at all?
 * @return bool
 */
function library_enabled() {
    return opt_bool('club_library');
}

/**
 * The three ways the public game list can be broken up.
 * @return array
 */
function library_pagination_modes() {
    return ['all', 'pages', 'alpha'];
}

/**
 * How the public game list is broken up: 'all', 'pages' or 'alpha'.
 *
 * Re-validated on read as well as on save, so a value written straight into the
 * options table cannot put the page into a mode it has no code for.
 * @return string
 */
function library_pagination() {
    $mode = (string)opt('library_pagination', 'all');
    return in_array($mode, library_pagination_modes(), true) ? $mode : 'all';
}

/**
 * Games per page in 'pages' mode. Clamped, so a stored 0 cannot produce a page
 * with no rows on it and an infinite page count.
 * @return int
 */
function library_per_page() {
    $n = (int)opt('library_per_page', 50);
    return $n > 0 ? min($n, 500) : 50;
}

/**
 * The letter a game is filed under: its first character, uppercased.
 *
 * Anything that is not a letter — a digit, a bracket, a quote — is filed under
 * '#', so "7 Wonders" and "Å" both land somewhere sensible rather than creating
 * a button each.
 *
 * mb_* throughout: Polish titles start with Ą, Ć, Ł, Ś and Ż, and substr() would
 * cut those multi-byte characters in half and produce a mojibake button.
 *
 * @param string $name
 * @return string  A single uppercase character, or '#'.
 */
function library_letter($name) {
    $name = trim((string)$name);
    if ($name === '') return '#';
    $first = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    // \p{L} rather than ctype_alpha(), which is byte-based and says no to Ł.
    return preg_match('/^\p{L}$/u', $first) ? $first : '#';
}

/**
 * Which letters actually have games behind them, in order.
 *
 * Built from the list rather than from a fixed A-Z, so a club sees buttons only
 * for letters that lead somewhere — and so Polish letters appear when they are
 * used without anybody having to configure an alphabet.
 *
 * @param array $games  From library_all_games().
 * @return array  Letter => count.
 */
function library_letters(array $games) {
    $out = [];
    foreach ($games as $g) {
        $l = library_letter($g['name']);
        $out[$l] = ($out[$l] ?? 0) + 1;
    }
    /* '#' last: it is the miscellany, and putting it first would give the strip
     * an odd-looking head.
     *
     * Accented letters sort NEXT TO their base letter — Ą after A, Ł after L —
     * which is what a Polish reader expects and what a byte comparison does
     * not do (strcmp puts every multi-byte letter after Z). intl's collator
     * would handle this, but the extension is not present on every shared host,
     * and a list whose order depends on which extensions happen to be installed
     * is worse than one that is merely simple. So: fold to a base letter for
     * comparison, and use the accented form itself only to break ties. */
    uksort($out, function ($a, $b) {
        if ($a === '#') return 1;
        if ($b === '#') return -1;
        $fold = function ($ch) {
            $map = ['Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
                    'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z'];
            return $map[$ch] ?? $ch;
        };
        $fa = $fold($a);
        $fb = $fold($b);
        return $fa === $fb ? strcmp($a, $b) : strcmp($fa, $fb);
    });
    return $out;
}

/* -----------------------------------------------------------------------------
 *  THE CLUB'S OWN SHELF
 *  Games the club owns, as opposed to games its members own. Independent of the
 *  members' library: either can be switched on without the other.
 *
 *  Only the SQL differs from the member functions above — everything that
 *  carries the actual behaviour (BGG lookup and promotion, link sanitising,
 *  letter grouping, pagination, every template) is shared, because none of it
 *  cares who owns a row.
 * -------------------------------------------------------------------------- */

/**
 * Is the club's own shelf switched on?
 * @return bool
 */
function club_shelf_enabled() {
    return opt_bool('club_shelf');
}

/**
 * Is there any library at all to link to — members', club's, or both?
 *
 * The header link and library.php ask this rather than either switch on its
 * own, so turning one off never leaves a link pointing at an empty page.
 * @return bool
 */
function library_any_enabled() {
    return library_enabled() || club_shelf_enabled();
}

/**
 * Should the COMBINED list — members' games and the club's together — be shown?
 *
 * It is one of four tabs, not a change to another one: the club's shelf and the
 * members' collections each stay viewable on their own, and this adds the view
 * that puts them together, where a game owned by both shows as a single line.
 *
 * @return bool
 */
function library_show_common() {
    /* The combined view needs BOTH halves to be worth showing: with only one
     * library switched on it would duplicate that library's own tab exactly. */
    return club_shelf_enabled() && library_enabled() && opt_bool('library_show_common');
}

/**
 * Where a "contact the club about this game" message goes, or '' for none.
 *
 * No address means no contact button — for the club there is no per-member
 * opt-in to fall back on, so the address IS the opt-in.
 *
 * @return string
 */
function library_club_email() {
    return trim((string)opt('library_club_email', ''));
}

/**
 * May the club be contacted about its games?
 *
 * Deliberately NOT gated on library_allow_contact: that switch is about
 * exposing MEMBERS to messages, which is a privacy question about individuals.
 * The club's address is one an admin typed in on purpose. The ordinary
 * messaging rules still apply, so a site with messaging off — or one barring
 * guests from it — bars this too.
 *
 * @return bool
 */
function library_club_can_contact() {
    return club_shelf_enabled() && library_club_email() !== '' && messaging_allowed();
}

/**
 * May a game be added to an event straight from the club's shelf?
 *
 * BOTH switches: the shelf has to exist before it can be picked from, so this
 * cannot be turned on alone and quietly show an empty chooser.
 *
 * @return bool
 */
function club_shelf_pick_enabled() {
    return club_shelf_enabled() && opt_bool('club_shelf_pick');
}

/**
 * Should the club's own games be offered FIRST?
 *
 * For a club whose cabinet is the usual source: the pick button leads the
 * add-a-game screen rather than sitting under the BGG search, and the club tab
 * opens by default on the library page.
 *
 * Presentation only — it changes what leads, never what is available, so
 * nothing is hidden by switching it on or off.
 *
 * @return bool
 */
function library_prefer_club() {
    return club_shelf_enabled() && opt_bool('library_prefer_club');
}

/**
 * Fill a game form from a club shelf entry.
 *
 * WHY THE SHELF STORES bgg_id: a club row keeps only what a library needs —
 * name, year, art, a link. A game form wants playing time, weight and player
 * count too, and those come from BGG. So for a shelf entry that came from BGG
 * we fetch the full record, exactly as choosing a search result does, and the
 * pick replaces the search step rather than the form.
 *
 * The fetch is BEST-EFFORT: on a host with no outbound route, or when BGG is
 * down, the form still opens prefilled with everything the shelf knows and the
 * remaining fields keep their defaults. Refusing to open would make the feature
 * unusable exactly when the ordinary BGG search is unusable too.
 *
 * @param array $form  Defaults from game_form_defaults().
 * @param array $row   A club_library_games row.
 * @return array       The form, prefilled.
 */
function club_shelf_prefill(array $form, array $row) {
    $form['name'] = $row['name'];

    if (!empty($row['bgg_id'])) {
        $form['bgg_id'] = (int)$row['bgg_id'];
        $form['source'] = 'bgg';

        require_once __DIR__ . '/bgg.php';
        $detail = bgg_thing((int)$row['bgg_id']);
        if ($detail) {
            $form['length_minutes'] = $detail['length']     ?: $form['length_minutes'];
            $form['weight']         = $detail['weight']     ?: $form['weight'];
            $form['max_players']    = $detail['maxplayers'] ?: $form['max_players'];
        }

        /* THE PICTURE THAT GOES ON THE TABLE, largest available first.
         *
         * 1. the row's own `image` — the full-size art of whatever edition was
         *    chosen. Exactly right, and the reason the column exists.
         * 2. BGG's full-size game image, but ONLY when the row's small
         *    thumbnail is the game's own. That means no edition was picked, so
         *    upgrading the size changes nothing but the resolution. Rows added
         *    before `image` existed land here and come out big, without a
         *    migration.
         * 3. the row's small thumbnail — an edition cover from an older row.
         *    Small, but it is the RIGHT picture, and swapping it for a
         *    bigger-but-wrong one is the trade the previous version made.
         *
         * The two URLs cannot be derived from each other: BGG signs them and
         * the path hash differs between sizes, so string-rewriting a small URL
         * into an original produces a link that will not load. */
        if (!empty($row['image'])) {
            $form['thumbnail'] = $row['image'];
        } elseif ($detail && !empty($detail['image'])
                  && (empty($row['thumbnail']) || $row['thumbnail'] === ($detail['thumbnail'] ?? ''))) {
            $form['thumbnail'] = $detail['image'];
        } elseif (!empty($row['thumbnail'])) {
            $form['thumbnail'] = $row['thumbnail'];
        } elseif ($detail) {
            $form['thumbnail'] = $detail['image'] ?: ($detail['thumbnail'] ?: $form['thumbnail']);
        }
        return $form;
    }

    /* A hand-added shelf entry: no BGG record to enrich it with, so it stays a
     * manual game and carries across the one extra thing the shelf holds — its
     * custom link. */
    $form['source'] = 'manual';
    if (!empty($row['link'])) $form['link'] = $row['link'];
    if (!empty($row['thumbnail'])) $form['thumbnail'] = $row['thumbnail'];
    return $form;
}

/**
 * The club's shelf, alphabetically.
 *
 * @param bool $activeOnly  True for the public view; admins see hidden rows too.
 * @return array
 */
function club_shelf_all($activeOnly = false) {
    return db_all(
        'SELECT * FROM club_library_games'
        . ($activeOnly ? ' WHERE is_active = 1' : '')
        . ' ORDER BY name COLLATE NOCASE ASC, year ASC'
    );
}

/**
 * One row from the club's shelf, or null.
 * @param int $rowId
 * @return array|null
 */
function club_shelf_entry($rowId) {
    if ($rowId <= 0) return null;
    return db_one('SELECT * FROM club_library_games WHERE id = ?', [(int)$rowId]);
}

/**
 * Add a game to the club's shelf.
 *
 * INSERT OR IGNORE against UNIQUE(bgg_id), so re-adding a BGG game the club
 * already has is a no-op and a re-run sync cannot duplicate anything — the same
 * property library_add() relies on.
 *
 * @param array $game  ['name','year','bgg_id','link','thumbnail']
 * @return bool  True when a row was actually written.
 */
function club_shelf_add(array $game) {
    $name = trim((string)($game['name'] ?? ''));
    if ($name === '') return false;

    // rowCount(), not lastInsertId(): the latter is connection-wide and stale
    // when INSERT OR IGNORE skips, which made every duplicate report success.
    $stmt = db_run(
        'INSERT OR IGNORE INTO club_library_games (name, year, bgg_id, link, thumbnail, image, bgg_version_id)
         VALUES (?,?,?,?,?,?,?)',
        [
            $name,
            !empty($game['year']) ? (int)$game['year'] : null,
            !empty($game['bgg_id']) ? (int)$game['bgg_id'] : null,
            !empty($game['link']) ? $game['link'] : null,
            !empty($game['thumbnail']) ? $game['thumbnail'] : null,
            // Full-size counterpart, for when this game reaches a table.
            !empty($game['image']) ? $game['image'] : null,
            !empty($game['bgg_version_id']) ? (int)$game['bgg_version_id'] : null,
        ]
    );
    return $stmt->rowCount() > 0;
}

/**
 * Remove one game from the club's shelf.
 * @param int $rowId
 * @return void
 */
function club_shelf_remove($rowId) {
    db_run('DELETE FROM club_library_games WHERE id = ?', [(int)$rowId]);
}

/**
 * Show or hide one game in the public view of the club's shelf.
 * @param int  $rowId
 * @param bool $active
 * @return void
 */
function club_shelf_set_active($rowId, $active) {
    db_run('UPDATE club_library_games SET is_active = ? WHERE id = ?',
           [$active ? 1 : 0, (int)$rowId]);
}

/**
 * Edit a manually added club entry, promoting it if the link turns out to be
 * a BGG game page. Mirrors library_update_manual(), same result shape.
 *
 * @return array  ['ok'=>bool, 'promoted'?, 'merged'?, 'name'?, 'why'?]
 */
function club_shelf_update_manual($rowId, $name, $year, $link = null) {
    $name = trim((string)$name);
    if ($name === '') return ['ok' => false, 'why' => 'name'];

    $row = db_one('SELECT * FROM club_library_games WHERE id = ? AND bgg_id IS NULL', [(int)$rowId]);
    if (!$row) return ['ok' => false, 'why' => 'not_editable'];

    require_once __DIR__ . '/events.php';   // game_link_sanitize()
    $link = $link === null ? (string)($row['link'] ?? '') : game_link_sanitize($link);

    // Same promotion rules as a member's entry — see library_update_manual().
    $bggId = library_bgg_id_from_link($link);
    if ($bggId > 0) {
        $existing = db_one('SELECT id FROM club_library_games WHERE bgg_id = ? AND id <> ?',
                           [$bggId, (int)$row['id']]);
        if ($existing) {
            db_run('DELETE FROM club_library_games WHERE id = ?', [(int)$row['id']]);
            return ['ok' => true, 'merged' => true, 'name' => $row['name']];
        }
        require_once __DIR__ . '/bgg.php';
        $thing = bgg_thing($bggId);
        if (!$thing || empty($thing['name'])) return ['ok' => false, 'why' => 'bgg_lookup'];
        db_run(
            'UPDATE club_library_games
                SET name = ?, year = ?, bgg_id = ?, thumbnail = ?, link = NULL
              WHERE id = ?',
            [
                $thing['name'],
                !empty($thing['year']) ? (int)$thing['year'] : null,
                $bggId,
                !empty($thing['thumbnail']) ? $thing['thumbnail'] : null,
                (int)$row['id'],
            ]
        );
        return ['ok' => true, 'promoted' => true, 'name' => $thing['name']];
    }

    db_run('UPDATE club_library_games SET name = ?, year = ?, link = ? WHERE id = ?',
           [$name, $year > 0 ? (int)$year : null, $link !== '' ? $link : null, (int)$row['id']]);
    return ['ok' => true];
}

/**
 * Point a club BGG entry at a different BGG game. Mirrors library_relink_bgg().
 * @return array
 */
function club_shelf_relink_bgg($rowId, $link, $name = null) {
    // Same split as library_relink_bgg(): renaming needs nothing, relinking
    // needs a lookup.
    if (trim((string)$link) !== '' && !bgg_configured()) {
        return ['ok' => false, 'why' => 'bgg_off'];
    }
    $row = db_one('SELECT * FROM club_library_games WHERE id = ? AND bgg_id IS NOT NULL', [(int)$rowId]);
    if (!$row) return ['ok' => false, 'why' => 'not_editable'];

    $link = trim((string)$link);

    // Renaming with no new link — see library_relink_bgg() for why this is safe
    // against a later sync.
    if ($link === '') {
        $name = trim((string)$name);
        if ($name === '') return ['ok' => false, 'why' => 'name'];
        if ($name === (string)$row['name']) return ['ok' => true];
        db_run('UPDATE club_library_games SET name = ? WHERE id = ?', [$name, (int)$row['id']]);
        return ['ok' => true, 'renamed' => true, 'name' => $name];
    }

    $bggId = library_bgg_id_from_link($link);
    if ($bggId <= 0) return ['ok' => false, 'why' => 'not_bgg'];
    if ($bggId === (int)$row['bgg_id']) return ['ok' => true];

    $existing = db_one('SELECT id FROM club_library_games WHERE bgg_id = ? AND id <> ?',
                       [$bggId, (int)$row['id']]);
    if ($existing) {
        db_run('DELETE FROM club_library_games WHERE id = ?', [(int)$row['id']]);
        return ['ok' => true, 'merged' => true, 'name' => $row['name']];
    }

    require_once __DIR__ . '/bgg.php';
    $thing = bgg_thing($bggId);
    if (!$thing || empty($thing['name'])) return ['ok' => false, 'why' => 'bgg_lookup'];
    db_run(
        'UPDATE club_library_games SET name = ?, year = ?, bgg_id = ?, thumbnail = ?, link = NULL WHERE id = ?',
        [
            $thing['name'],
            !empty($thing['year']) ? (int)$thing['year'] : null,
            $bggId,
            !empty($thing['thumbnail']) ? $thing['thumbnail'] : null,
            (int)$row['id'],
        ]
    );
    return ['ok' => true, 'promoted' => true, 'name' => $thing['name']];
}

/**
 * Replace the BGG-sourced half of the club's shelf from a BGG collection.
 * Mirrors library_sync_from_collection(); hand-added rows are left alone for
 * the same reason.
 *
 * @param array $collection  From library_parse_collection().
 * @return array  ['added'=>int, 'removed'=>int, 'kept'=>int]
 */
/* How many editions ONE sync run may look up individually.
 *
 * Only reached for an edition the collection response did not fully describe;
 * the normal case costs nothing extra, because the collection already carries
 * the title and art. Capped anyway so a collection full of odd entries cannot
 * fire a request per game and be throttled into doing almost nothing — which
 * is what a 93-game shelf did before the collection data was used. */
const LIBRARY_SYNC_FETCH_BUDGET = 12;

/**
 * The three ways a sync may treat what is already on a shelf.
 *
 * @return array
 */
function library_sync_modes() {
    // 'purge' last: it is the most destructive, and a dropdown people scan
    // top-down should not have it anywhere near the safe options.
    return ['add', 'update', 'full', 'purge'];
}

/**
 * Empty a library completely, so a sync can rebuild it from nothing.
 *
 * The escape hatch for when reconciliation has gone wrong in some way nobody
 * anticipated: throw the shelf away and take BGG's word for all of it. That
 * includes hand-added games and corrected titles, which is the whole point and
 * also why it is the one mode that says so in as many words on the form.
 *
 * SCOPED. On the members' table a user id is REQUIRED and the delete carries
 * it, so one member's purge can never reach another's shelf. Passing no id for
 * that table deletes nothing at all rather than everything — the failure mode
 * of a mistake here is far too expensive to leave to a caller remembering.
 *
 * @param string   $table   'club_library_games' or 'library_games'
 * @param int|null $userId  Required for library_games; ignored for the club shelf.
 * @return int              Rows removed.
 */
function library_purge($table, $userId = null) {
    if ($table === 'club_library_games') {
        $n = (int)db_val('SELECT COUNT(*) FROM club_library_games');
        db_run('DELETE FROM club_library_games');
        return $n;
    }
    if ($table !== 'library_games') return 0;

    // No id means no purge. Never "delete everything" by omission.
    $userId = (int)$userId;
    if ($userId <= 0) return 0;

    $n = (int)db_val('SELECT COUNT(*) FROM library_games WHERE user_id = ?', [$userId]);
    db_run('DELETE FROM library_games WHERE user_id = ?', [$userId]);
    return $n;
}

/**
 * The modes that DESTROY something and therefore need confirming.
 *
 * One list, asked by both controllers and rendered into the form, so the box
 * cannot appear for one set of modes while the server demands it for another.
 *
 * @return array
 */
function library_sync_destructive_modes() {
    return ['full', 'purge'];
}

/**
 * Normalise a submitted sync mode, defaulting to the SAFEST one.
 *
 * An unrecognised value falls back to 'add' rather than 'full': a mangled or
 * hand-built request must not be the thing that deletes somebody's shelf.
 *
 * @param mixed $raw
 * @return string
 */
function library_sync_mode($raw) {
    $raw = (string)$raw;
    return in_array($raw, library_sync_modes(), true) ? $raw : 'add';
}

/**
 * Fill in what a shelf row is MISSING from its BGG entry.
 *
 * WHAT IT DELIBERATELY DOES NOT DO is overwrite a value that is already there.
 * A row's name may have been corrected by hand (BGG hands back wrong-language
 * titles, which is why renaming exists), and its picture may be a chosen
 * edition's. Refreshing those on every sync would silently undo both, and a
 * club would find its Polish titles reverting to English every time somebody
 * pressed the button.
 *
 * So an update fills the gaps — most usefully the full-size `image` that older
 * rows lack — and leaves every deliberate choice alone.
 *
 * @param string $table  'club_library_games' or 'library_games'
 * @param array  $row    The existing row (id + current values).
 * @param array  $g      The BGG collection entry.
 * @return bool          Whether anything changed.
 */
/**
 * Look up an edition by id among a game's versions, or null.
 *
 * PURE — no network — so the decision this drives is testable without BGG.
 *
 * @param int   $versionId
 * @param array $versions   From bgg_versions().
 * @return array|null
 */
function library_find_version($versionId, array $versions) {
    $versionId = (int)$versionId;
    if ($versionId <= 0) return null;
    foreach ($versions as $v) {
        if ((int)($v['id'] ?? 0) === $versionId) return $v;
    }
    return null;
}

/**
 * Should a sync adopt the edition BGG's collection now reports for a game
 * already on the shelf?
 *
 * PURE, and this is the decision that was missing entirely — the reported bug
 * was that correcting a version on BGG and re-syncing left the old title in
 * place no matter how many times the button was pressed. There was no signal
 * to notice the correction at all: the collection was fetched without asking
 * for edition info, so every sync saw the same bare name regardless of which
 * printing was flagged.
 *
 * The signal now available is a version id, and this weighs it against what
 * the row already reflects:
 *
 *   - collection reports NOTHING flagged (0)            -> never adopt. There
 *     is no information to act on.
 *   - the row's stored edition MATCHES what BGG reports -> never adopt.
 *     Nothing changed; any difference in the name is presumably a manual
 *     correction, and correcting it again would undo that on every sync.
 *   - the row's stored edition DIFFERS from what BGG reports -> adopt. This
 *     is a genuine, detected change on BGG's side, which is exactly the case
 *     that was reported as broken.
 *   - the row has NO recorded edition (a legacy row, or one added before this
 *     existed) and BGG reports one -> adopt only in 'full' mode. There is no
 *     way to tell "never versioned" apart from "renamed by hand" from the row
 *     alone, so the gentler 'update' mode leaves it — but 'full' is the
 *     mode a person already opted into and confirmed, on the understanding
 *     that it reconciles the shelf with BGG.
 *
 * @param int|null $storedVersionId  The row's bgg_version_id, or null.
 * @param int      $collectionVersionId  What library_parse_collection() read.
 * @param string   $mode  'add' | 'update' | 'full'.
 * @return bool
 */
function library_should_adopt_edition($storedVersionId, $collectionVersionId, $mode) {
    $collectionVersionId = (int)$collectionVersionId;
    if ($collectionVersionId <= 0) return false;               // nothing flagged: no signal

    $storedVersionId = $storedVersionId !== null ? (int)$storedVersionId : 0;
    if ($storedVersionId === $collectionVersionId) return false;   // unchanged

    if ($storedVersionId > 0) return true;                     // a genuine, detected change

    return $mode === 'full';                                   // unknown history: only in full
}

function library_sync_fill_row($table, array $row, array $g) {
    if ($table !== 'club_library_games' && $table !== 'library_games') return false;

    /* The collection carries no full-size picture for a GAME, but it does for
     * the EDITION a member flagged — so a row can usually get its `image`
     * filled from the response the sync already has, instead of joining the
     * queue of rows the backfill has to fetch one at a time. That queue is
     * what made a big shelf report "still to do" for several runs. */
    $from = $g;
    if (empty($from['image']) && !empty($g['version_image'])) $from['image'] = $g['version_image'];

    $set = [];
    $args = [];
    foreach (['year', 'thumbnail', 'image', 'link'] as $col) {
        if (!array_key_exists($col, $row)) continue;          // column not selected
        if (!empty($row[$col])) continue;                     // already set: leave it
        if (empty($from[$col])) continue;                     // nothing to fill it with
        $set[] = $col . ' = ?';
        $args[] = ($col === 'year') ? (int)$from[$col] : $from[$col];
    }
    if (!$set) return false;

    $args[] = (int)$row['id'];
    db_run('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    return true;
}

/**
 * For a NEW item being added by a sync: adopt the edition BGG's collection
 * flagged, if any. There is no existing name to protect here, so this always
 * applies when a match is found — unlike the reconciliation below, which is
 * deliberately cautious about rows that already exist.
 *
 * NETWORK — one request when a version is flagged, none otherwise.
 *
 * @param array $g  A collection entry (bgg_id, name, thumbnail, version_id, ...).
 * @return array    The entry, with the edition applied if one was found.
 */
function library_sync_apply_new_edition(array $g, ?array $versions = null, &$fetchBudget = null) {
    $versionId = (int)($g['version_id'] ?? 0);
    if ($versionId <= 0) return $g;

    /* Same as the reconciler: prefer what the collection already told us, and
     * only look the edition up when it withheld a title. */
    if (!empty($g['version_title'])) {
        $v = [
            'id'        => $versionId,
            'title'     => $g['version_title'],
            'thumbnail' => $g['version_thumbnail'] ?? '',
            'image'     => $g['version_image'] ?? '',
        ];
    } else {
        if ($versions === null && $fetchBudget !== null && $fetchBudget <= 0) return $g;
        if ($versions === null) {
            require_once __DIR__ . '/bgg.php';
            if ($fetchBudget !== null) $fetchBudget--;
            $versions = bgg_versions((int)$g['bgg_id']);
        }
        $v = library_find_version($versionId, $versions);
    }
    if (!$v) return $g;                   // unmatched: the collection's own name stands

    if (!empty($v['title']))     $g['name']      = $v['title'];
    if (!empty($v['image']))     $g['image']     = $v['image'];
    elseif (!empty($v['thumbnail'])) $g['image'] = $v['thumbnail'];
    if (!empty($v['thumbnail'])) $g['thumbnail'] = $v['thumbnail'];
    $g['bgg_version_id'] = $versionId;
    return $g;
}

/**
 * For an EXISTING row: reconcile it against BGG's currently flagged edition,
 * per library_should_adopt_edition(). NETWORK — one request only when the
 * decision says to adopt.
 *
 * @param string $table  'club_library_games' or 'library_games'
 * @param array  $row    The existing row.
 * @param array  $g      The collection entry.
 * @param string $mode
 * @return bool          Whether the row was changed.
 */
function library_sync_reconcile_edition($table, array $row, array $g, $mode, ?array $versions = null,
                                        &$fetchBudget = null) {
    if ($table !== 'club_library_games' && $table !== 'library_games') return false;

    $storedVersionId = isset($row['bgg_version_id']) ? $row['bgg_version_id'] : null;
    if (!library_should_adopt_edition($storedVersionId, $g['version_id'] ?? 0, $mode)) return false;

    /* THE COLLECTION USUALLY ALREADY SAYS EVERYTHING NEEDED, and using it costs
     * nothing: no request, no throttling, and every changed game handled in the
     * single response the sync already fetched. Looking each edition up
     * separately is what made a large shelf take several runs.
     *
     * The lookup below is the fallback for the one case the collection cannot
     * answer: no canonicalname, so no title we would trust. */
    $v = null;
    if (!empty($g['version_title'])) {
        $v = [
            'id'        => (int)$g['version_id'],
            'title'     => $g['version_title'],
            'thumbnail' => $g['version_thumbnail'] ?? '',
            'image'     => $g['version_image'] ?? '',
        ];
    } else {
        /* BUDGETED. This is the slow path, and on a big shelf it is the one
         * that used to make a sync take several runs — so it is capped, and
         * what it cannot reach this time is simply picked up next time. The
         * fast path above is not budgeted, because it costs nothing. */
        if ($versions === null && $fetchBudget !== null && $fetchBudget <= 0) return false;
        if ($versions === null) {
            require_once __DIR__ . '/bgg.php';
            if ($fetchBudget !== null) $fetchBudget--;
            $versions = bgg_versions((int)$row['bgg_id']);
        }
        $v = library_find_version((int)$g['version_id'], $versions);
    }
    if (!$v) return false;                // could not confirm the match: leave the row as it is

    $name  = !empty($v['title']) ? $v['title'] : $row['name'];
    $thumb = !empty($v['thumbnail']) ? $v['thumbnail'] : ($row['thumbnail'] ?? null);
    $image = !empty($v['image']) ? $v['image'] : (!empty($v['thumbnail']) ? $v['thumbnail'] : ($row['image'] ?? null));
    db_run('UPDATE ' . $table . ' SET name = ?, thumbnail = ?, image = ?, bgg_version_id = ? WHERE id = ?',
           [$name, $thumb, $image, (int)$v['id'], (int)$row['id']]);
    return true;
}

function club_shelf_sync_from_collection(array $collection, $mode = 'full') {
    $mode = library_sync_mode($mode);

    /* PURGE: empty the shelf, then fall through and let the ordinary import
     * rebuild it. Everything below then sees an empty shelf and treats every
     * game as new, so there is no second code path to keep in step — the
     * rebuild IS the normal add path. */
    $purged = 0;
    if ($mode === 'purge') $purged = library_purge('club_library_games');

    $haveRows = db_all('SELECT id, name, bgg_id, year, thumbnail, image, link, bgg_version_id
                          FROM club_library_games WHERE bgg_id IS NOT NULL');
    $have = [];
    $rows = [];
    foreach ($haveRows as $r) {
        $have[(int)$r['bgg_id']] = (int)$r['id'];
        $rows[(int)$r['bgg_id']] = $r;
    }

    $wanted = [];
    foreach ($collection as $g) $wanted[(int)$g['bgg_id']] = $g;

    $added = 0;
    $removed = 0;
    $updated = 0;
    /* Requests this run may spend on editions the collection did not fully
     * describe. Most syncs spend none, because the collection answers already. */
    $fetchBudget = LIBRARY_SYNC_FETCH_BUDGET;
    db()->beginTransaction();
    try {
        foreach ($wanted as $id => $g) {
            if (isset($have[$id])) {
                // Only 'add' leaves existing rows completely untouched.
                if ($mode !== 'add') {
                    /* Two separate jobs, and the order matters: adopting a
                     * changed edition rewrites name/thumbnail/image outright,
                     * so it runs FIRST and the gap-filler then has nothing left
                     * to fill. Reversed, the filler would populate fields the
                     * adoption is about to replace anyway. */
                    $changed = library_sync_reconcile_edition('club_library_games', $rows[$id], $g, $mode,
                                                              null, $fetchBudget);
                    if (library_sync_fill_row('club_library_games', $rows[$id], $g)) $changed = true;
                    if ($changed) $updated++;
                }
                continue;
            }
            if (club_shelf_add(library_sync_apply_new_edition($g, null, $fetchBudget))) $added++;
        }
        /* DELETION IS THE DESTRUCTIVE STEP, and only the full mode does it here.
         * A purge has already emptied the shelf, so there is nothing left for
         * this loop to find. */
        if ($mode === 'full') {
            foreach ($have as $id => $rowId) {
                if (isset($wanted[$id])) continue;
                db_run('DELETE FROM club_library_games WHERE id = ?', [$rowId]);
                $removed++;
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    // A purge reports what it threw away as removed: from the admin's point of
    // view those games are gone, whatever the mechanism.
    return ['added' => $added, 'removed' => $removed + $purged, 'kept' => count($have) - $removed,
            'updated' => $updated, 'purged' => $purged];
}

/**
 * Should the public page show the members tab?
 * Meaningless when the library is off; callers check library_enabled() first.
 * @return bool
 */
function library_members_tab_enabled() {
    return opt_bool('library_show_members');
}

/**
 * May members be contacted about their library games?
 *
 * BOTH switches must be on: the admin's feature toggle AND the member's own
 * checkbox. On top of that the ordinary messaging gate still applies, so a site
 * with messaging switched off cannot be used to reach people through the
 * library either — see library_can_contact().
 * @return bool
 */
function library_contact_enabled() {
    return library_enabled() && opt_bool('library_allow_contact');
}

/**
 * May THIS member be contacted about their games?
 *
 * @param array|null $user  A users row.
 * @return bool
 */
function library_can_contact($user) {
    if (!$user || !library_contact_enabled()) return false;
    if ((int)($user['is_blocked'] ?? 0) === 1) return false;
    if ((int)($user['library_contact_ok'] ?? 0) !== 1) return false;
    // The site-wide messaging rules are not bypassed by this feature: if
    // messaging is off, or guests are barred and this visitor is one, there is
    // no contact button here either.
    return messaging_allowed();
}

/**
 * The WHERE fragment restricting library rows to publicly visible owners.
 *
 * Blocked accounts vanish from the public library entirely — their rows stay in
 * the database (unblocking restores them) but nothing lists them. Returned as a
 * fragment rather than repeated inline so the game view, the member view and the
 * member list cannot drift apart on who counts as visible.
 *
 * @return string  SQL fragment referring to a `users u` alias.
 */
function library_public_owner_sql() {
    return 'u.is_blocked = 0';
}

/**
 * One member's library, alphabetically.
 *
 * @param int $userId
 * @return array  library_games rows.
 */
function library_for_user($userId, $activeOnly = false) {
    /* $activeOnly separates the two audiences for the same list. The OWNER (and
     * an admin managing it) sees everything, including games marked inactive —
     * that is where they go to switch one back on. Everyone else sees only what
     * is actually available. Getting this backwards would either hide a
     * member's own game from them or advertise a game that is sitting in
     * somebody else's flat. */
    return db_all(
        'SELECT * FROM library_games
          WHERE user_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . '
          ORDER BY name COLLATE NOCASE ASC, year ASC',
        [(int)$userId]
    );
}

/**
 * May this viewer manage this library row — delete, hide or rename it?
 *
 * The owner, and an admin. Admins can reach the same controls from the public
 * member view rather than needing a separate admin tab: it is the screen they
 * are already looking at when they notice a problem.
 *
 * @param array|null $row  A library_games row.
 * @param int        $viewerId
 * @return bool
 */
function library_can_manage($row, $viewerId) {
    if (!$row) return false;
    if (is_admin()) return true;
    return (int)$row['user_id'] === (int)$viewerId && $viewerId > 0;
}

/**
 * Show or hide one entry in the public library.
 *
 * @param int  $rowId
 * @param bool $active
 * @param int  $userId  Owner scope; pass 0 for an admin acting on any row.
 * @return void
 */
function library_set_active($rowId, $active, $userId = 0) {
    $sql = 'UPDATE library_games SET is_active = ? WHERE id = ?';
    $args = [$active ? 1 : 0, (int)$rowId];
    // A non-admin caller passes their own id, so a hand-edited form cannot
    // reach somebody else's row.
    if ($userId > 0) { $sql .= ' AND user_id = ?'; $args[] = (int)$userId; }
    db_run($sql, $args);
}

/**
 * Rename a manually added entry, or correct its year.
 *
 * MANUAL ENTRIES ONLY (bgg_id IS NULL). A BGG game's name and year come from
 * BGG and are what let a sync match it up again; letting somebody rename one
 * would either be undone by the next sync or quietly break the pairing.
 *
 * @param int    $rowId
 * @param string $name
 * @param int    $year    0 to clear it.
 * @param int    $userId  Owner scope; 0 for an admin.
 * @return bool  False when the name is empty or the row is a BGG entry.
 */
function library_update_manual($rowId, $name, $year, $userId = 0, $link = null) {
    $name = trim((string)$name);
    if ($name === '') return ['ok' => false, 'why' => 'name'];

    // Same owner scoping as everywhere in this module: 0 means an admin.
    $where = 'id = ? AND bgg_id IS NULL';
    $whereArgs = [(int)$rowId];
    if ($userId > 0) { $where .= ' AND user_id = ?'; $whereArgs[] = (int)$userId; }

    $row = db_one('SELECT * FROM library_games WHERE ' . $where, $whereArgs);
    if (!$row) return ['ok' => false, 'why' => 'not_editable'];

    /* game_link_sanitize() lives in events.php, which the public library page
     * does not otherwise need. Required here rather than at the top so the
     * cost lands only on an actual edit — this file is loaded on every
     * request. */
    require_once __DIR__ . '/events.php';
    $link = $link === null ? (string)($row['link'] ?? '') : game_link_sanitize($link);

    /* PROMOTION. If the link turns out to point at a BGG game page, this stops
     * being a hand-typed entry and becomes the real thing: it picks up BGG's
     * canonical name, year and art, and from then on it merges with every other
     * member's copy of the same game on the public list.
     *
     * That is the whole point of the feature — a club ends up with one
     * "Monopoly" instead of one proper entry plus three hand-typed ones, two of
     * them misspelled — so BGG's name deliberately WINS over whatever was typed
     * here, typos included. */
    $bggId = library_bgg_id_from_link($link);
    if ($bggId > 0) {
        /* The member may already own this game as a proper BGG entry — which is
         * exactly the mess being cleaned up. UNIQUE(user_id, bgg_id) would
         * reject the update, so the duplicate is folded into the existing row
         * instead: this one goes. */
        $existing = db_one('SELECT id FROM library_games WHERE user_id = ? AND bgg_id = ? AND id <> ?',
                           [(int)$row['user_id'], $bggId, (int)$row['id']]);
        if ($existing) {
            db_run('DELETE FROM library_games WHERE id = ?', [(int)$row['id']]);
            return ['ok' => true, 'merged' => true, 'name' => $row['name']];
        }

        /* Requires a successful lookup. Setting bgg_id while keeping the typed
         * name would merge the group under a misspelling — worse than leaving
         * it alone, and invisible until somebody noticed the club library
         * listing "Monoply". A failed fetch (no network, bad id, BGG down)
         * leaves the row exactly as it was. */
        require_once __DIR__ . '/bgg.php';
        $thing = bgg_thing($bggId);
        if (!$thing || empty($thing['name'])) {
            return ['ok' => false, 'why' => 'bgg_lookup'];
        }

        db_run(
            'UPDATE library_games
                SET name = ?, year = ?, bgg_id = ?, thumbnail = ?, link = NULL
              WHERE id = ?',
            [
                $thing['name'],
                !empty($thing['year']) ? (int)$thing['year'] : null,
                $bggId,
                !empty($thing['thumbnail']) ? $thing['thumbnail'] : null,
                (int)$row['id'],
            ]
        );
        return ['ok' => true, 'promoted' => true, 'name' => $thing['name']];
    }

    // An ordinary edit: name, year, and a non-BGG link.
    db_run('UPDATE library_games SET name = ?, year = ?, link = ? WHERE id = ?',
           [$name, $year > 0 ? (int)$year : null, $link !== '' ? $link : null, (int)$row['id']]);
    return ['ok' => true];
}

/**
 * Every publicly visible game, alphabetically, each with its owners.
 *
 * Grouped by IDENTITY rather than by row: a BGG game is one entry however many
 * members own it (keyed by bgg_id), while non-BGG entries fall back to their
 * name, so two people typing the same title still land on one line. Owners
 * arrive as a list of ['id','display_name','library_contact_ok','is_blocked'].
 *
 * @return array  List of ['name','year','bgg_id','link','thumbnail','owners'].
 */
function library_all_games($includeClub = null) {
    /* WHICH LIST this is, decided by the caller rather than by an option.
     *
     * There are now two views built from this: the members' collections on
     * their own, and the combined list that also carries the club's shelf. The
     * option only decides whether that second TAB exists — it must not silently
     * change what the first one contains, which is what happened while a single
     * merged list served both purposes.
     *
     * null keeps the old behaviour for any caller that has not been told which
     * it wants. */
    if ($includeClub === null) $includeClub = library_show_common();
    $rows = db_all(
        'SELECT g.*, u.id AS owner_id, u.display_name AS owner_name,
                u.library_contact_ok AS owner_contact_ok, u.is_blocked AS owner_blocked
           FROM library_games g
           JOIN users u ON u.id = g.user_id
          WHERE ' . library_public_owner_sql() . '
            AND g.is_active = 1
          ORDER BY g.name COLLATE NOCASE ASC, g.year ASC'
    );

    $out = [];
    foreach ($rows as $r) {
        $key = !empty($r['bgg_id'])
            ? 'b' . (int)$r['bgg_id']
            : 'n' . mb_strtolower(trim((string)$r['name']));
        if (!isset($out[$key])) {
            $out[$key] = [
                'name'      => $r['name'],
                'year'      => $r['year'],
                'bgg_id'    => $r['bgg_id'],
                'link'      => $r['link'],
                'thumbnail' => $r['thumbnail'],
                'owners'    => [],
            ];
        }
        // First non-empty thumbnail wins: one member's copy may have been added
        // from BGG (with art) and another's typed by hand (without).
        if (empty($out[$key]['thumbnail']) && !empty($r['thumbnail'])) {
            $out[$key]['thumbnail'] = $r['thumbnail'];
        }
        if (empty($out[$key]['link']) && !empty($r['link'])) {
            $out[$key]['link'] = $r['link'];
        }
        $out[$key]['owners'][] = [
            'id'                 => (int)$r['owner_id'],
            'display_name'       => $r['owner_name'],
            'library_contact_ok' => (int)$r['owner_contact_ok'],
            'is_blocked'         => (int)$r['owner_blocked'],
            /* THIS owner's copy of the game. Entries are merged by identity, so
             * one line can carry several rows; the contact link needs the row
             * belonging to the person it is addressed to, and passing the row id
             * rather than the game's name keeps user-supplied text out of the
             * URL and out of the subject line. */
            'row_id'             => (int)$r['id'],
        ];
    }
    /* THE CLUB'S OWN GAMES, when an admin has asked for them here too.
     *
     * Merged into the SAME grouping the members' rows built, so a game owned by
     * both the club and two members is one line with three owners rather than a
     * duplicate entry. That works without any schema change because the key is
     * the game's identity — its BGG id, or its name — and a club row carries
     * both. The two shelves being separate tables costs nothing here.
     *
     * CLUB goes FIRST among the owners of a game: it is the copy anyone can
     * count on being in the building, so it is the useful one to read first. */
    if ($includeClub) {
        foreach (club_shelf_all(true) as $c) {
            $key = !empty($c['bgg_id'])
                ? 'b' . (int)$c['bgg_id']
                : 'n' . mb_strtolower(trim((string)$c['name']));
            if (!isset($out[$key])) {
                $out[$key] = [
                    'name'      => $c['name'],
                    'year'      => $c['year'],
                    'bgg_id'    => $c['bgg_id'],
                    'link'      => $c['link'],
                    'thumbnail' => $c['thumbnail'],
                    'owners'    => [],
                ];
            }
            if (empty($out[$key]['thumbnail']) && !empty($c['thumbnail'])) {
                $out[$key]['thumbnail'] = $c['thumbnail'];
            }
            if (empty($out[$key]['link']) && !empty($c['link'])) {
                $out[$key]['link'] = $c['link'];
            }
            array_unshift($out[$key]['owners'], [
                // No user id: this is not a person, and nothing downstream may
                // treat it as one. is_club is what the template keys on.
                'id'                 => 0,
                'is_club'            => true,
                'display_name'       => t('lib_club_owner'),
                'library_contact_ok' => 1,
                'is_blocked'         => 0,
                'row_id'             => (int)$c['id'],
            ]);
        }
        /* Re-sorted because the club's games were appended after the members'
         * were already ordered — a club-only title would otherwise land at the
         * end of an alphabetical list. */
        uasort($out, function ($a, $b) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }

    return array_values($out);
}

/**
 * Members with at least one game, alphabetically, with their game counts.
 *
 * @return array  List of users rows plus 'game_count'.
 */
function library_members() {
    return db_all(
        'SELECT u.*, COUNT(g.id) AS game_count
           FROM users u
           JOIN library_games g ON g.user_id = u.id
          WHERE ' . library_public_owner_sql() . '
            AND g.is_active = 1
          GROUP BY u.id
          ORDER BY u.display_name COLLATE NOCASE ASC'
    );
}

/**
 * One library entry, but only if it belongs to that member.
 *
 * The pairing is the point: message.php is handed a member id and a row id from
 * the URL, and naming somebody else's game in a message addressed to this
 * member would be both wrong and a small information leak.
 *
 * @param int $userId
 * @param int $rowId
 * @return array|null
 */
function library_entry_for_user($userId, $rowId) {
    if ($rowId <= 0) return null;
    return db_one('SELECT * FROM library_games WHERE id = ? AND user_id = ?',
                  [(int)$rowId, (int)$userId]);
}

/**
 * The external link for a library entry, or null.
 *
 * Mirrors game_link(): BGG entries link by id (always allowed), custom links
 * only while the admin permits them and the stored value still looks like an
 * http(s) URL — re-checked here rather than trusted from the database.
 *
 * @param array $row  A library_games row.
 * @return string|null
 */
function library_link($row) {
    if (!empty($row['bgg_id'])) {
        return 'https://boardgamegeek.com/boardgame/' . (int)$row['bgg_id'];
    }
    if (!empty($row['link']) && opt_bool('allow_custom_game_links')
        && preg_match('#^https?://#i', $row['link'])) {
        return $row['link'];
    }
    return null;
}

/**
 * Add one game to a member's library.
 *
 * INSERT OR IGNORE, so re-adding a BGG game a member already owns is a no-op
 * rather than an error — the UNIQUE(user_id, bgg_id) index does the work. That
 * is what lets a sync re-run safely.
 *
 * @param int    $userId
 * @param array  $game  ['name','year','bgg_id','link','thumbnail'] (all optional but name)
 * @return bool  True when a row was actually inserted.
 */
function library_add($userId, array $game) {
    $name = trim((string)($game['name'] ?? ''));
    if ($name === '') return false;

    /* rowCount(), NOT lastInsertId(): with INSERT OR IGNORE a skipped duplicate
     * inserts nothing, but lastInsertId() is connection-wide and still returns
     * whatever was inserted last — so it reported success for a row that was
     * never written. library_sync_from_collection() counts additions with this
     * return value, and would have overreported every re-sync. */
    $stmt = db_run(
        'INSERT OR IGNORE INTO library_games (user_id, name, year, bgg_id, link, thumbnail, image, bgg_version_id)
         VALUES (?,?,?,?,?,?,?,?)',
        [
            (int)$userId,
            $name,
            !empty($game['year']) ? (int)$game['year'] : null,
            !empty($game['bgg_id']) ? (int)$game['bgg_id'] : null,
            !empty($game['link']) ? $game['link'] : null,
            !empty($game['thumbnail']) ? $game['thumbnail'] : null,
            !empty($game['image']) ? $game['image'] : null,
            !empty($game['bgg_version_id']) ? (int)$game['bgg_version_id'] : null,
        ]
    );
    return $stmt->rowCount() > 0;
}

/**
 * Remove one game from a member's library.
 *
 * Scoped by user_id as well as row id, so an id from a hand-edited form cannot
 * delete somebody else's entry — EXCEPT when $userId is 0, which means "an
 * admin, acting on any row". Same convention as library_set_active() and
 * library_update_manual(), so all three read alike at the call site and the
 * caller decides the scope once.
 *
 * @param int $userId  Owner scope; 0 for an admin.
 * @param int $gameId
 * @return void
 */
function library_remove($userId, $gameId) {
    $sql  = 'DELETE FROM library_games WHERE id = ?';
    $args = [(int)$gameId];
    if ((int)$userId > 0) { $sql .= ' AND user_id = ?'; $args[] = (int)$userId; }
    db_run($sql, $args);
}

/**
 * Point a BGG entry at a DIFFERENT BGG game.
 *
 * The counterpart to promoting a manual entry. A BGG row's name and year are
 * BGG's, so there is nothing to type here — the only way it can be wrong is
 * that it is linked to the wrong game, and the fix is a new link. Someone picks
 * the wrong "Catan" from a search, or a member's sync pulls in an edition
 * nobody meant; an admin pastes the right address and the row becomes that
 * game, art and all.
 *
 * A NON-BGG LINK IS REFUSED rather than quietly turning the row back into a
 * hand-typed entry: the field on this form means "which BGG game is this", and
 * silently changing what kind of row it is would be a surprising answer to a
 * pasted typo.
 *
 * Same result shape as library_update_manual(), so both share
 * library_flash_edit().
 *
 * @param int    $rowId
 * @param string $link
 * @param int    $userId  Owner scope; 0 for an admin.
 * @return array
 */
function library_relink_bgg($rowId, $link, $userId = 0, $name = null) {
    /* A RENAME still works without BGG — it touches nothing but the stored
     * name. Swapping the row to a DIFFERENT game does not: that needs a lookup
     * to find out what the new game is. Checked here rather than only in the
     * template, so a stale form cannot half-apply it. */
    if (trim((string)$link) !== '' && !bgg_configured()) {
        return ['ok' => false, 'why' => 'bgg_off'];
    }
    $where = 'id = ? AND bgg_id IS NOT NULL';
    $args  = [(int)$rowId];
    if ($userId > 0) { $where .= ' AND user_id = ?'; $args[] = (int)$userId; }

    $row = db_one('SELECT * FROM library_games WHERE ' . $where, $args);
    if (!$row) return ['ok' => false, 'why' => 'not_editable'];

    $link = trim((string)$link);

    /* RENAMING A BGG ENTRY, when no new link is given. BGG sometimes returns a
     * title in the wrong language, and being stuck with it was the only thing
     * wrong with the row.
     *
     * Safe against a later sync: library_sync_from_collection() skips ids it
     * already holds, so it never rewrites the name of a game that stays owned.
     * (This was originally blocked on the belief that a sync WOULD overwrite
     * it — checking the sync showed otherwise.)
     *
     * The bgg_id is untouched, so the pairing that drives syncing and merging
     * still holds; only the label a human reads changes. */
    if ($link === '') {
        $name = trim((string)$name);
        if ($name === '') return ['ok' => false, 'why' => 'name'];
        if ($name === (string)$row['name']) return ['ok' => true];
        db_run('UPDATE library_games SET name = ? WHERE id = ?', [$name, (int)$row['id']]);
        return ['ok' => true, 'renamed' => true, 'name' => $name];
    }

    $bggId = library_bgg_id_from_link($link);
    if ($bggId <= 0) return ['ok' => false, 'why' => 'not_bgg'];

    // Pointing it at the game it already is: nothing to do, and reporting an
    // error for it would be wrong.
    if ($bggId === (int)$row['bgg_id']) return ['ok' => true];

    /* Already own the target as a separate row — the same collision promotion
     * handles. UNIQUE(user_id, bgg_id) would reject the update, so this row is
     * folded into the one that is already correct. */
    $existing = db_one('SELECT id FROM library_games WHERE user_id = ? AND bgg_id = ? AND id <> ?',
                       [(int)$row['user_id'], $bggId, (int)$row['id']]);
    if ($existing) {
        db_run('DELETE FROM library_games WHERE id = ?', [(int)$row['id']]);
        return ['ok' => true, 'merged' => true, 'name' => $row['name']];
    }

    /* Requires a successful lookup, for the same reason promotion does: keeping
     * the OLD name against a NEW id would leave the row lying about which game
     * it is, and it would then merge under that wrong name in the shared list. */
    require_once __DIR__ . '/bgg.php';
    $thing = bgg_thing($bggId);
    if (!$thing || empty($thing['name'])) return ['ok' => false, 'why' => 'bgg_lookup'];

    db_run(
        'UPDATE library_games SET name = ?, year = ?, bgg_id = ?, thumbnail = ?, link = NULL WHERE id = ?',
        [
            $thing['name'],
            !empty($thing['year']) ? (int)$thing['year'] : null,
            $bggId,
            !empty($thing['thumbnail']) ? $thing['thumbnail'] : null,
            (int)$row['id'],
        ]
    );
    return ['ok' => true, 'promoted' => true, 'name' => $thing['name']];
}

/**
 * Turn a library_update_manual() result into a flash message.
 *
 * The four outcomes read very differently to whoever pressed Save, and lumping
 * them into "saved / not saved" would hide the interesting ones — especially a
 * promotion, where the name they typed is deliberately replaced by BGG's.
 *
 * @param array $res  From library_update_manual().
 * @return void
 */
function library_flash_edit(array $res) {
    if (!empty($res['ok'])) {
        if (!empty($res['renamed'])) {
            flash_set(t('lib_edit_renamed', $res['name'] ?? ''));
        } elseif (!empty($res['merged'])) {
            flash_set(t('lib_edit_merged', $res['name'] ?? ''));
        } elseif (!empty($res['promoted'])) {
            flash_set(t('lib_edit_promoted', $res['name'] ?? ''));
        } else {
            flash_set(t('lib_edit_saved'));
        }
        return;
    }
    $why = $res['why'] ?? '';
    if ($why === 'bgg_off') {
        // Says WHY rather than "that failed": nothing is wrong with the link,
        // the site simply has no BoardGameGeek code configured.
        flash_set(t('lib_bgg_disabled'), 'error');
    } elseif ($why === 'not_bgg') {
        flash_set(t('lib_relink_not_bgg'), 'error');
    } elseif ($why === 'bgg_lookup') {
        // The link looked like BGG but could not be checked, so nothing was
        // changed — worth saying, or it looks like the edit silently failed.
        flash_set(t('lib_edit_bgg_failed'), 'error');
    } else {
        flash_set(t('lib_edit_failed'), 'error');
    }
}

/**
 * Should the edit form offer a link field for a manual entry?
 *
 * Custom links are an admin option, so ordinarily it follows that. But an ADMIN
 * always gets it, because pasting a BGG address here is how they fold three
 * hand-typed "Monopoly" rows into the one real entry — and a BGG link is not a
 * "custom link" at all, so the option was never meant to block that.
 *
 * One function rather than two copies of the condition: the form and the POST
 * handler must agree, or the field appears and is then ignored (or worse, is
 * hidden and still accepted from a hand-built request).
 *
 * @return bool
 */
function library_link_field_visible() {
    return opt_bool('allow_custom_game_links') || is_admin();
}

/* library_version_title() has been REMOVED. It preferred a version's alternate
 * name over its primary, on the guess that a localised printing carried its
 * real title there. A live response settled it: the versions block carries only
 * NICKNAMES ("Polish edition"), so the function fell back to the game's name
 * every single time and the chooser showed "Adds as: Petrichor" against every
 * edition.
 *
 * The localised titles ("Aura") are on the GAME item instead, as alternate
 * names — bgg_parse_thing() now returns them all — so the chooser offers the
 * title as its own choice rather than inferring one from the edition. */

/**
 * Which full-size picture belongs to a row whose only art is a small crop.
 *
 * PURE, so the matching can be tested without a network — the interesting part
 * of the backfill is exactly this decision, and it is the part that would
 * quietly attach the wrong cover.
 *
 * A row does not record which EDITION was picked, so its crop is the only link
 * back to one. Hence:
 *
 *   - no crop, or the game's own crop -> the game's original.
 *   - a crop matching one edition      -> THAT edition's original, so a Polish
 *                                         printing keeps its own cover instead
 *                                         of being handed the English one.
 *   - anything else                    -> '' , meaning leave the row alone. A
 *                                         small-but-right picture beats a
 *                                         big-but-wrong one.
 *
 * @param string $crop      The row's stored thumbnail.
 * @param array  $thing     From bgg_thing().
 * @param array  $versions  From bgg_versions(), or [] when not fetched.
 * @return string           A URL, or '' for "cannot tell".
 */
function library_pick_backfill_image($crop, $thing, array $versions = []) {
    $crop = (string)$crop;
    if (!is_array($thing)) return '';

    if ($crop === '' || $crop === (string)($thing['thumbnail'] ?? '')) {
        return (string)($thing['image'] ?? '');
    }
    foreach ($versions as $v) {
        if ((string)($v['thumbnail'] ?? '') !== $crop) continue;
        return (string)($v['image'] ?? '');
    }
    return '';
}

/**
 * Fill in the missing full-size picture on rows added before `image` existed.
 *
 * WHY THIS EXISTS: the library used to store only BGG's fit-in/200x150 crop, so
 * games added back then still reach a table as miniatures. The two URLs cannot
 * be derived from each other — BGG signs them and the path hash differs between
 * sizes — so the original has to be fetched.
 *
 * MATCHING THE RIGHT PICTURE is the whole difficulty. A row's crop may be the
 * game's own, or one EDITION's, and the row does not record which edition was
 * picked. So:
 *
 *   1. crop missing, or equal to the game's own -> the game's original.
 *   2. otherwise, look through the game's editions for the one whose crop is
 *      exactly this row's, and take THAT edition's original. This recovers the
 *      Polish printing's cover rather than replacing it with the English one.
 *   3. no match -> left alone. A row keeps its small-but-correct picture rather
 *      than gaining a big wrong one.
 *
 * CAPPED per run, because each row costs one or two BGG requests and a club
 * with a hundred games would otherwise hammer them in a single click. The
 * caller reports how many are left so an admin can press again.
 *
 * @param string $table  'club_library_games' or 'library_games'
 * @param int    $limit  Rows to attempt this run.
 * @return array ['done' => int, 'left' => int]
 */
function library_backfill_images($table, $limit = 25, $userId = null) {
    if ($table !== 'club_library_games' && $table !== 'library_games') return ['done' => 0, 'left' => 0];
    require_once __DIR__ . '/bgg.php';

    /* SCOPED TO ONE MEMBER on the members' table. Without this a member's sync
     * walked EVERY member's rows: it counted them ("657 left" on a 93-game
     * shelf), spent its budget fetching pictures for games belonging to other
     * people, and could never finish because the queue was the whole site's.
     *
     * The club shelf has no owner, so it passes no id and stays unscoped —
     * which is correct there, and why the club side never showed this. */
    $scope = '';
    $args  = [];
    if ($table === 'library_games' && $userId !== null) {
        $scope = ' AND user_id = ?';
        $args[] = (int)$userId;
    }

    $rows = db_all(
        'SELECT id, thumbnail, bgg_id FROM ' . $table . '
          WHERE bgg_id IS NOT NULL AND (image IS NULL OR image = \'\')' . $scope . '
          ORDER BY id LIMIT ' . (int)$limit,
        $args
    );

    $done = 0;
    foreach ($rows as $r) {
        $bggId = (int)$r['bgg_id'];
        $crop  = (string)($r['thumbnail'] ?? '');
        $big   = '';

        $thing = bgg_thing($bggId);
        if (!$thing) continue;                 // BGG unreachable: try again next run

        // Versions are only fetched when the crop is not the game's own, so
        // the common case costs one request rather than two.
        $isGameCrop = ($crop === '' || $crop === (string)($thing['thumbnail'] ?? ''));
        $big = library_pick_backfill_image($crop, $thing, $isGameCrop ? [] : bgg_versions($bggId));

        if ($big === '') continue;             // unknown: leave the row as it is
        db_run('UPDATE ' . $table . ' SET image = ? WHERE id = ?', [$big, (int)$r['id']]);
        $done++;
    }

    $left = (int)db_val(
        'SELECT COUNT(*) FROM ' . $table . '
          WHERE bgg_id IS NOT NULL AND (image IS NULL OR image = \'\')' . $scope,
        $args
    );
    return ['done' => $done, 'left' => $left];
}

/**
 * May a proposer pick which EDITION of a game they are bringing?
 *
 * Adds a "choose version" button beside the name on the add-a-game and
 * add-a-candidate forms. Off by default: it costs an extra BGG request per
 * proposal, and most clubs do not care which printing is on the table.
 *
 * @return bool
 */
function game_version_pick_enabled() {
    // Needs BGG to list the editions, so the option alone is not enough.
    return opt_bool('game_version_pick') && bgg_configured();
}

/**
 * The editions to offer beside a game name, or [] when there is nothing to ask.
 *
 * Returns nothing unless the admin enabled it, the game came from BGG, and BGG
 * lists more than one edition — a chooser with a single entry is a button that
 * does nothing.
 *
 * Every caller passes a game's own bgg_id, so a hand-typed game (no id) gets an
 * empty list without needing to know about the feature.
 *
 * @param mixed $bggId
 * @return array
 */
function game_pick_versions($bggId) {
    $bggId = (int)$bggId;
    if ($bggId <= 0 || !game_version_pick_enabled()) return [];
    require_once __DIR__ . '/bgg.php';
    $versions = bgg_versions($bggId);
    return count($versions) > 1 ? $versions : [];
}

/**
 * Apply the edition/title chosen in lib_version_pick to an entry.
 *
 * ONE function for every caller — adding to a member's shelf, adding to the
 * club's, and correcting a row already on either — because the three used to be
 * three copies of the same loop and would have drifted the first time the shape
 * changed.
 *
 * The TITLE comes from the game's own names, the COVER and YEAR from the chosen
 * edition. They are separate choices because BGG keeps them in separate places:
 * a version's name is a nickname ("Polish edition"), never a title.
 *
 * The title is checked against the list BGG actually returned rather than
 * trusted from the form, so a hand-edited post cannot set an arbitrary name
 * through a screen that only ever offered a dropdown.
 *
 * @param array $entry     The entry so far (name/year/thumbnail).
 * @param int   $gameId    The BGG game id.
 * @param array $post      The submitted form.
 * @return array           The entry with the choice applied.
 */
function library_apply_version_choice(array $entry, $gameId, array $post) {
    require_once __DIR__ . '/bgg.php';

    $wantVersion = (int)($post['version'] ?? 0);
    if ($wantVersion > 0) {
        foreach (bgg_versions((int)$gameId) as $v) {
            if ((int)$v['id'] !== $wantVersion) continue;
            /* Recorded so a later sync can tell "BGG's flagged edition changed"
             * apart from "this name was chosen on purpose" — see
             * library_collection_edition(). */
            $entry['bgg_version_id'] = (int)$v['id'];
            /* The edition's OWN title, from BGG's canonicalname — this is what
             * files a Polish printing as "Aura" rather than "Petrichor", and it
             * overrides the title dropdown because it is the more specific
             * answer: the person picked this exact edition. */
            if (!empty($v['title'])) $entry['name'] = $v['title'];
            /* THE FULL-SIZE IMAGE, not the 200x150 thumbnail.
             *
             * BGG gives each version both: `thumbnail` is a fit-in/200x150
             * crop, `image` is the original. The plain-BGG path has always
             * stored `image`, so a game added the ordinary way gets a big
             * picture — while a version pick stored the small one and came out
             * visibly tiny beside it. On a theme like blossom, where the cover
             * runs the full width of the card, that is the difference between a
             * cover and a postage stamp.
             *
             * The small one stays the fallback for a version that has no
             * original on file. */
            /* Both sizes again: the list shows the small crop, the table shows
             * the original. Storing only the original made library lists pull
             * full-size art; storing only the crop is what put miniatures on
             * the tables. */
            if (!empty($v['thumbnail'])) $entry['thumbnail'] = $v['thumbnail'];
            if (!empty($v['image']))     $entry['image']     = $v['image'];
            elseif (!empty($v['thumbnail'])) $entry['image'] = $v['thumbnail'];
            if (!empty($v['year'])) $entry['year'] = $v['year'];
            break;
        }
    }
    return $entry;
}

/**
 * A label for one edition in the chooser: nickname, year and language.
 *
 * @param array $version
 * @return string
 */
function library_version_label(array $version) {
    // The nickname, year and language — what tells two editions apart. The
    // TITLE is shown separately and more prominently, because that is the
    // string that will actually be stored.
    $bits = [];
    if (!empty($version['nickname'])) $bits[] = $version['nickname'];
    if (!empty($version['year']))     $bits[] = '(' . (int)$version['year'] . ')';
    if (!empty($version['languages'])) $bits[] = implode(', ', $version['languages']);
    return implode(' ', $bits);
}

/* library_default_version() has been REMOVED too. It guessed at an English
 * edition to preselect; with "no particular edition" always offered at the top
 * of the list, that is the honest default — it adds the game exactly as BGG has
 * it, which is what somebody who does not care about editions wants, and it
 * needs no guessing. */

/**
 * Turn whatever was pasted into a library entry.
 *
 * Handles BOTH shapes with one call, so the member page and the club shelf
 * cannot drift:
 *
 *   a game link    -> that game, with BGG's own title and cover;
 *   a version link -> the SAME game, but carrying that edition's title and
 *                     cover, so a Polish printing lists under its Polish name.
 *
 * The bgg_id stored is always the GAME's, never the version's. That is what
 * makes two members' copies of the same game merge into one entry on the
 * library page even when they pasted different editions — and it is what the
 * collection sync matches on.
 *
 * @param string $raw   A pasted link or a bare game id.
 * @param string $why   Out-param: short reason when it returns null.
 * @return array|null   ['name','year','bgg_id','thumbnail']
 */
function library_entry_from_bgg_input($raw, &$why = null) {
    $why = '';
    require_once __DIR__ . '/bgg.php';

    /* A VERSION LINK is refused rather than followed. Resolving one needs the
     * version's own web page, and BGG answers that with 403 for anything that
     * is not a browser — verified on a live site, not guessed. The editions are
     * available from the documented API instead (thing?versions=1), so the add
     * flow asks the game for its editions and lets the person choose. */
    if (library_bgg_version_id_from_link($raw) > 0) { $why = 'version link'; return null; }

    $gameId = library_bgg_id_from_input($raw);
    if ($gameId <= 0) { $why = 'not a bgg link'; return null; }

    $thing = bgg_thing($gameId);
    if (!$thing || empty($thing['name'])) { $why = 'lookup failed'; return null; }

    $entry = [
        'name'      => $thing['name'],
        'year'      => $thing['year'] ?? 0,
        'bgg_id'    => $thing['id'],
        // Both sizes: the small one for library lists, the full one for when
        // this game is put on a table. They cannot be derived from each other.
        'thumbnail' => $thing['thumbnail'] ?? '',
        'image'     => $thing['image'] ?? '',
    ];

    /* The EDITION is applied by the caller, not here: this returns the game as
     * BGG has it, and the chooser then overrides the title, cover and year for
     * whichever edition was picked. Keeping that out of here means the one
     * builder still answers exactly one question — "what game is this link?" */
    return $entry;
}

/**
 * A BGG VERSION id from a link, or 0.
 *
 * A version is one edition of a game — the Polish printing of Petrichor, say —
 * and it has its own id, its own title and its own cover:
 *
 *   .../boardgame/210274/petrichor              -> the game   (id 210274)
 *   .../boardgameversion/391600/polish-edition  -> a version  (id 391600)
 *
 * Host-anchored for the same reason library_bgg_id_from_link() is: this decides
 * what an arbitrary pasted link MEANS, so a lookalike path on another host must
 * not match.
 *
 * @param string $raw
 * @return int  0 when this is not a BGG version link.
 */
function library_bgg_version_id_from_link($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return 0;
    if (!preg_match('~^(?:https?://)?(?:www\.)?boardgamegeek\.com/~i', $raw)) return 0;
    if (preg_match('~/boardgameversion/(\d+)~i', $raw, $m)) return (int)$m[1];
    return 0;
}

/* The version-page SCRAPER that used to live here has been removed. Resolving a
 * /boardgameversion/NNN link needs that page, and BGG answers it with HTTP 403
 * for anything that is not a browser — confirmed against a live site, not
 * assumed. The editions are available from the documented API instead
 * (thing?versions=1), which is what the add flow uses now, so the scraper was
 * unreachable code that still looked like a working option. */

/**
 * A BGG game id from a link, but ONLY when the link really is a BGG game page.
 *
 * Deliberately stricter than library_bgg_id_from_input(). That one is used on
 * the "add from BGG" field, where the member has already said what they are
 * pasting, so it accepts a bare number and falls back to any trailing digits.
 * Here we are SNIFFING an arbitrary link the member typed, and that fallback
 * would read `https://example.org/games/42` as BGG game 42 and silently rewrite
 * an unrelated game into whatever BGG has under that id.
 *
 * So: the host must be boardgamegeek.com, and the path must be a game page.
 *
 * @param string $raw
 * @return int  0 when this is not a BGG game link.
 */
function library_bgg_id_from_link($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return 0;
    if (!preg_match('~^(?:https?://)?(?:www\.)?boardgamegeek\.com/~i', $raw)) return 0;
    if (preg_match('~/boardgame(?:expansion|accessory)?/(\d+)~i', $raw, $m)) return (int)$m[1];
    return 0;
}

/**
 * Pull a BGG game id out of whatever the member pasted.
 *
 * Accepts a full URL in any of BGG's shapes (/boardgame/13,
 * /boardgameexpansion/13/name, with or without host) or a bare number, because
 * people paste what is in their address bar.
 *
 * @param string $raw
 * @return int  0 when nothing id-shaped is present.
 */
function library_bgg_id_from_input($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return 0;
    if (ctype_digit($raw)) return (int)$raw;
    if (preg_match('#boardgame(?:expansion|accessory)?/(\d+)#i', $raw, $m)) return (int)$m[1];
    // A bare "…/13" or "?id=13" as a last resort.
    if (preg_match('#(?:^|/|=)(\d{2,})(?:/|$|\?)#', $raw, $m)) return (int)$m[1];
    return 0;
}

/**
 * Pull a BGG username out of a profile URL or a bare name.
 *
 * @param string $raw
 * @return string  '' when nothing usable is present.
 */
function library_bgg_user_from_input($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    /* ~ as the delimiter, not #: the character class below has to exclude a
     * literal '#' (a URL fragment), and with # as the delimiter that closes
     * the pattern early — PHP then reads the rest as modifiers and warns
     * "Unknown modifier". */
    /* All three shapes people actually paste. BGG shows a member TWO different
     * addresses for the same account — /profile/NAME on the profile page itself
     * and /collection/user/NAME on their game list — and the label offers
     * either, so refusing the first was simply wrong. */
    if (preg_match('~boardgamegeek\.com/(?:collection/)?user/([^/?#\s]+)~i', $raw, $m)) return urldecode($m[1]);
    if (preg_match('~boardgamegeek\.com/profile/([^/?#\s]+)~i', $raw, $m)) return urldecode($m[1]);
    if (preg_match('~[?&]username=([^&\s]+)~i', $raw, $m)) return urldecode($m[1]);
    // A bare username: BGG allows letters, digits, underscore, hyphen, dot.
    if (preg_match('~^[A-Za-z0-9._-]+$~', $raw)) return $raw;
    return '';
}

/**
 * Parse a BGG /collection XML payload into library entries.
 *
 * PURE — no network, so the sync logic is testable in an environment with no
 * outbound route (which is exactly the environment this was written in).
 *
 * ONLY OWNED GAMES. The endpoint is asked for own=1, but the response is
 * filtered again here on <status own="1">: a wishlist or preorder entry that
 * slipped through the query must not land in somebody's library, and trusting
 * the query alone would make that failure silent.
 *
 * @param string $xmlString  Raw collection XML.
 * @return array|null  List of ['bgg_id','name','year','thumbnail'], or null when
 *                     the payload is not parseable as a collection at all.
 */
function library_parse_collection($xmlString) {
    // bgg_unescape() lives in bgg.php, which this function may run before.
    require_once __DIR__ . '/bgg.php';

    if (!is_string($xmlString) || trim($xmlString) === '') return null;

    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($xmlString);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    /* === false, not falsy: an <items> element with no children casts to false
     * while having parsed perfectly — an empty collection is a valid answer
     * ("this person owns nothing"), not a parse failure. */
    if ($xml === false) return null;
    if ($xml->getName() !== 'items') return null;

    $out = [];
    foreach ($xml->item as $item) {
        if ((string)($item->status['own'] ?? '0') !== '1') continue;   // owned only
        $id = (int)($item['objectid'] ?? 0);
        if ($id <= 0) continue;
        $name = bgg_unescape(trim((string)$item->name));
        if ($name === '') continue;
        $out[] = [
            'bgg_id'    => $id,
            'name'      => $name,
            'year'      => (int)($item->yearpublished ?? 0),
            'thumbnail' => trim((string)$item->thumbnail),
            /* WHICH EDITION this member has flagged as theirs, and everything
             * BGG tells us about it IN THIS SAME RESPONSE.
             *
             * Taking the details here rather than looking each edition up
             * separately is what makes a sync finish in ONE request instead of
             * one per game: a 93-game shelf was firing 93 lookups, most of
             * which BGG throttled, so only a handful of changes landed per run
             * and the rest needed the button pressing again.
             *
             * <item> WITHIN <version> — an unrelated nesting from the
             * <versions> block of a thing response, though the fields inside
             * match. `canonicalname` is the TITLE the edition is published
             * under; <name> is its nickname ("Polish edition") and is
             * deliberately NOT read as a title. When canonicalname is absent
             * the title stays empty and the caller looks the edition up
             * properly rather than guessing. */
            'version_id'        => (int)($item->version->item['id'] ?? 0),
            'version_title'     => bgg_unescape(trim((string)($item->version->item->canonicalname['value'] ?? ''))),
            'version_thumbnail' => trim((string)($item->version->item->thumbnail ?? '')),
            'version_image'     => trim((string)($item->version->item->image ?? '')),
            'version_year'      => (int)($item->version->item->yearpublished['value'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Fetch a BGG user's owned collection. Network — returns null on any failure.
 *
 * @param string $username
 * @param string $why  Out-param: a short reason when it returns null.
 * @return array|null  Same shape as library_parse_collection().
 */
function library_fetch_collection($username, &$why = null) {
    $why = '';
    if (!function_exists('curl_init')) { $why = 'no curl'; return null; }
    $username = trim((string)$username);
    if ($username === '') { $why = 'no username'; return null; }

    require_once __DIR__ . '/bgg.php';   // lazy: see the note at the top

    /* own=1 asks for owned items only; excludesubtype drops expansions so a
     * library lists games rather than the boxes that go with them. The
     * response is filtered again in the parser regardless. */
    /* version=1 is what makes a corrected edition on BGG's side visible to a
     * sync at all: without it, BGG never says which printing a member flagged
     * as theirs, and this member's collection response is otherwise identical
     * whichever edition they own. Without this parameter the two are
     * indistinguishable, which is why fixing the edition on BGG previously had
     * no effect here no matter how many times somebody synced. */
    $url = BGG_BASE . 'collection?username=' . rawurlencode($username)
         . '&own=1&excludesubtype=boardgameexpansion&version=1';

    // Reuses inc/bgg.php's fetcher, which already retries BGG's 202 "queued"
    // response — a collection request is the one most likely to be queued,
    // since BGG builds it on demand.
    list($body, $code) = bgg_fetch_raw($url);
    if ($body === false) { $why = 'request failed'; return null; }
    if ($code === 202)   { $why = 'bgg still preparing the collection'; return null; }
    if ($code === 404)   { $why = 'no such bgg user'; return null; }
    if ($code < 200 || $code >= 300) { $why = 'HTTP ' . $code; return null; }

    $parsed = library_parse_collection((string)$body);
    if ($parsed === null) { $why = 'unexpected response format'; return null; }
    return $parsed;
}

/**
 * Replace a member's BGG-sourced library with the given collection.
 *
 * FULL SYNC, as the member is warned before confirming: anything of theirs that
 * is no longer in the collection goes, anything new arrives.
 *
 * MANUAL ENTRIES ARE LEFT ALONE — only rows with a bgg_id take part. A game
 * somebody typed in by hand was never in the BGG collection to begin with, so
 * "not in the collection" is not evidence they no longer own it, and deleting
 * it would be destroying data the sync knows nothing about.
 *
 * @param int   $userId
 * @param array $collection  From library_parse_collection().
 * @return array  ['added'=>int, 'removed'=>int, 'kept'=>int]
 */
function library_sync_from_collection($userId, array $collection, $mode = 'full') {
    $userId = (int)$userId;
    $mode   = library_sync_mode($mode);

    /* PURGE, scoped to THIS member — library_purge() carries the id and refuses
     * to run without one, so a purge cannot reach anybody else's shelf. The
     * rebuild below is the ordinary import: with the shelf empty, every game
     * simply looks new. */
    $purged = 0;
    if ($mode === 'purge') $purged = library_purge('library_games', $userId);

    $haveRows = db_all('SELECT id, name, bgg_id, year, thumbnail, image, link, bgg_version_id
                          FROM library_games WHERE user_id = ? AND bgg_id IS NOT NULL', [$userId]);
    $have = [];
    $rows = [];
    foreach ($haveRows as $r) {
        $have[(int)$r['bgg_id']] = (int)$r['id'];
        $rows[(int)$r['bgg_id']] = $r;
    }

    $wanted = [];
    foreach ($collection as $g) $wanted[(int)$g['bgg_id']] = $g;

    $added = 0;
    $removed = 0;
    $updated = 0;
    $fetchBudget = LIBRARY_SYNC_FETCH_BUDGET;
    db()->beginTransaction();
    try {
        foreach ($wanted as $id => $g) {
            if (isset($have[$id])) {
                if ($mode !== 'add') {
                    // Adoption first, then gap-filling — see the club shelf.
                    $changed = library_sync_reconcile_edition('library_games', $rows[$id], $g, $mode,
                                                              null, $fetchBudget);
                    if (library_sync_fill_row('library_games', $rows[$id], $g)) $changed = true;
                    if ($changed) $updated++;
                }
                continue;
            }
            if (library_add($userId, library_sync_apply_new_edition($g, null, $fetchBudget))) $added++;
        }
        // Only the full mode removes anything.
        if ($mode === 'full') {
            foreach ($have as $id => $rowId) {
                if (isset($wanted[$id])) continue;
                db_run('DELETE FROM library_games WHERE id = ? AND user_id = ?', [$rowId, $userId]);
                $removed++;
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    return ['added' => $added, 'removed' => $removed + $purged, 'kept' => count($have) - $removed,
            'updated' => $updated, 'purged' => $purged];
}
