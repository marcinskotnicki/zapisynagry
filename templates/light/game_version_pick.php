<?php
/* =============================================================================
 *  templates/light/game_version_pick.php — pick an edition, inline on the form.
 * -----------------------------------------------------------------------------
 *  Sits under the game name field on the add-a-game and add-a-candidate forms.
 *  A button reveals it; choosing an edition and pressing OK writes that
 *  edition's title into the name box (and its cover into the hidden thumbnail
 *  field) and hides the panel again.
 *
 *  ENTIRELY CLIENT-SIDE once rendered. Every edition's title and cover ride
 *  along as data- attributes, so pressing OK does not reload the form and lose
 *  what the proposer has already typed into the other fields — which is the
 *  whole reason this is not the library's separate chooser page.
 *
 *  NOT A NESTED FORM. This is inside the game form already, so the controls are
 *  plain buttons with type="button": a <button> without that inside a form
 *  submits it, which here would post a half-filled game.
 *
 *  RENDER VARS:
 *    $versions  — from bgg_parse_versions(), newest first.
 *    $game_name — the game's own title, for the "no particular edition" row.
 *    $game_thumb— the game's own cover, used for editions BGG has no art for.
 * ============================================================================= */
?>
<div class="field field-versionpick">
    <button type="button" class="btn btn-small js-verpick-open"><?= e(t('gvp_button')) ?></button>

    <div class="verpick" hidden>
        <p class="field-note"><?= e(t('gvp_intro')) ?></p>
        <ul class="lib-list verpick-list">
            <?php // "No particular edition" first and preselected: it leaves the
                  // name exactly as BGG gave it, which is what most proposers
                  // want and what happens today. ?>
            <li class="lib-item">
                <label class="lib-version-opt">
                    <input type="radio" name="verpick" value="0" checked
                           data-title="<?= e($game_name) ?>" data-thumb="<?= e($game_thumb) ?>">
                    <?php if ($game_thumb !== ''): ?>
                        <img class="lib-thumb" src="<?= e($game_thumb) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="lib-main">
                        <span class="lib-name"><?= e($game_name) ?></span>
                        <span class="lib-version-as"><?= e(t('lib_pick_version_none')) ?></span>
                    </span>
                </label>
            </li>

            <?php foreach ($versions as $v): ?>
                <?php
                $vTitle = $v['title'] !== '' ? $v['title'] : $game_name;
                // An edition with no art of its own shows the game's, which is
                // what it would end up with anyway.
                $vThumb = $v['thumbnail'] !== '' ? $v['thumbnail'] : $game_thumb;
                ?>
                <li class="lib-item">
                    <label class="lib-version-opt">
                        <input type="radio" name="verpick" value="<?= (int)$v['id'] ?>"
                               data-title="<?= e($vTitle) ?>" data-thumb="<?= e($vThumb) ?>">
                        <?php if ($vThumb !== ''): ?>
                            <img class="lib-thumb" src="<?= e($vThumb) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="lib-main">
                            <span class="lib-name"><?= e($vTitle) ?></span>
                            <span class="lib-version-as"><?= e(library_version_label($v)) ?></span>
                        </span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="form-actions">
            <button type="button" class="btn btn-small btn-primary js-verpick-ok"><?= e(t('gvp_ok')) ?></button>
            <button type="button" class="btn btn-small js-verpick-cancel"><?= e(t('cancel')) ?></button>
        </div>
    </div>
</div>
