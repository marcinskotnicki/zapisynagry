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
require __DIR__ . '/inc/mailing.php';   // the signup box under the timeline
require __DIR__ . '/inc/captcha.php';   // ...which renders a captcha when one is required
require __DIR__ . '/inc/polls.php';    // poll_resolve_expired() below (events.php only
                                       // loads it lazily, inside event_tables_full)
require __DIR__ . '/inc/verify.php';   // verify_can_show_buttons() used inside the game cards
require __DIR__ . '/inc/notify.php';   // a deadline resolution sends the conclusion email

// POOR MAN'S CRON: shared hosting has no scheduler, so expired poll deadlines
// are settled here, on ordinary visits to the front page (cheap no-op when
// nothing is due). See poll_resolve_expired() in inc/polls.php.
poll_resolve_expired();

// Same poor-man's-cron slot: retire events whose last day is far enough past,
// when the admin has asked for that. A cheap no-op unless both public archives
// and a non-zero auto_archive_days are set. Runs BEFORE the event is resolved
// so a just-retired event renders read-only on this very request rather than
// staying editable until the next one.
events_auto_archive();

// Same slot again: discard audit entries past their retention period. Throttled
// to once a day inside the function, and a no-op when retention is set to 0.
logs_prune();

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

/* Active day from ?day= (1-based), clamped to range (bad values -> day 1).
 *
 * With NO ?day= at all, an event may nominate a day to open on — useful once a
 * multi-day event is under way and the interesting day is no longer the first.
 * An explicit ?day= always wins, so a tab someone clicked, a bookmark and a
 * shared link all still land exactly where they say.
 *
 * highlight_day is a day_index rather than an event_days.id, so it survives days
 * being rebuilt when an event's length changes. */
$explicitDay = isset($_GET['day']);
$activeDay   = (int)($_GET['day'] ?? 0);
if (!$explicitDay) $activeDay = (int)($event['highlight_day'] ?? 0);
// One clamp for both routes. A stale highlight_day — left pointing past the end
// by shortening an event — lands here exactly like a bad ?day= and falls back
// to day 1, so the stored value needs no separate range check of its own.
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
            // Optional table name: only honoured when the option-gated
            // permission allows the CURRENT visitor to set one ('' -> NULL).
            $tname = table_names_can_set() ? trim($_POST['table_name'] ?? '') : '';
            // Junk ("'", "---") or oversize labels are dropped: the table is
            // still created, just without a name.
            if ($tname !== '' && (!text_has_content($tname) || text_too_long($tname, TEXT_NAME_MAX))) $tname = '';
            db_run('INSERT INTO game_tables (event_id, day_id, table_number, table_name) VALUES (?,?,?,?)',
                   [$event['id'], $dayRow['id'], $num, $tname !== '' ? $tname : null]);
            log_action('table_add', 'Table #' . $num . ' (day ' . $activeDay . ')');
        }
    }
    redirect('index.php?day=' . $activeDay);              // PRG: avoid resubmit on refresh
}

/* ---- Rename a table (POST, interactive view only) ------------------------ */
// The tiny edit button on a table block leads to an inline form (see the
/* ---- Delete an EMPTY table (POST, admins only) ---------------------------
 *
 * Admin-only and empty-only, both re-checked here rather than trusted from the
 * fact that a button was drawn. Emptiness is counted from the database, so a
 * table holding a soft-deleted game is not empty — deleting cascades, and that
 * history would go with it.
 */
if (!$readonly && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_table') {
    csrf_check();
    if (is_admin()) {
        $delId  = (int)($_POST['table_id'] ?? 0);
        // Must belong to THIS event's active day — blocks stale or foreign ids.
        $delRow = ($delId && $dayRow)
            ? db_one('SELECT * FROM game_tables WHERE id = ? AND day_id = ?', [$delId, $dayRow['id']])
            : null;
        if ($delRow && table_is_empty($delId)) {
            db_run('DELETE FROM game_tables WHERE id = ?', [$delId]);
            log_action('table_delete', 'Table #' . $delRow['table_number']);
        }
    }
    redirect('index.php?day=' . $activeDay);   // PRG: a refresh must not repeat it
}

// ?rename_table= handling below); this is where that form lands. An empty
// name CLEARS the label (back to just "Table #N").
if (!$readonly && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename_table') {
    csrf_check();
    if (table_names_can_edit()) {
        $tblId  = (int)($_POST['table_id'] ?? 0);
        // Table must belong to THIS event's active day (blocks stale/foreign ids).
        $tblRow = ($tblId && $dayRow)
            ? db_one('SELECT * FROM game_tables WHERE id = ? AND day_id = ?', [$tblId, $dayRow['id']])
            : null;
        if ($tblRow) {
            $tname = trim($_POST['table_name'] ?? '');
            // Same rule as adding: junk/oversize is treated as "no name".
            if ($tname !== '' && (!text_has_content($tname) || text_too_long($tname, TEXT_NAME_MAX))) $tname = '';
            db_run('UPDATE game_tables SET table_name = ? WHERE id = ?',
                   [$tname !== '' ? $tname : null, $tblId]);
            log_action('table_rename', 'Table #' . $tblRow['table_number'] . ' -> ' . ($tname !== '' ? $tname : '(none)'));
        }
    }
    // PRG back to the table's anchor (#table-<id>) so a successful save doesn't
    // scroll the page to the top. $tblId is safe here: it's an int, and if it
    // was a bad/foreign id the fragment simply matches nothing (harmless).
    redirect('index.php?day=' . $activeDay . '#table-' . (int)($_POST['table_id'] ?? 0));
}

// Build the day's tables (with nested games + players, polls, comments).
$tables = $dayRow ? event_tables_full($dayRow['id']) : [];

// Table-cap state for the view (controls whether the add-table button shows).
$max        = opt_int('max_tables');
$maxReached = ($max > 0 && $dayRow && event_table_count($dayRow['id']) >= $max);
$canAdd     = !$readonly && can_add_games();

// Table-name state for the view: whether the add form shows a name input,
// whether edit buttons render, and which table (if any) is showing its inline
// rename form right now (?rename_table=<id>, GET only — harmless if forged).
$canSetNames  = !$readonly && table_names_can_set();
$canEditNames = !$readonly && table_names_can_edit();
$renameTable  = $canEditNames ? (int)($_GET['rename_table'] ?? 0) : 0;

// Timeline for the active day (null when there are no games to draw). It's
// captured to a string and handed to the FOOTER's $after_content slot, so it
// renders outside the width-capped .content column at full page width.
$timeline     = $dayRow ? timeline_build($dayRow, $tables, opt_int('timeline_extension')) : null;
$timelineHtml = $timeline ? tpl_capture('timeline', ['timeline' => $timeline]) : '';

// Mailing-list signup box. Captured SEPARATELY from the timeline rather than
// appended to it: home_layout can place the two in either order, and while they
// shared one string the box was stuck to the timeline and travelled with it.
// Hidden on an archived event — no point collecting addresses for something
// that already happened.
$mailingHtml = '';
if (!$readonly && mailing_enabled()) {
    $mailingHtml = tpl_capture('mailing_form', [
        'active_day' => $activeDay,
        'gdpr'       => mailing_gdpr_text(),
        'flash'      => flash_get(),
        'flash_kind' => flash_kind(),   // read after flash_get(), which clears the text
        'csrf'       => csrf_field(),
    ]);
}

tpl_render('header', ['page_title' => $event['name']]);
// Event switcher tabs: every ACTIVE event (not archived, not deleted), soonest
// first — the same set and the same order as current_event() chooses from, so
// the event the page landed on always has a tab. Only assembled when the
// feature is on, so the usual single-event install pays nothing for it.
$eventTabs = public_archives_enabled() ? events_active() : [];

tpl_render('front_event', [
    'event_tabs'  => $eventTabs,
    'event'       => $event,
    'readonly'    => $readonly,
    'days'        => $days,
    'num_days'    => $numDays,
    'active_day'  => $activeDay,
    'day_row'     => $dayRow,
    'tables'      => $tables,
    'can_add'     => $canAdd,
    'max_reached' => $maxReached,
    'can_set_names'  => $canSetNames,    // show the optional name input on add-table
    'can_edit_names' => $canEditNames,   // show the tiny per-table rename button
    // Admins may remove a table that has nothing on it at all. Decided here so
    // the template does not run a query per table while rendering.
    'can_del_tables' => !$readonly && is_admin(),
    'rename_table'   => $renameTable,    // table id whose inline rename form is open (0 = none)
    'csrf'        => csrf_field(),
]);
tpl_render('footer', [
    'after_content' => $timelineHtml,
    'mailing_block' => $mailingHtml,
]);
