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
    // simplexml is what inc/bgg.php parses BoardGameGeek's XML responses with.
    // Without it, BGG search doesn't just come back empty — it fatals the
    // first time someone tries it, which is a far worse failure than the other
    // optional-feature extensions here. Checked alongside the rest so it's
    // caught at install time instead of the first live search.
    foreach (['pdo_sqlite', 'curl', 'zip', 'gd', 'mbstring', 'simplexml'] as $ext) {
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
 *  Installer wording.
 * -----------------------------------------------------------------------------
 *  The installer cannot use the app's own language files: those are loaded
 *  through inc/lang.php, which needs a database and a config.php that do not
 *  exist yet. So it carries its own small dictionary — every string on this
 *  page, in both languages.
 *
 *  Whatever is picked here also becomes the site's `default_language` option,
 *  so an admin who installs in Polish gets a Polish site without a second
 *  decision.
 * ============================================================================= */
const INSTALL_LANGS = ['pl' => 'Polski', 'en' => 'English'];

/**
 * The language for this request: an explicit choice, else the browser's
 * preference, else Polish (most clubs using this are Polish-speaking).
 *
 * @return string 'pl'|'en'
 */
function install_lang() {
    static $lang = null;
    if ($lang !== null) return $lang;

    $want = (string)($_REQUEST['lang'] ?? '');
    if (isset(INSTALL_LANGS[$want])) return $lang = $want;

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($accept !== '' && strpos($accept, 'pl') !== 0 && strpos($accept, 'en') === 0) {
        return $lang = 'en';
    }
    return $lang = 'pl';
}

/**
 * Translate one installer string.
 *
 * Falls back to the key itself if a string is somehow missing, so a typo shows
 * up as an odd label rather than a blank page.
 *
 * @param string $key
 * @param mixed  ...$args  sprintf arguments.
 * @return string
 */
function il($key, ...$args) {
    static $dict = null;
    if ($dict === null) $dict = install_strings();
    $lang = install_lang();
    $text = $dict[$lang][$key] ?? ($dict['en'][$key] ?? $key);
    return $args ? vsprintf($text, $args) : $text;
}

/**
 * Every string on this page, both languages.
 * @return array
 */
function install_strings() {
    return [
    'pl' => [
        'title'          => 'Instalacja zapisynagry',
        'lang_label'     => 'Język',
        'server_check'   => 'Sprawdzenie serwera',
        'fix_above'      => 'Napraw powyższe punkty i odśwież tę stronę.',
        'admin_section'  => 'Konto pierwszego administratora',
        'admin_email'    => 'E-mail (służy do logowania)',
        'admin_name'     => 'Nazwa wyświetlana',
        'admin_pass'     => 'Hasło',
        'admin_pass2'    => 'Powtórz hasło',
        'basics'         => 'Podstawy',
        'venue'          => 'Nazwa klubu / lokalu (opcjonalnie)',
        'email_section'  => 'Poczta i integracje (opcjonalnie)',
        'smtp_server'    => 'Serwer SMTP',
        'smtp_port'      => 'Port SMTP',
        'email_from'     => 'Adres e-mail (nadawca)',
        'email_login'    => 'Login SMTP',
        'email_pass'     => 'Hasło SMTP',
        'bgg_code'       => 'Kod API BoardGameGeek',
        'source_section' => 'Źródło pobierania',
        'source_warning' => 'Nie zmieniaj tego, jeśli nie wiesz, co robisz.',
        'source_hint'    => 'Nowsze repozytoria używają gałęzi main; jeśli pobieranie się nie powiedzie, spróbuj master.',
        'gh_user'        => 'Użytkownik',
        'gh_repo'        => 'Repozytorium',
        'gh_branch'      => 'Gałąź',
        'install_btn'    => 'Zainstaluj',
        'pass_mismatch'  => 'Hasła nie są takie same.',
        'err_email'      => 'Adres e-mail administratora jest nieprawidłowy.',
        'err_name'       => 'Nazwa wyświetlana administratora jest wymagana.',
        'err_pass_short' => 'Hasło administratora musi mieć co najmniej 6 znaków.',
        'please_fix'     => 'Popraw proszę:',
        'go_back'        => 'Wróć',
        'requirements'   => 'Wymagania nie są spełnione. Wróć i napraw je najpierw.',
        'installing'     => 'Instalowanie',
        'log_ssl_on'     => 'Włączono wymuszanie HTTPS (.htaccess)',
        'log_ssl_failed' => 'Nie udało się zapisać reguły HTTPS do .htaccess — możesz włączyć ją później w Ustawieniach',
        'log_ssl_skipped' => 'Pominięto wymuszanie HTTPS — instalacja przez http. Włącz je w Ustawieniach, gdy certyfikat będzie działać',
        'complete'       => 'Instalacja zakończona.',
        'self_delete_ok' => 'Instalator usunął sam siebie.',
        'self_delete_no' => 'Nie udało się usunąć pliku install.php. Usuń go teraz ręcznie — pozostawiony jest zagrożeniem dla bezpieczeństwa.',
        'open_app'       => 'Otwórz aplikację &rarr;',
        'admin_failed'   => 'Nie udało się utworzyć konta administratora: ',
        'config_failed'  => 'Nie udało się zapisać pliku config.php.',
    ],
    'en' => [
        'title'          => 'Install zapisynagry',
        'lang_label'     => 'Language',
        'server_check'   => 'Server check',
        'fix_above'      => 'Fix the failing items above, then reload this page.',
        'admin_section'  => 'First admin account',
        'admin_email'    => 'Email (used to log in)',
        'admin_name'     => 'Display name',
        'admin_pass'     => 'Password',
        'admin_pass2'    => 'Repeat password',
        'basics'         => 'Basics',
        'venue'          => 'Venue name (optional)',
        'email_section'  => 'Email &amp; integrations (optional)',
        'smtp_server'    => 'SMTP server',
        'smtp_port'      => 'SMTP port',
        'email_from'     => 'Email address (From)',
        'email_login'    => 'SMTP login',
        'email_pass'     => 'SMTP password',
        'bgg_code'       => 'BoardGameGeek API code',
        'source_section' => 'Download source',
        'source_warning' => "Don't change this unless you know what you're doing.",
        'source_hint'    => 'Newer repos use main; if the download fails, try master.',
        'gh_user'        => 'User',
        'gh_repo'        => 'Repository',
        'gh_branch'      => 'Branch',
        'install_btn'    => 'Install',
        'pass_mismatch'  => 'The passwords do not match.',
        'err_email'      => 'Admin email is not valid.',
        'err_name'       => 'Admin display name is required.',
        'err_pass_short' => 'Admin password must be at least 6 characters.',
        'please_fix'     => 'Please fix:',
        'go_back'        => 'Go back',
        'requirements'   => 'Requirements are not met. Go back and fix them first.',
        'installing'     => 'Installing',
        'log_ssl_on'     => 'Enabled forced HTTPS (.htaccess)',
        'log_ssl_failed' => 'Could not write the HTTPS rule to .htaccess — you can switch it on later in Settings',
        'log_ssl_skipped' => 'Skipped forced HTTPS — installing over http. Switch it on in Settings once the certificate works',
        'complete'       => 'Installation complete.',
        'self_delete_ok' => 'The installer has removed itself.',
        'self_delete_no' => 'I could not delete myself. Please delete install.php manually now — it is a security risk if left in place.',
        'open_app'       => 'Open the app &rarr;',
        'admin_failed'   => 'Could not create the admin account: ',
        'config_failed'  => 'Could not write config.php.',
    ],
    ];
}

/* =============================================================================
 *  Inline page chrome (necessary: templates don't exist yet).
 * ============================================================================= */
function page_head($title) {
    echo '<!doctype html><html lang="' . e(install_lang()) . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title><style>';
    echo 'body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;color:#222;line-height:1.5}';
    echo 'h1{font-size:1.4rem}label{display:block;margin:.8rem 0 .2rem;font-weight:600}';
    echo 'input{width:100%;padding:.5rem;border:1px solid #bbb;border-radius:6px;box-sizing:border-box}';
    echo 'button{margin-top:1.2rem;padding:.6rem 1.2rem;border:0;border-radius:6px;background:#2d6cdf;color:#fff;font-size:1rem;cursor:pointer}';
    echo '.ok{color:#157f3b}.bad{color:#c0392b}.row{display:flex;justify-content:space-between;border-bottom:1px solid #eee;padding:.35rem 0}';
    echo '.muted{color:#777;font-size:.9rem}.box{background:#f7f7f9;border:1px solid #e3e3e8;border-radius:8px;padding:1rem;margin:1rem 0}';
    echo 'code{background:#eee;padding:.1rem .3rem;border-radius:4px}';
    echo 'hr{border:0;border-top:1px solid #e3e3e8;margin:2rem 0}';
    echo '.langbar{display:flex;justify-content:flex-end;align-items:center;gap:.5rem;font-size:.9rem}';
    echo '.langbar select{width:auto;padding:.3rem .4rem}';
    echo 'details.advanced{margin-top:1rem}summary{cursor:pointer;font-weight:600}';
    echo '.warn{color:#c0392b;font-size:.9rem;margin:.4rem 0}';
    echo '</style></head><body>';
    install_lang_picker();
}

/**
 * Language chooser. A GET reload rather than anything clever, so the page
 * survives it — nothing has been submitted yet at this point.
 *
 * The <noscript> fallback matters: this is the one page where a person cannot
 * fix a broken site by changing a setting, so the choice must work without JS.
 *
 * @return void
 */
function install_lang_picker() {
    echo '<form class="langbar" method="get" action="install.php">';
    echo '<label for="lang" style="margin:0">' . e(il('lang_label')) . '</label>';
    echo '<select id="lang" name="lang" onchange="this.form.submit()">';
    foreach (INSTALL_LANGS as $code => $label) {
        echo '<option value="' . e($code) . '"' . ($code === install_lang() ? ' selected' : '') . '>'
           . e($label) . '</option>';
    }
    echo '</select><noscript><button type="submit" style="margin:0;padding:.3rem .6rem">OK</button></noscript>';
    echo '</form>';
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
    echo '<h1>' . e(il('installing')) . '</h1>';

    // ---- 0. Re-check requirements server-side (never trust the form). -------
    $checks = check_requirements($ROOT);
    if (!all_passed($checks)) {
        echo '<p class="bad">' . e(il('requirements')) . '</p>';
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
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = il('err_email');
    if ($adminName === '')                               $errors[] = il('err_name');
    if (strlen($adminPass) < 6)                          $errors[] = il('err_pass_short');
    if ($adminPass !== $adminPass2)                      $errors[] = il('pass_mismatch');
    if ($errors) {
        echo '<div class="box"><strong class="bad">' . e(il('please_fix')) . '</strong><ul>';
        foreach ($errors as $er) echo '<li>' . e($er) . '</li>';
        // The language rides along so going back does not reset the page to
        // the default language mid-install.
        echo '</ul></div><p><a href="install.php?lang=' . e(install_lang()) . '">&larr; '
           . e(il('go_back')) . '</a></p>';
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

    // ---- 4b. Register any thumbnails that shipped with the release ----------
    // The admin panel lists predefined thumbnails from the DATABASE, never by
    // scanning the folder — so an image that arrives with the release (or that
    // someone drops in over FTP) exists on disk and is web-servable, but is
    // invisible in the panel and unusable as a fallback until a row points at
    // it. Registering them here is what makes a shipped default actually work.
    //
    // Idempotent by filename: the table was just created empty, but this same
    // logic must stay safe if it is ever reused on a non-empty install.
    try {
        $thumbDir = $ROOT . '/thumbnails';
        $seen = [];
        foreach ($pdo->query('SELECT filename FROM predefined_thumbnails') as $r) {
            $seen[$r['filename']] = true;
        }
        $ins = $pdo->prepare('INSERT INTO predefined_thumbnails (filename) VALUES (?)');
        $found = 0;
        foreach (is_dir($thumbDir) ? scandir($thumbDir) : [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $abs = $thumbDir . '/' . $item;
            if (!is_file($abs)) continue;
            // Must be a real, readable image — getimagesize() rejects a stray
            // .htaccess, a README, or a truncated file, any of which would
            // otherwise become a broken <img> in the panel and in game cards.
            if (@getimagesize($abs) === false) continue;
            $rel = 'thumbnails/' . $item;   // same shape thumb_process() returns
            if (isset($seen[$rel])) continue;
            $ins->execute([$rel]);
            $seen[$rel] = true;
            $found++;
        }
        if ($found) $log("Registered $found bundled thumbnail(s)", 'ok');
    } catch (Throwable $ex) {
        // Non-fatal on purpose: a usable install without its bundled sample
        // images beats no install at all. The admin can upload them by hand.
        // Message says "skipped" in its own text because $log() only renders a
        // status marker for 'ok' — any other class shows an empty column, which
        // would read as ambiguous rather than as a warning.
        $log('Skipped bundled thumbnails (' . e($ex->getMessage()) . ') — you can upload them from the admin panel');
    }

    // ---- 5. First admin account + venue name -------------------------------
    try {
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, display_name, is_admin) VALUES (?,?,?,1)');
        $stmt->execute([$adminEmail, password_hash($adminPass, PASSWORD_DEFAULT), $adminName]);
        if ($venueName !== '') {
            $pdo->prepare('UPDATE options SET value=? WHERE key=?')->execute([$venueName, 'venue_name']);
        }
        // The language this page was filled in becomes the site's default, so
        // installing in Polish does not then require finding the setting.
        $pdo->prepare('UPDATE options SET value=? WHERE key=?')
            ->execute([install_lang(), 'default_language']);
        // Store whichever optional email/BGG settings were provided; blanks keep
        // the seeded defaults (everything remains editable in admin Options).
        $optStmt = $pdo->prepare('UPDATE options SET value=? WHERE key=?');
        foreach ($optSeed as $optKey => $optVal) {
            if ($optVal !== '') {
                $optStmt->execute([$optVal, $optKey]);
            }
        }
    } catch (Throwable $ex) {
        echo '<p class="bad">' . e(il('admin_failed')) . e($ex->getMessage()) . '</p>';
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
        echo '<p class="bad">' . e(il('config_failed')) . '</p>';
        page_foot(); exit;
    }
    $log("Wrote config.php", 'ok');

    // ---- 6b. Force HTTPS, but only if this very page arrived over it ---------
    // Writing the redirect after a plain-http install would make the site
    // unreachable the moment the installer finished, including the admin panel
    // needed to undo it. If this request is already https, the certificate
    // demonstrably works, so switching it on is safe.
    require_once $ROOT . '/inc/htaccess.php';
    if (request_is_https()) {
        if (htaccess_ssl_set(true)) {
            $log(il('log_ssl_on'), 'ok');
        } else {
            // Not fatal: the site works, it just is not forcing https yet, and
            // the admin can switch it on later from Options -> Security.
            $log(il('log_ssl_failed'), 'bad');
        }
    } else {
        $log(il('log_ssl_skipped'), 'ok');
    }

    // ---- 7. Self-destruct ---------------------------------------------------
    $selfGone = @unlink(__FILE__);
    echo '<div class="box"><strong class="ok">' . e(il('complete')) . '</strong></div>';
    if (!$selfGone) {
        echo '<p class="bad">' . e(il('self_delete_no')) . '</p>';
    } else {
        echo '<p>' . e(il('self_delete_ok')) . '</p>';
    }
    echo '<p><a href="index.php">' . il('open_app') . '</a></p>';
    page_foot();
    exit;
}

/* =============================================================================
 *  STEP: START — requirements + the install form
 * ============================================================================= */
page_head('Install zapisynagry');
echo '<h1>' . e(il('title')) . '</h1>';

$checks = check_requirements($ROOT);
echo '<div class="box"><strong>' . e(il('server_check')) . '</strong>';
foreach ($checks as $c) {
    echo '<div class="row"><span>' . e($c[0]) . '</span>'
       . '<span class="' . ($c[1] ? 'ok' : 'bad') . '">'
       . ($c[1] ? 'OK' : 'FAIL') . ' <span class="muted">' . e($c[2]) . '</span></span></div>';
}
echo '</div>';

if (!all_passed($checks)) {
    echo '<p class="bad">' . e(il('fix_above')) . '</p>';
    page_foot(); exit;
}

// The form. POSTs back to step=install.
echo '<form method="post" action="install.php">';
echo '<input type="hidden" name="step" value="install">';
// Carried through the POST so the installed site's default_language matches
// the language this page was filled in.
echo '<input type="hidden" name="lang" value="' . e(install_lang()) . '">';
if (!empty($_REQUEST['force'])) echo '<input type="hidden" name="force" value="1">';

echo '<h2 style="font-size:1.1rem">' . e(il('admin_section')) . '</h2>';
echo '<label>' . e(il('admin_email')) . '</label><input type="email" name="admin_email" required>';
echo '<label>' . e(il('admin_name')) . '</label><input name="admin_name" required>';
echo '<label>' . e(il('admin_pass')) . '</label><input type="password" name="admin_pass" required>';
echo '<label>' . e(il('admin_pass2')) . '</label><input type="password" name="admin_pass2" required>';

echo '<hr>';
echo '<h2 style="font-size:1.1rem">' . e(il('basics')) . '</h2>';
echo '<label>' . e(il('venue')) . '</label><input name="venue_name">';

echo '<hr>';
// All optional: outgoing email + BGG. The app installs fine without them, but
// email features (notifications, recovery, verification codes, messaging) and
// the BGG bearer code won't work until set — here or later in admin Options.
echo '<h2 style="font-size:1.1rem">' . il('email_section') . '</h2>';
echo '<label>' . e(il('smtp_server')) . '</label><input name="email_smtp_server" placeholder="smtp.example.com">';
echo '<label>' . e(il('smtp_port')) . '</label><input name="email_smtp_port" inputmode="numeric" placeholder="587 (STARTTLS) / 465 (TLS)">';
echo '<label>' . e(il('email_from')) . '</label><input type="email" name="email_address">';
echo '<label>' . e(il('email_login')) . '</label><input name="email_login">';
echo '<label>' . e(il('email_pass')) . '</label><input type="password" name="email_password">';
echo '<label>' . e(il('bgg_code')) . '</label><input name="bgg_api_code">';

echo '<hr>';
// Last, and folded away: almost nobody should touch this, and a wrong value
// here is the one setting that stops the install dead rather than being
// fixable afterwards in Options.
echo '<details class="advanced"><summary>' . e(il('source_section')) . '</summary>';
echo '<p class="warn">' . e(il('source_warning')) . '</p>';
echo '<label>' . e(il('gh_user')) . '</label><input name="gh_user" value="' . e(DEFAULT_GITHUB_USER) . '">';
echo '<label>' . e(il('gh_repo')) . '</label><input name="gh_repo" value="' . e(DEFAULT_GITHUB_REPO) . '">';
echo '<label>' . e(il('gh_branch')) . '</label><input name="gh_branch" value="' . e(DEFAULT_GITHUB_BRANCH) . '">';
echo '<p class="muted">' . e(il('source_hint')) . '</p>';
echo '</details>';

echo '<button type="submit">' . e(il('install_btn')) . '</button>';
echo '</form>';
page_foot();
