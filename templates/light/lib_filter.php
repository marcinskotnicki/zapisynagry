<?php
/* =============================================================================
 *  templates/light/lib_filter.php — the "filter this list" box.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. The same box the game-picker has always had, made reusable
 *  so every list of games gets it rather than only the one you pick from.
 *
 *  HIDDEN UNTIL THE SCRIPT REVEALS IT (hidden on the wrapper, cleared in
 *  scripts.js). With JavaScript off it would be a search field that does
 *  nothing, which is worse than no field at all — and the full list is
 *  perfectly usable without it.
 *
 *  ONLY WORTH SHOWING ON A COMPLETE LIST. It filters what is on the page and
 *  cannot know about rows on page four, so a paginated list would get a box
 *  that quietly lies about what it searched. The callers check that.
 *
 *  RENDER VARS:
 *    $id     — unique element id; a page may hold more than one of these.
 *    $target — CSS selector for the list to filter.
 * ============================================================================= */
?>
<div class="field club-filter" data-filter-list="<?= e($target) ?>" hidden>
    <label for="<?= e($id) ?>"><?= e(t('lib_filter_label')) ?></label>
    <?php // placeholder=" " is deliberate, not a stray: it makes
          // :placeholder-shown usable, which is how the magnifying glass hides
          // itself once there is text — otherwise it would sit under the
          // browser's own clear button. ?>
    <input type="search" id="<?= e($id) ?>" class="js-club-filter"
           autocomplete="off" placeholder=" ">
    <p class="field-note js-club-filter-none" hidden><?= e(t('lib_filter_none')) ?></p>
</div>
