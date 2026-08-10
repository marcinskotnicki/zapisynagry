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
 * Should the club's games also appear on the members' library page?
 *
 * They keep their own tab regardless; this only adds them to the merged list.
 * Needs the shelf itself, or there would be nothing to merge.
 *
 * @return bool
 */
function library_show_club_games() {
    return club_shelf_enabled() && opt_bool('library_show_club');
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
        // The shelf's own thumbnail first, so something shows even if the
        // lookup below fails.
        if (!empty($row['thumbnail'])) $form['thumbnail'] = $row['thumbnail'];

        require_once __DIR__ . '/bgg.php';
        $detail = bgg_thing((int)$row['bgg_id']);
        if ($detail) {
            $form['length_minutes'] = $detail['length']     ?: $form['length_minutes'];
            $form['weight']         = $detail['weight']     ?: $form['weight'];
            $form['max_players']    = $detail['maxplayers'] ?: $form['max_players'];
            // Prefer the full image, as the BGG search path does.
            $form['thumbnail']      = $detail['image'] ?: ($detail['thumbnail'] ?: $form['thumbnail']);
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
        'INSERT OR IGNORE INTO club_library_games (name, year, bgg_id, link, thumbnail)
         VALUES (?,?,?,?,?)',
        [
            $name,
            !empty($game['year']) ? (int)$game['year'] : null,
            !empty($game['bgg_id']) ? (int)$game['bgg_id'] : null,
            !empty($game['link']) ? $game['link'] : null,
            !empty($game['thumbnail']) ? $game['thumbnail'] : null,
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
function club_shelf_sync_from_collection(array $collection) {
    $haveRows = db_all('SELECT id, bgg_id FROM club_library_games WHERE bgg_id IS NOT NULL');
    $have = [];
    foreach ($haveRows as $r) $have[(int)$r['bgg_id']] = (int)$r['id'];

    $wanted = [];
    foreach ($collection as $g) $wanted[(int)$g['bgg_id']] = $g;

    $added = 0;
    $removed = 0;
    db()->beginTransaction();
    try {
        foreach ($wanted as $id => $g) {
            if (isset($have[$id])) continue;
            if (club_shelf_add($g)) $added++;
        }
        foreach ($have as $id => $rowId) {
            if (isset($wanted[$id])) continue;
            db_run('DELETE FROM club_library_games WHERE id = ?', [$rowId]);
            $removed++;
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    return ['added' => $added, 'removed' => $removed, 'kept' => count($have) - $removed];
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
function library_all_games() {
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
    if (library_show_club_games()) {
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
        'INSERT OR IGNORE INTO library_games (user_id, name, year, bgg_id, link, thumbnail)
         VALUES (?,?,?,?,?,?)',
        [
            (int)$userId,
            $name,
            !empty($game['year']) ? (int)$game['year'] : null,
            !empty($game['bgg_id']) ? (int)$game['bgg_id'] : null,
            !empty($game['link']) ? $game['link'] : null,
            !empty($game['thumbnail']) ? $game['thumbnail'] : null,
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
    if ($why === 'not_bgg') {
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
        $name = trim((string)$item->name);
        if ($name === '') continue;
        $out[] = [
            'bgg_id'    => $id,
            'name'      => $name,
            'year'      => (int)($item->yearpublished ?? 0),
            'thumbnail' => trim((string)$item->thumbnail),
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
    $url = BGG_BASE . 'collection?username=' . rawurlencode($username)
         . '&own=1&excludesubtype=boardgameexpansion';

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
function library_sync_from_collection($userId, array $collection) {
    $userId = (int)$userId;

    $haveRows = db_all('SELECT id, bgg_id FROM library_games WHERE user_id = ? AND bgg_id IS NOT NULL', [$userId]);
    $have = [];
    foreach ($haveRows as $r) $have[(int)$r['bgg_id']] = (int)$r['id'];

    $wanted = [];
    foreach ($collection as $g) $wanted[(int)$g['bgg_id']] = $g;

    $added = 0;
    $removed = 0;
    db()->beginTransaction();
    try {
        foreach ($wanted as $id => $g) {
            if (isset($have[$id])) continue;
            if (library_add($userId, $g)) $added++;
        }
        foreach ($have as $id => $rowId) {
            if (isset($wanted[$id])) continue;
            db_run('DELETE FROM library_games WHERE id = ? AND user_id = ?', [$rowId, $userId]);
            $removed++;
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    return ['added' => $added, 'removed' => $removed, 'kept' => count($have) - $removed];
}
