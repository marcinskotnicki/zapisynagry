<?php
/* =============================================================================
 *  templates/light/add_game_bgg_list.php — BGG search results. Presentation.
 * -----------------------------------------------------------------------------
 *  A list of search hits; each links to the prefilled add form for that BGG id.
 *  REUSED for poll candidates by passing $link_base='add_poll_game.php' so the
 *  links carry the chosen game into the poll flow instead of the game flow.
 *
 *  RENDER VARS:
 *    $problem   — 'empty' (nothing typed) | 'unconfigured' (no BGG API code) |
 *                 null (a real search); decides which message replaces the list.
 *    $table     — the target table (id threaded into every link).
 *    $query     — the text that was searched (echoed in the subheading).
 *    $results   — list of ['id','name','year','thumbnail'] (thumbnail may be '').
 *    $link_base — target script for each result (default 'add_game.php').
 * ============================================================================= */
$link_base = $link_base ?? 'add_game.php';   // where a chosen result leads
?>
<?php $problem = $problem ?? null;   // 'empty' | 'unconfigured' | null ?>
<div class="card">
    <h1><?= e(t('addgame_bgg_pick')) ?></h1>
    <?php if ($problem === null): // echoing an empty search back reads oddly ?>
        <p class="muted"><?= e(t('addgame_bgg_for', $query)) ?></p>
    <?php endif; ?>

    <?php // An empty result list has three very different causes and each needs
          // its own wording — "nothing found" for all of them made a missing API
          // code look like the game not existing on BGG. ?>
    <?php if ($problem === 'empty'): ?>
        <p class="msg msg-error"><?= e(t('addgame_search_empty')) ?></p>
    <?php elseif ($problem === 'unconfigured'): ?>
        <p class="msg msg-error"><?= e(t('addgame_search_nokey')) ?></p>
    <?php elseif (empty($results)): // a real search that matched nothing ?>
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
        <?php // Same grey .btn as every other cancel/back control on the site. ?>
        <a class="btn" href="<?= e($link_base) ?>?table=<?= (int)$table['id'] ?>"><?= e(t('back')) ?></a>
    </p>
</div>
