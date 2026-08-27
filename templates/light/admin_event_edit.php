<?php
/* =============================================================================
 *  templates/light/admin_event_edit.php — one event: rename, and manage days.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $csrf       — hidden CSRF field.
 *    $event      — the event row.
 *    $days       — its days, already in date order.
 *    $day_info   — day id => ['tables','games','players'], for the delete warning.
 *    $can_manage — false for an archived event with public archives off, where
 *                  the rest of the app refuses edits too.
 * ============================================================================= */
?>
<p><a href="admin.php?tab=archive">&laquo; <?= e(t('events_back')) ?></a></p>

<h3><?= e($event['name']) ?></h3>

<?php if ((int)$event['is_deleted'] === 1): ?>
    <p class="field-warn"><?= e(t('events_edit_deleted')) ?></p>
<?php endif; ?>

<fieldset>
    <legend><?= e(t('events_rename')) ?></legend>
    <?php // enctype for the optional picture; harmless when the details are off
          // and the form is text-only. ?>
    <form method="post" action="admin.php?tab=archive&amp;event=<?= (int)$event['id'] ?>"
          enctype="multipart/form-data">
        <?= $csrf ?>
        <input type="hidden" name="event" value="<?= (int)$event['id'] ?>">
        <input type="hidden" name="action" value="rename">
        <div class="field">
            <label for="ev_name"><?= e(t('newevent_name')) ?></label>
            <input type="text" id="ev_name" name="name" value="<?= e($event['name']) ?>" maxlength="200" required>
        </div>
        <?php /* The same three optional details as the create form. Rendered
                 only when the feature is on — and the controller only WRITES
                 them then, so anything typed before it was switched off is kept
                 rather than blanked by an absent field. */ ?>
        <?php if (!empty($details)): ?>
            <div class="field">
                <label for="ev_loc"><?= e(t('event_location_name')) ?></label>
                <input type="text" id="ev_loc" name="location_name"
                       value="<?= e($event['location_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="ev_addr"><?= e(t('event_location_address')) ?></label>
                <textarea id="ev_addr" name="location_address" rows="3"><?= e($event['location_address'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label for="ev_thumb"><?= e(t('event_thumbnail')) ?></label>
                <input type="file" id="ev_thumb" name="thumb_file" accept="image/*">
                <p class="field-note"><?= e(t('event_thumbnail_note')) ?></p>
                <?php // The current picture, with the only way to say "none" —
                      // a file input can replace an upload but never clear one. ?>
                <?php if (!empty($event['thumbnail'])): ?>
                    <img class="event-thumb-preview" src="<?= e($event['thumbnail']) ?>" alt="">
                    <label class="check">
                        <input type="checkbox" name="thumb_clear" value="1">
                        <?= e(t('event_thumbnail_clear')) ?>
                    </label>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <button type="submit" class="btn"><?= e(t('save')) ?></button>
    </form>
</fieldset>

<?php // Which day the event opens on when the address has no ?day=. Useful once
      // a multi-day event is under way and the first day is no longer the one
      // people want. Only offered when there is more than one day to choose
      // between — on a single-day event the setting could not do anything. ?>
<?php if (count($days) > 1): ?>
<fieldset>
    <legend><?= e(t('events_highlight')) ?></legend>
    <form method="post" action="admin.php?tab=archive&amp;event=<?= (int)$event['id'] ?>">
        <?= $csrf ?>
        <input type="hidden" name="event" value="<?= (int)$event['id'] ?>">
        <input type="hidden" name="action" value="highlight">
        <div class="field">
            <label for="ev_highlight"><?= e(t('events_highlight_label')) ?></label>
            <select id="ev_highlight" name="highlight_day">
                <option value="0"><?= e(t('events_highlight_none')) ?></option>
                <?php foreach ($days as $d): ?>
                    <?php
                    $di = (int)$d['day_index'];
                    // Date and name where they exist, so the choice is
                    // recognisable rather than "day 4".
                    $label = t('day_n', $di);
                    $extra = trim((string)($d['day_date'] ?? '') . ' ' . (string)($d['day_name'] ?? ''));
                    if ($extra !== '') $label .= ' — ' . $extra;
                    ?>
                    <option value="<?= $di ?>"<?= (int)($event['highlight_day'] ?? 0) === $di ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('events_highlight_note')) ?></p>
        </div>
        <button type="submit" class="btn"><?= e(t('save')) ?></button>
    </form>
</fieldset>
<?php endif; ?>

<fieldset>
    <legend><?= e(t('events_days')) ?></legend>

    <table class="grid">
        <thead>
            <tr>
                <th><?= e(t('events_day_no')) ?></th>
                <th><?= e(t('events_day_date')) ?></th>
                <th><?= e(t('events_day_hours')) ?></th>
                <?php if (day_names_enabled()): ?><th><?= e(t('newevent_day_name')) ?></th><?php endif; ?>
                <th><?= e(t('events_day_contents')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($days as $d): ?>
                <?php $info = $day_info[(int)$d['id']] ?? ['tables' => 0, 'games' => 0, 'players' => 0]; ?>
                <?php $editing = (int)$edit_day === (int)$d['id']; ?>
                <?php if ($editing): ?>
                    <?php // The row turns into a form in place, so the day being
                          // changed stays next to its neighbours for comparison. ?>
                    <tr>
                        <td><?= (int)$d['day_index'] ?></td>
                        <td colspan="4">
                            <form method="post" action="admin.php?tab=archive&amp;event=<?= (int)$event['id'] ?>" class="day-edit-form">
                                <?= $csrf ?>
                                <input type="hidden" name="event" value="<?= (int)$event['id'] ?>">
                                <input type="hidden" name="action" value="day_update">
                                <input type="hidden" name="day" value="<?= (int)$d['id'] ?>">
                                <div class="field">
                                    <label for="ed_date"><?= e(t('events_day_date')) ?></label>
                                    <input type="date" id="ed_date" name="day_date" value="<?= e($d['day_date']) ?>" required>
                                </div>
                                <div class="field">
                                    <label for="ed_start"><?= e(t('newevent_start')) ?></label>
                                    <input type="time" id="ed_start" name="day_start" value="<?= e($d['start_time']) ?>" required>
                                </div>
                                <div class="field">
                                    <label for="ed_end"><?= e(t('newevent_end')) ?></label>
                                    <input type="time" id="ed_end" name="day_end" value="<?= e($d['end_time']) ?>" required>
                                </div>
                                <?php if (day_names_enabled()): ?>
                                    <div class="field">
                                        <label for="ed_name"><?= e(t('newevent_day_name')) ?></label>
                                        <input type="text" id="ed_name" name="day_name" maxlength="100"
                                               value="<?= e($d['day_name'] ?? '') ?>"
                                               placeholder="<?= e(t('newevent_day_name_ph')) ?>">
                                    </div>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-small btn-primary"><?= e(t('save')) ?></button>
                                <a class="btn btn-small" href="admin.php?tab=archive&amp;event=<?= (int)$event['id'] ?>"><?= e(t('cancel')) ?></a>
                            </form>
                        </td>
                    </tr>
                    <?php continue; ?>
                <?php endif; ?>
                <tr>
                    <td data-label="<?= e(t('events_day_no')) ?>"><?= (int)$d['day_index'] ?></td>
                    <td data-label="<?= e(t('events_day_date')) ?>" class="nowrap"><?= e($d['day_date']) ?></td>
                    <td data-label="<?= e(t('events_day_hours')) ?>" class="nowrap"><?= e($d['start_time']) ?>–<?= e($d['end_time']) ?></td>
                    <?php // Shown BEFORE the delete button, because removing a day
                          // takes its tables, games and sign-ups with it and an
                          // admin should see the cost before clicking. ?>
                    <?php if (day_names_enabled()): ?>
                        <td><?= e($d['day_name'] ?? '') ?></td>
                    <?php endif; ?>
                    <td data-label="<?= e(t('events_day_contents')) ?>"><?= e(t('events_day_summary', $info['tables'], $info['games'], $info['players'])) ?></td>
                    <td>
                        <a class="btn btn-small" href="admin.php?tab=archive&amp;event=<?= (int)$event['id'] ?>&amp;day=<?= (int)$d['id'] ?>"><?= e(t('events_day_edit')) ?></a>
                        <?php if (count($days) > 1): ?>
                            <form method="post" action="admin.php?tab=archive&amp;event=<?= (int)$event['id'] ?>" class="inline"
                                  onsubmit="return confirm('<?= e(t('events_day_delete_confirm', $d['day_date'], $info['games'], $info['players'])) ?>');">
                                <?= $csrf ?>
                                <input type="hidden" name="event" value="<?= (int)$event['id'] ?>">
                                <input type="hidden" name="action" value="day_delete">
                                <input type="hidden" name="day" value="<?= (int)$d['id'] ?>">
                                <button class="btn btn-small btn-danger"><?= e(t('events_day_delete')) ?></button>
                            </form>
                        <?php else: ?>
                            <?php // An event with no days has no page to show, so the
                                  // last one cannot be removed — delete the event instead. ?>
                            <span class="muted"><?= e(t('events_day_last')) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h4 class="opt-subhead"><?= e(t('events_day_add')) ?></h4>
    <form method="post" action="admin.php?tab=archive&amp;event=<?= (int)$event['id'] ?>" class="day-add-form">
        <?= $csrf ?>
        <input type="hidden" name="event" value="<?= (int)$event['id'] ?>">
        <input type="hidden" name="action" value="day_add">
        <div class="field">
            <label for="new_day_date"><?= e(t('events_day_date')) ?></label>
            <input type="date" id="new_day_date" name="day_date" required>
        </div>
        <div class="field">
            <label for="new_day_start"><?= e(t('newevent_start')) ?></label>
            <input type="time" id="new_day_start" name="day_start" value="<?= e(opt('default_start_time')) ?>">
        </div>
        <div class="field">
            <label for="new_day_end"><?= e(t('newevent_end')) ?></label>
            <input type="time" id="new_day_end" name="day_end" value="<?= e(opt('default_end_time')) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><?= e(t('events_day_add')) ?></button>
        <?php // Days are renumbered by date after every change, so a day added
              // out of order still lands in the right place on the front page. ?>
        <p class="field-note"><?= e(t('events_day_add_note')) ?></p>
    </form>
</fieldset>
