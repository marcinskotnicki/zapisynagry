<?php
/* =============================================================================
 *  templates/light/lib_version_pick.php — choose which edition to add.
 * -----------------------------------------------------------------------------
 *  Shown between "paste a BGG link" and the game landing on the shelf, when BGG
 *  reports more than one published edition. Picking one stores that edition's
 *  cover and, for a localised printing, the name it is actually sold under —
 *  the Polish edition of Petrichor is called Aura.
 *
 *  EVERY OPTION SHOWS THE NAME IT WILL BE SAVED UNDER. BGG gives a version both
 *  a nickname ("Polish edition") and sometimes a real localised title, and which
 *  field carries which has not been verified against a live response. Printing
 *  the outcome means a wrong guess is visible before saving rather than
 *  discovered later as a shelf full of entries called "Polish edition".
 *
 *  One template, two callers (a member's shelf and the club's), so $action and
 *  $back say where to post and where to go back to.
 *
 *  RENDER VARS:
 *    $game     — ['id','name'] the game itself.
 *    $versions — from bgg_parse_versions(), newest first.
 *    $default  — version id to preselect.
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
        <input type="hidden" name="action" value="add_bgg_pick">
        <input type="hidden" name="game_id" value="<?= (int)$game['id'] ?>">

        <ul class="lib-list lib-version-list">
            <?php // "Just the game" first: it is the old behaviour, and someone
                  // who does not care about editions should not have to read the
                  // list to get it. ?>
            <li class="lib-item">
                <label class="lib-version-opt">
                    <input type="radio" name="version" value="0"<?= (int)$default === 0 ? ' checked' : '' ?>>
                    <span class="lib-main">
                        <span class="lib-name"><?= e(t('lib_pick_version_none')) ?></span>
                        <span class="lib-version-as"><?= e(t('lib_pick_version_as', $game['name'])) ?></span>
                    </span>
                </label>
            </li>

            <?php foreach ($versions as $v): ?>
                <?php $title = library_version_title($v, $game['name']); ?>
                <li class="lib-item">
                    <label class="lib-version-opt">
                        <input type="radio" name="version" value="<?= (int)$v['id'] ?>"<?= (int)$default === (int)$v['id'] ? ' checked' : '' ?>>
                        <?php if (!empty($v['thumbnail'])): ?>
                            <img class="lib-thumb" src="<?= e($v['thumbnail']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="lib-main">
                            <span class="lib-name"><?= e(library_version_label($v)) ?></span>
                            <?php // The decisive line: what this option will store. ?>
                            <span class="lib-version-as"><?= e(t('lib_pick_version_as', $title)) ?></span>
                        </span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('lib_add_btn')) ?></button>
            <a class="btn" href="<?= e($back) ?>"><?= e(t('back')) ?></a>
        </div>
    </form>
</div>
