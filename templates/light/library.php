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
 *    $games        — merged game list (games tab only), already paged/filtered.
 *    $mode         — 'all' | 'pages' | 'alpha'.
 *    $letters      — letter => count, for the alphabetical index.
 *    $letter       — the chosen letter, or '' for the index itself.
 *    $page/$page_count — position in 'pages' mode.
 *    $members      — members with at least one game (members tab, no member chosen).
 *    $member       — the chosen member's row, or null.
 *    $member_games — that member's games.
 *    $can_manage   — draw the per-game manage controls on this shelf.
 *    $csrf         — hidden CSRF field (only needed when managing).
 *    $flash        — result of the last manage action, or null.
 *    $flash_kind   — how to draw it.
 *
 *  The outer container carries BOTH .card (the boxed look every page with one
 *  gets) AND .library. .card alone is too broad a hook for a theme-specific
 *  fix — it wraps nearly every page, so a rule aimed at "the library page"
 *  written as .tpl-x .card would also hit sign-up forms, message forms, and
 *  everything else boxed. .library scopes a fix to exactly these two pages,
 *  the same way .admin and .userpanel already scope fixes to those sections.
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
function lib_render_row(array $g, $owners = null, $manage = false, $csrf = '', $scope = 'member') {
    /* $scope tells the manage forms WHICH collection the row belongs to. The two
     * shelves live in different tables, so a club row's id and a member row's id
     * can collide — without this the handler would happily act on whichever
     * table it guessed, and deleting "game 7" could remove the wrong game
     * entirely. Posted as a hidden field and checked server-side. */
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
                        <?php // The club is not a person: it gets its own class
                              // (and colour) so nobody reads CLUB as a member's
                              // display name, and its contact link goes to the
                              // club address rather than to a user id. ?>
                        <?php $isClub = !empty($o['is_club']); ?>
                        <span class="lib-owner<?= $isClub ? ' lib-owner-club' : '' ?>">
                            <?= e($o['display_name']) ?>
                            <?php // The contact button appears only for members who
                                  // opted in AND while the admin allows it AND while
                                  // the ordinary messaging rules permit this visitor
                                  // to send anything — all three live in
                                  // library_can_contact(). ?>
                            <?php if ($isClub ? library_club_can_contact() : library_can_contact($o)): ?>
                                <?php // Linked to THIS owner's copy of the game, so the
                                      // message says which title it is about rather than
                                      // "about their games" generally. ?>
                                <?php $oTitle = t('lib_contact_title_game', $o['display_name'], $g['name']); ?>
                                <?php $oHref = $isClub
                                    ? 'message.php?library_club=1&amp;library_game=' . (int)$o['row_id']
                                    : 'message.php?library_member=' . (int)$o['id'] . '&amp;library_game=' . (int)$o['row_id']; ?>
                                <a class="msg-icon" href="<?= $oHref ?>"
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
                <?php if (!empty($g['bgg_id'])): ?>
                    <?php // A BGG entry has nothing typeable — its name and year come
                          // from BGG — so its only edit is "this is the wrong game",
                          // answered with a different address. ?>
                    <details class="lib-edit">
                        <summary class="btn btn-small"><?= e(t('edit')) ?></summary>
                        <form method="post" action="library.php" class="lib-edit-form">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                            <input type="hidden" name="scope" value="<?= e($scope) ?>">
                            <?php // A BGG title sometimes comes back in the wrong
                                  // language. Renaming leaves bgg_id alone, so the
                                  // pairing that drives syncing and merging still
                                  // holds — only the label changes. ?>
                            <div class="field field-name">
                                <label for="libn_<?= (int)$g['id'] ?>"><?= e(t('lib_add_manual_name')) ?></label>
                                <input type="text" id="libn_<?= (int)$g['id'] ?>" name="name" value="<?= e($g['name']) ?>">
                            </div>
                            <?php // Needs a lookup — see my_library.php. ?>
                            <?php if (bgg_configured()): ?>
                            <div class="field field-link">
                                <label for="libr_<?= (int)$g['id'] ?>"><?= e(t('lib_relink_label')) ?></label>
                                <input type="url" id="libr_<?= (int)$g['id'] ?>" name="link"
                                       placeholder="https://boardgamegeek.com/boardgame/...">
                                <p class="field-note"><?= e(t('lib_relink_hint')) ?></p>
                            </div>
                            <?php endif; ?>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-small btn-primary"><?= e(t('save')) ?></button>
                            </div>
                        </form>
                    </details>
                <?php else: ?>
                    <details class="lib-edit">
                        <summary class="btn btn-small"><?= e(t('edit')) ?></summary>
                        <form method="post" action="library.php" class="lib-edit-form">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                            <input type="hidden" name="scope" value="<?= e($scope) ?>">
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
                            <input type="hidden" name="scope" value="<?= e($scope) ?>">
                    <input type="hidden" name="active" value="<?= $inactive ? '1' : '0' ?>">
                    <button type="submit" class="btn btn-small">
                        <?= $inactive ? e(t('lib_activate')) : e(t('lib_deactivate')) ?>
                    </button>
                </form>

                <form method="post" action="library.php" class="lib-del">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                            <input type="hidden" name="scope" value="<?= e($scope) ?>">
                    <button type="submit" class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                </form>
            </span>
        <?php endif; ?>
    </li>
    <?php
}
endif;
?>
<div class="card library">
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
    <?php // Only when there is more than one view to move between: a strip of a
          // single tab is noise, which is the case for a club running only its
          // own shelf. ?>
    <?php if (($tab_count ?? 0) > 1): ?>
        <nav class="day-tabs lib-tabs">
            <?php // The club tab leads when the admin prefers that source, so the
                  // strip matches which view actually opens by default —
                  // otherwise the first tab and the landing view disagree. ?>
            <?php if (!empty($show_club) && !empty($club_first)): ?>
                <a class="day-tab<?= $tab === 'club' ? ' day-tab-active' : '' ?>" href="library.php?tab=club"><?= e(t('lib_tab_club')) ?></a>
            <?php endif; ?>
            <?php if (!empty($show_games)): ?>
                <a class="day-tab<?= $tab === 'games' ? ' day-tab-active' : '' ?>" href="library.php?tab=games"><?= e(t('lib_tab_games')) ?></a>
            <?php endif; ?>
            <?php if (!empty($show_members)): ?>
                <a class="day-tab<?= $tab === 'members' ? ' day-tab-active' : '' ?>" href="library.php?tab=members"><?= e(t('lib_tab_members')) ?></a>
            <?php endif; ?>
            <?php if (!empty($show_club) && empty($club_first)): ?>
                <a class="day-tab<?= $tab === 'club' ? ' day-tab-active' : '' ?>" href="library.php?tab=club"><?= e(t('lib_tab_club')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <?php if ($tab === 'club'): ?>
        <?php // The same address, offered once for the shelf as a whole — the
              // counterpart of the button on a member's own shelf page.
              // Shares lib_contact_btn (and its icon) with that button, so
              // "Send a message" and the envelope look the same everywhere the
              // library offers to contact someone. ?>
        <?php if (library_club_can_contact()): ?>
            <p class="lib-club-contact">
                <a class="btn btn-small lib-contact-btn" href="message.php?library_club=1">
                    <span class="msg-icon" aria-hidden="true">&#9993;</span><?= e(t('lib_contact_btn')) ?>
                </a>
            </p>
        <?php endif; ?>
        <?php // Same row markup and the same alphabet/pager as the members'
              // list; the one difference is that no owners are named, because
              // there is only one owner and the tab already says who. ?>
        <?php if (($mode ?? 'all') === 'alpha' && !empty($letters)): ?>
            <nav class="lib-alpha">
                <?php foreach ($letters as $l => $count): ?>
                    <a class="btn btn-small lib-alpha-btn<?= ($letter ?? '') === (string)$l ? ' lib-alpha-active' : '' ?>"
                       href="library.php?tab=club&amp;letter=<?= rawurlencode((string)$l) ?>"><?= e($l) ?>
                        <span class="lib-alpha-count"><?= (int)$count ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if (($letter ?? '') !== ''): ?>
                <p class="lib-alpha-back">
                    <a class="btn btn-small" href="library.php?tab=club"><?= e(t('lib_all_letters')) ?></a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($club_games)): ?>
            <?php if (($mode ?? 'all') === 'alpha' && !empty($letters) && ($letter ?? '') === ''): ?>
                <p class="muted"><?= e(t('lib_pick_letter')) ?></p>
            <?php else: ?>
                <p class="muted"><?= e(t('lib_club_empty')) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <ul class="lib-list">
                <?php foreach ($club_games as $g): ?>
                    <?php lib_render_row($g, null, !empty($club_manage), $csrf ?? '', 'club'); ?>
                <?php endforeach; ?>
            </ul>
            <?php if (($mode ?? 'all') === 'pages' && ($page_count ?? 1) > 1): ?>
                <nav class="pager">
                    <?php if (($page ?? 1) > 1): ?>
                        <a class="btn btn-small" href="library.php?tab=club&amp;page=<?= (int)$page - 1 ?>"><?= e(t('pager_prev')) ?></a>
                    <?php endif; ?>
                    <span class="pager-pos"><?= e(t('pager_position', (int)$page, (int)$page_count)) ?></span>
                    <?php if (($page ?? 1) < ($page_count ?? 1)): ?>
                        <a class="btn btn-small" href="library.php?tab=club&amp;page=<?= (int)$page + 1 ?>"><?= e(t('pager_next')) ?></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($tab === 'games'): ?>
        <?php // ALPHABETICAL INDEX. With no letter chosen this is the whole
              // page: a strip of letters that actually have games behind them,
              // so nothing leads to an empty list. ?>
        <?php if (($mode ?? 'all') === 'alpha' && !empty($letters)): ?>
            <nav class="lib-alpha">
                <?php foreach ($letters as $l => $count): ?>
                    <a class="btn btn-small lib-alpha-btn<?= ($letter ?? '') === (string)$l ? ' lib-alpha-active' : '' ?>"
                       href="library.php?tab=games&amp;letter=<?= rawurlencode((string)$l) ?>"><?= e($l) ?>
                        <span class="lib-alpha-count"><?= (int)$count ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if (($letter ?? '') !== ''): ?>
                <p class="lib-alpha-back">
                    <a class="btn btn-small" href="library.php?tab=games"><?= e(t('lib_all_letters')) ?></a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($games)): ?>
            <?php // On the alpha index "no games" would be wrong — there are
                  // games, none is selected. Say the right thing for the state. ?>
            <?php if (($mode ?? 'all') === 'alpha' && !empty($letters) && ($letter ?? '') === ''): ?>
                <p class="muted"><?= e(t('lib_pick_letter')) ?></p>
            <?php else: ?>
                <p class="muted"><?= e(t('lib_empty')) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <ul class="lib-list">
                <?php foreach ($games as $g): ?>
                    <?php lib_render_row($g, $g['owners']); ?>
                <?php endforeach; ?>
            </ul>
            <?php // Prev/next rather than a numbered pager: the same choice the
                  // archive makes, and it stays usable on a phone. ?>
            <?php if (($mode ?? 'all') === 'pages' && ($page_count ?? 1) > 1): ?>
                <nav class="pager">
                    <?php if (($page ?? 1) > 1): ?>
                        <a class="btn btn-small" href="library.php?tab=games&amp;page=<?= (int)$page - 1 ?>"><?= e(t('pager_prev')) ?></a>
                    <?php endif; ?>
                    <span class="pager-pos"><?= e(t('pager_position', (int)$page, (int)$page_count)) ?></span>
                    <?php if (($page ?? 1) < ($page_count ?? 1)): ?>
                        <a class="btn btn-small" href="library.php?tab=games&amp;page=<?= (int)$page + 1 ?>"><?= e(t('pager_next')) ?></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($member): ?>
        <?php // One member's shelf. ?>
        <h2 class="lib-member-head">
            <?= e($member['display_name']) ?>
            <?php if (library_can_contact($member)): ?>
                <a class="btn btn-small lib-contact-btn" href="message.php?library_member=<?= (int)$member['id'] ?>">
                    <span class="msg-icon" aria-hidden="true">&#9993;</span><?= e(t('lib_contact_btn')) ?>
                </a>
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
