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
 *    $details  — whether the optional event details are switched on.
 *    $loc_name / $loc_addr / $thumb — those details (carried between screens).
 *    $days     — per-day rows ['date','start','end'] (screen 2).
 *    $error    — message above the form, or null.
 *    $current  — the live event row, or null. When present, screen 1 shows a
 *                rename form for it above the create-event form.
 *    $sources  — events offered in the "copy from" picker (screen 1).
 *    $copy_from — id of the event being copied, 0 = none (carried hidden).
 *    $copy_src — that event's row right after loading it, for the note.
 *    $desc_mode — event_description_mode(): 'off' | 'event' | 'day'.
 *    $desc     — the event description ('event' mode).
 *    $highlight — copied default day, carried hidden.
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

    <?php // COPY FROM AN EXISTING EVENT. A plain GET form: picking an event
          // reloads this screen pre-filled from it, nothing is saved yet. ?>
    <?php if (!empty($sources)): ?>
        <form method="get" action="admin.php" class="newevent-copy">
            <input type="hidden" name="tab" value="new_event">
            <div class="field">
                <label for="ev_copy"><?= e(t('newevent_copy')) ?></label>
                <select id="ev_copy" name="copy">
                    <option value="0"><?= e(t('newevent_copy_none')) ?></option>
                    <?php foreach ($sources as $src): ?>
                        <option value="<?= (int)$src['id'] ?>"<?= (int)($copy_from ?? 0) === (int)$src['id'] ? ' selected' : '' ?>>
                            <?= e($src['name']) ?><?= !empty($src['first_date']) ? ' (' . e($src['first_date']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-small"><?= e(t('newevent_copy_load')) ?></button>
                <p class="field-note"><?= e(t('newevent_copy_note')) ?></p>
            </div>
        </form>
    <?php endif; ?>
    <?php if (!empty($copy_src)): ?>
        <p class="msg msg-ok"><?= e(t('newevent_copied_from', $copy_src['name'])) ?></p>
    <?php endif; ?>

    <?php // Screen 1: name + number of days, plus the optional details.
          // enctype, because the details include a file: without it the browser
          // posts only the filename and the upload silently never arrives. ?>
    <form method="post" action="admin.php?tab=new_event" class="newevent" enctype="multipart/form-data">
        <?= $csrf ?>
        <input type="hidden" name="stage" value="start">
        <input type="hidden" name="copy_from" value="<?= (int)($copy_from ?? 0) ?>">
        <input type="hidden" name="highlight_day" value="<?= (int)($highlight ?? 0) ?>">

        <div class="field">
            <label for="ev_name"><?= e(t('newevent_name')) ?></label>
            <input type="text" id="ev_name" name="name" value="<?= e($name) ?>" required>
        </div>
        <div class="field">
            <label for="ev_days"><?= e(t('newevent_days')) ?></label>
            <input type="number" id="ev_days" name="num_days" min="1" max="60" value="<?= e($num_days) ?>" required>
        </div>
        <?php if (($desc_mode ?? 'off') === 'event'): // the event's own description ?>
            <div class="field">
                <label for="ev_desc"><?= e(t('event_description')) ?></label>
                <textarea id="ev_desc" name="description" rows="4"><?= e($desc ?? '') ?></textarea>
                <p class="field-note"><?= e(t('event_description_note')) ?></p>
            </div>
        <?php endif; ?>

        <?php /* OPTIONAL DETAILS — only when the admin turned them on. Prefilled
                 from the configured defaults, so the common case is to leave
                 them alone. */ ?>
        <?php if (!empty($details)): ?>
            <div class="field">
                <label for="ev_loc"><?= e(t('event_location_name')) ?></label>
                <input type="text" id="ev_loc" name="location_name" value="<?= e($loc_name) ?>">
            </div>
            <div class="field">
                <label for="ev_addr"><?= e(t('event_location_address')) ?></label>
                <textarea id="ev_addr" name="location_address" rows="3"><?= e($loc_addr) ?></textarea>
            </div>
            <div class="field">
                <label for="ev_thumb"><?= e(t('event_thumbnail')) ?></label>
                <input type="file" id="ev_thumb" name="thumb_file" accept="image/*">
                <p class="field-note"><?= e(t('event_thumbnail_note')) ?></p>
                <?php // Already uploaded and processed on a previous pass through
                      // this screen (a validation error, say) — show it, so it is
                      // clear the file is not waiting to be picked again. ?>
                <?php if (!empty($thumb)): ?>
                    <input type="hidden" name="thumbnail" value="<?= e($thumb) ?>">
                    <img class="event-thumb-preview" src="<?= e($thumb) ?>" alt="">
                    <label class="checkbox">
                        <input type="checkbox" name="thumb_clear" value="1"> <?= e(t('event_thumbnail_clear')) ?>
                    </label>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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
        <input type="hidden" name="copy_from" value="<?= (int)($copy_from ?? 0) ?>">
        <input type="hidden" name="highlight_day" value="<?= (int)($highlight ?? 0) ?>">
        <?php if (($desc_mode ?? 'off') === 'event'): ?>
            <input type="hidden" name="description" value="<?= e($desc ?? '') ?>">
        <?php endif; ?>
        <?php // The details carry across the same way the name does. The picture
              // travels as the PATH screen 1 already produced, since a file input
              // cannot be refilled from markup. ?>
        <?php if (!empty($details)): ?>
            <input type="hidden" name="location_name" value="<?= e($loc_name) ?>">
            <input type="hidden" name="location_address" value="<?= e($loc_addr) ?>">
            <input type="hidden" name="thumbnail" value="<?= e($thumb) ?>">
        <?php endif; ?>

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
                    <?php if (($desc_mode ?? 'off') === 'day'): // this day's description ?>
                        <div class="field">
                            <label><?= e(t('event_description')) ?></label>
                            <textarea name="day_desc[]" rows="3"><?= e($row['desc'] ?? '') ?></textarea>
                        </div>
                    <?php endif; ?>
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
