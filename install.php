<?php
/* =============================================================================
 *  zapisynagry — installer (install.php)
 * -----------------------------------------------------------------------------
 *  A STANDALONE, self-deleting web installer. The user uploads only this one
 *  file to an empty web directory and opens it in a browser. It then:
 *
 *    1. Checks the server meets requirements (PHP version + extensions +
 *       a writable directory).
 *    2. Downloads the whole app from GitHub as a zip and unpacks it here.
 *    3. Creates the runtime directories (data/, thumbnails/) and permissions.
 *    4. Builds the SQLite database by running the bundled database.sql.
 *    5. Creates the first admin account and stores the venue name.
 *    6. Writes config.php (DB path + repo coords + app secret).
 *    7. Deletes itself.
 *
 *  It can't use the app's templates/bootstrap because those don't exist on disk
 *  until step 2 runs. That's why the HTML/CSS below is inline — the one place
 *  the "no inline styles/markup" rule has to bend, by necessity.
 *
 *  No alert()-style popups: every state is a full page, per the project rules.
 * ============================================================================= */

// Don't let a slow GitHub download or a big unzip time us out mid-install.
@set_time_limit(0);
// Hide deprecation/notice chatter so the install pages stay clean; real
// failures are reported explicitly as page text at each step below.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* -----------------------------------------------------------------------------
 *  Defaults (prefilled in the form; the user can override the GitHub source).
 * --------------------------------------------------------------------------- */
const DEFAULT_GITHUB_USER   = 'marcinskotnicki';
const DEFAULT_GITHUB_REPO   = 'zapisynagry';
const DEFAULT_GITHUB_BRANCH = 'main';      // new GitHub repos default to "main"
const MIN_PHP_VERSION       = '7.4.0';

// Absolute path of the directory this installer lives in = the app root.
$ROOT = __DIR__;

/* =============================================================================
 *  Small helpers
 * ============================================================================= */

/** Recursively delete a directory and everything in it. */
function rrmdir($dir) {
    if (!is_dir($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

/** Recursively copy $src into $dst, creating directories as needed. */
function rcopy($src, $dst) {
    if (is_dir($src)) {
        if (!is_dir($dst)) @mkdir($dst, 0775, true);
        foreach (scandir($src) as $item) {
            if ($item === '.' || $item === '..') continue;
            rcopy($src . DIRECTORY_SEPARATOR . $item, $dst . DIRECTORY_SEPARATOR . $item);
        }
    } else {
        @copy($src, $dst);
    }
}

/** Download a URL to a local file with cURL. Returns [ok(bool), httpCode, error]. */
function download_to($url, $destFile) {
    $fh = @fopen($destFile, 'wb');
    if (!$fh) return [false, 0, "Cannot open $destFile for writing"];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fh);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);   // github.com -> codeload
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    // GitHub's edge expects a User-Agent; default cURL sends none and may 403.
    curl_setopt($ch, CURLOPT_USERAGENT, 'zapisynagry-installer');
    curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    return [($err === '' && $code >= 200 && $code < 400), $code, $err];
}

/** Find the single top-level folder inside an extracted GitHub zip (e.g. repo-main). */
function find_extracted_root($tmpDir) {
    foreach (scandir($tmpDir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $tmpDir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($p)) return $p;   // GitHub zips wrap everything in one dir
    }
    return null;
}

/** HTML escape shortcut. */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =============================================================================
 *  Requirement checks (run on every page so we can show status up front).
 * ============================================================================= */
function check_requirements($root) {
    $checks = [];
    $checks[] = [
        'PHP ' . MIN_PHP_VERSION . '+',
        version_compare(PHP_VERSION, MIN_PHP_VERSION, '>='),
        'Found ' . PHP_VERSION,
    ];
    foreach (['pdo_sqlite', 'curl', 'zip', 'gd', 'mbstring'] as $ext) {
        $checks[] = ["Extension: $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing'];
    }
    $checks[] = ['Install directory writable', is_writable($root), $root];
    return $checks;
}

/** True if every requirement passed. */
function all_passed($checks) {
    foreach ($checks as $c) if (!$c[1]) return false;
    return true;
}

/* =============================================================================
 *  Inline page chrome (necessary: templates don't exist yet).
 * ============================================================================= */
function page_head($title) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title><style>';
    echo 'body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;color:#222;line-height:1.5}';
    echo 'h1{font-size:1.4rem}label{display:block;margin:.8rem 0 .2rem;font-weight:600}';
    echo 'input{width:100%;padding:.5rem;border:1px solid #bbb;border-radius:6px;box-sizing:border-box}';
    echo 'button{margin-top:1.2rem;padding:.6rem 1.2rem;border:0;border-radius:6px;background:#2d6cdf;color:#fff;font-size:1rem;cursor:pointer}';
    echo '.ok{color:#157f3b}.bad{color:#c0392b}.row{display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding:.35rem 0}';
    echo '.muted{color:#777;font-size:.9rem}.box{background:#f7f7f9;border:1px solid #e3e3e8;border-radius:8px;padding:1rem;margin:1rem 0}';
    echo 'code{background:#eee;padding:.1rem .3rem;border-radius:4px}</style></head><body>';
}
function page_foot() { echo '</body></html>'; }

/* =============================================================================
 *  ROUTING
 *  step (default "start") -> form; POST "install" -> do the work.
 * ============================================================================= */
$step = $_POST['step'] ?? $_GET['step'] ?? 'start';

/* -----------------------------------------------------------------------------
 *  Guard: refuse to clobber an existing install unless explicitly forced.
 * --------------------------------------------------------------------------- */
$dbAlreadyThere = file_exists($ROOT . '/data/database.sqlite');
if ($dbAlreadyThere && empty($_REQUEST['force'])) {
    page_head('Already installed');
    echo '<h1>Looks like the app is already installed</h1>';
    echo '<p>A database already exists at <code>data/database.sqlite</code>. ';
    echo 'Re-running the installer would overwrite it.</p>';
    echo '<p class="muted">If you really want to reinstall (this destroys all data), ';
    echo 'append <code>?force=1</code> to the URL.</p>';
    echo '<p>Otherwise just delete this <code>install.php</code> file and open the app.</p>';
    page_foot();
    exit;
}

/* =============================================================================
 *  STEP: DO THE INSTALL
 * ============================================================================= */
if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    page_head('Installing…');
    echo '<h1>Installing</h1>';

    // ---- 0. Re-check requirements server-side (never trust the form). -------
    $checks = check_requirements($ROOT);
    if (!all_passed($checks)) {
        echo '<p class="bad">Requirements are not met. Go back and fix them first.</p>';
        page_foot(); exit;
    }

    // ---- Collect & validate input ------------------------------------------
    $ghUser   = trim($_POST['gh_user']   ?? '') ?: DEFAULT_GITHUB_USER;
    $ghRepo   = trim($_POST['gh_repo']   ?? '') ?: DEFAULT_GITHUB_REPO;
    $ghBranch = trim($_POST['gh_branch'] ?? '') ?: DEFAULT_GITHUB_BRANCH;

    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminName  = trim($_POST['admin_name']  ?? '');
    $adminPass  = (string)($_POST['admin_pass']  ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
    $venueName  = trim($_POST['venue_name'] ?? '');

    // Optional email + integration settings (all skippable; they land in the
    // options table and can be set/changed later in the admin Options tab).
    $optSeed = [
        'email_smtp_server' => trim($_POST['email_smtp_server'] ?? ''),
        'email_smtp_port'   => trim($_POST['email_smtp_port']   ?? ''),
        'email_address'     => trim($_POST['email_address']     ?? ''),
        'email_login'       => trim($_POST['email_login']       ?? ''),
        'email_password'    => (string)($_POST['email_password'] ?? ''),
        'bgg_api_code'      => trim($_POST['bgg_api_code']      ?? ''),
    ];

    $errors = [];
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Admin email is not valid.';
    if ($adminName === '')                               $errors[] = 'Admin display name is required.';
    if (strlen($adminPass) < 6)                          $errors[] = 'Admin password must be at least 6 characters.';
    if ($adminPass !== $adminPass2)                      $errors[] = 'The two passwords do not match.';
    if ($errors) {
        echo '<div class="box"><strong class="bad">Please fix:</strong><ul>';
        foreach ($errors as $er) echo '<li>' . e($er) . '</li>';
        echo '</ul></div><p><a href="install.php">&larr; Go back</a></p>';
        page_foot(); exit;
    }

    // A running log so the user sees progress and exactly where it stops.
    // A tiny closure that echoes one status row and flush()es it immediately, so
    // each step appears as it happens rather than all at once at the end.
    $log = function($msg, $cls = '') { echo '<div class="row"><span>' . $msg . '</span><span class="' . $cls . '">' . ($cls === 'ok' ? 'OK' : '') . '</span></div>'; flush(); };

    // ---- 1. Download the repo zip ------------------------------------------
    $zipUrl  = "https://github.com/{$ghUser}/{$ghRepo}/archive/refs/heads/{$ghBranch}.zip";
    $zipFile = $ROOT . '/_install_' . bin2hex(random_bytes(4)) . '.zip';
    $log("Downloading <code>" . e("{$ghUser}/{$ghRepo}@{$ghBranch}") . "</code>");
    [$ok, $code, $err] = download_to($zipUrl, $zipFile);
    if (!$ok) {
        @unlink($zipFile);
        echo '<p class="bad">Download failed (HTTP ' . e($code) . '). ' . e($err) . '</p>';
        echo '<p class="muted">Check the repo/branch is correct and public. New repos use <code>main</code>; older ones may use <code>master</code>.</p>';
        page_foot(); exit;
    }
    $log("Downloaded archive", 'ok');

    // ---- 2. Unzip into a temp dir, then move contents into the app root ----
    $tmpDir = $ROOT . '/_install_tmp_' . bin2hex(random_bytes(4));
    @mkdir($tmpDir, 0775, true);
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true || !$zip->extractTo($tmpDir)) {
        $zip->close(); @unlink($zipFile); rrmdir($tmpDir);
        echo '<p class="bad">Could not unzip the archive.</p>';
        page_foot(); exit;
    }
    $zip->close();
    @unlink($zipFile);

    $extractedRoot = find_extracted_root($tmpDir);   // e.g. repo-main/
    if (!$extractedRoot) {
        rrmdir($tmpDir);
        echo '<p class="bad">Archive looked empty after extraction.</p>';
        page_foot(); exit;
    }
    // Copy everything from the wrapper dir up into the app root.
    foreach (scandir($extractedRoot) as $item) {
        if ($item === '.' || $item === '..') continue;
        rcopy($extractedRoot . DIRECTORY_SEPARATOR . $item, $ROOT . DIRECTORY_SEPARATOR . $item);
    }
    rrmdir($tmpDir);
    $log("Unpacked application files", 'ok');

    // ---- 3. Runtime directories + permissions ------------------------------
    foreach (['data', 'thumbnails'] as $d) {
        $path = $ROOT . '/' . $d;
        if (!is_dir($path)) @mkdir($path, 0775, true);
        @chmod($path, 0775);
    }
    // Keep the database out of the web root's reach on Apache hosts.
    @file_put_contents($ROOT . '/data/.htaccess', "Require all denied\n");
    $log("Created data/ and thumbnails/", 'ok');

    // ---- 4. Build the database from the bundled database.sql ---------------
    $sqlFile = $ROOT . '/database.sql';
    $dbFile  = $ROOT . '/data/database.sqlite';
    if (!file_exists($sqlFile)) {
        echo '<p class="bad">database.sql was not found in the downloaded files.</p>';
        page_foot(); exit;
    }
    try {
        if (file_exists($dbFile)) @unlink($dbFile);   // fresh (force path)
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // pdo_sqlite runs multiple statements in one exec() via sqlite3_exec.
        $pdo->exec(file_get_contents($sqlFile));
    } catch (Throwable $ex) {
        echo '<p class="bad">Database build failed: ' . e($ex->getMessage()) . '</p>';
        page_foot(); exit;
    }
    @chmod($dbFile, 0664);
    $log("Built the database", 'ok');

    // ---- 5. First admin account + venue name -------------------------------
    try {
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, display_name, is_admin) VALUES (?,?,?,1)');
        $stmt->execute([$adminEmail, password_hash($adminPass, PASSWORD_DEFAULT), $adminName]);
        if ($venueName !== '') {
            $pdo->prepare('UPDATE options SET value=? WHERE key=?')->execute([$venueName, 'venue_name']);
        }
        // Store whichever optional email/BGG settings were provided; blanks keep
        // the seeded defaults (everything remains editable in admin Options).
        $optStmt = $pdo->prepare('UPDATE options SET value=? WHERE key=?');
        foreach ($optSeed as $optKey => $optVal) {
            if ($optVal !== '') {
                $optStmt->execute([$optVal, $optKey]);
            }
        }
    } catch (Throwable $ex) {
        echo '<p class="bad">Could not create the admin account: ' . e($ex->getMessage()) . '</p>';
        page_foot(); exit;
    }
    $log("Created admin account", 'ok');

    // ---- 6. Write config.php (NOT tracked; updater will skip it) -----------
    $secret = bin2hex(random_bytes(32));
    $config = "<?php\n"
        . "/* Auto-generated by install.php. Do NOT commit this file; the\n"
        . "   updater deliberately skips it so your settings survive updates. */\n"
        . "define('DB_PATH', __DIR__ . '/data/database.sqlite');\n"
        . "define('GITHUB_USER', " . var_export($ghUser, true) . ");\n"
        . "define('GITHUB_REPO', " . var_export($ghRepo, true) . ");\n"
        . "define('GITHUB_BRANCH', " . var_export($ghBranch, true) . ");\n"
        . "define('APP_SECRET', " . var_export($secret, true) . ");\n";
    if (@file_put_contents($ROOT . '/config.php', $config) === false) {
        echo '<p class="bad">Could not write config.php.</p>';
        page_foot(); exit;
    }
    $log("Wrote config.php", 'ok');

    // ---- 7. Self-destruct ---------------------------------------------------
    $selfGone = @unlink(__FILE__);
    echo '<div class="box"><strong class="ok">Installation complete.</strong></div>';
    if (!$selfGone) {
        echo '<p class="bad">I could not delete myself. Please delete <code>install.php</code> manually now — it is a security risk if left in place.</p>';
    } else {
        echo '<p>The installer has removed itself.</p>';
    }
    echo '<p><a href="index.php">Open the app &rarr;</a></p>';
    page_foot();
    exit;
}

/* =============================================================================
 *  STEP: START — requirements + the install form
 * ============================================================================= */
page_head('Install zapisynagry');
echo '<h1>Install zapisynagry</h1>';

$checks = check_requirements($ROOT);
echo '<div class="box"><strong>Server check</strong>';
foreach ($checks as $c) {
    echo '<div class="row"><span>' . e($c[0]) . '</span>'
       . '<span class="' . ($c[1] ? 'ok' : 'bad') . '">'
       . ($c[1] ? 'OK' : 'FAIL') . ' <span class="muted">' . e($c[2]) . '</span></span></div>';
}
echo '</div>';

if (!all_passed($checks)) {
    echo '<p class="bad">Fix the failing items above, then reload this page.</p>';
    page_foot(); exit;
}

// The form. POSTs back to step=install.
echo '<form method="post" action="install.php">';
echo '<input type="hidden" name="step" value="install">';
if (!empty($_REQUEST['force'])) echo '<input type="hidden" name="force" value="1">';

echo '<h2 style="font-size:1.1rem">GitHub source</h2>';
echo '<label>User</label><input name="gh_user" value="' . e(DEFAULT_GITHUB_USER) . '">';
echo '<label>Repository</label><input name="gh_repo" value="' . e(DEFAULT_GITHUB_REPO) . '">';
echo '<label>Branch</label><input name="gh_branch" value="' . e(DEFAULT_GITHUB_BRANCH) . '">';
echo '<p class="muted">Newer repos use <code>main</code>; if the download fails, try <code>master</code>.</p>';

echo '<h2 style="font-size:1.1rem">First admin account</h2>';
echo '<label>Email (used to log in)</label><input type="email" name="admin_email" required>';
echo '<label>Display name</label><input name="admin_name" required>';
echo '<label>Password</label><input type="password" name="admin_pass" required>';
echo '<label>Repeat password</label><input type="password" name="admin_pass2" required>';

echo '<h2 style="font-size:1.1rem">Basics</h2>';
echo '<label>Venue name (optional)</label><input name="venue_name">';

// All optional: outgoing email + BGG. The app installs fine without them, but
// email features (notifications, recovery, verification codes, messaging) and
// the BGG bearer code won't work until set — here or later in admin Options.
echo '<h2 style="font-size:1.1rem">Email &amp; integrations (optional)</h2>';
echo '<label>SMTP server</label><input name="email_smtp_server" placeholder="smtp.example.com">';
echo '<label>SMTP port</label><input name="email_smtp_port" inputmode="numeric" placeholder="587 (STARTTLS) / 465 (TLS)">';
echo '<label>Email address (From)</label><input type="email" name="email_address">';
echo '<label>Email login (SMTP user)</label><input name="email_login">';
echo '<label>Email password</label><input type="password" name="email_password">';
echo '<label>BGG API code</label><input name="bgg_api_code">';
echo '<p class="muted">You can skip these and fill them in later under Admin &rarr; Options.</p>';

echo '<button type="submit">Install now</button>';
echo '</form>';
page_foot();
