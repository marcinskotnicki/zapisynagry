<?php
/* =============================================================================
 *  inc/admin/archive.php — Archive tab controller.
 * -----------------------------------------------------------------------------
 *  Lists every event with a shareable link. Archived events are viewable by
 *  anyone holding the link (index.php?e=<access_token>) — index.php renders that
 *  read-only view. This tab is just the directory of those links.
 *
 *  Using the per-event access_token (not the numeric id) means the link is
 *  unguessable and can be shared publicly without exposing the id space.
 *
 *  Runs in admin.php's scope: sets $tab_body.
 * ============================================================================= */

$events = db_all('SELECT id, name, num_days, is_archived, access_token, created_at, archived_at
                  FROM events ORDER BY id DESC');

// Build an absolute base URL so the links are copy-pasteable as-is. We derive
// scheme/host/dir from the current request rather than hard-coding a domain.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');   // the folder admin.php sits in
$base   = $scheme . '://' . $host . $dir . '/index.php?e=';      // template appends the token

$tab_body = tpl_capture('admin_archive', [
    'events' => $events,
    'base'   => $base,
]);
