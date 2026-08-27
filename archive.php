<?php
/* =============================================================================
 *  archive.php — the public list of events.
 * -----------------------------------------------------------------------------
 *  Only reachable when the 'public_archives' option is on; otherwise it is a
 *  plain 404-ish redirect, so switching the feature off genuinely closes the
 *  page rather than only hiding its button.
 *
 *  Newest first by the event's FIRST day. Paginated so a club with years of
 *  history does not build one enormous page — the query is LIMIT/OFFSET, not a
 *  fetch-everything-then-slice.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';

// This view specifically, not just the archives as a whole: a club may
// offer only the other shape, and typing the URL should not bypass that.
if (!archive_list_enabled()) {
    redirect('index.php');
}

$perPage = max(1, min(200, opt_int('archive_per_page')));
$total   = events_count();
$pages   = max(1, (int)ceil($total / $perPage));
// Clamp rather than 404: a hand-edited or stale ?page= should land on a real
// page instead of an error, and clamping keeps prev/next arithmetic simple.
$page    = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$events  = events_page($perPage, ($page - 1) * $perPage);
// Games/players per event, for THIS page only, in one batched query set.
$evStats = event_stats_enabled() ? events_stats(array_column($events, 'id')) : [];

tpl_render('header', ['page_title' => t('archive_title')]);
tpl_render('archive_list', [
    'events'  => $events,
    'stats'   => $evStats,
    'page'    => $page,
    'pages'   => $pages,
    'total'   => $total,
]);
tpl_render('footer');
