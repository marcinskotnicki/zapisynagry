<?php
/* =============================================================================
 *  inc/admin/new_event.php — New event tab controller.
 * -----------------------------------------------------------------------------
 *  Two-screen flow:
 *    Screen 1 ('start')  : event name + number of days.
 *    Screen 2 ('days')   : per-day date / start / end, prefilled from defaults.
 *  Submitting screen 2 ('create') archives the current event and creates the
 *  new one with its days.
 *
 *  Screen 1 also carries a small RENAME form for the currently live event
 *  (stage 'rename'): just an UPDATE of events.name, no archiving involved.
 *
 *  Stage is carried in a hidden 'stage' field so we know which screen posted.
 *  Creating a new event ARCHIVES the previous one (there's at most one live
 *  event at a time) — that's why archive links keep working afterwards.
 *
 *  Runs in admin.php's scope: may set $flash, must set $tab_body; csrf already
 *  checked by admin.php.
 * ============================================================================= */

const MAX_EVENT_DAYS = 60;   // sanity cap on the day count

$stage = 'start';
$error = null;

// Defaults / carried values for the template (screen 1 initial render).
$name     = opt('default_event_name');
$num_days = 1;
$days     = [];   // list of ['date'=>, 'start'=>, 'end'=>] for screen 2 prefill

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStage = $_POST['stage'] ?? '';

    if ($postStage === 'rename') {
        // Rename the LIVE event in place (no archiving, days untouched).
        $newName = trim($_POST['current_name'] ?? '');
        if ($newName !== '') {
            db_run('UPDATE events SET name = ? WHERE is_archived = 0', [$newName]);
            log_action('event_rename', $newName);
            $flash = t('newevent_renamed');
        }
        // Fall through to render screen 1 (with the fresh name shown).

    } else {
    // Name falls back to the configured default; day count is clamped 1..MAX.
    $name      = trim($_POST['name'] ?? '') ?: opt('default_event_name');
    $num_days  = max(1, min(MAX_EVENT_DAYS, (int)($_POST['num_days'] ?? 1)));

    if ($postStage === 'start') {
        // Screen 1 submitted -> advance to screen 2 with one prefilled row per day.
        $stage = 'days';
        for ($i = 1; $i <= $num_days; $i++) {
            $days[] = [
                'date'  => '',
                'start' => opt('default_start_time'),
                'end'   => opt('default_end_time'),
            ];
        }

    } elseif ($postStage === 'days') {
        // Screen 2 submitted -> validate the day rows, then (if OK) create.
        // The three parallel arrays are indexed by day position.
        $inDates  = $_POST['day_date']  ?? [];
        $inStarts = $_POST['day_start'] ?? [];
        $inEnds   = $_POST['day_end']   ?? [];

        $ok = true;
        for ($i = 0; $i < $num_days; $i++) {
            $d = trim($inDates[$i]  ?? '');
            $s = trim($inStarts[$i] ?? '');
            $e = trim($inEnds[$i]   ?? '');
            $days[] = ['date' => $d, 'start' => $s, 'end' => $e];   // keep for re-render on error
            if (!is_valid_date($d) || !is_valid_time($s) || !is_valid_time($e)) {
                $ok = false;
            }
        }

        if (!$ok) {
            // Bounce back to screen 2 with the entered values + an error message.
            $stage = 'days';
            $error = t('newevent_error_dates');
        } else {
            // All valid: archive the current event, create the new one + its days,
            // all in one transaction so a mid-way failure leaves nothing half-made.
            $now = gmdate('Y-m-d H:i:s');
            db()->beginTransaction();
            try {
                // Archive whatever is currently live (at most one row).
                db_run('UPDATE events SET is_archived = 1, archived_at = ? WHERE is_archived = 0', [$now]);

                // Unguessable share token for the read-only archive link later.
                $token = bin2hex(random_bytes(16));
                db_run('INSERT INTO events (name, num_days, access_token) VALUES (?,?,?)',
                       [$name, $num_days, $token]);
                $eventId = (int)db()->lastInsertId();

                // Insert the days (1-based day_index).
                $stmt = db()->prepare(
                    'INSERT INTO event_days (event_id, day_index, day_date, start_time, end_time)
                     VALUES (?,?,?,?,?)'
                );
                foreach ($days as $idx => $row) {
                    $stmt->execute([$eventId, $idx + 1, $row['date'], $row['start'], $row['end']]);
                }
                db()->commit();
            } catch (Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $stage = 'days';
                $error = t('update_failed', $ex->getMessage());
            }

            if ($error === null) {
                log_action('event_create', $name);
                redirect('index.php');   // success -> show the freshly created event
            }
        }
    }
    }   // end: stages other than 'rename'
}

// The currently live event (if any) — screen 1 shows a rename form for it.
$current = db_one('SELECT * FROM events WHERE is_archived = 0');

$tab_body = tpl_capture('admin_new_event', [
    'csrf'     => csrf_field(),
    'stage'    => $stage,        // 'start' or 'days' — which screen to render
    'name'     => $name,
    'num_days' => $num_days,
    'days'     => $days,         // prefill rows for screen 2
    'error'    => $error,
    'current'  => $current,      // live event row, or null (drives the rename form)
]);
