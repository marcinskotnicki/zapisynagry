<?php
/* =============================================================================
 *  templates/light/admin_logs.php — Logs tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  A per-event selector (auto-submits via onchange; a <noscript> button covers
 *  no-JS), then the audit table for the chosen event (newest first). Read-only.
 *
 *  RENDER VARS:
 *    $events  — all events (for the selector; current one is flagged).
 *    $view_id — the event whose logs are shown (selected option).
 *    $logs    — the log rows to display (already limited by the controller).
 * ============================================================================= */
?>
<form method="get" action="admin.php" class="log-filter">
    <input type="hidden" name="tab" value="logs">
    <label for="log_event"><?= e(t('logs_event')) ?></label>
    <select id="log_event" name="event_id" onchange="this.form.submit()">
        <?php // Site-wide entries — settings, accounts, updates, uploads — are
              // stored with no event, so they get their own scope rather than
              // being stapled to whichever event was current when they happened. ?>
        <option value="0"<?= (int)$view_id === 0 ? ' selected' : '' ?>><?= e(t('logs_system')) ?></option>
        <?php foreach ($events as $ev): ?>
            <option value="<?= (int)$ev['id'] ?>"<?= (int)$ev['id'] === (int)$view_id ? ' selected' : '' ?>>
                <?= e($ev['name']) ?><?= (int)$ev['is_archived'] === 0 ? ' (' . e(t('archive_current')) . ')' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-small"><?= e(t('logs_show')) ?></button></noscript>
</form>

<?php if (empty($logs)): ?>
    <p class="muted"><?= e(t('logs_none')) ?></p>
<?php else: ?>
<table class="grid">
    <thead>
        <tr>
            <th><?= e(t('logs_when')) ?></th>
            <th><?= e(t('logs_action')) ?></th>
            <th><?= e(t('logs_detail')) ?></th>
            <th><?= e(t('logs_actor')) ?></th>
            <th><?= e(t('logs_ip')) ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $row): ?>
        <tr>
            <td data-label="<?= e(t('logs_when')) ?>" class="nowrap"><?= e($row['created_at']) ?></td>
            <td data-label="<?= e(t('logs_action')) ?>"><?= e($row['action']) ?></td>
            <td data-label="<?= e(t('logs_detail')) ?>"><?= e($row['detail']) ?></td>
            <td data-label="<?= e(t('logs_actor')) ?>"><?= e($row['actor_name']) ?><?php if (!empty($row['account_name'])): // the acting ACCOUNT, when logged in ?> <span class="muted">@<?= e($row['account_name']) ?></span><?php endif; ?></td>
            <td data-label="<?= e(t('logs_ip')) ?>" class="nowrap"><?= e($row['ip']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
