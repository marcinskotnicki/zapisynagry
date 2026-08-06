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
