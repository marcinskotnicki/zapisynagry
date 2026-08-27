<?php
/* =============================================================================
 *  inc/images.php — shared image processing.
 * -----------------------------------------------------------------------------
 *  thumb_process() lived in inc/admin/thumbnails.php, which is a TAB CONTROLLER:
 *  requiring it from anywhere else would have run that whole tab. It is now
 *  needed in two places — the Thumbnails tab and the event forms, which accept
 *  an event picture — so it lives here and both require this file.
 *
 *  Deliberately not merged with logo_process()/icon_process(), which stay in the
 *  thumbnails tab: those are single-purpose (one logo, one favicon set) and have
 *  no second caller. See their own comments for why each keeps its own canvas
 *  handling.
 * ============================================================================= */

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
