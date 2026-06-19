<?php
/* =============================================================================
 *  inc/admin/thumbnails.php — Thumbnails tab controller.
 * -----------------------------------------------------------------------------
 *  Manages the predefined fallback images offered when a game has no BGG image.
 *  Uploads are converted to JPG and downscaled so the longest edge is <= 600px,
 *  then saved under /thumbnails and recorded in predefined_thumbnails.
 *
 *  Runs in admin.php's scope: may set $flash, must set $tab_body; uses $APP_ROOT;
 *  csrf already checked by admin.php.
 * ============================================================================= */

$THUMB_DIR = $APP_ROOT . '/thumbnails';   // where processed images live (web-served)
$MAX_EDGE  = 600;                         // longest-edge cap, in px

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
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
