<?php
/* =============================================================================
 *  templates/light/admin_mailing.php — Mailing tab body. Presentation only.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $draft     — ['audience','subject','body'] to repopulate after an error.
 *    $counts    — audience key => number of addresses, shown beside each option.
 *    $audiences — the allowed keys, in display order.
 *    $enabled   — whether the feature is switched on at all.
 *    $error     — message above the form, or null.
 *    $events    — active events to choose between; the selector is SKIPPED
 *                 when there are fewer than two, since there is no choice.
 *    $event     — the selected event row, or null if none is active.
 *    $event_id  — its id, carried on the send form.
 *    $csrf      — hidden CSRF field.
 * ============================================================================= */
?>
<h2><?= e(t('ml_admin_title')) ?></h2>

<?php if (!$enabled): ?>
    <?php // The form is useless with the feature off, so say why rather than
          // letting someone write a message that can't be sent. ?>
    <p class="msg msg-error"><?= e(t('ml_admin_disabled')) ?></p>
<?php else: ?>
    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <p class="muted"><?= e(t('ml_admin_intro')) ?></p>

    <?php if (count($events) > 1): ?>
        <?php /* Its own GET form, like the Logs tab's scope picker: changing the
                 event has to reload the tab so the recipient counts below match
                 the event they describe. A count that belongs to a different
                 event is worse than no count at all. */ ?>
        <form method="get" action="admin.php" class="ml-event-filter">
            <input type="hidden" name="tab" value="mailing">
            <label for="ml_event"><?= e(t('ml_admin_event')) ?></label>
            <select id="ml_event" name="event_id" onchange="this.form.submit()">
                <?php foreach ($events as $ev): ?>
                    <option value="<?= (int)$ev['id'] ?>"<?= (int)$ev['id'] === (int)$event_id ? ' selected' : '' ?>>
                        <?= e($ev['name']) ?><?php if (!empty($ev['date_from'])): ?> (<?= e($ev['date_from']) ?>)<?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-small"><?= e(t('logs_show')) ?></button></noscript>
        </form>
    <?php elseif ($event !== null): ?>
        <?php // One active event: no choice to offer, but say which it is, so
              // "subscribers" is never ambiguous even on a single-event site. ?>
        <p class="field-note"><?= e(t('ml_admin_event')) ?>: <strong><?= e($event['name']) ?></strong></p>
    <?php else: ?>
        <?php // No active event at all. The event-scoped audiences are empty;
              // 'all subscribers' still works, so the form stays usable. ?>
        <p class="field-note"><?= e(t('ml_admin_noevent')) ?></p>
    <?php endif; ?>

    <form method="post" action="admin.php?tab=mailing">
        <?= $csrf ?>
        <input type="hidden" name="action" value="send">
        <?php // Carried on the POST so the send targets the event whose counts
              // the admin was looking at, not whatever current_event() says now. ?>
        <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">

        <div class="field">
            <label for="ml_audience"><?= e(t('ml_admin_audience')) ?></label>
            <select id="ml_audience" name="audience">
                <?php foreach ($audiences as $a): ?>
                    <option value="<?= e($a) ?>"<?= ($draft['audience'] ?? '') === $a ? ' selected' : '' ?>>
                        <?= e(t('ml_aud_' . $a)) ?> (<?= (int)($counts[$a] ?? 0) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-note"><?= e(t('ml_admin_audience_note')) ?></p>
            <?php // Stated only when there are any: the counts above exclude
                  // them, so an admin seeing fewer recipients than signups
                  // would otherwise have no explanation for the difference. ?>
            <?php if (!empty($pending)): ?>
                <p class="field-note"><?= e(t('ml_admin_pending', (int)$pending)) ?></p>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="ml_subject"><?= e(t('ml_admin_subject')) ?></label>
            <input type="text" id="ml_subject" name="subject" value="<?= e($draft['subject'] ?? '') ?>">
        </div>

        <div class="field">
            <label for="ml_body"><?= e(t('ml_admin_body')) ?></label>
            <textarea id="ml_body" name="body" rows="10"><?= e($draft['body'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('ml_admin_send')) ?></button>
        </div>
    </form>
<?php endif; ?>
