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
 * config.php = settings; data/ = the DB; thumbnails/ = uploads; icons/ = the
 * admin-uploaded site icon set; install.php = the self-deleted installer
 * (restoring it would re-expose setup).
 * @return string[]
 */
function update_protected_paths() {
    return ['config.php', 'data', 'thumbnails', 'icons', 'install.php'];
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
 * @return void
 */
function update_rcopy($src, $dst) {
    if (is_dir($src)) {
        if (!is_dir($dst)) @mkdir($dst, 0775, true);
        foreach (scandir($src) as $i) {
            if ($i === '.' || $i === '..') continue;
            update_rcopy($src . '/' . $i, $dst . '/' . $i);
        }
    } else {
        @copy($src, $dst);
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
    if ($za->open($zip) !== true || !$za->extractTo($tmp)) {
        $za->close(); @unlink($zip); update_rrmdir($tmp);
        return [t('update_failed', 'unzip')];
    }
    $za->close();
    @unlink($zip);                                   // zip no longer needed

    $src = update_extracted_root($tmp);              // the "<repo>-<branch>/" wrapper
    if (!$src) { update_rrmdir($tmp); return [t('update_failed', 'empty archive')]; }

    // Overlay every top-level item except the protected ones.
    $protected = update_protected_paths();
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, $protected, true)) continue;   // never clobber settings/data/installer
        update_rcopy($src . '/' . $item, $root . '/' . $item);
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
