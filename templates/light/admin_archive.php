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
        <tr<?= (int)$ev['is_deleted'] === 1 ? ' class="archive-row-deleted"' : '' ?>>
            <td><?= e($ev['name']) ?></td>
            <td>
                <?php // Deleted outranks archived in the label: a deleted event is
                      // always archived too, so showing "archived" would hide the
                      // state that actually matters. ?>
                <?= (int)$ev['is_deleted'] === 1
                        ? e(t('archive_deleted'))
                        : ((int)$ev['is_archived'] === 0
                            ? e(t('archive_current'))
                            : e(t('archive_archived'))) ?>
            </td>
            <td class="nowrap"><?= e($ev['created_at']) ?></td>
            <td class="link-cell">
                <?php // The flex row goes on an inner div, never on the <td>:
                      // display:flex replaces display:table-cell, which drops the
                      // cell out of the row's height calculation — it then sizes
                      // to its own content and its bottom border lands above the
                      // neighbouring cells', breaking the row lines. ?>
                <div class="link-cell-inner">
                    <input type="text" readonly value="<?= e($link) ?>" id="lnk<?= (int)$ev['id'] ?>">
                    <button type="button" class="btn btn-small copy-btn" data-copy-target="lnk<?= (int)$ev['id'] ?>">
                        <?= e(t('copy')) ?>
                    </button>
                </div>
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
