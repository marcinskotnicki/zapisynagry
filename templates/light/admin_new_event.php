<?php
/* =============================================================================
 *  templates/light/admin_new_event.php — New event tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  Two screens, chosen by $stage:
 *    'start' — event name + number of days.
 *    'days'  — one date/start/end block per day (name + count carried hidden).
 *  On screen 2, js/scripts.js cascades the first date down to the other days
 *  (data-day-index marks the date inputs). Creating the event archives the
 *  current one (handled in the controller).
 *
 *  RENDER VARS:
 *    $csrf     — hidden CSRF field.
 *    $stage    — 'start' or 'days'.
 *    $name     — event name (prefill / carried hidden on screen 2).
 *    $num_days — number of days.
 *    $days     — per-day rows ['date','start','end'] (screen 2).
 *    $error    — message above the form, or null.
 *    $current  — the live event row, or null. When present, screen 1 shows a
 *                rename form for it above the create-event form.
 *
 *  Day labels: try a full localized label ('day_label_N'); if missing, fall
 *  back to the generic "Day N" pattern.
 * ============================================================================= */
$dayLabel = function($i) {
    // Prefer a specific 'day_label_<n>' string; t() returns the key unchanged
    // when it's missing, which is how we detect "no specific label" here.
    $key = 'day_label_' . $i;
    $s = t($key);
    return $s === $key ? t('day_n', $i) : $s;
};
?>
<?php if (!empty($error)): ?>
    <p class="msg msg-error"><?= e($error) ?></p>
<?php endif; ?>

<?php if (($stage ?? 'start') === 'start'): ?>
    <?php if (!empty($current)): // a live event exists -> offer an in-place rename ?>
        <fieldset class="event-rename">
            <legend><?= e(t('newevent_current')) ?></legend>
            <form method="post" action="admin.php?tab=new_event">
                <?= $csrf ?>
                <input type="hidden" name="stage" value="rename">
                <div class="field">
                    <label for="cur_name"><?= e(t('newevent_name')) ?></label>
                    <input type="text" id="cur_name" name="current_name" value="<?= e($current['name']) ?>" required>
                </div>
                <button type="submit" class="btn"><?= e(t('newevent_rename')) ?></button>
            </form>
        </fieldset>
    <?php endif; ?>

    <?php // Screen 1: name + number of days. ?>
    <form method="post" action="admin.php?tab=new_event" class="newevent">
        <?= $csrf ?>
        <input type="hidden" name="stage" value="start">

        <div class="field">
            <label for="ev_name"><?= e(t('newevent_name')) ?></label>
            <input type="text" id="ev_name" name="name" value="<?= e($name) ?>" required>
        </div>
        <div class="field">
            <label for="ev_days"><?= e(t('newevent_days')) ?></label>
            <input type="number" id="ev_days" name="num_days" min="1" max="60" value="<?= e($num_days) ?>" required>
        </div>

        <button type="submit" class="btn btn-primary"><?= e(t('newevent_next')) ?></button>
    </form>

<?php else: ?>
    <?php // Screen 2: per-day date/start/end. JS cascades the first date down. ?>
    <form method="post" action="admin.php?tab=new_event" class="newevent">
        <?= $csrf ?>
        <input type="hidden" name="stage" value="days">
        <input type="hidden" name="name" value="<?= e($name) ?>">
        <input type="hidden" name="num_days" value="<?= e($num_days) ?>">

        <?php foreach ($days as $i => $row): // parallel arrays: day_date[]/day_start[]/day_end[] ?>
            <fieldset class="day-block">
                <legend><?= e($dayLabel($i + 1)) ?></legend>
                <div class="day-grid">
                    <div class="field">
                        <label><?= e(t('newevent_date')) ?></label>
                        <input type="date" name="day_date[]"
                               class="day-date" data-day-index="<?= $i ?>"
                               value="<?= e($row['date']) ?>" required>
                    </div>
                    <div class="field">
                        <label><?= e(t('newevent_start')) ?></label>
                        <input type="time" name="day_start[]" value="<?= e($row['start']) ?>" required>
                    </div>
                    <div class="field">
                        <label><?= e(t('newevent_end')) ?></label>
                        <input type="time" name="day_end[]" value="<?= e($row['end']) ?>" required>
                    </div>
                </div>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary"><?= e(t('newevent_create')) ?></button>
    </form>
<?php endif; ?>
