<?php
/* =============================================================================
 *  templates/light/lib_version_pick.php — choose an edition and a title.
 * -----------------------------------------------------------------------------
 *  Shown when BGG reports more than one published edition of a game. Two
 *  choices, because BGG keeps them in two different places:
 *
 *    EDITION  — from the game's versions block. Sets the cover (and year).
 *               Its entries are NICKNAMES ("Polish edition"), not titles.
 *    TITLE    — from the game's own names. This is where a localised title
 *               lives ("Aura" for the Polish printing of Petrichor), and it is
 *               offered separately rather than inferred from the edition,
 *               because nothing in the data links the two.
 *
 *  "No particular edition" leads and is preselected: it adds the game exactly
 *  as BGG has it, which is what somebody who does not care wants, and it needs
 *  no guessing about which edition anyone meant.
 *
 *  Used both when ADDING a game and when correcting one already on a shelf, so
 *  $row_id is set in the second case and the form says which it is.
 *
 *  RENDER VARS:
 *    $game     — ['id','name'] the game itself; each edition carries its own title.
 *    $versions — from bgg_parse_versions(), newest first.
 *    $row_id   — an existing shelf row to update, or 0 when adding.
 *    $action   — form target.
 *    $back     — cancel link.
 *    $csrf     — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card library">
    <h1><?= e(t('lib_pick_version_title')) ?></h1>
    <p class="muted"><?= e(t('lib_pick_version_intro', $game['name'])) ?></p>

    <form method="post" action="<?= e($action) ?>">
        <?= $csrf ?>
        <input type="hidden" name="action" value="<?= !empty($row_id) ? 'set_version' : 'add_bgg_pick' ?>">
        <input type="hidden" name="game_id" value="<?= (int)$game['id'] ?>">
        <?php if (!empty($row_id)): ?>
            <input type="hidden" name="game" value="<?= (int)$row_id ?>">
            <?php if (!empty($scope)): ?>
                <input type="hidden" name="scope" value="<?= e($scope) ?>">
            <?php endif; ?>
        <?php endif; ?>

        <?php // No separate title control: each edition carries its own title
              // (BGG's canonicalname), so picking one answers both questions at
              // once. A dropdown here would be a second, conflicting way to set
              // the same field. ?>
        <ul class="lib-list lib-version-list">
            <li class="lib-item">
                <label class="lib-version-opt">
                    <input type="radio" name="version" value="0" checked>
                    <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
                    <span class="lib-main">
                        <span class="lib-name"><?= e(t('lib_pick_version_none')) ?></span>
                        <span class="lib-version-as"><?= e(t('lib_pick_version_none_note')) ?></span>
                    </span>
                </label>
            </li>

            <?php foreach ($versions as $v): ?>
                <li class="lib-item">
                    <label class="lib-version-opt">
                        <input type="radio" name="version" value="<?= (int)$v['id'] ?>">
                        <?php if (!empty($v['thumbnail'])): ?>
                            <img class="lib-thumb" src="<?= e($v['thumbnail']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="lib-main">
                            <?php // The TITLE leads, because it is the string that
                                  // will be stored — a Polish printing shows as
                                  // "Aura", not "Polish edition". The nickname,
                                  // year and language sit under it as the things
                                  // that tell two editions apart. ?>
                            <span class="lib-name"><?= e($v['title'] !== '' ? $v['title'] : $game['name']) ?></span>
                            <span class="lib-version-as"><?= e(library_version_label($v)) ?></span>
                        </span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(!empty($row_id) ? t('save') : t('lib_add_btn')) ?></button>
            <a class="btn" href="<?= e($back) ?>"><?= e(t('back')) ?></a>
        </div>
    </form>
</div>
