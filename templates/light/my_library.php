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
 *    $flash — one-shot message from the last action, or null.
 *    $csrf  — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card">
    <h1><?= e(t('lib_my_title')) ?></h1>

    <?php if (!empty($flash)): ?>
        <p class="msg msg-ok"><?= e($flash) ?></p>
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
    <?php if (empty($games)): ?>
        <p class="muted"><?= e(t('lib_my_empty')) ?></p>
    <?php else: ?>
        <ul class="lib-list">
            <?php foreach ($games as $g): ?>
                <?php $lnk = library_link($g); ?>
                <li class="lib-item">
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
                    </span>
                    <?php if (!empty($g['year'])): ?>
                        <span class="lib-year"><?= (int)$g['year'] ?></span>
                    <?php endif; ?>
                    <form method="post" action="my_library.php" class="lib-del">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                    </form>
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
            <div class="field field-name">
                <label for="lib_name"><?= e(t('lib_add_manual_name')) ?></label>
                <input type="text" id="lib_name" name="name" required>
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
            <div class="field field-check field-confirm">
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
