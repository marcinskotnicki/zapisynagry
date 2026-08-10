<?php
/* =============================================================================
 *  templates/light/add_poll_club_list.php — pick a poll candidate from the club's shelf.
 * -----------------------------------------------------------------------------
 *  Stands exactly where the BGG search results stand: choose one, and the next
 *  screen is the ordinary candidate form, prefilled. Everything after this point is
 *  unchanged, which is the whole design — this replaces the SEARCH step, not
 *  the form.
 *
 *  Only active games are passed in: one marked as lent out should not be
 *  offered for a table tonight.
 *
 *  RENDER VARS:
 *    $table — the table being added to (for the links and the back button).
 *    $games — the club's active games, alphabetically.
 * ============================================================================= */
?>
<div class="card">
    <h1><?= e(t('addpoll_club_title')) ?></h1>

    <?php if (empty($games)): ?>
        <?php // Distinct from "no search results": there is nothing on the
              // shelf at all, and the fix is an admin adding some, not a
              // different search term. ?>
        <p class="muted"><?= e(t('addgame_club_empty')) ?></p>
    <?php else: ?>
        <p class="muted"><?= e(t('addgame_club_intro')) ?></p>
        <ul class="lib-list club-pick-list">
            <?php foreach ($games as $g): ?>
                <li class="lib-item">
                    <a class="club-pick" href="add_poll_game.php?club=<?= (int)$g['id'] ?>">
                        <?php if (!empty($g['thumbnail'])): ?>
                            <img class="lib-thumb" src="<?= e($g['thumbnail']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="lib-main">
                            <span class="lib-name"><?= e($g['name']) ?>
                                <?php if (!empty($g['year'])): ?>
                                    <span class="lib-year"><?= (int)$g['year'] ?></span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <div class="form-actions">
        <a class="btn" href="add_poll_game.php"><?= e(t('back')) ?></a>
    </div>
</div>
