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
function library_for_user($userId) {
    return db_all(
        'SELECT * FROM library_games WHERE user_id = ? ORDER BY name COLLATE NOCASE ASC, year ASC',
        [(int)$userId]
    );
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
          GROUP BY u.id
          ORDER BY u.display_name COLLATE NOCASE ASC'
    );
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
 * Scoped by user_id as well as row id: an id from a hand-edited form must not
 * be able to delete somebody else's entry.
 *
 * @param int $userId
 * @param int $gameId
 * @return void
 */
function library_remove($userId, $gameId) {
    db_run('DELETE FROM library_games WHERE id = ? AND user_id = ?', [(int)$gameId, (int)$userId]);
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
    /* Both URL shapes people actually paste: the profile (/user/NAME) and the
     * collection page they were just looking at (/collection/user/NAME). */
    if (preg_match('~boardgamegeek\.com/(?:collection/)?user/([^/?#\s]+)~i', $raw, $m)) return urldecode($m[1]);
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
