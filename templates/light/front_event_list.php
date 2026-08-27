<?php
/* =============================================================================
 *  templates/light/front_event_list.php — the active events, as a landing page.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Shown by index.php instead of opening an event, when the
 *  'home_event_list' option is on and nothing in the URL names an event.
 *
 *  Each row is ONE link wrapping everything — picture, name, dates, location.
 *  A row with a link somewhere inside it makes people aim; the whole row being
 *  the target is what you want on a phone, which is where most of these are
 *  read.
 *
 *  The location block only appears when the details feature is on AND that
 *  event filled something in — event_details() answers both at once, so this
 *  template never asks about the option itself.
 *
 *  RENDER VARS:
 *    $events — events_active() rows, each with date_from / date_to.
 * ============================================================================= */
?>
<div class="card event-list-card">
    <h1><?= e(t('evlist_title')) ?></h1>

    <?php if (empty($events)): ?>
        <?php // Not an error: a club between events sees this, and so does a
              // brand-new install before the first event exists. ?>
        <p class="muted"><?= e(t('evlist_none')) ?></p>
    <?php else: ?>
        <?php /* Does ANY event here have a picture? If so every row reserves the
                 picture column, so the names line up in one straight edge
                 whether or not a particular event has one. If none do, the
                 column is not reserved at all — a club that never uploads
                 pictures should not get an empty gutter down the page. */ ?>
        <?php $evAnyPic = false;
              foreach ($events as $evScan) {
                  $evScanD = event_details($evScan);
                  if (!empty($evScanD['thumbnail'])) { $evAnyPic = true; break; }
              } ?>
        <ul class="event-list<?= $evAnyPic ? ' event-list-withpics' : '' ?>">
            <?php foreach ($events as $ev): ?>
                <?php $evd = event_details($ev); ?>
                <li>
                    <a class="event-list-row" href="index.php?event=<?= (int)$ev['id'] ?>">
                        <?php // The box is what has the fixed width; the picture
                              // is fitted inside it, so pictures of any shape or
                              // size line up in one column. Rendered empty for an
                              // event without one, to hold that column open. ?>
                        <?php if ($evAnyPic): ?>
                            <span class="event-list-thumbbox">
                                <?php if (!empty($evd['thumbnail'])): ?>
                                    <img class="event-list-thumb" src="<?= e($evd['thumbnail']) ?>" alt="">
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <span class="event-list-text">
                            <span class="event-list-name"><?= e($ev['name']) ?></span>
                            <?php $evLabel = event_date_label($ev); ?>
                            <?php if ($evLabel !== ''): ?>
                                <span class="event-list-date"><?= e($evLabel) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($evd['name'])): ?>
                                <span class="event-list-loc"><?= e($evd['name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($evd['address'])): ?>
                                <?php // Typed as several lines, so shown as several
                                      // lines: escape first, then turn the newlines
                                      // into breaks — never the other way round. ?>
                                <span class="event-list-addr"><?= nl2br(e($evd['address'])) ?></span>
                            <?php endif; ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
