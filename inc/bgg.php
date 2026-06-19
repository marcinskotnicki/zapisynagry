<?php
/* =============================================================================
 *  inc/bgg.php — BoardGameGeek XML API v2 integration.
 * -----------------------------------------------------------------------------
 *  Mirrors the behaviour proven in the previous project, cleaned up:
 *    * One /search call returns id + name + year for every match. Expansions
 *      are excluded server-side (type=boardgame & excludesubtype).
 *    * Thumbnails are NOT in the search response, so we only fetch them (via
 *      per-item /thing) when the result list is small enough — big lists skip
 *      thumbnails to avoid hammering the API. (RESULT_IMAGE_LIMIT.)
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
    foreach ($item->name as $n) {
        if ((string)$n['type'] === 'primary') { $name = (string)$n['value']; break; }
    }

    // Average weight (complexity). Clamp into 1..5 for our weight buckets.
    $weight = (float)($item->statistics->ratings->averageweight['value'] ?? 0);
    if ($weight < 1) $weight = 1;
    if ($weight > 5) $weight = 5;

    return [
        'id'         => (int)$item['id'],
        'name'       => $name,
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
 * @param string $query  User's search text.
 * @return array  List of matches; empty on network/HTTP failure.
 */
function bgg_search($query) {
    $url = BGG_BASE . 'search?type=boardgame&excludesubtype=boardgameexpansion&query='
         . urlencode($query);
    [$body, $code] = bgg_fetch_raw($url);
    if ($body === false || $code !== 200) return [];   // any failure -> no results

    $items = bgg_parse_search($body);

    // Conditionally enrich with thumbnails (one /thing per item) for short lists.
    // For long lists we'd make dozens of extra calls, so we skip thumbnails there.
    if (count($items) <= RESULT_IMAGE_LIMIT) {
        foreach ($items as &$it) {
            $detail = bgg_thing($it['id']);
            $it['thumbnail'] = $detail ? $detail['thumbnail'] : '';
        }
        unset($it);
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
function bgg_thing($id) {
    $url = BGG_BASE . 'thing?stats=1&id=' . (int)$id;
    [$body, $code] = bgg_fetch_raw($url);
    if ($body === false || $code !== 200) return null;
    return bgg_parse_thing($body);
}
