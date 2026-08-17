<?php
/* =============================================================================
 *  inc/bgg.php — BoardGameGeek XML API v2 integration.
 * -----------------------------------------------------------------------------
 *  Mirrors the behaviour proven in the previous project, cleaned up:
 *    * One /search call returns id + name + year for every match. Expansions
 *      are excluded server-side (type=boardgame & excludesubtype) — but BGG's
 *      own index mistags plenty of items, so that filter is a first pass, not
 *      a guarantee. A second, more reliable pass runs later (see bgg_search).
 *    * Thumbnails are NOT in the search response, so we only fetch them (via
 *      per-item /thing) when the result list is small enough — big lists skip
 *      thumbnails to avoid hammering the API. (RESULT_IMAGE_LIMIT.) That same
 *      /thing call also carries an authoritative type="..." attribute, so
 *      short lists get a second expansion/promo filter for free; long lists
 *      don't get this second pass, since there's no per-item call to piggyback
 *      it on without hammering the API further.
 *    * The chosen game's full details (length, weight, players, image) come
 *      from a single /thing call after the user clicks.
 *
 *  Request hardening (learned the hard way — keep all three):
 *    * Authorization: Bearer header — confirmed necessary in real testing, but
 *      sent ONLY when an API code is configured (empty code => no header).
 *    * User-Agent always set — BGG's edge can 403 a header-less request.
 *    * Retry on HTTP 202 — BGG returns 202 while a request is queued, then 200
 *      once it's ready; we wait briefly and retry.
 *
 *  STRUCTURE: bgg_fetch_raw() is the only function that touches the network.
 *  The two parsers (bgg_parse_*) are pure string->array functions, so they're
 *  unit-tested offline with fixtures. The high-level bgg_search()/bgg_thing()
 *  just glue fetch + parse together.
 * ============================================================================= */

const BGG_BASE            = 'https://boardgamegeek.com/xmlapi2/';
const RESULT_IMAGE_LIMIT  = 12;   // fetch per-item thumbnails only at/below this count
const BGG_MAX_RETRIES     = 3;    // attempts, for HTTP 202 (queued) responses

/**
 * Low-level fetch. Returns [body|false, httpCode]. Retries on 202.
 *
 * The ONLY networking function in this file. A 202 means "queued, try again";
 * we sleep ~0.7s and loop up to BGG_MAX_RETRIES. Any other status breaks out
 * immediately and the caller decides what a non-200 means.
 *
 * @param string $url  Fully-built BGG endpoint URL.
 * @return array{0: string|false, 1: int}  [response body or false, HTTP status].
 */
function bgg_fetch_raw($url) {
    /* Without the curl extension every BGG feature is unavailable — but it must
     * be UNAVAILABLE, not fatal. This function is reached from a member adding
     * a game, an admin correcting one, and the collection sync; an uncaught
     * Error there takes down the whole page instead of showing "could not
     * fetch that game". Same guard, same reasoning, as inc/update.php's two
     * fetchers. Returning the shape callers already handle for a failed
     * request means no caller needs to know. */
    if (!function_exists('curl_init')) return [false, 0];

    $code = 0; $body = false;
    for ($attempt = 0; $attempt < BGG_MAX_RETRIES; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);   // return body as a string
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'zapisynagry/1.0 (board game signups)');

        // Keep the bearer header, but only when a code is actually configured.
        $apiCode = opt('bgg_api_code');
        if ($apiCode !== '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiCode]);
        }

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 202) {           // queued — wait briefly and retry
            usleep(700000);            // 0.7s
            continue;
        }
        break;                         // 200 (or an error we won't retry) — done
    }
    return [$body, $code];
}

/* -----------------------------------------------------------------------------
 *  PARSERS (pure functions — no network — so they're unit-testable)
 *  Both are defensive: they enable libxml internal errors and return an empty
 *  result on malformed XML rather than emitting warnings or throwing.
 * --------------------------------------------------------------------------- */

/**
 * Parse a /search XML payload into a list of ['id','name','year'].
 * Uses the primary name; ignores items without an id.
 *
 * @param string $xmlString  Raw XML body.
 * @return array  List of ['id'=>int,'name'=>string,'year'=>string] (maybe empty).
 */
function bgg_parse_search($xmlString) {
    $out = [];
    if (!is_string($xmlString) || $xmlString === '') return $out;

    libxml_use_internal_errors(true);                  // swallow XML warnings
    $xml = simplexml_load_string($xmlString);
    if ($xml === false) return $out;                   // malformed -> empty

    foreach ($xml->item as $item) {
        $id = (string)$item['id'];
        if ($id === '') continue;                      // skip anything without an id
        // Prefer the primary name; fall back to whatever name is present.
        $name = '';
        foreach ($item->name as $n) {
            if ((string)$n['type'] === 'primary') { $name = (string)$n['value']; break; }
            if ($name === '') $name = (string)$n['value'];
        }
        $out[] = [
            'id'   => (int)$id,
            'name' => $name,
            'year' => (string)($item->yearpublished['value'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Parse a /thing XML payload into a normalised detail array, or null.
 * weight is clamped into the app's 1..5 range (BGG's averageweight is ~1..5 but
 * can be 0 when unrated; we floor it to 1 so the weight badge stays valid).
 *
 * @param string $xmlString  Raw XML body.
 * @return array|null  Normalised details, or null on missing/malformed XML.
 */
function bgg_parse_thing($xmlString) {
    if (!is_string($xmlString) || $xmlString === '') return null;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if ($xml === false || !isset($xml->item)) return null;

    $item = $xml->item;

    // Primary name.
    $name = '';
    /* EVERY name, not just the primary. A game's localised titles live here as
     * `alternate` entries — the Polish edition of Petrichor is sold as "Aura" —
     * and this is the only place BGG exposes them. The versions block carries
     * nicknames ("Polish edition"), which are not titles and must never be
     * stored as one. */
    $names = [];
    foreach ($item->name as $n) {
        $val = bgg_unescape(trim((string)$n['value']));
        if ($val === '') continue;
        if ((string)$n['type'] === 'primary') {
            $name = $val;
            array_unshift($names, $val);       // primary leads the list
        } elseif (!in_array($val, $names, true)) {
            $names[] = $val;
        }
    }

    // Average weight (complexity). Clamp into 1..5 for our weight buckets.
    $weight = (float)($item->statistics->ratings->averageweight['value'] ?? 0);
    if ($weight < 1) $weight = 1;
    if ($weight > 5) $weight = 5;

    return [
        'id'         => (int)$item['id'],
        'name'       => $name,
        // Every title this game is published under, primary first — what the
        // edition chooser offers so a localised printing can be filed under
        // its real name rather than the English one.
        'names'      => $names,
        // Publication year, for the club library's list. 0 when BGG has none,
        // which callers store as NULL rather than printing "0".
        'year'       => (int)($item->yearpublished['value'] ?? 0),
        'type'       => (string)($item['type'] ?? ''),   // 'boardgame' | 'boardgameexpansion' | 'boardgameaccessory' | ...
        'thumbnail'  => (string)($item->thumbnail ?? ''),
        'image'      => (string)($item->image ?? ''),
        'length'     => (int)($item->playingtime['value'] ?? 0),
        'weight'     => round($weight, 2),
        'maxplayers' => (int)($item->maxplayers['value'] ?? 0),
        'minplayers' => (int)($item->minplayers['value'] ?? 0),
    ];
}

/* -----------------------------------------------------------------------------
 *  HIGH-LEVEL CALLS (network + parse)
 * --------------------------------------------------------------------------- */

/**
 * Search BGG for $query. Returns a list of ['id','name','year','thumbnail'].
 * Thumbnails are only filled when the result count is small (RESULT_IMAGE_LIMIT);
 * otherwise 'thumbnail' is ''. Sorted alphabetically by name.
 *
 * For short lists, results are also filtered a second time against /thing's
 * authoritative type attribute, which catches expansions and accessories the
 * search endpoint's own filter missed (see the file header). Long lists don't
 * get this second pass and may still include a stray expansion or promo.
 *
 * @param string $query  User's search text.
 * @return array  List of matches; empty on network/HTTP failure.
 */
/* bgg_configured() used to live here. It moved to helpers.php: it answers a
 * CONFIGURATION question rather than talking to BGG, and it is now asked while
 * rendering ordinary pages — every template that decides whether to offer a
 * BGG route calls it. Keeping it here would have meant loading the whole BGG
 * client on every request just to read one option, which is exactly what the
 * lazy-require note in library.php exists to avoid. */

/**
 * Does /thing say this specific entry is something OTHER than a plain board
 * game — an expansion, an accessory, etc? This is bgg_search()'s second,
 * authoritative filter pass; see the file header for why the search
 * endpoint's own filter isn't enough on its own.
 *
 * An EMPTY type (a missing attribute, or bgg_thing() having failed for this
 * item) is NOT treated as "other" — with nothing to go on, keeping the item is
 * safer than discarding an otherwise-real game over one incomplete lookup.
 *
 * Pulled out as its own function (rather than an inline condition inside
 * bgg_search) so the rule has one place to live and can be tested directly
 * against parsed /thing fixtures, without needing the network.
 *
 * @param array|null $detail  bgg_parse_thing()'s return, or null on failure.
 * @return bool
 */
function bgg_is_non_game($detail) {
    if ($detail === null) return false;
    $type = $detail['type'] ?? '';
    return $type !== '' && $type !== 'boardgame';
}

function bgg_search($query) {
    $url = BGG_BASE . 'search?type=boardgame&excludesubtype=boardgameexpansion&query='
         . urlencode($query);
    [$body, $code] = bgg_fetch_raw($url);
    if ($body === false || $code !== 200) return [];   // any failure -> no results

    $items = bgg_parse_search($body);

    // Conditionally enrich with thumbnails (one /thing per item) for short lists.
    // For long lists we'd make dozens of extra calls, so we skip thumbnails there.
    //
    // SECOND FILTER PASS, piggybacking on that same /thing call: the search
    // endpoint's excludesubtype is well known to be unreliable — BGG's own
    // index mistags plenty of expansions and promos, so some still slip
    // through. /thing's type="..." attribute is the authoritative source (it's
    // that specific entry's own declared type), so anything that isn't a plain
    // 'boardgame' is dropped here — at NO extra API cost, since we were
    // fetching this response for the thumbnail anyway.
    //
    // This only runs for short lists, for the same reason thumbnails do: a
    // long list has no enrichment call to piggyback on, so it keeps whatever
    // the search endpoint returned, mistags and all. And a promo that BGG
    // staff filed as a standalone 'boardgame' entry (rather than as an
    // expansion/accessory of something) looks identical to a real game from
    // here — that's a data problem on BGG's end no client-side filter can fix.
    if (count($items) <= RESULT_IMAGE_LIMIT) {
        $filtered = [];
        foreach ($items as $it) {
            $detail = bgg_thing($it['id']);
            if (bgg_is_non_game($detail)) continue;   // expansion/accessory etc — drop
            $it['thumbnail'] = $detail ? $detail['thumbnail'] : '';
            $filtered[] = $it;
        }
        $items = $filtered;
    } else {
        foreach ($items as &$it) { $it['thumbnail'] = ''; }
        unset($it);
    }

    // Alphabetical by name (case-insensitive) for a predictable result order.
    usort($items, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

/**
 * Fetch + parse a single game's details. Returns the detail array or null.
 * stats=1 asks BGG to include the ratings block (where averageweight lives).
 *
 * @param int $id  BGG game id.
 * @return array|null
 */
/**
 * Parse the <versions> block of a thing response into a pickable list.
 *
 * PURE — no network — so the shape-matching is testable in an environment with
 * no outbound route, which is the one this was written in.
 *
 * DELIBERATELY DEFENSIVE about where a version's title comes from. BGG gives a
 * version both a nickname ("Polish edition") and, for a localised printing, the
 * name that edition is actually sold under ("Aura"). Which element carries
 * which has not been verified against a live response here, so every name on
 * the item is collected and the caller picks — rather than reading one field
 * confidently and silently storing the wrong string.
 *
 * @param string $xmlString  A thing?...&versions=1 response.
 * @return array  List of ['id','name','nickname','names','year','thumbnail',
 *                'image','languages'], newest first. Empty when there are none.
 */
/**
 * Undo escaping that arrived inside a BGG value.
 *
 * Some BGG records carry a literal backslash before an apostrophe —
 * "Summoner\\'s Isle" — an artifact of their own storage rather than part of
 * the title. It shows up in edition `canonicalname` values in particular, which
 * is why ordinary game names look fine and only some editions are affected.
 *
 * Deliberately NARROW: only a backslash directly before a quote is removed.
 * Stripping backslashes generally would corrupt a title that legitimately
 * contains one, and this is display data, not something to be clever with.
 *
 * @param string $value
 * @return string
 */
function bgg_unescape($value) {
    return str_replace(["\\'", '\\"'], ["'", '"'], (string)$value);
}

function bgg_parse_versions($xmlString) {
    if (!is_string($xmlString) || trim($xmlString) === '') return [];

    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($xmlString);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml === false || !isset($xml->item)) return [];

    $out = [];
    foreach ($xml->item->versions->item ?? [] as $v) {
        $id = (int)($v['id'] ?? 0);
        if ($id <= 0) continue;

        /* TWO different strings, and the distinction is the whole feature:
         *
         *   <name value="Polish edition"/>   — the NICKNAME. What the edition
         *                                      is called on BGG. Not a title.
         *   <canonicalname value="Aura"/>    — the TITLE this edition is
         *                                      actually published under.
         *
         * The first attempt read only <name> and stored nicknames, so every
         * edition of Petrichor came out called "Petrichor" (the fallback) and
         * the localised titles never appeared. canonicalname is where they are. */
        $nickname = '';
        foreach ($v->name ?? [] as $n) {
            if ((string)($n['type'] ?? '') !== 'primary') continue;
            $nickname = bgg_unescape(trim((string)($n['value'] ?? '')));
            break;
        }
        $title = bgg_unescape(trim((string)($v->canonicalname['value'] ?? '')));

        // Languages, so an English edition can be preselected for people who
        // do not care which one they get.
        $languages = [];
        foreach ($v->link ?? [] as $l) {
            if ((string)($l['type'] ?? '') === 'language') {
                $lv = trim((string)($l['value'] ?? ''));
                if ($lv !== '') $languages[] = $lv;
            }
        }

        $out[] = [
            'id'        => $id,
            // The title to store; falls back to the nickname only when BGG has
            // no canonical name at all, which is better than an empty label.
            'title'     => $title !== '' ? $title : $nickname,
            'nickname'  => $nickname,
            'year'      => (int)($v->yearpublished['value'] ?? 0),
            'thumbnail' => trim((string)($v->thumbnail ?? '')),
            'image'     => trim((string)($v->image ?? '')),
            'languages' => $languages,
        ];
    }

    /* Newest first: a club buying a game today wants the current printing, and
     * BGG returns these in no order a human would expect. */
    usort($out, function ($a, $b) { return $b['year'] <=> $a['year']; });
    return $out;
}

/**
 * Every published edition of a game. Network — [] on any failure.
 *
 * @param int $id  A GAME id.
 * @return array
 */
function bgg_versions($id) {
    // The documented endpoint, so no scraping and no 403: BGG returns the
    // versions alongside the game itself.
    $url = BGG_BASE . 'thing?versions=1&id=' . (int)$id;
    [$body, $code] = bgg_fetch_raw($url);
    if ($body === false || $code !== 200) return [];
    return bgg_parse_versions($body);
}

function bgg_thing($id) {
    $url = BGG_BASE . 'thing?stats=1&id=' . (int)$id;
    [$body, $code] = bgg_fetch_raw($url);
    if ($body === false || $code !== 200) return null;
    return bgg_parse_thing($body);
}
