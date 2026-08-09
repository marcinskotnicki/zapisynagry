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
        if (!empty($res['merged'])) {
            flash_set(t('lib_edit_merged', $res['name'] ?? ''));
        } elseif (!empty($res['promoted'])) {
            flash_set(t('lib_edit_promoted', $res['name'] ?? ''));
        } else {
            flash_set(t('lib_edit_saved'));
        }
        return;
    }
    $why = $res['why'] ?? '';
    if ($why === 'bgg_lookup') {
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
