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
    <form method="post" action="admin.php?tab=archive&amp;event=<?= (int)$event['id'] ?>">
        <?= $csrf ?>
        <input type="hidden" name="event" value="<?= (int)$event['id'] ?>">
        <input type="hidden" name="action" value="rename">
        <div class="field">
            <label for="ev_name"><?= e(t('newevent_name')) ?></label>
            <input type="text" id="ev_name" name="name" value="<?= e($event['name']) ?>" maxlength="200" required>
        </div>
        <button type="submit" class="btn"><?= e(t('newevent_rename')) ?></button>
    </form>
</fieldset>

<fieldset>
    <legend><?= e(t('events_days')) ?></legend>

    <table class="grid">
        <thead>
            <tr>
                <th><?= e(t('events_day_no')) ?></th>
                <th><?= e(t('events_day_date')) ?></th>
                <th><?= e(t('events_day_hours')) ?></th>
                <th><?= e(t('events_day_contents')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($days as $d): ?>
                <?php $info = $day_info[(int)$d['id']] ?? ['tables' => 0, 'games' => 0, 'players' => 0]; ?>
                <tr>
                    <td><?= (int)$d['day_index'] ?></td>
                    <td class="nowrap"><?= e($d['day_date']) ?></td>
                    <td class="nowrap"><?= e($d['start_time']) ?>–<?= e($d['end_time']) ?></td>
                    <?php // Shown BEFORE the delete button, because removing a day
                          // takes its tables, games and sign-ups with it and an
                          // admin should see the cost before clicking. ?>
                    <td><?= e(t('events_day_summary', $info['tables'], $info['games'], $info['players'])) ?></td>
                    <td>
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
