<?php
/* =============================================================================
 *  calendar.php — month view of the event archive.
 * -----------------------------------------------------------------------------
 *  One month at a time, with year/month steppers. Shares the 'public_archives'
 *  gate with archive.php: this is the same section in a different shape, so it
 *  should appear and disappear with it rather than needing its own switch.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';

if (!public_archives_enabled()) {
    redirect('index.php');
}

// Default to the month we are in. Normalising here means the prev/next links
// can be plain month arithmetic without wrapping December by hand.
list($year, $month) = calendar_normalise(
    $_GET['y'] ?? date('Y'),
    $_GET['m'] ?? date('n')
);

$weeks  = calendar_weeks($year, $month);
$byDay  = events_by_day($year, $month);

list($py, $pm) = calendar_normalise($year, $month - 1);
list($ny, $nm) = calendar_normalise($year, $month + 1);

tpl_render('header', ['page_title' => t('calendar_title')]);
tpl_render('calendar_month', [
    'year'   => $year,
    'month'  => $month,
    'weeks'  => $weeks,
    'by_day' => $byDay,
    'prev_month' => ['y' => $py, 'm' => $pm],
    'next_month' => ['y' => $ny, 'm' => $nm],
    'prev_year'  => calendar_normalise($year - 1, $month),
    'next_year'  => calendar_normalise($year + 1, $month),
    'today'  => date('Y-m-d'),
]);
tpl_render('footer');
