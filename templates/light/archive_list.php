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
        <?php // Same: a club showing only the list has no calendar to link to. ?>
        <?php if (archive_calendar_enabled()): ?>
            <a class="btn btn-small" href="calendar.php"><?= e(t('archive_to_calendar')) ?></a>
        <?php endif; ?>
    </div>

    <?php if (!$events): ?>
        <p class="muted"><?= e(t('archive_empty')) ?></p>
    <?php else: ?>
        <?php /* Does ANY event on this page have a picture? If so, rows without
                 one get an empty box of the same size, so every name starts at
                 the same x and the date column stays straight. Same reasoning
                 (and same shape) as the event list's picture column. Scanned per
                 page rather than across the whole archive: this list is
                 paginated, and a picture three pages back cannot misalign
                 anything here. */ ?>
        <?php $adAnyPic = false;
              foreach ($events as $adScan) {
                  $adScanD = event_details($adScan);
                  if (!empty($adScanD['thumbnail'])) { $adAnyPic = true; break; }
              } ?>
        <ul class="archive-list">
            <?php foreach ($events as $ev): ?>
                <?php $ad = event_details($ev); ?>
                <li class="archive-item<?= (int)$ev['is_archived'] === 1 ? ' archive-item-closed' : '' ?><?= !empty($ad['thumbnail']) ? ' archive-item-withpic' : '' ?>">
                    <?php // A small picture to the left, when the event has one.
                          // Deliberately smaller than the event list's: this is a
                          // dense index of everything a club has ever run, not a
                          // shop window. ?>
                    <?php if (!empty($ad['thumbnail'])): ?>
                        <img class="archive-thumb" src="<?= e($ad['thumbnail']) ?>" alt="">
                    <?php elseif ($adAnyPic): ?>
                        <span class="archive-thumb-empty"></span>
                    <?php endif; ?>
                    <?php /* THREE children exactly — name-block, date, badge —
                             because on desktop this is a three-column grid. The
                             location goes INSIDE the name block rather than
                             beside it: a fourth child would wrap onto a second
                             grid row and pull the dates out of line. */ ?>
                    <span class="archive-item-text">
                        <span class="archive-item-main">
                            <?php // Linked by id, not by share token: the token is for
                                  // handing out a read-only view to people without an
                                  // account, whereas this list is the site's own
                                  // navigation and should behave like the rest of it —
                                  // an event still open stays editable when opened. ?>
                            <a class="archive-name" href="index.php?event=<?= (int)$ev['id'] ?>"><?= e($ev['name']) ?></a>
                            <?php // Location name only — no address here, same as the
                                  // calendar: this list is scanned, not read. ?>
                            <?php if (!empty($ad['name'])): ?>
                                <span class="archive-loc"><?= e($ad['name']) ?></span>
                            <?php endif; ?>
                        </span>
                        <?php $label = event_date_label($ev); ?>
                        <span class="archive-date"><?= $label !== '' ? e($label) : '' ?></span>
                        <?php if ((int)$ev['is_archived'] === 1): ?>
                            <span class="archive-badge"><?= e(t('archive_closed')) ?></span>
                        <?php endif; ?>
                    </span>
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
