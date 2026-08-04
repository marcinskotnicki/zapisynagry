<?php
/* =============================================================================
 *  templates/light/admin_chat.php — the chat moderation list. Presentation only.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $csrf     — hidden CSRF field.
 *    $messages — rows, newest first.
 *    $total    — total stored messages.
 *    $page, $pages — pagination state.
 * ============================================================================= */
?>
<h3><?= e(t('chat_admin_title')) ?></h3>

<?php if (!$messages): ?>
    <p class="muted"><?= e(t('chat_admin_empty')) ?></p>
<?php else: ?>
    <p class="muted"><?= e(t('chat_admin_count', $total)) ?></p>

    <table class="grid">
        <thead>
            <tr>
                <th><?= e(t('chat_admin_when')) ?></th>
                <th><?= e(t('chat_admin_who')) ?></th>
                <th><?= e(t('chat_admin_message')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($messages as $m): ?>
                <tr>
                    <?php // Date bold, time light: at a glance the eye needs to group
                          // by day first, and "05.08 00:13" read as one blur. ?>
                    <?php $when = chat_time_parts($m['created_at']); ?>
                    <td class="chat-admin-when">
                        <strong class="chat-when-date"><?= e($when['date']) ?></strong>
                        <span class="chat-when-time"><?= e($when['time']) ?></span>
                    </td>
                    <td>
                        <?= e($m['name']) ?>
                        <?php // The role is frozen at post time, so an old line keeps the
                              // badge it was posted with even if the account changed since. ?>
                        <?php if ((int)$m['is_admin'] === 1): ?>
                            <span class="chat-admin-badge"><?= e(t('chat_admin_is_admin')) ?></span>
                        <?php elseif ($m['user_id'] !== null): ?>
                            <span class="chat-admin-badge"><?= e(t('chat_admin_is_user')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($m['message']) ?></td>
                    <td>
                        <form method="post" action="admin.php?tab=chat&amp;page=<?= (int)$page ?>" class="inline">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <nav class="pager">
            <?php if ($page > 1): ?>
                <a class="btn btn-small" href="admin.php?tab=chat&amp;page=<?= $page - 1 ?>"><?= e(t('pager_prev')) ?></a>
            <?php endif; ?>
            <span class="pager-pos"><?= e(t('pager_position', $page, $pages)) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-small" href="admin.php?tab=chat&amp;page=<?= $page + 1 ?>"><?= e(t('pager_next')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <?php // Clearing everything, kept away from the per-row buttons and behind a
          // confirm: it cannot be undone, and the rows are the only copy. ?>
    <form method="post" action="admin.php?tab=chat" class="chat-admin-purge"
          onsubmit="return confirm('<?= e(t('chat_admin_purge_confirm')) ?>');">
        <?= $csrf ?>
        <input type="hidden" name="action" value="purge">
        <button class="btn btn-small btn-danger"><?= e(t('chat_admin_purge')) ?></button>
    </form>
<?php endif; ?>
