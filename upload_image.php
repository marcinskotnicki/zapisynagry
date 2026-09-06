<?php
/* =============================================================================
 *  upload_image.php — receives an image dropped into the page editor.
 * -----------------------------------------------------------------------------
 *  The editor (Trix, on the Pages tab) shows a picture the moment you attach
 *  one, but that preview is a blob that lives only in the browser: unless
 *  something uploads the file and hands the attachment a real URL, it vanishes
 *  the moment the page is saved. That was the reported bug — the button looked
 *  like it worked and the image was gone afterwards. This is the missing half.
 *
 *  ADMINS ONLY. Pages are written by admins, so nothing else has any business
 *  putting files on the server; an upload endpoint open to visitors is a
 *  different and much larger thing to secure.
 *
 *  WHAT ARRIVES IS NEVER WHAT IS STORED. The file is decoded with GD and
 *  re-encoded as a JPG under a random name (thumb_process), which means:
 *    - the CONTENT decides the type, not the extension or the browser's claim;
 *    - anything GD cannot read as an image is rejected, so a PHP file renamed
 *      .jpg never reaches the disk;
 *    - re-encoding drops whatever was in the original's metadata;
 *    - the attacker-chosen filename is discarded entirely.
 *  That is the same treatment the club's thumbnails already get.
 *
 *  Answers JSON because an editor asks by XHR, not by submitting a form.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/images.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Answer and stop. One exit path, so no route can accidentally return HTML to
 * something waiting for JSON.
 */
function upload_fail($code, $why) {
    http_response_code($code);
    echo json_encode(['error' => $why]);
    exit;
}

if (!is_admin())                              upload_fail(403, 'forbidden');
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    upload_fail(405, 'post only');
// Same token the surrounding form carries; the editor sends it in the FormData.
if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) upload_fail(400, 'csrf');

$file = $_FILES['file'] ?? null;
if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) upload_fail(400, 'no file');
if ((int)($file['error'] ?? 1) !== UPLOAD_ERR_OK)         upload_fail(400, 'upload failed');

/* A size cap before decoding, not after: GD allocates roughly width x height x 4
 * bytes to open an image, so a deliberately enormous one is a way to exhaust
 * memory rather than to store anything. 8 MB is far more than a page
 * illustration needs. */
if ((int)($file['size'] ?? 0) > 8 * 1024 * 1024) upload_fail(413, 'too large');

/* 1200px on the longest edge: wide enough to fill a column on a big screen,
 * small enough that a club's page does not cost a phone user a megabyte an
 * image. Stored in /uploads, separate from /thumbnails so the game-picker's
 * pictures and page illustrations never get muddled — different things, chosen
 * in different places, deleted on different schedules. */
$rel = thumb_process($file['tmp_name'], __DIR__ . '/uploads', 1200, 'uploads/', 'u_');
if ($rel === null) upload_fail(415, 'not an image');

log_action('page_image_upload', $rel);

/* An ABSOLUTE url, because the editor stores whatever it is given straight into
 * the page's HTML, and that HTML is then shown at addresses of differing depth.
 * A relative "uploads/x.jpg" would resolve differently on /page.php?p=2 than on
 * a rewritten /about, and one of the two would be broken.
 *
 * site_base_url() prefers the configured site address and falls back to this
 * request, so it works whether or not an admin has filled that in. If it can
 * determine nothing at all, the relative path is still better than an empty
 * one — it works on every page that lives at the site root. */
$base = site_base_url();
echo json_encode(['url' => $base !== '' ? $base . $rel : $rel]);
