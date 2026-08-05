<?php
/* =============================================================================
 *  templates/light/admin_archive.php — Archive tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  A table of every event (newest first) with a copy-able read-only link
 *  (index.php?e=<token>). The current event is marked as such; the rest are
 *  archived. The copy button is wired by js/scripts.js via data-copy-target.
 *
 *  RENDER VARS:
 *    $events — all event rows (incl. access_token, is_archived, created_at).
 *    $base   — link prefix ending in "index.php?e="; the token is appended here.
 * ============================================================================= */
?>
<?php if (empty($events)): ?>
    <p class="muted"><?= e(t('archive_none')) ?></p>
<?php else: ?>
<table class="grid">
    <thead>
        <tr>
            <th><?= e(t('archive_event')) ?></th>
            <th><?= e(t('archive_status')) ?></th>
            <th><?= e(t('archive_created')) ?></th>
            <th><?= e(t('archive_link')) ?></th>
            <th><?= e(t('archive_action')) ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($events as $ev): ?>
        <?php $link = $base . urlencode($ev['access_token']); // full shareable URL for this event ?>
        <?php
        // Deleted outranks archived: a deleted event is always archived too, so
        // reporting "archived" would hide the state that actually matters.
        $state = (int)$ev['is_deleted'] === 1 ? 'deleted'
               : ((int)$ev['is_archived'] === 0 ? 'current' : 'archived');
        $stateLabel = $state === 'deleted' ? t('archive_deleted')
                    : ($state === 'current' ? t('archive_current') : t('archive_archived'));
        ?>
        <tr class="archive-row archive-row-<?= e($state) ?>">
            <td><?= e($ev['name']) ?></td>
            <td class="archive-status archive-status-<?= e($state) ?>"><?= e($stateLabel) ?></td>
            <td class="nowrap"><?= e($ev['created_at']) ?></td>
            <td class="link-cell">
                <?php // Input then button on the line below. No flex anywhere near
                      // the cell: display:flex on a <td> replaces display:table-cell
                      // and drops it out of the row's height calculation, which is
                      // what was breaking the row borders here. The input already
                      // fills the cell, so the button wraps on its own. ?>
                <input type="text" readonly value="<?= e($link) ?>" id="lnk<?= (int)$ev['id'] ?>">
                <button type="button" class="btn btn-small copy-btn" data-copy-target="lnk<?= (int)$ev['id'] ?>">
                    <?= e(t('copy')) ?>
                </button>
            </td>
            <td class="archive-actions">
                <?php $isDeleted = (int)$ev['is_deleted'] === 1; ?>
                <?php if (!$isDeleted): ?>
                    <?php // Renaming and day management are ordinary event admin, so
                          // they are offered whether or not public archives are on —
                          // unlike archive/unarchive below, which only make sense when
                          // archiving is not already automatic. ?>
                    <a class="btn btn-small" href="admin.php?tab=archive&amp;event=<?= (int)$ev['id'] ?>"><?= e(t('events_edit')) ?></a>
                    <?php if ($can_toggle): ?>
                        <?php // One button per row, showing the action rather than the
                              // state — the Status column already says which it is. ?>
                        <form method="post" action="admin.php?tab=archive&amp;page=<?= (int)$page ?>" class="inline">
                            <?= $csrf ?>
                            <input type="hidden" name="event" value="<?= (int)$ev['id'] ?>">
                            <input type="hidden" name="action"
                                   value="<?= (int)$ev['is_archived'] === 1 ? 'unarchive' : 'archive' ?>">
                            <button class="btn btn-small">
                                <?= (int)$ev['is_archived'] === 1
                                        ? e(t('archive_unarchive'))
                                        : e(t('archive_archive')) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="admin.php?tab=archive&amp;page=<?= (int)$page ?>" class="inline"
                          onsubmit="return confirm('<?= e(t('archive_delete_confirm')) ?>');">
                        <?= $csrf ?>
                        <input type="hidden" name="event" value="<?= (int)$ev['id'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <button class="btn btn-small btn-danger"><?= e(t('archive_delete')) ?></button>
                    </form>
                <?php else: ?>
                    <?php // A deleted event offers only the two ways out of that
                          // state: bring it back, or destroy it for good. ?>
                    <form method="post" action="admin.php?tab=archive&amp;page=<?= (int)$page ?>" class="inline">
                        <?= $csrf ?>
                        <input type="hidden" name="event" value="<?= (int)$ev['id'] ?>">
                        <input type="hidden" name="action" value="restore">
                        <button class="btn btn-small"><?= e(t('archive_restore')) ?></button>
                    </form>
                    <form method="post" action="admin.php?tab=archive&amp;page=<?= (int)$page ?>" class="inline"
                          onsubmit="return confirm('<?= e(t('archive_purge_confirm')) ?>');">
                        <?= $csrf ?>
                        <input type="hidden" name="event" value="<?= (int)$ev['id'] ?>">
                        <input type="hidden" name="action" value="purge">
                        <button class="btn btn-small btn-danger"><?= e(t('archive_purge')) ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($pages > 1): ?>
    <nav class="pager">
        <?php if ($page > 1): ?>
            <a class="btn btn-small" href="admin.php?tab=archive&amp;page=<?= $page - 1 ?>"><?= e(t('pager_prev')) ?></a>
        <?php endif; ?>
        <span class="pager-pos"><?= e(t('pager_position', $page, $pages)) ?></span>
        <?php if ($page < $pages): ?>
            <a class="btn btn-small" href="admin.php?tab=archive&amp;page=<?= $page + 1 ?>"><?= e(t('pager_next')) ?></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>
