<?php
/* =============================================================================
 *  templates/light/library.php — the club's shared game library.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Three states in one template, because they share the tab
 *  strip and the game-row markup:
 *    - the games list (every member's games, merged);
 *    - the members list;
 *    - one member's shelf.
 *
 *  OWNERS RENDER AS A HORIZONTAL WRAPPING LIST so a game owned by eight people
 *  costs one line rather than eight — the point of the games view is scanning
 *  for a title, not reading names.
 *
 *  RENDER VARS:
 *    $tab          — 'games' | 'members'.
 *    $show_members — whether the members tab exists at all.
 *    $games        — merged game list (games tab only).
 *    $members      — members with at least one game (members tab, no member chosen).
 *    $member       — the chosen member's row, or null.
 *    $member_games — that member's games.
 *    $can_manage   — draw the per-game manage controls on this shelf.
 *    $csrf         — hidden CSRF field (only needed when managing).
 *    $flash        — result of the last manage action, or null.
 *    $flash_kind   — how to draw it.
 * ============================================================================= */

/**
 * One game row, shared by the games list and a member's shelf.
 *
 * $owners is null on a member's shelf (the owner is the page you are on) and a
 * list on the merged view.
 *
 * function_exists() guards the DEFINITION because this is a template: PHP
 * fatals on a redeclare, and a template can legitimately be rendered twice in
 * one request. Cheap insurance against a crash that would only appear once
 * somebody reused this partial.
 */
if (!function_exists('lib_render_row')):
function lib_render_row(array $g, $owners = null, $manage = false, $csrf = '') {
    $link = library_link($g);
    $inactive = isset($g['is_active']) && (int)$g['is_active'] !== 1;
    ?>
    <li class="lib-item<?= $inactive ? ' lib-item-inactive' : '' ?>">
        <?php if (!empty($g['thumbnail'])): ?>
            <img class="lib-thumb" src="<?= e($g['thumbnail']) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="lib-thumb lib-thumb-none" aria-hidden="true"></span>
        <?php endif; ?>

        <span class="lib-main">
            <span class="lib-name">
                <?php if ($link): ?>
                    <a href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a>
                <?php else: ?>
                    <?= e($g['name']) ?>
                <?php endif; ?>
                <?php if (!empty($g['year'])): ?>
                    <span class="lib-year"><?= (int)$g['year'] ?></span>
                <?php endif; ?>
                <?php // Only ever visible to someone who can act on it — an
                      // ordinary visitor never receives inactive rows at all. ?>
                <?php if ($inactive): ?>
                    <span class="lib-tag-inactive"><?= e(t('lib_inactive_tag')) ?></span>
                <?php endif; ?>
            </span>

            <?php if (is_array($owners)): ?>
                <?php // Horizontal and wrapping: a popular game should not push
                      // the rest of the list off the screen. ?>
                <span class="lib-owners">
                    <?php foreach ($owners as $o): ?>
                        <span class="lib-owner">
                            <?= e($o['display_name']) ?>
                            <?php // The contact button appears only for members who
                                  // opted in AND while the admin allows it AND while
                                  // the ordinary messaging rules permit this visitor
                                  // to send anything — all three live in
                                  // library_can_contact(). ?>
                            <?php if (library_can_contact($o)): ?>
                                <?php // Linked to THIS owner's copy of the game, so the
                                      // message says which title it is about rather than
                                      // "about their games" generally. ?>
                                <?php $oTitle = t('lib_contact_title_game', $o['display_name'], $g['name']); ?>
                                <a class="msg-icon" href="message.php?library_member=<?= (int)$o['id'] ?>&amp;library_game=<?= (int)$o['row_id'] ?>"
                                   title="<?= e($oTitle) ?>"
                                   aria-label="<?= e($oTitle) ?>">&#9993;</a>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </span>

        <?php // Same three controls the owner gets on their own page, drawn here
              // for an admin (or the owner) looking at the shelf. One code path,
              // because library_can_manage() already decided who may act. ?>
        <?php if ($manage): ?>
            <span class="lib-actions">
                <?php if (empty($g['bgg_id'])): ?>
                    <details class="lib-edit">
                        <summary class="btn btn-small"><?= e(t('edit')) ?></summary>
                        <form method="post" action="library.php" class="lib-edit-form">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                            <div class="field field-name">
                                <label for="libm_n_<?= (int)$g['id'] ?>"><?= e(t('lib_add_manual_name')) ?></label>
                                <input type="text" id="libm_n_<?= (int)$g['id'] ?>" name="name" value="<?= e($g['name']) ?>" required>
                            </div>
                            <div class="field field-year">
                                <label for="libm_y_<?= (int)$g['id'] ?>"><?= e(t('lib_year')) ?></label>
                                <input type="number" id="libm_y_<?= (int)$g['id'] ?>" name="year" min="0" max="2999"
                                       value="<?= !empty($g['year']) ? (int)$g['year'] : '' ?>">
                            </div>
                            <?php // A BGG address here promotes the entry: it adopts BGG's
                                  // name, year and art and merges with everyone else's copy
                                  // of the same game — which is how an admin folds several
                                  // hand-typed rows into one. ?>
                            <?php if (library_link_field_visible()): ?>
                                <div class="field field-link">
                                    <label for="libm_l_<?= (int)$g['id'] ?>"><?= e(t('lib_link_label')) ?></label>
                                    <input type="url" id="libm_l_<?= (int)$g['id'] ?>" name="link"
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

                <form method="post" action="library.php" class="lib-toggle">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                    <input type="hidden" name="active" value="<?= $inactive ? '1' : '0' ?>">
                    <button type="submit" class="btn btn-small">
                        <?= $inactive ? e(t('lib_activate')) : e(t('lib_deactivate')) ?>
                    </button>
                </form>

                <form method="post" action="library.php" class="lib-del">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                    <button type="submit" class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                </form>
            </span>
        <?php endif; ?>
    </li>
    <?php
}
endif;
?>
<div class="card">
    <h1><?= e(t('lib_title')) ?></h1>

    <?php if (!empty($flash)): ?>
        <p class="msg msg-<?= e($flash_kind ?? 'ok') ?>"><?= e($flash) ?></p>
    <?php endif; ?>

    <?php // Optional admin note, rendered only when filled. Above the tabs, so
          // it applies to the whole library rather than looking like a comment
          // on whichever view happens to be open. ?>
    <?php if (opt_msg('msg_library') !== ''): ?>
        <p class="event-msg"><?= e(opt_msg('msg_library')) ?></p>
    <?php endif; ?>

    <?php // With the members tab off there is only one view, so a tab strip of
          // one tab would be noise. ?>
    <?php if ($show_members): ?>
        <nav class="day-tabs lib-tabs">
            <a class="day-tab<?= $tab === 'games' ? ' day-tab-active' : '' ?>" href="library.php?tab=games"><?= e(t('lib_tab_games')) ?></a>
            <a class="day-tab<?= $tab === 'members' ? ' day-tab-active' : '' ?>" href="library.php?tab=members"><?= e(t('lib_tab_members')) ?></a>
        </nav>
    <?php endif; ?>

    <?php if ($tab === 'games'): ?>
        <?php if (empty($games)): ?>
            <p class="muted"><?= e(t('lib_empty')) ?></p>
        <?php else: ?>
            <ul class="lib-list">
                <?php foreach ($games as $g): ?>
                    <?php lib_render_row($g, $g['owners']); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    <?php elseif ($member): ?>
        <?php // One member's shelf. ?>
        <h2 class="lib-member-head">
            <?= e($member['display_name']) ?>
            <?php if (library_can_contact($member)): ?>
                <a class="btn btn-small" href="message.php?library_member=<?= (int)$member['id'] ?>"><?= e(t('lib_contact_btn')) ?></a>
            <?php endif; ?>
        </h2>
        <?php if (empty($member_games)): ?>
            <p class="muted"><?= e(t('lib_empty')) ?></p>
        <?php else: ?>
            <ul class="lib-list">
                <?php foreach ($member_games as $g): ?>
                    <?php lib_render_row($g, null, !empty($can_manage), $csrf ?? ''); ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="form-actions">
            <a class="btn" href="library.php?tab=members"><?= e(t('lib_back_to_members')) ?></a>
        </div>

    <?php else: ?>
        <?php if (empty($members)): ?>
            <p class="muted"><?= e(t('lib_no_members')) ?></p>
        <?php else: ?>
            <ul class="lib-members">
                <?php foreach ($members as $m): ?>
                    <li class="lib-member">
                        <a href="library.php?tab=members&amp;member=<?= (int)$m['id'] ?>"><?= e($m['display_name']) ?></a>
                        <span class="lib-count"><?= e(t('lib_game_count', (int)$m['game_count'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>
