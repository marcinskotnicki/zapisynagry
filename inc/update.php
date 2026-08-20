<?php
/* =============================================================================
 *  inc/update.php — the admin-triggered system updater.
 * -----------------------------------------------------------------------------
 *  Two jobs, in order:
 *    1. FILES — download the repo zip (coords from config.php) and overwrite
 *       the app files, but NEVER touch config.php, data/, thumbnails/, or
 *       install.php (settings, the database, uploads, and — importantly — we
 *       must not restore the deleted installer).
 *    2. SCHEMA — additively reconcile the database against the freshly pulled
 *       database.sql: add missing tables, add missing columns, seed any new
 *       options rows. Strictly non-destructive: nothing is renamed, retyped,
 *       or dropped (agreed scope).
 *
 *  Schema reconciliation works by building a throwaway database from the new
 *  database.sql and diffing it against the live DB with SQLite's own
 *  introspection (sqlite_master + PRAGMA table_info). That avoids fragile
 *  hand-parsing of SQL.
 *
 *  Returns an array of human-readable result lines (already translated). The
 *  admin "Update" tab just prints them.
 *
 *  WHY NON-DESTRUCTIVE ONLY: the live DB holds real event data. Additive changes
 *  (new table/column/option) are always safe to replay; drops/renames/retypes
 *  could lose data, so they are deliberately out of scope. If a future release
 *  truly needs one, it must be handled as a separate, explicit migration.
 * ============================================================================= */

/**
 * Top-level names the updater must never overwrite/restore.
 *
 * These belong to the SERVER, not to the release: config.php = settings;
 * data/ = the DB; thumbnails/ = uploads; icons/ = the admin-uploaded site icon
 * set; install.php = the self-deleted installer (restoring it would re-expose
 * setup). Copying over any of them would destroy something the admin owns.
 * @return string[]
 */
function update_protected_paths() {
    return ['config.php', 'data', 'thumbnails', 'icons', 'logo', 'install.php'];
}

/**
 * Top-level names that ship in the repository but must NOT be deployed.
 *
 * Distinct from update_protected_paths() and deliberately a separate list: those
 * are things on the SERVER worth shielding, these are things in the RELEASE that
 * simply have no business on a live site. Conflating the two would make both
 * lists confusing to read a year from now.
 *
 * docs/  — developer documentation; useful in the repo, dead weight in a web
 *          root, and publicly readable there for no reason.
 * tests/ — the test suite. It creates and tears down its own database and is
 *          meaningless (and undesirable) on a live install. Excluding it here
 *          makes that guarantee automatic rather than depending on remembering
 *          not to commit the folder.
 * @return string[]
 */
function update_skipped_paths() {
    return ['docs', 'tests'];
}

/**
 * Download a URL to a file with cURL (UA set; GitHub redirects followed).
 * @return bool  True on a 2xx/3xx response with no transport error.
 */
function update_download($url, $dest) {
    // curl is effectively required for this app to update at all, so its
    // absence is rare — but "rare" still fatals every page on a host that
    // lacks it, rather than showing "Update failed: no curl" on this one tab.
    // Same reasoning as update_remote_commit()'s guard, applied here too.
    if (!function_exists('curl_init')) return false;
    $fh = @fopen($dest, 'wb');
    if (!$fh) return false;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fh);              // stream straight to disk
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);   // GitHub redirects to a CDN
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);           // a repo zip can take a while
    curl_setopt($ch, CURLOPT_USERAGENT, 'zapisynagry-updater');
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    return ($err === '' && $code >= 200 && $code < 400);
}

/**
 * Recursive copy (used to overlay new files onto the app root).
 * Creates directories as needed; @-suppressed so a single unwritable file
 * doesn't abort the whole overlay.
 *
 * $failed is an OUT parameter: every path that could not be written is
 * appended to it, so update_run() can report a partial overlay instead of
 * claiming success. Typed `?array` rather than `array ... = null`, which PHP
 * 8.4 deprecates as an implicitly-nullable parameter. The explicit form is
 * valid from PHP 7.1, so it is safe against this app's 7.4 floor.
 * @return void
 */
// How many changed-file lines the report prints before summarising the rest.
// A first run touches the whole tree; without a cap the schema results end up
// scrolled off the bottom of the page.
const UPDATE_LIST_LIMIT = 40;

function update_rcopy($src, $dst, ?array &$failed = null, ?array &$changed = null) {
    // ---- Directory: make it if needed, then recurse. ----
    if (is_dir($src)) {
        if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
            if ($failed !== null) $failed[] = $dst;
            return;
        }
        foreach (scandir($src) as $i) {
            if ($i === '.' || $i === '..') continue;
            update_rcopy($src . '/' . $i, $dst . '/' . $i, $failed, $changed);
        }
        return;
    }

    /* ---- File: skip it when it is already identical. --------------------
     * The release is a full tree, so a blind copy cannot tell an updated file
     * from an unchanged one. Comparing first can, and it is cheap: the size
     * check rejects almost every differing file outright, so only same-size
     * files are ever hashed.
     *
     * Two payoffs. The admin gets a real list of what moved instead of just
     * "done"; and an untouched file is never rewritten, which on a routine
     * update where three files changed means three writes instead of ~170 —
     * far less disk churn and far less opcache invalidation. */
    $isNew = !is_file($dst);
    if (!$isNew
        && filesize($src) === @filesize($dst)
        && @md5_file($src) === @md5_file($dst)) {
        return;                                  // byte-identical: nothing to do
    }

    // The @ suppresses the warning, NOT the failure — the return value is what
    // matters and it used to be discarded entirely. A file that failed to copy
    // (locked by opcache, a transient permission problem, a full disk) was
    // skipped silently and the update still reported success. That shipped a
    // half-updated tree once: a template was written but the inc/ file defining
    // the function it calls was not, so every page fataled.
    if (!@copy($src, $dst)) {
        if ($failed !== null) $failed[] = $dst;
        return;
    }
    // Byte-for-byte check. copy() can return true having written a short file
    // if the disk filled mid-write, and a truncated PHP file is worse than a
    // missing one — it parses far enough to break confusingly.
    if (filesize($src) !== @filesize($dst)) {
        if ($failed !== null) $failed[] = $dst;
        return;
    }
    // Only recorded once the write is known good, so the list is what actually
    // landed rather than what was attempted.
    if ($changed !== null) $changed[] = ($isNew ? '+' : '~') . $dst;
}

/**
 * Recursive delete (temp cleanup for the downloaded zip + extraction dir).
 * @return void
 */
function update_rrmdir($dir) {
    if (!is_dir($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) as $i) {
        if ($i === '.' || $i === '..') continue;
        $p = $dir . '/' . $i;
        is_dir($p) ? update_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Find the single wrapper directory inside an extracted GitHub zip.
 * GitHub archives wrap everything in one "<repo>-<branch>/" folder; this finds
 * it so we can overlay its CONTENTS (not the wrapper) onto the app root.
 * @return string|null  Path to the wrapper dir, or null if none.
 */
function update_extracted_root($tmp) {
    foreach (scandir($tmp) as $i) {
        if ($i === '.' || $i === '..') continue;
        if (is_dir($tmp . '/' . $i)) return $tmp . '/' . $i;
    }
    return null;
}

/**
 * Reconstruct an "ADD COLUMN" clause from a PRAGMA table_info row.
 *
 * Keeps a DEFAULT only when it's a plain literal — SQLite rejects ADD COLUMN
 * with a non-constant default (e.g. datetime('now')), so in that case we add
 * the column as nullable with no default (safe, additive). NOT NULL is only
 * attached alongside a literal default, since a NOT NULL column added to a
 * table with existing rows would otherwise need a default to fill them.
 *
 * @param string $table
 * @param array  $col    A PRAGMA table_info row (name, type, notnull, dflt_value).
 * @return string  An ALTER TABLE ... ADD COLUMN statement.
 */
function update_addcolumn_sql($table, $col) {
    $sql = 'ALTER TABLE "' . $table . '" ADD COLUMN "' . $col['name'] . '" ' . $col['type'];
    $def = $col['dflt_value'];
    // Literal = quoted string, a number, or NULL. Anything else (a function call)
    // is non-constant and unsafe for ADD COLUMN, so we drop it.
    $literalDefault = ($def !== null) && preg_match("/^('.*'|-?\\d+(\\.\\d+)?|NULL)$/is", (string)$def);
    if ($literalDefault) {
        $sql .= ' DEFAULT ' . $def;
        if ((int)$col['notnull'] === 1) $sql .= ' NOT NULL';
    }
    return $sql;
}

/**
 * List user tables (name => create-sql) in a PDO connection.
 * Excludes SQLite's internal sqlite_* tables.
 * @return array<string,string>
 */
function update_tables($pdo) {
    $out = [];
    $rows = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll();
    foreach ($rows as $r) $out[$r['name']] = $r['sql'];
    return $out;
}

/**
 * List user-defined indexes (name => create-sql) in a PDO connection.
 *
 * Excludes the ones SQLite makes for itself: sqlite_autoindex_* (which back a
 * UNIQUE or PRIMARY KEY declared inside CREATE TABLE) have no SQL to replay, and
 * are created automatically with the table anyway.
 *
 * WHY THIS EXISTS: copying a table's CREATE TABLE statement does NOT bring its
 * separate CREATE INDEX statements with it, so a table added by an update used
 * to arrive without its indexes — including UNIQUE ones, whose absence quietly
 * drops a constraint rather than just costing speed.
 * @return array<string,string>
 */
function update_indexes($pdo) {
    $out = [];
    $rows = $pdo->query("SELECT name, sql FROM sqlite_master
                         WHERE type='index' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL")->fetchAll();
    foreach ($rows as $r) $out[$r['name']] = $r['sql'];
    return $out;
}

/**
 * Column list (name => PRAGMA row) for a table.
 * @return array<string,array>
 */
function update_columns($pdo, $table) {
    $out = [];
    foreach ($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll() as $c) {
        $out[$c['name']] = $c;
    }
    return $out;
}

/**
 * The GitHub repository this install updates itself from, as a base URL with
 * no trailing slash, e.g. "https://github.com/someone/zapisynagry".
 *
 * The 'github_url' option wins when set to an http(s) URL; otherwise the
 * GITHUB_USER/GITHUB_REPO constants config.php was installed with.
 *
 * Empty-means-inherit, rather than seeding a literal default into the
 * database: install.php lets the admin enter their own coordinates, so a
 * hardcoded seed would silently be wrong for anyone who did — and would then
 * point their updater at the wrong repository.
 *
 * @return string
 */
function update_repo_url() {
    $custom = trim((string)opt('github_url'));
    /* PINNED TO github.com, not merely "is this a URL".
     *
     * update_remote_commit() has always checked this, but it only READS a
     * version number. The path that DOWNLOADS AND OVERLAYS CODE came through
     * here, where any http(s) address passed — so the host restriction was
     * enforced for the harmless call and not for the destructive one. A
     * github_url pointed elsewhere (a tampered config, a compromised admin
     * session) meant the next update deployed unverified code from that host.
     *
     * An address that is not github.com now falls back to the built-in repo
     * rather than being used: refusing to update is a far better failure than
     * updating from somewhere unintended. */
    if ($custom !== '' && update_is_github_url($custom)) {
        return rtrim($custom, '/');
    }
    return 'https://github.com/' . GITHUB_USER . '/' . GITHUB_REPO;
}

/**
 * Is this a github.com address?
 *
 * One definition, used by every caller, so the read-only check and the
 * code-deploying one can never again disagree about what is acceptable.
 *
 * @param string $url
 * @return bool
 */
function update_is_github_url($url) {
    return (bool)preg_match('#^https://(?:www\.)?github\.com/[^/]+/[^/]+#i', trim((string)$url));
}

/**
 * The "download this branch as a zip" URL the updater actually fetches.
 * The branch still comes from config.php: a fork changes WHERE the code lives
 * far more often than it changes which branch is the released one.
 *
 * @return string
 */
/**
 * Which branch updates come from.
 *
 * Exists so a test site can follow a pre-release branch while every deployed
 * club stays on the stable one, without editing config.php on each host.
 *
 * @return string
 */
function update_branch() {
    // Same rule as update_repo_url(): an admin override wins, otherwise the
    // coordinates the installer was given. Kept to the characters GitHub
    // actually allows in a ref, so a stray space or quote cannot be spliced
    // into the download URL.
    $custom = trim((string)opt('github_branch'));
    // A ref may contain dots and slashes (feature/x, v1.2.3), which is exactly
    // what makes ".." dangerous here: the value is pasted into a URL path, so
    // "../../x" would climb out of /archive/refs/heads/ and point the updater
    // somewhere else entirely. Allow the characters, refuse the traversal.
    $looksLikeRef = $custom !== ''
        && preg_match('#^[A-Za-z0-9._/-]+$#', $custom)
        && strpos($custom, '..') === false
        && $custom[0] !== '/' && substr($custom, -1) !== '/';
    return $looksLikeRef ? $custom : GITHUB_BRANCH;
}

function update_zip_url() {
    return update_repo_url() . '/archive/refs/heads/' . update_branch() . '.zip';
}

/**
 * Run the whole update. $root is the app root directory.
 * Returns translated result lines for display (one per action + a final "done").
 *
 * @param string $root  Absolute path to the app root (where index.php lives).
 * @return string[]
 */
/**
 * When the newest commit on the update branch was made, or null.
 *
 * Best-effort by design. This runs while an admin waits for a tab to render,
 * so every failure path returns null and the tab simply omits the line:
 *   - no curl, no network, DNS failure, host blocks outbound requests;
 *   - a non-GitHub github_url override, which has no API to ask;
 *   - GitHub rate-limiting (60/hour per IP unauthenticated), which a shared
 *     host shares across every site on it — so this WILL be refused sometimes.
 *
 * CACHED for an hour in an option. Without it, every render of the Update tab
 * spends a network round trip, and an admin clicking between tabs would burn
 * the rate limit on its own. The cache stores the answer AND the time it was
 * fetched, so a failure is not cached as "no newer version" — a failed lookup
 * leaves the previous good answer in place until it expires normally.
 *
 * @return array|null  ['date' => 'Y-m-d H:i:s' UTC, 'sha' => short hash]
 */
function update_remote_commit(&$why = null, $allowFetch = true) {
    $why = '';

    /* $allowFetch = false is the ORDINARY case: rendering the Update tab reads
     * whatever a previous check left behind and never touches the network.
     * Only the "check now" button passes true.
     *
     * That is the whole design. Polling on page load meant an admin on a host
     * with no outbound route waited up to ten seconds — two sources, five
     * seconds each — for a tab to appear, and "what is the newest version" is a
     * question you ask deliberately, not one worth a network round trip every
     * time you open a settings page. */
    $cacheRaw = (string)opt('remote_commit_cache');
    $cache = $cacheRaw !== '' ? json_decode($cacheRaw, true) : null;
    if (!is_array($cache)) $cache = [];
    $stale = !empty($cache['date']) ? ['date' => $cache['date'], 'sha' => $cache['sha'] ?? ''] : null;

    if (!$allowFetch) {
        /* Reading only: hand back whatever is on file, however old, with no
         * freshness window applied — there is no "should we fetch" decision
         * to make on this path, since it never fetches either way.
         *
         * A prior version applied the same 1-hour/15-minute windows below to
         * BOTH branches, before this check even ran. That made the CHECK
         * BUTTON A NO-OP whenever a successful check already existed within
         * the last hour: pressing it just handed back the old cached answer,
         * including its old fetched_at, so it looked like a fresh check had
         * just run when nothing had been requested at all. */
        if ($stale === null && !empty($cache['why'])) $why = (string)$cache['why'];
        return $stale;
    }

    /* From here on, $allowFetch = true — the button was pressed, and it must
     * be allowed to reach GitHub even if a recent successful answer exists,
     * or pressing it would never do anything different from just waiting.
     *
     * Two short windows still apply, and both are about the REQUEST, not
     * about hiding a fresh answer:
     *   - 60 seconds after a SUCCESS, to absorb a literal double click or a
     *     resubmitted form, not to defeat a deliberate re-check minutes
     *     later.
     *   - 15 minutes after a FAILURE, because this path is reachable by
     *     repeated human clicks in a way the passive one is not, and
     *     retrying a live rate limit seconds later only makes it worse.
     *     GitHub allows 60 requests an hour per IP unauthenticated — on
     *     shared hosting that IP is shared with every other site on the box,
     *     so the budget can be gone before this app asks even once. */
    if (!empty($cache['fetched_at']) && (time() - (int)$cache['fetched_at']) < 60) {
        return $stale;
    }
    if (!empty($cache['failed_at']) && (time() - (int)$cache['failed_at']) < 900) {
        $why = (string)($cache['why'] ?? 'unavailable');
        return $stale;
    }

    $fail = function ($reason) use (&$why, $stale, $cache) {
        $why = $reason;
        /* Preserve a previous success alongside the failure note, so backing
         * off does not throw away a date we already knew. */
        $keep = [];
        if (!empty($cache['date'])) {
            $keep = ['date' => $cache['date'], 'sha' => $cache['sha'] ?? '',
                     'fetched_at' => (int)($cache['fetched_at'] ?? 0)];
        }
        opt_set('remote_commit_cache', json_encode($keep + ['failed_at' => time(), 'why' => $reason]));
        return $stale;
    };

    if (!function_exists('curl_init')) return $fail('no curl');

    /* Only github.com has the endpoints this speaks. Belt and braces now —
     * update_repo_url() already refuses anything else — but kept so this still
     * fails cleanly rather than building a nonsense API path if that ever
     * changes. The owner/repo capture below needs the match anyway. */
    $repo = update_repo_url();
    if (!update_is_github_url($repo)
        || !preg_match('#^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)#i', $repo, $m)) {
        return $fail('not a github.com source');
    }
    $owner = rawurlencode($m[1]);
    $name  = rawurlencode(preg_replace('/\.git$/i', '', $m[2]));
    /* A ref may legitimately contain '/' (feature/x), which rawurlencode would
     * turn into %2F and break the path. update_branch() has already refused
     * '..' and leading/trailing slashes, so encoding each segment separately
     * is safe. */
    $branch = implode('/', array_map('rawurlencode', explode('/', update_branch())));

    /* Tried in order. The API is the better answer — it gives the committer
     * date and a clean sha — but it is the rate-limited one. The Atom feed is
     * served from github.com itself, is not subject to the API's hourly limit,
     * and lives on the host the updater already downloads from, so a host that
     * can update at all can almost certainly read it. */
    $sources = [
        ['url' => 'https://api.github.com/repos/' . $owner . '/' . $name . '/commits/' . $branch,
         'accept' => 'application/vnd.github+json',
         'parse' => 'update_parse_commit'],
        ['url' => 'https://github.com/' . $owner . '/' . $name . '/commits/' . $branch . '.atom',
         'accept' => 'application/atom+xml',
         'parse' => 'update_parse_commit_atom'],
    ];

    $lastWhy = 'unavailable';
    foreach ($sources as $src) {
        $ch = curl_init($src['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        /* Short, because an admin is watching a page render. The updater itself
         * can afford 300s for a zip; this cannot afford five — and with two
         * sources the worst case is twice this. */
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'zapisynagry-updater');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: ' . $src['accept']]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            $lastWhy = 'request failed' . ($err !== '' ? ': ' . $err : '');
            continue;
        }
        if ($code < 200 || $code >= 300) {
            /* 403 from the API is normally the hourly limit rather than a
             * permissions problem, and saying so saves an admin chasing a
             * misconfiguration that isn't there. */
            $lastWhy = 'HTTP ' . $code;
            if ($code === 403 && stripos((string)$body, 'rate limit') !== false) {
                $lastWhy = 'GitHub rate limit';
            }
            continue;
        }

        $out = $src['parse']((string)$body);
        if ($out === null) { $lastWhy = 'unexpected response format'; continue; }

        opt_set('remote_commit_cache', json_encode($out + ['fetched_at' => time()]));
        return $out;
    }

    return $fail($lastWhy);
}

/**
 * When the stored upstream answer was last fetched, or 0 if there is none.
 *
 * Lets the tab say "checked <when>" beside a cached date. Without it a week-old
 * answer looks exactly like a fresh one, which matters more here than usual
 * because nothing refreshes it automatically any more.
 *
 * @return int  Unix timestamp, or 0.
 */
function update_remote_checked_at() {
    $raw = (string)opt('remote_commit_cache');
    if ($raw === '') return 0;
    $cache = json_decode($raw, true);
    if (!is_array($cache)) return 0;
    return max((int)($cache['fetched_at'] ?? 0), (int)($cache['failed_at'] ?? 0));
}

/**
 * Same job as update_parse_commit(), for GitHub's Atom commit feed.
 *
 * The feed exists because the JSON API is rate-limited per IP and a shared host
 * burns that budget across every site on it. This is plain XML off github.com,
 * which the updater already reaches to download releases.
 *
 * Its <updated> is the commit date, and the entry <id> carries the sha in the
 * form "tag:github.com,2008:Grit::Commit/<sha>".
 *
 * @param string $xml  Raw feed body.
 * @return array|null  ['date' => 'Y-m-d H:i:s' UTC, 'sha' => short hash]
 */
function update_parse_commit_atom($xml) {
    if (!function_exists('simplexml_load_string')) return null;

    $prev = libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    /* === false, not falsy: an empty element casts to false while having
     * parsed perfectly — the same trap the legacy XML importer hit. */
    if ($feed === false) return null;

    $entry = $feed->entry[0] ?? null;
    if ($entry === null) return null;

    $iso = trim((string)$entry->updated);
    if ($iso === '') return null;
    $ts = strtotime($iso);
    if ($ts === false) return null;

    $sha = '';
    $id  = trim((string)$entry->id);
    if ($id !== '' && ($slash = strrpos($id, '/')) !== false) {
        $sha = substr($id, $slash + 1, 7);
    }
    return ['date' => gmdate('Y-m-d H:i:s', $ts), 'sha' => $sha];
}

/**
 * Pull the commit date and short hash out of a GitHub commit API response.
 *
 * Split from the fetch so it can be tested without a network: the transport is
 * a handful of curl options, but THIS is where a change in GitHub's response
 * shape would silently produce a wrong date, and it is the half worth pinning.
 *
 * The COMMITTER date, not the author date: a commit can be authored weeks
 * before it lands on a branch, and what matters here is when the code this
 * install would actually pull became available.
 *
 * @param string $json  Raw response body.
 * @return array|null   ['date' => 'Y-m-d H:i:s' UTC, 'sha' => short hash]
 */
function update_parse_commit($json) {
    $data = json_decode($json, true);
    if (!is_array($data)) return null;

    $iso = $data['commit']['committer']['date'] ?? ($data['commit']['author']['date'] ?? '');
    if (!is_string($iso) || $iso === '') return null;
    $ts = strtotime($iso);
    if ($ts === false) return null;

    return ['date' => gmdate('Y-m-d H:i:s', $ts), 'sha' => substr((string)($data['sha'] ?? ''), 0, 7)];
}

function update_run($root) {
    $results = [];

    // ---- 1. Download + overlay files ---------------------------------------
    $url = update_zip_url();
    // Random temp names so concurrent/retried updates can't collide.
    $zip = $root . '/_update_' . bin2hex(random_bytes(4)) . '.zip';
    if (!update_download($url, $zip)) {
        @unlink($zip);
        return [t('update_failed', 'download')];
    }

    $tmp = $root . '/_update_tmp_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0775, true);
    $za = new ZipArchive();
    // CHECKCONS validates the archive's internal consistency on open, which
    // catches a truncated download — a half-received zip can still open and
    // extract its early entries happily, producing exactly the partial tree
    // this whole guard exists to prevent.
    if ($za->open($zip, ZipArchive::CHECKCONS) !== true || !$za->extractTo($tmp)) {
        $za->close(); @unlink($zip); update_rrmdir($tmp);
        return [t('update_failed', 'unzip')];
    }

    // Every entry must have landed at its declared size before we touch the
    // live tree. Verifying here rather than after the overlay means a bad
    // download costs nothing — the app is still untouched at this point.
    $shortEntries = [];
    for ($i = 0; $i < $za->numFiles; $i++) {
        $st = $za->statIndex($i);
        if (!$st || substr($st['name'], -1) === '/') continue;
        $out = $tmp . '/' . $st['name'];
        if (!is_file($out) || filesize($out) !== $st['size']) {
            $shortEntries[] = $st['name'];
        }
    }
    $za->close();
    @unlink($zip);                                   // zip no longer needed
    if ($shortEntries) {
        update_rrmdir($tmp);
        return [t('update_failed', 'incomplete archive: ' . count($shortEntries) . ' file(s)')];
    }

    $src = update_extracted_root($tmp);              // the "<repo>-<branch>/" wrapper
    if (!$src) { update_rrmdir($tmp); return [t('update_failed', 'empty archive')]; }

    // Overlay every top-level item, minus the two exclusion lists: things the
    // server owns (settings, data, uploads) and things the release ships but
    // shouldn't deploy (docs, tests).
    $skip = array_merge(update_protected_paths(), update_skipped_paths());

    /* THE HTTPS REDIRECT IS A SETTING THAT LIVES IN A SHIPPED FILE.
     *
     * .htaccess is deliberately NOT protected — it also carries the rules that
     * keep data/ out of the web, and a release improving those has to be able
     * to land. But the release copy has no managed block in it, so overlaying
     * it silently switched "force HTTPS" off on every update, and nothing said
     * so: the checkbox reads the file, so it agreed with the file and reported
     * the setting as off.
     *
     * Remembered here and re-applied after the overlay, so the release's own
     * .htaccess changes arrive AND the admin's choice survives them. */
    require_once __DIR__ . '/htaccess.php';
    $sslWasOn = htaccess_ssl_enabled();

    $failed = [];
    $changed = [];
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, $skip, true)) continue;
        update_rcopy($src . '/' . $item, $root . '/' . $item, $failed, $changed);
    }

    /* Put the HTTPS redirect back, before anything else can go wrong — an
     * admin whose site was forcing HTTPS a minute ago must not be left serving
     * plain HTTP because they pressed Update.
     *
     * Only when it was on: this restores a choice, it never makes one. */
    if ($sslWasOn && !htaccess_ssl_enabled()) {
        if (htaccess_ssl_set(true)) {
            log_action('update_htaccess', 'HTTPS redirect restored after update');
        } else {
            // Worth saying out loud rather than leaving them to notice: the
            // site is now reachable over plain HTTP and they did not choose it.
            $results[] = t('update_ssl_lost');
        }
    }

    // STOP HERE if anything failed to land. A partially-overlaid tree is the
    // worst outcome available: files that reference each other get out of step
    // (a template calling a helper its inc/ file no longer defines takes every
    // page down), and carrying on to migrate the schema would compound it.
    //
    // Reporting the names matters as much as stopping — the admin needs to
    // know WHICH files to re-upload by hand, which is exactly the information
    // the silent version threw away.
    if ($failed) {
        update_rrmdir($tmp);
        $results[] = t('update_failed', 'copy: ' . implode(', ', array_map(
            function ($f) use ($root) { return ltrim(str_replace($root, '', $f), '/'); },
            array_slice($failed, 0, 12)
        )) . (count($failed) > 12 ? ' (+' . (count($failed) - 12) . ')' : ''));
        return $results;
    }

    update_rrmdir($tmp);                             // clean up the extraction dir
    /* List what actually moved. "Nothing changed" is a genuinely useful answer
     * — it tells an admin the update ran and the install was already current,
     * rather than leaving them to wonder whether it did anything.
     *
     * Capped: a first-run or a big release can touch every file in the tree,
     * and a 170-line wall of paths buries the schema results underneath it.
     * The count is always exact even when the list is trimmed. */
    if (!$changed) {
        $results[] = t('update_files_current');
    } else {
        $results[] = t('update_files_ok');
        $rel = array_map(function ($f) use ($root) {
            // Each entry is prefixed + (new) or ~ (modified) by update_rcopy.
            $mark = $f[0];
            return $mark . ' ' . ltrim(str_replace($root, '', substr($f, 1)), '/');
        }, $changed);
        sort($rel);
        $show = array_slice($rel, 0, UPDATE_LIST_LIMIT);
        foreach ($show as $line) $results[] = $line;
        if (count($rel) > UPDATE_LIST_LIMIT) {
            $results[] = t('update_files_more', count($rel) - UPDATE_LIST_LIMIT);
        }
        $results[] = t('update_files_count', count($rel));
    }

    // ---- 2. Reconcile the schema -------------------------------------------
    // Build a throwaway DB from the (now updated) database.sql and diff it.
    $newSql = @file_get_contents($root . '/database.sql');
    if ($newSql === false) {
        $results[] = t('update_failed', 'database.sql missing');
        return $results;
    }

    $tmpDbFile = tempnam(sys_get_temp_dir(), 'zng');
    try {
        // The "reference" DB: exactly what a fresh install of this version looks like.
        $ref = new PDO('sqlite:' . $tmpDbFile);
        $ref->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ref->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $ref->exec($newSql);

        // All schema changes happen in one transaction on the live DB.
        $live = db();
        $live->beginTransaction();

        $refTables  = update_tables($ref);           // what the new schema has
        $liveTables = update_tables($live);          // what we currently have

        foreach ($refTables as $name => $createSql) {
            if (!isset($liveTables[$name])) {
                // New table — create it verbatim from the reference schema.
                $live->exec($createSql);
                $results[] = t('update_added_table', $name);
            } else {
                // Existing table — add any new columns the reference has.
                $refCols  = update_columns($ref, $name);
                $liveCols = update_columns($live, $name);
                foreach ($refCols as $colName => $col) {
                    if (!isset($liveCols[$colName])) {
                        $live->exec(update_addcolumn_sql($name, $col));
                        $results[] = t('update_added_column', $name, $colName);
                    }
                }
            }
        }

        // Indexes the reference schema declares but this database lacks. Runs
        // AFTER the table pass, so an index belonging to a table created a
        // moment ago finds its table already there.
        //
        // Only ever ADDS. An index that exists here but not in the reference is
        // left alone: it may be something an admin added deliberately, and
        // dropping it is not a call an updater should make.
        $refIdx  = update_indexes($ref);
        $liveIdx = update_indexes($live);
        foreach ($refIdx as $idxName => $idxSql) {
            if (isset($liveIdx[$idxName])) continue;
            // A UNIQUE index can fail on data that predates it (duplicate rows
            // that were legal until now). Let that abort the transaction loudly
            // rather than pretend the constraint is in force when it isn't.
            $live->exec($idxSql);
            $results[] = t('update_added_index', $idxName);
        }

        // Seed any newly introduced options keys (settings added in a release).
        // Existing keys are left untouched — we never overwrite an admin's value.
        if (isset($refTables['options']) && isset($liveTables['options'])) {
            $refOpts  = $ref->query('SELECT key, value FROM options')->fetchAll();
            foreach ($refOpts as $o) {
                $exists = $live->prepare('SELECT 1 FROM options WHERE key = ?');
                $exists->execute([$o['key']]);
                if (!$exists->fetchColumn()) {
                    $ins = $live->prepare('INSERT INTO options (key, value) VALUES (?, ?)');
                    $ins->execute([$o['key'], $o['value']]);
                    $results[] = t('update_added_option', $o['key']);
                }
            }
        }

        // Bump the recorded schema version to the new one (informational marker).
        if (isset($refTables['meta'])) {
            $newVer = $ref->query("SELECT value FROM meta WHERE key='schema_version'")->fetchColumn();
            if ($newVer !== false) {
                $live->prepare(
                    "INSERT INTO meta (key, value) VALUES ('schema_version', ?)
                     ON CONFLICT(key) DO UPDATE SET value = excluded.value"
                )->execute([$newVer]);
            }
        }

        $live->commit();

        // If nothing structural changed, say so plainly (only the files line so far).
        if (count($results) === 1) {   // only the "files updated" line
            $results[] = t('update_schema_ok');
        }
    } catch (Throwable $ex) {
        // Any schema error rolls the whole reconciliation back — the live DB is
        // left exactly as it was before the attempt.
        if (db()->inTransaction()) db()->rollBack();
        $results[] = t('update_failed', $ex->getMessage());
    } finally {
        @unlink($tmpDbFile);                         // always remove the throwaway DB
    }

    // The new PHP files are on disk, but PHP's opcode cache (OPcache) may keep
    // serving the OLD compiled versions — a browser hard-refresh cannot touch
    // this server-side cache, and on hosts with timestamp revalidation disabled
    // it never expires on its own. Reset it so the update takes effect
    // immediately. (Static files like CSS are unaffected by OPcache, which is
    // why theme changes can appear while PHP changes seem "missing".)
    if (function_exists('opcache_reset')) {
        @opcache_reset();
        $results[] = t('update_cache_reset');
    }
    clearstatcache(true);                        // also drop PHP's file-stat cache

    /* Stamp WHEN, so the tab can say what it last did. Stored as an option
     * rather than read back from the audit log: log rows are pruned on the
     * log_retention_days schedule, so on a site that keeps a year of logs the
     * "last updated" line would silently go blank the moment the entry aged
     * out, while the install is no less updated than it was. */
    opt_set('last_update_at', gmdate('Y-m-d H:i:s'));

    $results[] = t('update_done');
    return $results;
}
