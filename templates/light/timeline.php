<?php
/* =============================================================================
 *  templates/light/timeline.php — per-day timeline. Presentation only.
 * -----------------------------------------------------------------------------
 *  Renders the horizontal schedule built by timeline_build(): hour brackets
 *  across the top, then per table a number band followed by one or more "lanes"
 *  of game blocks. Each block is positioned by left% and sized by width%, and
 *  links to its game card; a full game's player count gets the tl-count-full
 *  class (styled red). Wrapped in a horizontally-scrollable container so a long
 *  day still works on a narrow screen.
 *
 *  RENDER VARS:
 *    $timeline = [
 *      'hours'  => [ ['label'=>'14:00','left'=>33.3], ... ],   // bracket marks
 *      'tables' => [ ['number'=>1, 'lanes'=>[ [block,...], ... ]], ... ],
 *    ]  where block = ['id','name','start_time','cur','max','full','left','width'].
 *
 *  Inline left/width percentages are unavoidable here: each game's horizontal
 *  position and span are data-driven, which is exactly the "unless necessary"
 *  exception to the no-inline-styles rule.
 * ============================================================================= */
?>
<section class="timeline">
    <h2 class="timeline-title"><?= e(t('timeline_title')) ?></h2>

    <div class="tl-scroll">
        <div class="tl-inner">
            <?php // Hour brackets across the top (positioned by left%). ?>
            <div class="tl-hours">
                <?php foreach ($timeline['hours'] as $h): ?>
                    <span class="tl-hour" style="left:<?= $h['left'] ?>%"><?= e($h['label']) ?></span>
                <?php endforeach; ?>
            </div>

            <?php foreach ($timeline['tables'] as $tbl): // table number band, then its lanes ?>
                <div class="tl-table-no"><?= e(t('table_label', $tbl['number'])) ?></div>
                <?php foreach ($tbl['lanes'] as $lane): // each lane is a row of non-overlapping blocks ?>
                    <div class="tl-lane">
                        <?php foreach ($lane as $b): // one block, positioned + sized by % ?>
                            <?php if (($b['type'] ?? 'game') === 'poll'): // provisional 2h poll block ?>
                                <a class="tl-game tl-poll" href="#poll-<?= (int)$b['id'] ?>"
                                   style="left:<?= $b['left'] ?>%;width:<?= $b['width'] ?>%">
                                    <span class="tl-name"><?= e($b['name']) ?></span>
                                    <span class="tl-sub"><?= e($b['start_time']) ?></span>
                                </a>
                            <?php else: ?>
                                <a class="tl-game" href="#game-<?= (int)$b['id'] ?>"
                                   style="left:<?= $b['left'] ?>%;width:<?= $b['width'] ?>%">
                                    <span class="tl-name"><?= e($b['name']) ?></span>
                                    <span class="tl-sub"><?= e($b['start_time']) ?>,
                                        <span class="tl-count<?= $b['full'] ? ' tl-count-full' : '' ?>"><?= (int)$b['cur'] ?>/<?= (int)$b['max'] ?></span>
                                    </span>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
