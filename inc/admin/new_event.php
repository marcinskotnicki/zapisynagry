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

// thumb_process(), for the optional event picture on screen 1.
require_once __DIR__ . '/../images.php';

$stage = 'start';
$error = null;

// Defaults / carried values for the template (screen 1 initial render).
$name     = opt('default_event_name');
$num_days = 1;
/* OPTIONAL EVENT DETAILS, prefilled from the configured defaults — most clubs
 * meet in the same place every time, so typing it once is enough. Collected on
 * screen 1 and carried hidden through screen 2, like the name and day count. */
$loc_name = opt('default_location_name');
$loc_addr = opt('default_location_address');
$thumb    = '';                  // relative path once an image has been processed
$days     = [];   // list of ['date'=>, 'start'=>, 'end'=>] for screen 2 prefill

// day_names_enabled() / day_tab_formats() live here; this tab does not otherwise
// need event data, so the dependency is stated explicitly.
require_once __DIR__ . '/../events.php';
require_once __DIR__ . '/../mail.php';      // send_mail(), which mailing.php calls
require_once __DIR__ . '/../mailing.php';   // mailing_notify_new_event()

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStage = $_POST['stage'] ?? '';

    // Renaming moved to the Events tab, where it works on ANY event by id. The
    // version that lived here updated `WHERE is_archived = 0`, which renamed
    // every live event at once — harmless when only one could be live, wrong
    // now that public archives allow several.
    {
    // Name falls back to the configured default; day count is clamped 1..MAX.
    $name      = trim($_POST['name'] ?? '') ?: opt('default_event_name');
    $num_days  = max(1, min(MAX_EVENT_DAYS, (int)($_POST['num_days'] ?? 1)));
    /* Carried on both posts. Read even when the feature is off, so that turning
     * it off mid-flow cannot half-create an event — the values simply stay
     * empty because the fields were never rendered. */
    $loc_name  = trim((string)($_POST['location_name'] ?? ''));
    $loc_addr  = trim((string)($_POST['location_address'] ?? ''));
    // Screen 2 hands back the path screen 1 produced.
    $thumb     = trim((string)($_POST['thumbnail'] ?? ''));

    /* THE PICTURE is processed the moment it is uploaded, on screen 1, and only
     * its path travels on. A file input cannot be carried through a second form
     * the way a text field can, so deferring it to the create step would mean
     * losing the upload on every screen-2 validation error. Failure is not
     * fatal: the event is still worth creating without a picture, and the admin
     * can add one from the event's own screen afterwards. */
    if (event_details_enabled()
        && !empty($_FILES['thumb_file']['tmp_name'])
        && is_uploaded_file($_FILES['thumb_file']['tmp_name'])) {
        $made = thumb_process($_FILES['thumb_file']['tmp_name'], $APP_ROOT . '/thumbnails', 600);
        if ($made !== null) $thumb = $made;
    }

    if ($postStage === 'start') {
        // Screen 1 submitted -> advance to screen 2 with one prefilled row per day.
        $stage = 'days';
        for ($i = 1; $i <= $num_days; $i++) {
            $days[] = [
                'date'  => '',
                'start' => opt('default_start_time'),
                'end'   => opt('default_end_time'),
                'name'  => '',
            ];
        }

    } elseif ($postStage === 'days') {
        // Screen 2 submitted -> validate the day rows, then (if OK) create.
        // The three parallel arrays are indexed by day position.
        $inDates  = $_POST['day_date']  ?? [];
        $inStarts = $_POST['day_start'] ?? [];
        $inEnds   = $_POST['day_end']   ?? [];
        // Optional labels, only collected when the feature is on.
        $inNames  = $_POST['day_name']  ?? [];

        $ok = true;
        for ($i = 0; $i < $num_days; $i++) {
            $d = trim($inDates[$i]  ?? '');
            $s = trim($inStarts[$i] ?? '');
            $e = trim($inEnds[$i]   ?? '');
            $nm = day_names_enabled() ? trim((string)($inNames[$i] ?? '')) : '';
            $days[] = ['date' => $d, 'start' => $s, 'end' => $e, 'name' => $nm];   // keep for re-render on error
            // NB: end EARLIER than start is deliberately allowed — it means the
            // day runs past midnight (18:00 -> 03:00); day_rel_min() gives all
            // ordering/plotting that interpretation. So: no end>start check.
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
                // Archive whatever is currently live (at most one row) — UNLESS
                // public archives are on, where past events stay open on
                // purpose so they can still be edited and signed up for. With
                // the feature on, archiving becomes a manual (or time-based)
                // decision made from the Archive tab instead.
                if (!public_archives_enabled()) {
                    db_run('UPDATE events SET is_archived = 1, archived_at = ? WHERE is_archived = 0', [$now]);
                }

                // Unguessable share token for the read-only archive link later.
                $token = bin2hex(random_bytes(16));
                db_run('INSERT INTO events (name, num_days, access_token, location_name, location_address, thumbnail)
                        VALUES (?,?,?,?,?,?)',
                       [$name, $num_days, $token,
                        // NULL rather than '' for "not given": one value in the
                        // column instead of two, same as day_name above.
                        $loc_name !== '' ? $loc_name : null,
                        $loc_addr !== '' ? $loc_addr : null,
                        $thumb    !== '' ? $thumb    : null]);
                $eventId = (int)db()->lastInsertId();

                // Insert the days (1-based day_index).
                $stmt = db()->prepare(
                    'INSERT INTO event_days (event_id, day_index, day_date, day_name, start_time, end_time)
                     VALUES (?,?,?,?,?,?)'
                );
                foreach ($days as $idx => $row) {
                    // Stored as NULL rather than '' when unnamed, so "no label"
                    // is one value in the column instead of two.
                    $nm = trim((string)($row['name'] ?? ''));
                    $stmt->execute([$eventId, $idx + 1, $row['date'],
                                    $nm === '' ? null : $nm, $row['start'], $row['end']]);
                }

                // Chat scoped to the event? Then a new event starts a new
                // conversation. Inside the transaction with the event itself:
                // if creating the event rolls back, the old chat must come back
                // with it, or an admin whose event creation FAILED would still
                // have lost the log.
                if (opt('chat_scope') === 'event') {
                    chat_wipe();
                }

                db()->commit();
            } catch (Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $stage = 'days';
                $error = t('update_failed', $ex->getMessage());
            }

            if ($error === null) {
                log_action('event_create', $name);
                /* After the commit, never inside it: a mail cannot be rolled
                 * back, so telling people about an event whose creation then
                 * failed would be unfixable. Returns 0 quietly when the feature
                 * or email sending is off. */
                mailing_notify_new_event($eventId);
                /* Normally: go and look at the event just created.
                 *
                 * But if its dates are already past the auto-archive threshold,
                 * index.php is the WORST place to send the admin — that page
                 * load runs the sweep, so the event they just made is archived
                 * before they see it, and the front page shows something else.
                 * Send them to the archive tab with the warning instead: the
                 * event is listed there, still active, and the reason it is
                 * about to disappear is on screen. */
                if (event_auto_archive_due($eventId)) {
                    redirect('admin.php?tab=archive&warn=autoarchive');
                }
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
    'loc_name' => $loc_name,
    'loc_addr' => $loc_addr,
    'thumb'    => $thumb,
    'details'  => event_details_enabled(),
    'days'     => $days,         // prefill rows for screen 2
    'error'    => $error,
    'current'  => $current,      // live event row, or null (drives the rename form)
]);
