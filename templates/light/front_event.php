<?php
/* =============================================================================
 *  templates/light/front_event.php — the event front page body.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. The main event view: title, optional day tabs (multi-day
 *  events), each table with its interleaved game/poll cards, the add-table
 *  control (or "cap reached" note). The timeline is rendered separately by
 *  index.php through the footer's full-width slot, not by this view.
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
 *    $can_set_names  — show the optional table-name input on the add form.
 *    $can_edit_names — show the tiny rename button on each table block.
 *    $rename_table   — table id whose inline rename form is open (0 = none).
 *    $csrf        — hidden CSRF field (for the add-table form).
 * ============================================================================= */
// In read-only mode every internal link must keep the share token so the viewer
// stays inside the archived event.
// Whatever identifies the event we are LOOKING at, so every in-page link
// (day tabs, table forms, rename links) stays on it. Without this, switching
// day from a non-current event would silently bounce back to the live one.
// The share token wins when present: that view is reached without an account
// and must keep working the same way.
$tokenQS = $readonly && !empty($event['access_token'])
    ? ('&e=' . urlencode($event['access_token']))
    : '';
if ($tokenQS === '' && public_archives_enabled()
    && (int)($_GET['event'] ?? 0) === (int)$event['id']) {
    $tokenQS = '&event=' . (int)$event['id'];
}
?>
<?php // Event switcher: only when public archives are on, and only when there
      // is more than one event worth offering — a single tab pointing at the
      // page you are already on is noise. Horizontally scrollable rather than
      // wrapping, so a club with many upcoming dates keeps one tidy strip. ?>
<?php if (!empty($event_tabs) && count($event_tabs) > 1): ?>
    <?php // Reuses the DAY-tab classes on purpose. Eight of the ten themes
          // restyle .day-tab / .day-tab-active / .day-tab-date heavily; giving
          // the event switcher its own class names would have left it looking
          // like a stray grey box on cork, parchment, graphite and the rest
          // until every theme was updated separately. The two are the same
          // kind of control — a row of things you pick between — so sharing the
          // styling is honest rather than a shortcut.
          //
          // .event-tabs / .event-tab stay ALONGSIDE them, carrying only what
          // differs: horizontal scrolling instead of wrapping, since there can
          // be many more events than days. ?>
    <div class="tab-scroll">
    <nav class="day-tabs event-tabs" aria-label="<?= e(t('event_switch')) ?>">
        <?php foreach ($event_tabs as $et): ?>
            <?php $isHere = (int)$et['id'] === (int)$event['id']; ?>
            <a class="day-tab event-tab<?= $isHere ? ' day-tab-active' : '' ?>"
               href="index.php?event=<?= (int)$et['id'] ?>"
               <?= $isHere ? 'aria-current="page"' : '' ?>>
                <span class="event-tab-name"><?= e($et['name']) ?></span>
                <?php $etLabel = event_date_label($et); ?>
                <?php if ($etLabel !== ''): ?>
                    <span class="day-tab-date"><?= e($etLabel) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    </div>
<?php endif; ?>
<div class="event-header">
    <h1><?= e($event['name']) ?></h1>
    <?php if ((int)$num_days === 1 && !empty($days[0]['day_date'])): // single-day: show the date inline ?>
        <p class="event-date"><?= e($days[0]['day_date']) ?></p>
    <?php endif; ?>
    <?php // Opening hours, under the date. Deliberately NOT tied to the date being
          // set: a one-day event with no date still has hours worth stating. ?>
    <?php if ((int)$num_days === 1 && ($hrs = day_hours_label($days[0] ?? null)) !== ''): ?>
        <p class="event-hours"><?= e($hrs) ?></p>
    <?php endif; ?>
    <?php if (opt_msg('msg_below_event') !== ''): // optional admin banner ?>
        <p class="event-msg"><?= e(opt_msg('msg_below_event')) ?></p>
    <?php endif; ?>
</div>

<?php if ((int)$num_days > 1): // multi-day events get a tab per day ?>
    <?php // Wrapper exists ONLY so the scroll arrow has something to anchor to:
          // the <nav> itself is the scrolling box, so anything positioned
          // inside it scrolls away with the tabs. See initTabScroll(). ?>
    <div class="tab-scroll">
    <?php // The chosen layout rides on the container so CSS can size the same
          // piece differently per format — the day number is the largest thing
          // in the default layout and the smallest in the weekday one. ?>
    <nav class="day-tabs fmt-<?= e(opt('day_tab_format')) ?>">
        <?php foreach ($days as $d): ?>
            <?php $i = (int)$d['day_index']; // 1-based day index, used for the active class + link ?>
            <a class="day-tab<?= $i === (int)$active_day ? ' day-tab-active' : '' ?>"
               href="index.php?day=<?= $i ?><?= $tokenQS ?>">
                <?php // Which pieces appear, and in what order, is the admin's
                      // 'day_tab_format' choice — assembled by day_tab_parts()
                      // so the six layouts live in one place rather than as
                      // branches here. Empty pieces are already dropped, and the
                      // day number is used if a layout would leave nothing. ?>
                <?php foreach (day_tab_parts($d) as $part): ?>
                    <span class="<?= e($part['class']) ?>"><?= e($part['text']) ?></span>
                <?php endforeach; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    </div>
<?php endif; ?>

<div class="tables">
    <?php foreach ($tables as $tbl): // one block per table on the active day ?>
        <?php // id anchor: the rename edit/cancel links and the post-save redirect
              // append #table-<id> so the browser jumps back here instead of the
              // page top (these are full navigations, not AJAX). ?>
        <section class="table-block" id="table-<?= (int)$tbl['id'] ?>">
            <?php if ((int)$rename_table === (int)$tbl['id']): // this table's inline rename form is open ?>
                <form method="post" action="index.php?day=<?= (int)$active_day ?><?= $tokenQS ?>" class="table-rename-form">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="rename_table">
                    <input type="hidden" name="table_id" value="<?= (int)$tbl['id'] ?>">
                    <span class="table-title"><?= e(t('table_label', (int)$tbl['table_number'])) ?></span>
                    <input type="text" name="table_name" value="<?= e($tbl['table_name'] ?? '') ?>"
                           placeholder="<?= e(t('table_name_label')) ?>" maxlength="100">
                    <button type="submit" class="btn btn-small"><?= e(t('save')) ?></button>
                    <a class="btn btn-small" href="index.php?day=<?= (int)$active_day ?><?= $tokenQS ?>#table-<?= (int)$tbl['id'] ?>"><?= e(t('cancel')) ?></a>
                </form>
            <?php else: ?>
                <h2 class="table-title">
                    <?= e(t('table_label', (int)$tbl['table_number'])) ?>
                    <?php if (!empty($tbl['table_name']) && table_names_enabled()): // optional label in smaller print after the number ?>
                        <span class="table-name"><?= e($tbl['table_name']) ?></span>
                    <?php endif; ?>
                </h2>
                <?php if ($can_edit_names): // tiny corner button -> reload with the inline form open ?>
                    <a class="table-rename-btn" title="<?= e(t('table_rename')) ?>"
                       href="index.php?day=<?= (int)$active_day ?><?= $tokenQS ?>&rename_table=<?= (int)$tbl['id'] ?>#table-<?= (int)$tbl['id'] ?>">&#9998;</a>
                <?php endif; ?>
                <?php /* Remove a table nobody has put anything on. Admins only, and
                       * only when it is COMPLETELY empty — table_is_empty() counts
                       * rows rather than looking at the rendered list, so a table
                       * still holding a soft-deleted game keeps its button hidden.
                       * Deleting cascades, and that history would go with it.
                       *
                       * The confirm is worth having even though nothing is lost:
                       * it sits one pixel from the rename button. */ ?>
                <?php if (!empty($can_del_tables) && table_is_empty((int)$tbl['id'])): ?>
                    <form method="post" action="index.php?day=<?= (int)$active_day ?><?= $tokenQS ?>"
                          class="table-del-form"
                          onsubmit="return confirm('<?= e(t('table_delete_confirm')) ?>');">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="delete_table">
                        <input type="hidden" name="table_id" value="<?= (int)$tbl['id'] ?>">
                        <button type="submit" class="table-del-btn" title="<?= e(t('table_delete')) ?>">&times;</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($tbl['items'])): ?>
                <p class="muted no-games"><?= e(t('no_games')) ?></p>
            <?php else: ?>
                <div class="game-list">
                    <?php foreach ($tbl['items'] as $item): // items are time-sorted games + polls ?>
                        <?php if ($item['type'] === 'poll'): ?>
                            <?php tpl_render('poll_card', ['poll' => $item['data'], 'readonly' => $readonly, 'table_no' => (int)$tbl['table_number']]); ?>
                        <?php else: ?>
                            <?php // table_no is passed for themes that key a colour off the
                                  // table (schematic). Inert everywhere else — an unused
                                  // render var costs nothing. It is the table NUMBER, not
                                  // its row id, so the key matches what the visitor sees
                                  // and what the timeline can derive. ?>
                            <?php
                            // A soft-deleted game shown in FULL: the card itself is
                            // the ordinary active one (so it can never drift from
                            // the live version, and no theme fork needs its own
                            // copy), wrapped here and forced read-only. A deleted
                            // game cannot be signed up to, edited or commented on,
                            // and the readonly path already suppresses all of that.
                            $gDeleted = (int)$item['data']['is_archived'] === 1
                                     && deleted_games_display() === 'full';
                            ?>
                            <?php if ($gDeleted): ?>
                                <div class="game-deleted">
                                    <p class="game-deleted-note">
                                        <?= e(t('game_archived_note')) ?>
                                        <?php // The way back is offered here rather than
                                              // inside the card, which is read-only. ?>
                                        <?php if (!$readonly): ?>
                                            <a class="btn btn-small" href="bring_back.php?game=<?= (int)$item['data']['id'] ?>" rel="nofollow"><?= e(t('bringback_button')) ?></a>
                                            <?php if (is_admin()): ?>
                                                <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$item['data']['id'] ?>" rel="nofollow"><?= e(t('game_purge_button')) ?></a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </p>
                            <?php endif; ?>
                            <?php tpl_render('game_card', ['g' => $item['data'], 'readonly' => $readonly || $gDeleted, 'table_no' => (int)$tbl['table_number']]); ?>
                            <?php if ($gDeleted): ?></div><?php endif; ?>
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
    <form method="post" action="index.php?day=<?= (int)$active_day ?><?= $tokenQS ?>" class="add-table-form">
        <?= $csrf ?>
        <input type="hidden" name="action" value="add_table">
        <?php if ($can_set_names): // optional table name, honoured server-side by the same gate ?>
            <input type="text" name="table_name" placeholder="<?= e(t('table_name_label')) ?>" maxlength="100">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= e(t('add_table')) ?></button>
    </form>
<?php elseif ($can_add && $max_reached): ?>
    <p class="msg muted"><?= e(t('add_table_max')) ?></p>
<?php endif; ?>
<?php // NOTE: the timeline is NOT rendered here. index.php captures the timeline
      // template and passes it to the footer's $after_content slot, so it sits
      // outside the width-capped .content column (full page width). ?>
