<?php
/* =============================================================================
 *  templates/light/admin_comments.php — every comment, newest first.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Game and poll comments in one list, so moderating them is
 *  one job in one place rather than a hunt through the events.
 *
 *  Each row says WHERE it was written and links there: "somebody said this
 *  somewhere" is not something an admin can act on, and the usual reason for
 *  opening this list is to go and look at the thread.
 *
 *  RENDER VARS:
 *    $csrf     — hidden CSRF field.
 *    $comments — rows with kind/parent_id/parent_name/event_name, newest first.
 *    $total    — how many there are altogether.
 *    $page, $pages — pagination state.
 *    $sub      — which sub-tab is open.
 * ============================================================================= */
?>
<?php tpl_render('admin_msg_subtabs', ['sub' => $sub ?? 'comments']); ?>
<h3><?= e(t('comments_admin_title')) ?></h3>

<?php if (!$comments): ?>
    <p class="muted"><?= e(t('comments_admin_empty')) ?></p>
<?php else: ?>
    <p class="muted"><?= e(t('comments_admin_count', $total)) ?></p>

    <table class="grid">
        <thead>
            <tr>
                <th><?= e(t('comments_admin_event')) ?></th>
                <th><?= e(t('chat_admin_when')) ?></th>
                <th><?= e(t('comments_admin_where')) ?></th>
                <th><?= e(t('chat_admin_who')) ?></th>
                <th><?= e(t('chat_admin_message')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($comments as $c): ?>
                <tr>
                    <td data-label="<?= e(t('comments_admin_event')) ?>">
                        <?= e($c['event_name'] ?? '') ?>
                    </td>
                    <td data-label="<?= e(t('chat_admin_when')) ?>"><?= e($c['created_at']) ?></td>
                    <td data-label="<?= e(t('comments_admin_where')) ?>">
                        <?php /* A poll has no name of its own, so it is simply
                                 called what it is. Reuses poll_label, the same
                                 word the poll cards use, rather than inventing a
                                 second phrase for the same thing. */ ?>
                        <?php $cWhere = $c['kind'] === 'poll'
                                ? t('poll_label')
                                : (string)($c['parent_name'] ?? ''); ?>
                        <?php if (!empty($c['event_id'])): ?>
                            <?php // Straight to the card it was written on. ?>
                            <a href="index.php?event=<?= (int)$c['event_id'] ?><?= $c['kind'] === 'poll'
                                    ? '#poll-' : '#game-' ?><?= (int)$c['parent_id'] ?>"><?= e($cWhere) ?></a>
                        <?php else: ?>
                            <?= e($cWhere) ?>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= e(t('chat_admin_who')) ?>"><?= e($c['name']) ?></td>
                    <td data-label="<?= e(t('chat_admin_message')) ?>" class="comment-cell">
                        <?= nl2br(e($c['comment'])) ?>
                    </td>
                    <td class="row-actions">
                        <?php /* Editing, in place: the same <details> form the
                                 cards use, so a correction made here behaves
                                 identically to one made on the event page. The
                                 'back' field brings the admin to this list
                                 rather than out to the event. */ ?>
                        <details class="c-edit">
                            <summary class="btn btn-small"><?= e(t('edit')) ?></summary>
                            <form method="post" action="edit_comment.php">
                                <?= $csrf ?>
                                <input type="hidden" name="comment" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="kind" value="<?= e($c['kind']) ?>">
                                <input type="hidden" name="back" value="admin.php?tab=chat&amp;sub=comments">
                                <textarea name="comment_text" rows="3" required><?= e($c['comment']) ?></textarea>
                                <button type="submit" class="btn btn-small"><?= e(t('save')) ?></button>
                            </form>
                        </details>
                        <?php /* Posts to the SAME endpoint the card's own × uses,
                                 rather than a second delete written here: one
                                 place to get the permission check right, and the
                                 log line reads the same either way. 'back' brings
                                 the admin to this list instead of the event. */ ?>
                        <form method="post" action="delete_comment.php" class="inline"
                              onsubmit="return confirm('<?= e(t('comment_delete_confirm')) ?>');">
                            <?= $csrf ?>
                            <input type="hidden" name="comment" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="kind" value="<?= e($c['kind']) ?>">
                            <input type="hidden" name="back" value="admin.php?tab=chat&amp;sub=comments">
                            <button class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <nav class="pager">
            <?php $cUrl = 'admin.php?tab=chat&amp;sub=comments'; ?>
            <?php if ($page > 1): ?>
                <a class="btn btn-small" href="<?= $cUrl ?>&amp;page=<?= $page - 1 ?>"><?= e(t('pager_prev')) ?></a>
            <?php endif; ?>
            <span class="pager-info"><?= e(t('pager_position', $page, $pages)) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-small" href="<?= $cUrl ?>&amp;page=<?= $page + 1 ?>"><?= e(t('pager_next')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
