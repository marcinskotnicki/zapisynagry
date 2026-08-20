<?php
/* =============================================================================
 *  templates/light/calendar_month.php — one month. Presentation only.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $year, $month  — the month on show.
 *    $weeks         — whole weeks of ['date','day','current'] cells.
 *    $by_day        — 'YYYY-MM-DD' => [ ['id','name'], ... ].
 *    $prev_month / $next_month / $prev_year / $next_year — [y, m] targets.
 *    $today         — 'YYYY-MM-DD', for highlighting.
 * ============================================================================= */
?>
<div class="card archive-page">
    <?php // The stepper bar: year and month either side of the label, so the
          // wider jump sits outside the finer one and the pair reads as a
          // single control rather than four unrelated buttons. ?>
    <nav class="cal-bar" aria-label="<?= e(t('calendar_nav')) ?>">
        <a class="btn btn-small" href="calendar.php?y=<?= (int)$prev_year[0] ?>&amp;m=<?= (int)$prev_year[1] ?>"
           aria-label="<?= e(t('calendar_prev_year')) ?>" title="<?= e(t('calendar_prev_year')) ?>">&laquo;</a>
        <a class="btn btn-small" href="calendar.php?y=<?= (int)$prev_month['y'] ?>&amp;m=<?= (int)$prev_month['m'] ?>"
           aria-label="<?= e(t('calendar_prev_month')) ?>" title="<?= e(t('calendar_prev_month')) ?>">&lsaquo;</a>

        <h1 class="cal-title"><?= e(t('month_' . (int)$month)) ?> <?= (int)$year ?></h1>

        <a class="btn btn-small" href="calendar.php?y=<?= (int)$next_month['y'] ?>&amp;m=<?= (int)$next_month['m'] ?>"
           aria-label="<?= e(t('calendar_next_month')) ?>" title="<?= e(t('calendar_next_month')) ?>">&rsaquo;</a>
        <a class="btn btn-small" href="calendar.php?y=<?= (int)$next_year[0] ?>&amp;m=<?= (int)$next_year[1] ?>"
           aria-label="<?= e(t('calendar_next_year')) ?>" title="<?= e(t('calendar_next_year')) ?>">&raquo;</a>
    </nav>

    <?php // A real <table>: this IS tabular data (days by weekday), so the
          // semantics are honest and a screen reader can announce the column. ?>
    <table class="cal">
        <thead>
            <tr>
                <?php // Monday-first, matching calendar_weeks(). ?>
                <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $wd): ?>
                    <th scope="col" abbr="<?= e(t('weekday_' . $wd)) ?>"><?= e(t('weekday_short_' . $wd)) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($weeks as $week): ?>
                <tr>
                    <?php foreach ($week as $cell): ?>
                        <?php
                        $evs = $by_day[$cell['date']] ?? [];
                        $cls = 'cal-cell';
                        if (!$cell['current'])            $cls .= ' cal-outside';
                        if ($cell['date'] === $today)     $cls .= ' cal-today';
                        if ($evs)                         $cls .= ' cal-has';
                        ?>
                        <td class="<?= e($cls) ?>">
                            <span class="cal-day"><?= (int)$cell['day'] ?></span>
                            <?php // Every event on the day gets its own link —
                                  // two clubs sharing a date is normal, and one
                                  // link would hide the other. ?>
                            <?php foreach ($evs as $ev): ?>
                                <a class="cal-ev" href="index.php?event=<?= (int)$ev['id'] ?>&amp;day=<?= (int)$ev['day'] ?>"
                                   title="<?= e($ev['name']) ?>"><?= e($ev['name']) ?></a>
                            <?php endforeach; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="cal-foot">
        <?php // Only when that view is offered — otherwise this is a link to
              // a page that now refuses to load. ?>
        <?php if (archive_list_enabled()): ?>
            <a href="archive.php"><?= e(t('calendar_to_list')) ?></a>
        <?php endif; ?>
    </p>
</div>
