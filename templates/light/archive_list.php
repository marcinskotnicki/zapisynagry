<?php
/* =============================================================================
 *  templates/light/archive_list.php — the public event list. Presentation only.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $events — event rows with date_from / date_to attached.
 *    $page   — current 1-based page.
 *    $pages  — total pages.
 *    $total  — total events.
 * ============================================================================= */
?>
<div class="card archive-page">
    <div class="archive-head">
        <h1><?= e(t('archive_title')) ?></h1>
        <a class="btn btn-small" href="calendar.php"><?= e(t('archive_to_calendar')) ?></a>
    </div>

    <?php if (!$events): ?>
        <p class="muted"><?= e(t('archive_empty')) ?></p>
    <?php else: ?>
        <ul class="archive-list">
            <?php foreach ($events as $ev): ?>
                <li class="archive-item<?= (int)$ev['is_archived'] === 1 ? ' archive-item-closed' : '' ?>">
                    <?php // Linked by id, not by share token: the token is for
                          // handing out a read-only view to people without an
                          // account, whereas this list is the site's own
                          // navigation and should behave like the rest of it —
                          // an event still open stays editable when opened. ?>
                    <a class="archive-name" href="index.php?event=<?= (int)$ev['id'] ?>"><?= e($ev['name']) ?></a>
                    <?php $label = event_date_label($ev); ?>
                    <?php if ($label !== ''): ?>
                        <span class="archive-date"><?= e($label) ?></span>
                    <?php endif; ?>
                    <?php if ((int)$ev['is_archived'] === 1): ?>
                        <span class="archive-badge"><?= e(t('archive_closed')) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php // Prev/next only — a club with years of events does not need a
              // numbered pager, and two controls stay usable on a phone. ?>
        <?php if ($pages > 1): ?>
            <nav class="pager">
                <?php if ($page > 1): ?>
                    <a class="btn btn-small" href="archive.php?page=<?= $page - 1 ?>"><?= e(t('pager_prev')) ?></a>
                <?php endif; ?>
                <span class="pager-pos"><?= e(t('pager_position', $page, $pages)) ?></span>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-small" href="archive.php?page=<?= $page + 1 ?>"><?= e(t('pager_next')) ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
