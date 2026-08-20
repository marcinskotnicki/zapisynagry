<?php
/* =============================================================================
 *  templates/light/add_poll_own_list.php — pick a poll candidate from the club's shelf.
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
    <h1><?= e(t('addgame_own_title')) ?></h1>

    <?php if (empty($games)): ?>
        <?php // Distinct from "no search results": there is nothing on the
              // shelf at all, and the fix is an admin adding some, not a
              // different search term. ?>
        <p class="muted"><?= e(t('addgame_own_empty')) ?></p>
    <?php else: ?>
        <p class="muted"><?= e(t('addgame_club_intro')) ?></p>
    <?php // Filter, not search: typing hides rows that do not match, so a club
          // with a big cabinet can find a game without paging. Purely local —
          // no request, and with JavaScript off the box simply never appears
          // (it is added by the script) and the full list is still usable. ?>
    <div class="field club-filter" hidden>
        <label for="club_filter"><?= e(t('lib_filter_label')) ?></label>
        <?php // placeholder=" " is deliberate, not a stray: it makes
              // :placeholder-shown usable, which is how the magnifying
              // glass hides itself once there is text — otherwise it
              // would sit under the browser's own clear button. ?>
        <input type="search" id="club_filter" class="js-club-filter"
               autocomplete="off" placeholder=" ">
        <p class="field-note js-club-filter-none" hidden><?= e(t('lib_filter_none')) ?></p>
    </div>

        <ul class="lib-list club-pick-list">
            <?php foreach ($games as $g): ?>
                <li class="lib-item">
                    <a class="club-pick" href="add_poll_game.php?mine=<?= (int)$g['id'] ?>">
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
