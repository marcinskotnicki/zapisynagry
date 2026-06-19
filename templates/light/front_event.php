<?php
/* =============================================================================
 *  templates/light/front_event.php — the event front page body.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. The main event view: title, optional day tabs (multi-day
 *  events), each table with its interleaved game/poll cards, the add-table
 *  control (or "cap reached" note), and the timeline at the bottom.
 *
 *  Game vs poll cards are dispatched per item type to sub-templates
 *  (game_card / poll_card). In the read-only archive view ($readonly), all the
 *  add/edit controls are suppressed and day links carry the &e=token along.
 *
 *  RENDER VARS:
 *    $event       — the event row.
 *    $readonly    — archived/read-only view (hide controls, thread the token).
 *    $days        — all day rows; $num_days — their count; $active_day — index.
 *    $day_row     — the active day's row.
 *    $tables      — active day's tables, each with ['items'] (games + polls).
 *    $can_add     — may the viewer add tables/games?
 *    $max_reached — table cap hit (hide the add-table button, show a note).
 *    $timeline    — timeline data (or null/empty when the day has no games).
 *    $csrf        — hidden CSRF field (for the add-table form).
 * ============================================================================= */
// In read-only mode every internal link must keep the share token so the viewer
// stays inside the archived event.
$tokenQS = $readonly ? ('&e=' . urlencode($event['access_token'])) : '';
?>
<div class="event-header">
    <h1><?= e($event['name']) ?></h1>
    <?php if ((int)$num_days === 1 && !empty($days[0]['day_date'])): // single-day: show the date inline ?>
        <p class="event-date"><?= e($days[0]['day_date']) ?></p>
    <?php endif; ?>
    <?php if (opt('msg_below_event') !== ''): // optional admin banner ?>
        <p class="event-msg"><?= e(opt('msg_below_event')) ?></p>
    <?php endif; ?>
</div>

<?php if ((int)$num_days > 1): // multi-day events get a tab per day ?>
    <nav class="day-tabs">
        <?php foreach ($days as $d): ?>
            <?php $i = (int)$d['day_index']; // 1-based day index, used for the active class + link ?>
            <a class="day-tab<?= $i === (int)$active_day ? ' day-tab-active' : '' ?>"
               href="index.php?day=<?= $i ?><?= $tokenQS ?>">
                <?= e(t('day_n', $i)) ?>
                <?php if (!empty($d['day_date'])): ?><span class="day-tab-date"><?= e($d['day_date']) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<div class="tables">
    <?php foreach ($tables as $tbl): // one block per table on the active day ?>
        <section class="table-block">
            <h2 class="table-title"><?= e(t('table_label', (int)$tbl['table_number'])) ?></h2>

            <?php if (empty($tbl['items'])): ?>
                <p class="muted no-games"><?= e(t('no_games')) ?></p>
            <?php else: ?>
                <div class="game-list">
                    <?php foreach ($tbl['items'] as $item): // items are time-sorted games + polls ?>
                        <?php if ($item['type'] === 'poll'): ?>
                            <?php tpl_render('poll_card', ['poll' => $item['data'], 'readonly' => $readonly]); ?>
                        <?php else: ?>
                            <?php tpl_render('game_card', ['g' => $item['data'], 'readonly' => $readonly]); ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($can_add): ?>
                <a class="btn btn-primary add-game-btn"
                   href="add_game.php?table=<?= (int)$tbl['id'] ?>"><?= e(t('add_game')) ?></a>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php // Add-table control, or the "table cap reached" note. ?>
<?php if ($can_add && !$max_reached): ?>
    <form method="post" action="index.php?day=<?= (int)$active_day ?>" class="add-table-form">
        <?= $csrf ?>
        <input type="hidden" name="action" value="add_table">
        <button type="submit" class="btn btn-primary"><?= e(t('add_table')) ?></button>
    </form>
<?php elseif ($can_add && $max_reached): ?>
    <p class="msg muted"><?= e(t('add_table_max')) ?></p>
<?php endif; ?>

<?php // Timeline (only when the day actually has games to plot). ?>
<?php if (!empty($timeline)): ?>
    <?php tpl_render('timeline', ['timeline' => $timeline]); ?>
<?php endif; ?>
