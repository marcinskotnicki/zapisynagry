<?php
/* =============================================================================
 *  templates/light/admin_club_shelf.php — the club's own game collection.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. The list, then the three ways to add to it — deliberately
 *  the same shape as a member's own library page (templates/light/my_library.php),
 *  because it is the same job done by an admin on the club's behalf.
 *
 *  Each add method is its own <form>, not one form with a mode switch: they post
 *  different fields, and the sync one DELETES games — keeping it separate means
 *  its confirmation checkbox belongs to it alone and cannot be carried along by
 *  a different button.
 *
 *  EDITING AND HIDING ARE NOT HERE. Those controls sit beside each game on the
 *  public Library page's club tab, where an admin already is when they notice a
 *  wrong entry. A second copy of the list here would be one more thing to keep
 *  in step. The link below points at it.
 *
 *  RENDER VARS:
 *    $csrf  — hidden CSRF field.
 *    $games — every club game, including hidden ones.
 * ============================================================================= */
?>
<h2><?= e(t('club_shelf_title')) ?></h2>
<p class="muted"><?= e(t('club_shelf_intro')) ?></p>

<h3><?= e(t('lib_my_games', count($games))) ?></h3>
<?php if (empty($games)): ?>
    <p class="muted"><?= e(t('lib_club_empty')) ?></p>
<?php else: ?>
    <ul class="lib-list">
        <?php foreach ($games as $g): ?>
            <?php
            $lnk = library_link($g);
            $inactive = (int)$g['is_active'] !== 1;
            ?>
            <li class="lib-item<?= $inactive ? ' lib-item-inactive' : '' ?>">
                <?php if (!empty($g['thumbnail'])): ?>
                    <img class="lib-thumb" src="<?= e($g['thumbnail']) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
                <?php endif; ?>
                <span class="lib-main">
                    <span class="lib-name">
                        <?php if ($lnk): ?>
                            <a href="<?= e($lnk) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a>
                        <?php else: ?>
                            <?= e($g['name']) ?>
                        <?php endif; ?>
                        <?php if (!empty($g['year'])): ?>
                            <span class="lib-year"><?= (int)$g['year'] ?></span>
                        <?php endif; ?>
                        <?php // Said plainly, because "why is that game missing
                              // from the club library?" is the question this
                              // would otherwise create. ?>
                        <?php if ($inactive): ?>
                            <span class="lib-tag-inactive"><?= e(t('lib_inactive_tag')) ?></span>
                        <?php endif; ?>
                    </span>
                </span>
                <span class="lib-actions">
                    <?php // For a game imported from a BGG collection: pick the
                          // edition and the title without typing anything. BGG
                          // entries only — a hand-added game has no editions. ?>
                    <?php if (!empty($g['bgg_id'])): ?>
                        <form method="post" action="admin.php?tab=club_shelf" class="lib-pickver">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="pick_version">
                            <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                            <button type="submit" class="btn btn-small"><?= e(t('lib_pick_version_btn')) ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="admin.php?tab=club_shelf" class="lib-del">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                    </form>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php // Where the per-game edit and hide controls live. Stated rather than
          // duplicated, so there is only ever one list to keep correct. ?>
    <p class="field-note">
        <?= e(t('club_shelf_manage_note')) ?>
        <a href="library.php?tab=club"><?= e(t('club_shelf_open_public')) ?></a>
    </p>
<?php endif; ?>

<h3><?= e(t('lib_add_title')) ?></h3>

<?php // No BGG code, no BGG routes — see my_library.php. ?>
<?php if (bgg_configured()): ?>
<?php // Search by name, above the paste-a-link block: easier of the two, and
      // both end in the same add. ?>
<details class="lib-add">
    <summary><?= e(t('lib_search_bgg')) ?></summary>
    <form class="js-busy" method="post" action="admin.php?tab=club_shelf">
        <?= $csrf ?>
        <input type="hidden" name="action" value="search_bgg">
        <div class="field field-bgg">
            <label for="cs_q"><?= e(t('lib_search_bgg_label')) ?></label>
            <input type="text" id="cs_q" name="q" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-busy-label="<?= e(t('lib_search_working')) ?>"><?= e(t('lib_search_btn')) ?></button>
        </div>
    </form>
</details>

<details class="lib-add">
    <summary><?= e(t('lib_add_bgg')) ?></summary>
    <form method="post" action="admin.php?tab=club_shelf">
        <?= $csrf ?>
        <input type="hidden" name="action" value="add_bgg">
        <div class="field field-bgg">
            <label for="cs_bgg"><?= e(t('lib_add_bgg_label')) ?></label>
            <input type="text" id="cs_bgg" name="bgg" placeholder="https://boardgamegeek.com/boardgame/13" required>
            <p class="field-note"><?= e(t('lib_add_bgg_hint')) ?></p>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('lib_add_btn')) ?></button>
        </div>
    </form>
</details>
<?php endif; ?>

<details class="lib-add">
    <summary><?= e(t('lib_add_manual')) ?></summary>
    <form method="post" action="admin.php?tab=club_shelf">
        <?= $csrf ?>
        <input type="hidden" name="action" value="add_manual">
        <div class="field-row">
            <div class="field field-name">
                <label for="cs_name"><?= e(t('lib_add_manual_name')) ?></label>
                <input type="text" id="cs_name" name="name" required>
            </div>
            <div class="field field-year">
                <label for="cs_year"><?= e(t('lib_year')) ?></label>
                <input type="number" id="cs_year" name="year" min="0" max="2999">
            </div>
        </div>
        <?php // Same admin option as everywhere else: with custom links off the
              // field disappears, and any link already stored stops being
              // rendered without being destroyed. ?>
        <?php if (library_link_field_visible()): ?>
            <div class="field field-link">
                <label for="cs_link"><?= e(t('lib_add_manual_link')) ?></label>
                <input type="url" id="cs_link" name="link" placeholder="https://">
            </div>
        <?php endif; ?>
        <?php /* A picture for a game BGG has no record of — admin uploads only,
                 the same picker the manual add-game form uses. Hidden when none
                 have been uploaded rather than shown empty. */ ?>
        <?php $csThumbs = db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'); ?>
        <?php if (!empty($csThumbs)): ?>
            <div class="field field-thumbnail">
                <label><?= e(t('f_thumbnail')) ?></label>
                <div class="thumb-picker">
                    <label class="thumb-opt">
                        <input type="radio" name="thumbnail" value="" checked>
                        <span class="thumb-none-box"><?= e(t('no')) ?></span>
                    </label>
                    <?php foreach ($csThumbs as $tn): ?>
                        <label class="thumb-opt">
                            <input type="radio" name="thumbnail" value="<?= e($tn['filename']) ?>">
                            <img src="<?= e($tn['filename']) ?>" alt="">
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('lib_add_btn')) ?></button>
        </div>
    </form>
</details>

<?php // Nothing to sync against without a code. ?>
<?php if (bgg_configured()): ?>
<details class="lib-add lib-add-sync">
    <summary><?= e(t('lib_sync')) ?></summary>
    <form class="js-busy" method="post" action="admin.php?tab=club_shelf">
        <?= $csrf ?>
        <input type="hidden" name="action" value="sync">
        <?php // The warning is the point of this block: a sync DELETES games that
              // are no longer in the BGG collection. Stated before the field, not
              // after the button. ?>
        <?php /* One paragraph per mode, all rendered and all but the current one
              * hidden by the script. Rendered rather than swapped in by JS so the
              * wording still comes from the language files, and so a browser with
              * no JS shows the ADD text — which matches the mode the form is
              * preselected to, rather than warning about deletions that will not
              * happen. */ ?>
        <div class="js-sync-warn">
            <?php foreach (library_sync_modes() as $swMode): ?>
                <p class="field-warn" data-mode="<?= e($swMode) ?>"<?= $swMode === 'add' ? '' : ' hidden' ?>>
                    <?= e(t('club_shelf_sync_warn_' . $swMode)) ?>
                </p>
            <?php endforeach; ?>
        </div>
        <div class="field field-bgg_user">
            <label for="cs_bgg_user"><?= e(t('club_shelf_sync_user_label')) ?></label>
            <input type="text" id="cs_bgg_user" name="bgg_user" placeholder="https://boardgamegeek.com/user/…" required>
            <p class="field-note"><?= e(t('lib_sync_manual_note')) ?></p>
        </div>
        <?php // WHAT the sync is allowed to do, rather than a bare "are you
              // sure". Only the last option deletes anything, so the two safe
              // ones no longer make people tick a warning about removal that
              // does not apply to them. ?>
        <div class="field field-syncmode">
            <label for="cs_sync_mode"><?= e(t('lib_sync_mode')) ?></label>
            <select id="cs_sync_mode" name="mode">
                <?php foreach (library_sync_modes() as $sm): ?>
                    <option value="<?= e($sm) ?>"<?= $sm === 'add' ? ' selected' : '' ?>>
                        <?= e(t('lib_sync_mode_' . $sm)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php // Untrue of a purge, which overwrites everything by design —
                  // so the script hides it for that mode. ?>
            <p class="field-note js-sync-fillnote"><?= e(t('lib_sync_mode_note')) ?></p>
        </div>
        <?php // Still required, but only the deleting option is worth confirming;
              // the script unticks and hides this for the other two. ?>
        <div class="field field-check field-confirm js-sync-confirm"
             data-danger-modes="<?= e(implode(' ', library_sync_destructive_modes())) ?>">
            <label>
                <input type="checkbox" name="confirm" value="1" required>
                <?= e(t('lib_sync_confirm')) ?>
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" data-busy-label="<?= e(t('lib_sync_working')) ?>"><?= e(t('lib_sync_btn')) ?></button>
        </div>
    </form>
</details>
<?php endif; ?>
