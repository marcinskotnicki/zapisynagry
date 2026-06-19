<?php
/* =============================================================================
 *  templates/light/add_game_bgg_list.php — BGG search results. Presentation.
 * -----------------------------------------------------------------------------
 *  A list of search hits; each links to the prefilled add form for that BGG id.
 *  REUSED for poll candidates by passing $link_base='add_poll_game.php' so the
 *  links carry the chosen game into the poll flow instead of the game flow.
 *
 *  RENDER VARS:
 *    $table     — the target table (id threaded into every link).
 *    $query     — the text that was searched (echoed in the subheading).
 *    $results   — list of ['id','name','year','thumbnail'] (thumbnail may be '').
 *    $link_base — target script for each result (default 'add_game.php').
 * ============================================================================= */
$link_base = $link_base ?? 'add_game.php';   // where a chosen result leads
?>
<div class="card">
    <h1><?= e(t('addgame_bgg_pick')) ?></h1>
    <p class="muted"><?= e(t('addgame_bgg_for', $query)) ?></p>

    <?php if (empty($results)): // nothing matched ?>
        <p class="msg muted"><?= e(t('addgame_search_none')) ?></p>
    <?php else: ?>
        <ul class="bgg-list">
            <?php foreach ($results as $r): ?>
                <li>
                    <a href="<?= e($link_base) ?>?table=<?= (int)$table['id'] ?>&amp;id=<?= (int)$r['id'] ?>">
                        <?php if (!empty($r['thumbnail'])): // only short result sets carry thumbnails ?>
                            <img src="<?= e($r['thumbnail']) ?>" alt="">
                        <?php endif; ?>
                        <span class="bgg-name"><?= e($r['name']) ?></span>
                        <?php if ($r['year'] !== ''): ?>
                            <span class="bgg-year">(<?= e($r['year']) ?>)</span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="muted">
        <a href="<?= e($link_base) ?>?table=<?= (int)$table['id'] ?>"><?= e(t('back')) ?></a>
    </p>
</div>
