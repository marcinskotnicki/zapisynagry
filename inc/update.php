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
    return ['config.php', 'data', 'thumbnails', 'icons', 'install.php'];
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
function update_rcopy($src, $dst, ?array &$failed = null) {
    if (is_dir($src)) {
        if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
            if ($failed !== null) $failed[] = $dst;
            return;
        }
        foreach (scandir($src) as $i) {
            if ($i === '.' || $i === '..') continue;
            update_rcopy($src . '/' . $i, $dst . '/' . $i, $failed);
        }
    } else {
        // The @ suppresses the warning, NOT the failure — the return value is
        // what matters and it used to be discarded entirely. A file that
        // failed to copy (locked by opcache, a transient permission problem, a
        // full disk) was skipped silently and the update still reported
        // success. That shipped a half-updated tree once: a template was
        // written but the inc/ file defining the function it calls was not, so
        // every page fataled.
        if (!@copy($src, $dst)) {
            if ($failed !== null) $failed[] = $dst;
            return;
        }
        // Byte-for-byte check. copy() can return true having written a short
        // file if the disk filled mid-write, and a truncated PHP file is worse
        // than a missing one — it parses far enough to break confusingly.
        if (filesize($src) !== @filesize($dst) && $failed !== null) {
            $failed[] = $dst;
        }
    }
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
 * Run the whole update. $root is the app root directory.
 * Returns translated result lines for display (one per action + a final "done").
 *
 * @param string $root  Absolute path to the app root (where index.php lives).
 * @return string[]
 */
function update_run($root) {
    $results = [];

    // ---- 1. Download + overlay files ---------------------------------------
    // Build the GitHub "download this branch as zip" URL from the config coords.
    $url = 'https://github.com/' . GITHUB_USER . '/' . GITHUB_REPO
         . '/archive/refs/heads/' . GITHUB_BRANCH . '.zip';
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
    $failed = [];
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, $skip, true)) continue;
        update_rcopy($src . '/' . $item, $root . '/' . $item, $failed);
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
    $results[] = t('update_files_ok');

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

    $results[] = t('update_done');
    return $results;
}
