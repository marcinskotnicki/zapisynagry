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
        </tr>
    </thead>
    <tbody>
    <?php foreach ($events as $ev): ?>
        <?php $link = $base . urlencode($ev['access_token']); // full shareable URL for this event ?>
        <tr>
            <td><?= e($ev['name']) ?></td>
            <td>
                <?= (int)$ev['is_archived'] === 0
                        ? e(t('archive_current'))
                        : e(t('archive_archived')) ?>
            </td>
            <td class="nowrap"><?= e($ev['created_at']) ?></td>
            <td class="link-cell">
                <input type="text" readonly value="<?= e($link) ?>" id="lnk<?= (int)$ev['id'] ?>">
                <button type="button" class="btn btn-small copy-btn" data-copy-target="lnk<?= (int)$ev['id'] ?>">
                    <?= e(t('copy')) ?>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
