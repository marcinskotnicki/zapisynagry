<?php
/* =============================================================================
 *  inc/admin/thumbnails.php — Thumbnails tab controller.
 * -----------------------------------------------------------------------------
 *  Manages the predefined fallback images offered when a game has no BGG image.
 *  Uploads are converted to JPG and downscaled so the longest edge is <= 600px,
 *  then saved under /thumbnails and recorded in predefined_thumbnails.
 *
 *  Also home of the SITE ICON (favicon + home-screen icons): one uploaded image
 *  is centre-cropped square and rendered into the standard PNG sizes under
 *  /icons, plus a site.webmanifest for Android "add to home screen". The
 *  'site_icon' option stores a version stamp ('' = no icon); header.php emits
 *  the <link> tags (with ?v= cache busting) only when it's non-empty.
 *
 *  Runs in admin.php's scope: may set $flash, must set $tab_body; uses $APP_ROOT;
 *  csrf already checked by admin.php.
 * ============================================================================= */

$THUMB_DIR = $APP_ROOT . '/thumbnails';   // where processed images live (web-served)
$MAX_EDGE  = 600;                         // longest-edge cap, in px
$ICON_DIR  = $APP_ROOT . '/icons';        // favicon + home-screen icons (web-served)

/**
 * Convert + resize one uploaded image into a JPG under $destDir.
 * Returns the stored relative path (e.g. "thumbnails/t_ab12.jpg") or null on
 * failure. Transparency is flattened onto white since JPG has no alpha channel.
 *
 * @param string $tmpPath  The uploaded temp file.
 * @param string $destDir  Absolute /thumbnails dir.
 * @param int    $maxEdge  Longest-edge cap (never upscales smaller images).
 * @return string|null     Relative path on success, null on any failure.
 */
function thumb_process($tmpPath, $destDir, $maxEdge) {
    $info = @getimagesize($tmpPath);             // also tells us the real type
    if (!$info) return null;                     // not an image we can read
    [$w, $h] = $info;

    // Decode according to the detected type (don't trust the file extension).
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmpPath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmpPath);  break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($tmpPath);  break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false; break;
        default: return null;                    // unsupported type
    }
    if (!$src) return null;

    // Scale so the longest edge fits $maxEdge; min(1.0, ...) means never upscale.
    $scale = min(1.0, $maxEdge / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    // New canvas filled white (flattens any transparency for JPG output).
    $dst   = imagecreatetruecolor($nw, $nh);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
    $name = 't_' . bin2hex(random_bytes(6)) . '.jpg';   // random, collision-proof name
    $okSave = imagejpeg($dst, $destDir . '/' . $name, 85);   // quality 85

    imagedestroy($src);                          // free GD memory
    imagedestroy($dst);
    return $okSave ? 'thumbnails/' . $name : null;
}

/**
 * The site-icon output files: filename => square size in px. These are the
 * sizes browsers/devices actually ask for (tab favicon, iOS home screen,
 * Android home screen / splash). Fixed names so header.php can link them
 * without a lookup; cache busting is done with the ?v= version stamp instead.
 * @return array<string,int>
 */
function icon_sizes() {
    return [
        'favicon-16.png'         => 16,    // browser tab (small)
        'favicon-32.png'         => 32,    // browser tab (standard)
        'apple-touch-icon.png'   => 180,   // iOS "add to home screen"
        'icon-192.png'           => 192,   // Android home screen (manifest)
        'icon-512.png'           => 512,   // Android splash (manifest)
    ];
}

/**
 * Turn ONE uploaded image into the full site-icon set under $destDir.
 * The source is centre-cropped to a square first (so non-square uploads don't
 * come out stretched), then resampled to each size as PNG with transparency
 * preserved. Also writes the site.webmanifest Android needs for home-screen
 * pinning. All-or-nothing: any failure returns false (partial files may remain
 * but are harmless — the option flag is only set by the caller on success).
 *
 * @param string $tmpPath  The uploaded temp file.
 * @param string $destDir  Absolute /icons dir.
 * @return bool            True when every size + the manifest were written.
 */
function icon_process($tmpPath, $destDir) {
    $info = @getimagesize($tmpPath);             // also tells us the real type
    if (!$info) return false;                    // not an image we can read
    [$w, $h] = $info;

    // Decode according to the detected type (don't trust the file extension).
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmpPath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmpPath);  break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($tmpPath);  break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false; break;
        default: return false;                   // unsupported type
    }
    if (!$src) return false;

    // Centre-crop coordinates for the largest square that fits the source.
    $side = min($w, $h);
    $sx   = (int)(($w - $side) / 2);
    $sy   = (int)(($h - $side) / 2);

    if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

    $ok = true;
    foreach (icon_sizes() as $name => $size) {
        // Transparent canvas (PNG keeps alpha, unlike the JPG thumbnails).
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $clear);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);
        if (!imagepng($dst, $destDir . '/' . $name)) $ok = false;
        imagedestroy($dst);
    }
    imagedestroy($src);

    // The manifest Android reads for "add to home screen". The venue name is
    // baked in at generation time (re-upload the icon after renaming the venue
    // if the pinned name matters).
    $manifest = json_encode([
        'name'    => opt('venue_name') ?: 'zapisynagry',
        'icons'   => [
            ['src' => 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ],
        'display' => 'browser',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($destDir . '/site.webmanifest', $manifest) === false) $ok = false;

    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'icon_upload') {
        // Replace the site icon with a freshly processed set. The version stamp
        // in the option changes every upload, busting browser favicon caches.
        $f = $_FILES['icon'] ?? null;
        if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
               && is_uploaded_file($f['tmp_name'])
               && icon_process($f['tmp_name'], $ICON_DIR)) {
            opt_set('site_icon', (string)time());   // non-empty = enabled + version
            log_action('icon_upload', 'Site icon updated');
            $flash = t('icon_saved');
        } else {
            $flash = t('icon_invalid');
        }

    } elseif ($action === 'icon_delete') {
        // Remove the generated files and clear the flag (header stops linking).
        foreach (array_keys(icon_sizes()) as $name) {
            $file = $ICON_DIR . '/' . $name;
            if (is_file($file)) @unlink($file);
        }
        $mf = $ICON_DIR . '/site.webmanifest';
        if (is_file($mf)) @unlink($mf);
        opt_set('site_icon', '');
        log_action('icon_delete', 'Site icon removed');
        $flash = t('icon_deleted');

    } elseif ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = $id ? db_one('SELECT * FROM predefined_thumbnails WHERE id = ?', [$id]) : null;
        if ($row) {
            // Only ever touch a file inside /thumbnails (basename strips any path,
            // guarding against directory traversal in a tampered filename).
            $file = $THUMB_DIR . '/' . basename($row['filename']);
            if (is_file($file)) @unlink($file);
            db_run('DELETE FROM predefined_thumbnails WHERE id = ?', [$id]);
            log_action('thumb_delete', $row['filename']);
            $flash = t('thumb_deleted');
        }

    } elseif (!empty($_FILES['files'])) {
        // The file input is multi (name="files[]"), so PHP gives us parallel
        // arrays keyed by index. Walk them and process each valid upload.
        $f = $_FILES['files'];
        $count = is_array($f['tmp_name']) ? count($f['tmp_name']) : 0;
        $added = 0; $bad = 0;
        for ($i = 0; $i < $count; $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;   // skip empty slots
            if (!is_uploaded_file($f['tmp_name'][$i])) continue;                       // must be a real upload
            $rel = thumb_process($f['tmp_name'][$i], $THUMB_DIR, $MAX_EDGE);
            if ($rel) {
                db_run('INSERT INTO predefined_thumbnails (filename) VALUES (?)', [$rel]);
                $added++;
            } else {
                $bad++;
            }
        }
        if ($added) { log_action('thumb_upload', $added . ' file(s)'); }
        // "invalid" wins the message if any file failed; otherwise plain success.
        $flash = $bad ? t('thumb_invalid') : t('thumb_uploaded');
    }
}

$thumbs = db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC');

$tab_body = tpl_capture('admin_thumbnails', [
    'csrf'   => csrf_field(),
    'thumbs' => $thumbs,
]);
