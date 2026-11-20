<?php
/* =============================================================================
 *  game_item.php — one game in a table's list, deleted ones included.
 * -----------------------------------------------------------------------------
 *  Shared by the tables and by the "deleted games" group (front_event.php), so
 *  a soft-deleted game looks the same wherever it is listed.
 *
 *  A soft-deleted game shown in FULL: the card itself is the ordinary active
 *  one (so it can never drift from the live version, and no theme fork needs
 *  its own copy), wrapped here and forced read-only. A deleted game cannot be
 *  signed up to, edited or commented on, and the readonly path already
 *  suppresses all of that. Shown by NAME only, the card draws its own greyed
 *  stub (game_card.php).
 *
 *  RENDER VARS: $g (game row), $readonly, $table_no (the table NUMBER, for the
 *  themes that colour by table), $origin (optional: "Stół 3" — shown above the
 *  card in the deleted-games group, where the table is no longer obvious).
 * ============================================================================= */
$gDeleted = (int)$g['is_archived'] === 1 && deleted_games_display() === 'full';
?>
<?php // In the deleted-games group: the table it came from, kept together with
      // its card as ONE item, so themes that lay cards out in a grid keep them paired. ?>
<?php if (!empty($origin)): ?>
<div class="deleted-game-item">
    <p class="deleted-game-origin muted"><?= e($origin) ?></p>
<?php endif; ?>
<?php if ($gDeleted): ?>
    <div class="game-deleted">
        <p class="game-deleted-note">
            <?= e(t('game_archived_note')) ?>
            <?php // The way back is offered here rather than inside the card,
                  // which is read-only. ?>
            <?php if (!$readonly): ?>
                <a class="btn btn-small" href="bring_back.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('bringback_button')) ?></a>
                <?php if (is_admin()): ?>
                    <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('game_purge_button')) ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </p>
<?php endif; ?>
<?php tpl_render('game_card', ['g' => $g, 'readonly' => $readonly || $gDeleted, 'table_no' => (int)$table_no]); ?>
<?php if ($gDeleted): ?></div><?php endif; ?>
<?php if (!empty($origin)): ?></div><?php endif; ?>
