<?php
/* =============================================================================
 *  templates/light/lib_bgg_search.php — BGG search results for a library.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. The shelf equivalent of add_game_bgg_list.php, and it
 *  exists separately for one reason: that template's hits are LINKS carrying a
 *  table id, and a shelf has no table. Here each hit is a small POST instead,
 *  which also keeps adding a game behind the CSRF check rather than making it
 *  a GET a crawler could follow.
 *
 *  Each hit posts action=add_bgg with the BGG id in the 'bgg' field — exactly
 *  what pasting an address produces, since library_entry_from_bgg_input()
 *  accepts a bare id. So a searched game goes through the same edition chooser,
 *  the same duplicate handling and the same everything as a pasted one; this
 *  screen only chooses WHICH id gets posted.
 *
 *  SHARED by a member's own shelf, an admin viewing somebody else's, and the
 *  club shelf — hence $action/$back rather than a hardcoded target.
 *
 *  RENDER VARS:
 *    $query     — what was searched (echoed in the subheading).
 *    $results   — hits: ['id','name','year','thumbnail'] (thumbnail may be '').
 *    $problem   — 'empty' | 'unconfigured' | null; decides which message shows.
 *    $action    — where a chosen hit posts.
 *    $back      — where the back button goes.
 *    $uid_field — hidden user field in admin mode, '' otherwise.
 *    $csrf      — hidden CSRF field.
 * ============================================================================= */
$problem   = $problem ?? null;
$uid_field = $uid_field ?? '';
?>
<div class="card">
    <h1><?= e(t('addgame_bgg_pick')) ?></h1>
    <?php if ($problem === null): // echoing an empty search back reads oddly ?>
        <p class="muted"><?= e(t('addgame_bgg_for', $query)) ?></p>
    <?php endif; ?>

    <?php // Three different reasons for an empty list, three different messages:
          // a missing API code must not read as "that game isn't on BGG". Same
          // wording as the add-game search, deliberately. ?>
    <?php if ($problem === 'empty'): ?>
        <p class="msg msg-error"><?= e(t('addgame_search_empty')) ?></p>
    <?php elseif ($problem === 'unconfigured'): ?>
        <p class="msg msg-error"><?= e(t('addgame_search_nokey')) ?></p>
    <?php elseif (empty($results)): // a real search that matched nothing ?>
        <p class="msg muted"><?= e(t('addgame_search_none')) ?></p>
    <?php else: ?>
        <ul class="bgg-list bgg-list-form">
            <?php foreach ($results as $r): ?>
                <li>
                    <form method="post" action="<?= e($action) ?>">
                        <?= $csrf ?><?= $uid_field ?>
                        <input type="hidden" name="action" value="add_bgg">
                        <?php // The id, not a link: the same field a pasted
                              // address fills, so both paths converge here. ?>
                        <input type="hidden" name="bgg" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="bgg-hit">
                            <?php if (!empty($r['thumbnail'])): // only short result sets carry thumbnails ?>
                                <img src="<?= e($r['thumbnail']) ?>" alt="">
                            <?php endif; ?>
                            <span class="bgg-name"><?= e($r['name']) ?></span>
                            <?php if ($r['year'] !== ''): ?>
                                <span class="bgg-year">(<?= e($r['year']) ?>)</span>
                            <?php endif; ?>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="muted">
        <a class="btn" href="<?= e($back) ?>"><?= e(t('back')) ?></a>
    </p>
</div>
