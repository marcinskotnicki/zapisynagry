<?php
/* =============================================================================
 *  templates/light/my_library.php — a member's own game library.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. The list, then the three ways to add to it.
 *
 *  Each add method is its own <form>, not one form with a mode switch: they
 *  post different fields, and the sync one DELETES games — keeping it separate
 *  means its confirmation checkbox belongs to it alone and cannot be carried
 *  along by a different button.
 *
 *  RENDER VARS:
 *    $games — this member's library rows, already sorted.
 *    $user  — the signed-in member (for the contact preference).
 *    $flash      — one-shot message from the last action, or null.
 *    $flash_kind — 'ok' | 'error' | 'warn', how to draw it.
 *    $csrf  — hidden CSRF field.
 *
 *  The outer container carries BOTH .card (the boxed look every page with one
 *  gets) AND .library, the same scoping hook library.php uses — see that
 *  file's header for why .card alone is too broad a target for a
 *  theme-specific fix.
 * ============================================================================= */
?>
<div class="card library">
    <h1><?= e(t('lib_my_title')) ?></h1>

    <?php // The kind matters: a refusal drawn in the success colour reads as
          // "done" whatever the words say. ?>
    <?php if (!empty($flash)): ?>
        <p class="msg msg-<?= e($flash_kind ?? 'ok') ?>"><?= e($flash) ?></p>
    <?php endif; ?>

    <?php // Optional admin note, rendered only when filled — same treatment as
          // the banner on the event page. ?>
    <?php if (opt_msg('msg_my_library') !== ''): ?>
        <p class="event-msg"><?= e(opt_msg('msg_my_library')) ?></p>
    <?php endif; ?>

    <p class="muted"><?= e(t('lib_my_intro')) ?></p>

    <?php // The contact preference lives here rather than in the general user
          // panel because it is about the library specifically, and only shows
          // when the admin has enabled contacting at all — a checkbox that
          // cannot do anything would just raise questions. ?>
    <?php if (library_contact_enabled()): ?>
        <form method="post" action="my_library.php" class="lib-prefs">
            <?= $csrf ?>
            <input type="hidden" name="action" value="contact_pref">
            <div class="field field-check field-library_contact_ok">
                <label>
                    <input type="checkbox" name="library_contact_ok" value="1"
                        <?= (int)($user['library_contact_ok'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <?= e(t('lib_contact_ok')) ?>
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-small"><?= e(t('save')) ?></button>
            </div>
        </form>
    <?php endif; ?>

    <h2><?= e(t('lib_my_games', count($games))) ?></h2>
    <p class="muted"><?= e(t('lib_inactive_hint')) ?></p>
    <?php if (empty($games)): ?>
        <p class="muted"><?= e(t('lib_my_empty')) ?></p>
    <?php else: ?>
        <ul class="lib-list">
            <?php foreach ($games as $g): ?>
                <?php $lnk = library_link($g); ?>
                <?php $inactive = (int)$g['is_active'] !== 1; ?>
                <li class="lib-item<?= $inactive ? ' lib-item-inactive' : '' ?>">
                    <?php if (!empty($g['thumbnail'])): ?>
                        <img class="lib-thumb" src="<?= e($g['thumbnail']) ?>" alt="">
                    <?php else: ?>
                        <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="lib-name">
                        <?php if ($lnk): ?>
                            <a href="<?= e($lnk) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a>
                        <?php else: ?>
                            <?= e($g['name']) ?>
                        <?php endif; ?>
                        <?php if (!empty($g['year'])): ?>
                            <span class="lib-year"><?= (int)$g['year'] ?></span>
                        <?php endif; ?>
                        <?php // Said plainly on the row, because "why is my game
                              // missing from the library?" is the question this
                              // feature would otherwise create. ?>
                        <?php if ($inactive): ?>
                            <span class="lib-tag-inactive"><?= e(t('lib_inactive_tag')) ?></span>
                        <?php endif; ?>
                    </span>

                    <span class="lib-actions">
                        <?php if (!empty($g['bgg_id'])): ?>
                        <?php // A BGG entry has nothing typeable — its name and year
                              // come from BGG — so its only edit is "this is the
                              // wrong game", answered with a different address. ?>
                        <?php // For a game imported from a BGG collection: pick the edition
                              // and the title without typing anything. BGG entries only —
                              // a hand-added game has no editions to choose from. ?>
                        <form method="post" action="my_library.php" class="lib-pickver">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="pick_version">
                            <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">

                            <button type="submit" class="btn btn-small"><?= e(t('lib_pick_version_btn')) ?></button>
                        </form>
                        <details class="lib-edit">
                            <summary class="btn btn-small"><?= e(t('edit')) ?></summary>
                            <form method="post" action="my_library.php" class="lib-edit-form">
                                <?= $csrf ?>
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                                <?php // A BGG title sometimes comes back in the wrong
                                      // language. Renaming leaves bgg_id alone, so
                                      // the pairing that drives syncing and merging
                                      // still holds — only the label changes. ?>
                                <div class="field field-name">
                                    <label for="lib_n2_<?= (int)$g['id'] ?>"><?= e(t('lib_add_manual_name')) ?></label>
                                    <input type="text" id="lib_n2_<?= (int)$g['id'] ?>" name="name" value="<?= e($g['name']) ?>">
                                </div>
                                <div class="field field-link">
                                    <label for="lib_r_<?= (int)$g['id'] ?>"><?= e(t('lib_relink_label')) ?></label>
                                    <input type="url" id="lib_r_<?= (int)$g['id'] ?>" name="link"
                                           placeholder="https://boardgamegeek.com/boardgame/...">
                                    <p class="field-note"><?= e(t('lib_relink_hint')) ?></p>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-small btn-primary"><?= e(t('save')) ?></button>
                                </div>
                            </form>
                        </details>
                        <?php else: ?>
                            <details class="lib-edit">
                                <summary class="btn btn-small"><?= e(t('edit')) ?></summary>
                                <form method="post" action="my_library.php" class="lib-edit-form">
                                    <?= $csrf ?>
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                                    <div class="field field-name">
                                        <label for="lib_n_<?= (int)$g['id'] ?>"><?= e(t('lib_add_manual_name')) ?></label>
                                        <input type="text" id="lib_n_<?= (int)$g['id'] ?>" name="name" value="<?= e($g['name']) ?>" required>
                                    </div>
                                    <div class="field field-year">
                                        <label for="lib_y_<?= (int)$g['id'] ?>"><?= e(t('lib_year')) ?></label>
                                        <input type="number" id="lib_y_<?= (int)$g['id'] ?>" name="year" min="0" max="2999"
                                               value="<?= !empty($g['year']) ? (int)$g['year'] : '' ?>">
                                    </div>
                                    <?php // A BGG address here promotes the entry: it adopts
                                          // BGG's name, year and art and merges with everyone
                                          // else's copy of the same game. Any other address is
                                          // kept as a plain link. ?>
                                    <?php if (library_link_field_visible()): ?>
                                        <div class="field field-link">
                                            <label for="lib_l_<?= (int)$g['id'] ?>"><?= e(t('lib_link_label')) ?></label>
                                            <input type="url" id="lib_l_<?= (int)$g['id'] ?>" name="link"
                                                   value="<?= e($g['link'] ?? '') ?>" placeholder="https://">
                                            <p class="field-note"><?= e(t('lib_link_hint')) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-small btn-primary"><?= e(t('save')) ?></button>
                                    </div>
                                </form>
                            </details>
                        <?php endif; ?>

                        <form method="post" action="my_library.php" class="lib-toggle">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                            <?php // Posts the state we want, not a flip, so a double
                                  // submit lands on the same result either way. ?>
                            <input type="hidden" name="active" value="<?= $inactive ? '1' : '0' ?>">
                            <button type="submit" class="btn btn-small">
                                <?= $inactive ? e(t('lib_activate')) : e(t('lib_deactivate')) ?>
                            </button>
                        </form>

                        <form method="post" action="my_library.php" class="lib-del">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                            <button type="submit" class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                        </form>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h2><?= e(t('lib_add_title')) ?></h2>

    <details class="lib-add">
        <summary><?= e(t('lib_add_bgg')) ?></summary>
        <form method="post" action="my_library.php">
            <?= $csrf ?>
            <input type="hidden" name="action" value="add_bgg">
            <div class="field field-bgg">
                <label for="bgg"><?= e(t('lib_add_bgg_label')) ?></label>
                <input type="text" id="bgg" name="bgg" placeholder="https://boardgamegeek.com/boardgame/13" required>
                <p class="field-note"><?= e(t('lib_add_bgg_hint')) ?></p>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('lib_add_btn')) ?></button>
            </div>
        </form>
    </details>

    <details class="lib-add">
        <summary><?= e(t('lib_add_manual')) ?></summary>
        <form method="post" action="my_library.php">
            <?= $csrf ?>
            <input type="hidden" name="action" value="add_manual">
            <div class="field-row">
                <div class="field field-name">
                    <label for="lib_name"><?= e(t('lib_add_manual_name')) ?></label>
                    <input type="text" id="lib_name" name="name" required>
                </div>
                <?php // Optional: a year makes two editions of the same title
                      // tellable apart in the shared list. It was editable but
                      // not enterable, which meant adding a game then editing it
                      // just to fill one box. ?>
                <div class="field field-year">
                    <label for="lib_year"><?= e(t('lib_year')) ?></label>
                    <input type="number" id="lib_year" name="year" min="0" max="2999">
                </div>
            </div>
            <?php // Same admin option as everywhere else: with custom links off
                  // the field disappears, and any link already stored stops
                  // being rendered without being destroyed. ?>
            <?php if (opt_bool('allow_custom_game_links')): ?>
                <div class="field field-link">
                    <label for="lib_link"><?= e(t('lib_add_manual_link')) ?></label>
                    <input type="url" id="lib_link" name="link" placeholder="https://">
                </div>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('lib_add_btn')) ?></button>
            </div>
        </form>
    </details>

    <details class="lib-add lib-add-sync">
        <summary><?= e(t('lib_sync')) ?></summary>
        <form method="post" action="my_library.php">
            <?= $csrf ?>
            <input type="hidden" name="action" value="sync">
            <?php // The warning is the point of this block: a sync DELETES games
                  // that are no longer in the BGG collection. Stated before the
                  // field, not after the button. ?>
            <p class="field-warn"><?= e(t('lib_sync_warning')) ?></p>
            <div class="field field-bgg_user">
                <label for="bgg_user"><?= e(t('lib_sync_user_label')) ?></label>
                <input type="text" id="bgg_user" name="bgg_user" placeholder="https://boardgamegeek.com/user/…" required>
                <p class="field-note"><?= e(t('lib_sync_manual_note')) ?></p>
            </div>
            <?php // WHAT the sync is allowed to do, rather than a bare "are you
                  // sure". Only the last option deletes anything, so the two safe
                  // ones no longer make people tick a warning about removal that
                  // does not apply to them. ?>
            <div class="field field-syncmode">
                <label for="ml_sync_mode"><?= e(t('lib_sync_mode')) ?></label>
                <select id="ml_sync_mode" name="mode">
                    <?php foreach (library_sync_modes() as $sm): ?>
                        <option value="<?= e($sm) ?>"<?= $sm === 'add' ? ' selected' : '' ?>>
                            <?= e(t('lib_sync_mode_' . $sm)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="field-note"><?= e(t('lib_sync_mode_note')) ?></p>
            </div>
            <?php // Still required, but only the deleting option is worth confirming;
                  // the script unticks and hides this for the other two. ?>
            <div class="field field-check field-confirm js-sync-confirm" data-danger-mode="full">
                <label>
                    <input type="checkbox" name="confirm" value="1" required>
                    <?= e(t('lib_sync_confirm')) ?>
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= e(t('lib_sync_btn')) ?></button>
            </div>
        </form>
    </details>

    <div class="form-actions">
        <a class="btn" href="user.php"><?= e(t('lib_back_to_panel')) ?></a>
    </div>
</div>
