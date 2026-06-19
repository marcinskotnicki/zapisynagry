<?php
/* =============================================================================
 *  index.php — the event front page.
 * -----------------------------------------------------------------------------
 *  Shows the current event (or an archived event read-only via ?e=token):
 *  header, day tabs, the active day's tables and their games, and the
 *  add-table / add-game controls (subject to permissions and the table cap).
 *
 *  This is a CONTROLLER: it gathers data with the inc/events.php helpers and
 *  hands it to templates. The only write it performs itself is "add a table".
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/verify.php';   // verify_can_show_buttons() used inside the game cards

// Decide which event to show: live (interactive) or an archived one (read-only).
$resolved = event_resolve();
$event    = $resolved['event'];
$readonly = $resolved['readonly'];

// No event at all yet -> the simple placeholder (prompt admin to create one).
if (!$event) {
    tpl_render('header', ['page_title' => t('app_name')]);
    tpl_render('front_placeholder', ['event' => null]);
    tpl_render('footer');
    exit;
}

$days     = event_days($event['id']);
$numDays  = max(1, (int)$event['num_days']);

// Active day from ?day= (1-based), clamped to range (bad values -> day 1).
$activeDay = (int)($_GET['day'] ?? 1);
if ($activeDay < 1 || $activeDay > $numDays) $activeDay = 1;
$dayRow = event_day($event['id'], $activeDay);

/* ---- Add a table (POST, interactive view only) --------------------------- */
if (!$readonly && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_table') {
    csrf_check();
    if (can_add_games() && $dayRow) {
        $max   = opt_int('max_tables');                  // 0 = unlimited
        $count = event_table_count($dayRow['id']);
        if ($max === 0 || $count < $max) {               // respect the cap
            $num = event_next_table_number($dayRow['id']);
            db_run('INSERT INTO game_tables (event_id, day_id, table_number) VALUES (?,?,?)',
                   [$event['id'], $dayRow['id'], $num]);
            log_action('table_add', 'Table #' . $num . ' (day ' . $activeDay . ')');
        }
    }
    redirect('index.php?day=' . $activeDay);              // PRG: avoid resubmit on refresh
}

// Build the day's tables (with nested games + players, polls, comments).
$tables = $dayRow ? event_tables_full($dayRow['id']) : [];

// Table-cap state for the view (controls whether the add-table button shows).
$max        = opt_int('max_tables');
$maxReached = ($max > 0 && $dayRow && event_table_count($dayRow['id']) >= $max);
$canAdd     = !$readonly && can_add_games();

// Timeline for the active day (null when there are no games to draw).
$timeline = $dayRow ? timeline_build($dayRow, $tables, opt_int('timeline_extension')) : null;

tpl_render('header', ['page_title' => $event['name']]);
tpl_render('front_event', [
    'event'       => $event,
    'readonly'    => $readonly,
    'days'        => $days,
    'num_days'    => $numDays,
    'active_day'  => $activeDay,
    'day_row'     => $dayRow,
    'tables'      => $tables,
    'can_add'     => $canAdd,
    'max_reached' => $maxReached,
    'timeline'    => $timeline,
    'csrf'        => csrf_field(),
]);
tpl_render('footer');
