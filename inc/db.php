<?php
/* =============================================================================
 *  inc/db.php — the one place we open the SQLite connection.
 * -----------------------------------------------------------------------------
 *  Deliberately tiny: a single shared PDO handle. No query builder, no ORM —
 *  the rest of the app writes plain SQL with prepared statements. That keeps
 *  things fast and obvious, which is what we want here.
 *
 *  WHY A SINGLETON: every page makes several queries; opening one connection
 *  and reusing it avoids per-query connection overhead and guarantees that the
 *  `PRAGMA foreign_keys = ON` (set once, below) applies to every query in the
 *  request. SQLite enforces foreign keys *per connection*, so a second handle
 *  would silently lose cascade behaviour. The same is true of the WAL and
 *  busy-timeout settings below.
 *
 *  CONCURRENCY: the connection runs in WAL mode with a busy timeout, so readers
 *  are never blocked by a writer and a brief write collision waits instead of
 *  erroring. Both are set per connection and both degrade gracefully if the
 *  host refuses them — see the comments at each.
 *
 *  HOW TO USE (the four helpers at the bottom cover ~all call sites):
 *    db_run($sql, $params)  -> PDOStatement   (INSERT/UPDATE/DELETE, or when you
 *                                               need the statement object back)
 *    db_one($sql, $params)  -> one row | null  (SELECT ... LIMIT 1)
 *    db_all($sql, $params)  -> array of rows   (SELECT returning many rows)
 *    db_val($sql, $params)  -> scalar | null   (SELECT COUNT(*), single column)
 *  Always pass user data through the $params array (prepared statements) — never
 *  concatenate it into $sql, or you reopen the door to SQL injection.
 * ============================================================================= */

/**
 * Return the shared PDO connection, opening it on first use.
 *
 * DB_PATH comes from config.php (written by the installer). The connection is
 * cached in a static so the second and later calls are free.
 *
 * @return PDO  The one connection every helper below talks through.
 */
function db() {
    // Static = remembered between calls within the same request. Once opened,
    // we short-circuit straight back out.
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Open the SQLite file named in config.php.
    $pdo = new PDO('sqlite:' . DB_PATH);
    // Throw exceptions on SQL errors (instead of silent false returns) so bugs
    // surface loudly rather than corrupting data quietly.
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Fetch rows as associative arrays ($row['name']) — what the whole codebase
    // and every template expects.
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // SQLite needs foreign-key enforcement switched on per connection. Without
    // this, the ON DELETE CASCADE rules in database.sql would do nothing.
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // Write-ahead logging. In SQLite's DEFAULT journal mode a write blocks
    // readers as well as other writers, so a burst of sign-ups while the chat
    // is polling can collide and surface as "database is locked". Under WAL a
    // reader never waits for a writer, which is the shape of traffic this app
    // actually has.
    //
    // Wrapped because it can legitimately fail: WAL needs to create sidecar
    // files next to the database, which some network filesystems (a few shared
    // hosts mount NFS) do not support. Failing to switch is not fatal — the
    // database still works exactly as it did before — so a host that refuses
    // it should keep running rather than break on every page.
    try {
        $pdo->exec('PRAGMA journal_mode = WAL;');
    } catch (Throwable $e) {
        // Left on the default journal; nothing else changes.
    }

    // Wait rather than fail instantly when another request holds the write
    // lock. Without this a collision throws immediately; five seconds is far
    // longer than any query here takes, so a brief overlap resolves itself
    // instead of reaching the visitor as an error.
    try {
        $pdo->exec('PRAGMA busy_timeout = 5000;');
    } catch (Throwable $e) {
        // Older builds may not accept it; the default (fail fast) still works.
    }

    return $pdo;
}

/**
 * Write a consistent snapshot of the database to $dest.
 *
 * NOT a plain file copy. Under WAL the live file is only half the story — the
 * recent commits sit in the -wal sidecar — so copying it can produce a backup
 * that is missing data or, mid-write, is internally torn.
 *
 * `VACUUM INTO` asks SQLite itself for the snapshot: it takes a read lock,
 * writes a single self-contained file with no sidecars, and cannot capture a
 * half-finished transaction. It needs SQLite 3.27 (2019); on anything older we
 * fall back to checkpointing the WAL back into the main file and copying it
 * while holding a lock, which is the best a plain copy can do.
 *
 * @param string $dest  Absolute path to write. Must not already exist.
 * @return bool  True on success.
 */
function db_snapshot($dest) {
    if ($dest === '' || file_exists($dest)) return false;
    $pdo = db();

    try {
        // Quoted as a literal rather than bound: VACUUM INTO takes a filename
        // expression, not a bindable parameter. $dest is built by the caller
        // from a temp dir and a generated name, never from user input.
        $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
        return is_file($dest) && filesize($dest) > 0;
    } catch (Throwable $e) {
        // Older SQLite, or a filesystem that refused the write.
    }

    try {
        // Fold the WAL back into the main file so a copy sees everything, then
        // copy while a read lock keeps writers out for the duration.
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE);');
        $pdo->beginTransaction();
        $pdo->exec('SELECT 1');           // materialise the read lock
        $ok = @copy(DB_PATH, $dest);
        $pdo->commit();
        return $ok && is_file($dest) && filesize($dest) > 0;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($dest);
        return false;
    }
}

/* ---- A couple of one-liner shortcuts so call sites stay readable. --------- *
 * These all funnel through db() (so they share the one connection) and through
 * prepare()/execute() (so every value is safely bound). Prefer them over
 * touching db() directly.
 * --------------------------------------------------------------------------- */

/**
 * Run a query with bound params, return the statement.
 * Use for writes, or when you want to chain ->rowCount(), ->fetchColumn(), etc.
 *
 * @param string $sql     SQL with `?` placeholders.
 * @param array  $params  Values bound to those placeholders, in order.
 * @return PDOStatement   The executed statement.
 */
function db_run($sql, $params = []) {
    $stmt = db()->prepare($sql);   // compile + protect against injection
    $stmt->execute($params);       // bind values and run
    return $stmt;
}

/**
 * Fetch a single row (or null).
 * Returns null rather than PDO's `false` so callers can write `if (!$row)`.
 *
 * @return array|null  The first row as an assoc array, or null if none.
 */
function db_one($sql, $params = []) {
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/**
 * Fetch all rows.
 *
 * @return array  List of assoc-array rows (empty array if none).
 */
function db_all($sql, $params = []) {
    return db_run($sql, $params)->fetchAll();
}

/**
 * Fetch a single scalar value from the first column (or null).
 * Handy for `SELECT COUNT(*) ...` or grabbing one field.
 *
 * @return mixed|null  The first column of the first row, or null if none.
 */
function db_val($sql, $params = []) {
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/**
 * The cached answer to db_exposure_state(), rechecked at most once a day.
 *
 * The check makes an outbound HTTP request, so it cannot run on every admin
 * page load. Only a confirmed 'exposed' is treated as a warning: 'unknown'
 * stays quiet, because crying wolf at an admin who cannot reproduce it is how
 * a real warning gets ignored later.
 *
 * @return bool
 */
function db_exposure_is_exposed() {
    $cache = json_decode((string)opt('db_exposure_cache', ''), true);
    $age   = is_array($cache) ? (time() - (int)($cache['at'] ?? 0)) : PHP_INT_MAX;

    if ($age < 86400 && isset($cache['state'])) {
        return $cache['state'] === 'exposed';
    }

    $state = db_exposure_state();
    opt_set('db_exposure_cache', json_encode(['state' => $state, 'at' => time()]));
    return $state === 'exposed';
}

/**
 * Is the database file downloadable over HTTP?
 *
 * WHY THIS EXISTS: the database — password hashes, email addresses, every
 * sign-up — sits inside the web root, protected only by .htaccess. Apache
 * honours that; Nginx, Caddy and IIS silently ignore it, and the whole file
 * becomes a URL anybody can fetch. Nothing in the application noticed, so a
 * misconfigured deployment looked exactly like a correct one.
 *
 * This does not fix the exposure — only moving the file out of the web root
 * does that — but it turns a silent catastrophe into a visible warning.
 *
 * Returns one of:
 *   'exposed'  — fetched it, and it really is the database. Act now.
 *   'blocked'  — the server refused, as it should.
 *   'unknown'  — could not tell (no curl, no reachable URL, request failed).
 *
 * Deliberately cheap and cached: this runs behind an admin page, not on every
 * request, and a wrong answer must never be alarming — 'unknown' is reported
 * as "could not check", never as "you are safe".
 *
 * @return string
 */
function db_exposure_state() {
    if (!defined('DB_PATH')) return 'unknown';
    if (!function_exists('curl_init')) return 'unknown';

    $base = site_base_url();
    if ($base === '') return 'unknown';

    /* The DB has to actually live under the web root for a URL to exist. If an
     * admin has moved it out — the real fix — there is nothing to test. */
    $root = realpath(dirname(__DIR__));
    $db   = realpath(DB_PATH);
    if ($root === false || $db === false) return 'unknown';
    if (strpos($db, $root . DIRECTORY_SEPARATOR) !== 0) return 'blocked';   // outside the web root

    $rel = str_replace('\\', '/', substr($db, strlen($root) + 1));
    $url = rtrim($base, '/') . '/' . $rel;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    // Only the first bytes are needed to recognise the file.
    curl_setopt($ch, CURLOPT_RANGE, '0-63');
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) return 'unknown';          // network refused us, not proof of anything
    if ($code === 403 || $code === 404) return 'blocked';
    if ($code < 200 || $code >= 300) return 'unknown';

    /* A 200 is not enough on its own: some hosts answer everything with a
     * friendly error page. Every SQLite file opens with this exact string, so
     * seeing it means we really did just download the database. */
    return strpos((string)$body, 'SQLite format 3') === 0 ? 'exposed' : 'unknown';
}

