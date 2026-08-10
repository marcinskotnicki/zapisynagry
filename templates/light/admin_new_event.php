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
    <?php // Only when the archive is OFF, because that is the setting that
          // decides it: with archives on, several events run side by side and
          // creating one leaves the others alone. Shown here rather than only
          // in Options, since this is the screen where the behaviour bites. ?>
    <?php if (!public_archives_enabled()): ?>
        <p class="field-warn"><?= e(t('newevent_archive_warning')) ?></p>
    <?php endif; ?>
    <?php if (!empty($current)): // point at the tab that now owns renaming ?>
        <p class="muted">
            <?= e(t('newevent_current')) ?>: <strong><?= e($current['name']) ?></strong>
            — <a href="admin.php?tab=archive&amp;event=<?= (int)$current['id'] ?>"><?= e(t('newevent_rename')) ?></a>
        </p>
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

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('newevent_next')) ?></button>
            <?php // A way back, now that this screen is reached by a button on the
                  // events list rather than by a nav tab — without it the only
                  // exit is another tab, which loses what was typed with no hint
                  // that it would. ?>
            <a class="btn" href="admin.php?tab=archive"><?= e(t('back')) ?></a>
        </div>
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
                    <?php // Optional label for this day, offered only while the
                          // feature is on. Never required: an event where only
                          // some days are named is a normal thing to want. ?>
                    <?php if (day_names_enabled()): ?>
                        <div class="field">
                            <label><?= e(t('newevent_day_name')) ?></label>
                            <input type="text" name="day_name[]" maxlength="100"
                                   value="<?= e($row['name'] ?? '') ?>"
                                   placeholder="<?= e(t('newevent_day_name_ph')) ?>">
                        </div>
                    <?php endif; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>

        <?php // End earlier than start = the day runs past midnight (day_rel_min). ?>
        <p class="field-note"><?= e(t('newevent_overnight_hint')) ?></p>

        <button type="submit" class="btn btn-primary"><?= e(t('newevent_create')) ?></button>
    </form>
<?php endif; ?>
