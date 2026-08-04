<?php
/* =============================================================================
 *  templates/schematic/game_card.php — one game, as a console strip.
 * -----------------------------------------------------------------------------
 *  THE READING: these boards (Ian O'Toole's) are technical diagrams — flat
 *  colour-blocked modules, hairline rules, tiny labelled cells, and numbered
 *  tracks you read left to right. So a game is not a card here, it is a STRIP
 *  of a control panel: an identity block, a row of labelled data cells, and a
 *  capacity track.
 *
 *  LAYOUT IS DELIBERATELY UNLIKE EVERY OTHER THEME. The others are either a
 *  grid of cards (corkboard, elvish, space) or a two-panel split (classic,
 *  steampunk, imperial). This one is a FULL-WIDTH HORIZONTAL ROW: one strip per
 *  game, stacked, read across rather than down. That is the structural
 *  difference the brief asked for, and it is why the file is forked rather than
 *  restyled — light's card has no horizontal reading order to restyle into.
 *
 *  THE OTHER TWO REASONS IT FORKS:
 *    1. THE CAPACITY TRACK — the roster as a horizontal run of numbered slot
 *       chips, filled or open, exactly like a resource track on those boards.
 *       Light renders only players that EXIST, so open slots have nothing to
 *       hang off.
 *    2. COLOUR CODING BY TABLE — every strip takes an accent from its table_id,
 *       so games sharing a table share a colour band and the wall of strips
 *       reads as a keyed system rather than a list. That needs a value computed
 *       per row, which CSS cannot derive.
 *
 *  THE DRIFT RISK is not hypothetical: classic forked this file and silently
 *  lost its anti-bot field, breaking commenting on that theme until it was
 *  caught. tests/test_schematic.php pins this file to light's on every control.
 *
 *  RENDER VARS: identical to light's — $g and $readonly.
 * ============================================================================= */
?>
<?php if ((int)$g['is_archived'] === 1): ?>
    <article class="game-card game-archived sc-strip" id="game-<?= (int)$g['id'] ?>">
        <div class="sc-id"><span class="sc-id-num">—</span></div>
        <div class="sc-body">
            <h3 class="game-name"><?= e($g['name']) ?></h3>
            <p class="sc-note"><?= e(t('game_archived_note')) ?></p>
            <?php if (!$readonly): ?>
                <div class="sc-actions">
                    <a class="btn btn-small" href="bring_back.php?game=<?= (int)$g['id'] ?>"><?= e(t('bringback_button')) ?></a>
                    <?php if (is_admin()): ?>
                        <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('game_purge_button')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
<?php else: ?>
    <?php
    $bucket = weight_bucket($g['weight']);
    $canMsg = !$readonly && messaging_allowed();
    $crew = [];
    $reserves = [];
    foreach ($g['players'] as $p) {
        if ((int)$p['is_reserve'] === 1) { $reserves[] = $p; } else { $crew[] = $p; }
    }
    $max  = (int)$g['max_players'];
    $open = max(0, $max - count($crew));
// THE COLOUR KEY comes from the table NUMBER, not its row id. Two reasons:
    // the number is what the visitor sees ("Table #2") and is 1,2,3… per event,
    // so adjacent tables always differ and the key is stable; and it is the one
    // value the TIMELINE can also derive, which is what lets a game's band
    // there match its strip here. Falls back to the row id if the render var is
    // ever absent, so an older caller still gets a colour rather than none.
    $chan = (((int)($table_no ?? $g['table_id'])) % 5) + 1;
    // The track draws a chip per seat, capped so a huge game stays one row.
    $trackSlots = min($max, 12);
    ?>
    <article class="game-card sc-strip sc-chan-<?= $chan ?>" id="game-<?= (int)$g['id'] ?>">

        <?php // ---- Identity block: the coloured tab that keys the strip ---- ?>
        <div class="sc-id">
            <span class="sc-id-time"><?= e($g['start_time']) ?></span>
            <span class="sc-id-num"><?= str_pad((string)(int)$g['id'], 3, '0', STR_PAD_LEFT) ?></span>
        </div>

        <div class="sc-body">
            <div class="sc-head">
                <?php $gLink = game_link($g); ?>
                <h3 class="game-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>
                <?php if ($canMsg): ?>
                    <a class="msg-icon msg-icon-all" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msgbtn_game_all')) ?>" aria-label="<?= e(t('msgbtn_game_all')) ?>">&#9993;</a>
                <?php endif; ?>
                <?php if (!$readonly && verify_can_show_buttons($g['added_by_user_id'])): ?>
                    <span class="sc-actions">
                        <a class="btn btn-small" href="edit_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('edit')) ?></a>
                        <?php // Admin-only: move this game to another table on the same day.
                              // Guarded again server-side in move_item.php — hiding a button
                              // is a UI courtesy, not a permission check. ?>
                        <?php if (is_admin()): ?>
                            <a class="btn btn-small" href="move_item.php?game=<?= (int)$g['id'] ?>"><?= e(t('move_btn')) ?></a>
                        <?php endif; ?>
                        <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('delete')) ?></a>
                    </span>
                <?php endif; ?>
            </div>

            <?php // ---- Data cells: label above value, the way a panel legend
                  // annotates a diagram. ---- ?>
            <div class="sc-cells">
                <?php // The box art, sized to the cell row so it reads as the first
                      // chip in the strip's data run rather than a floating image.
                      // Placed here rather than out beside the roster because this
                      // row always exists and is always the same height — the track
                      // wraps, so the space next to it is not reliably free. ?>
                <?php if (!empty($g['thumbnail'])): ?>
                    <span class="sc-cell sc-cell-thumb"><img src="<?= e($g['thumbnail']) ?>" alt=""></span>
                <?php endif; ?>
                <span class="sc-cell sc-cell-weight weight-<?= $bucket ?>">
                    <span class="sc-key"><?= e(t('cl_weight')) ?></span>
                    <span class="sc-val"><?= e(number_format((float)$g['weight'], 1, ',', '')) ?></span>
                </span>
                <?php if ((int)$g['length_minutes'] > 0): ?>
                    <span class="sc-cell">
                        <span class="sc-key"><?= e(t('cl_length')) ?></span>
                        <span class="sc-val"><?= (int)$g['length_minutes'] ?>&#8242;</span>
                    </span>
                <?php endif; ?>
                <span class="sc-cell">
                    <span class="sc-key"><?= e(t('cl_players')) ?></span>
                    <span class="sc-val"><?= count($crew) ?>/<?= $max ?></span>
                </span>
                <?php if (!empty($g['language'])): ?>
                    <span class="sc-cell">
                        <span class="sc-key"><?= e(t('cl_version')) ?></span>
                        <span class="sc-val"><?= e($g['language']) ?></span>
                    </span>
                <?php endif; ?>
                <?php if (!empty($g['brings_name'])): ?>
                    <span class="sc-cell sc-cell-wide">
                        <span class="sc-key"><?= e(t('game_brings')) ?></span>
                        <span class="sc-val"><?= e($g['brings_name']) ?></span>
                    </span>
                <?php endif; ?>
                <?php // No key label on this one: unlike "weight 3,5" the rules
                      // value is a whole phrase that already says what it is
                      // ("explains the rules"), so a label would just repeat it. ?>
                <span class="sc-cell sc-cell-wide sc-cell-rules rules-<?= rules_tone($g['explain_rules']) ?>">
                    <span class="sc-val"><?= e(explain_rules_label($g['explain_rules'])) ?></span>
                </span>
                <?php // Rules / manual link, as its own cell right after the rules
                      // one so it joins the same run. manual_link() returns null
                      // when the admin has the feature off, so it disappears
                      // everywhere at once without touching stored data. ?>
                <?php if (($mlink = manual_link($g)) !== null): ?>
                    <a class="sc-cell sc-cell-manual game-manual" href="<?= e($mlink) ?>"
                       target="_blank" rel="noopener noreferrer"
                       title="<?= e(t('game_manual_title')) ?>">
                        <span class="sc-val"><?= e(t('game_manual_btn')) ?></span>
                    </a>
                <?php endif; ?>
            </div>

            <?php if (!empty($g['comment'])): ?>
                <p class="sc-comment"><?= nl2br(e($g['comment'])) ?></p>
            <?php endif; ?>

            <?php // ---- THE CAPACITY TRACK ---- ?>
            <div class="sc-track" role="group" aria-label="<?= e(t('cl_players')) ?>: <?= count($crew) ?>/<?= $max ?>">
                <?php for ($i = 0; $i < $trackSlots; $i++): ?>
                    <?php $p = $crew[$i] ?? null; ?>
                    <span class="sc-slot<?= $p ? ' sc-slot-on' : ' sc-slot-off' ?>">
                        <span class="sc-slot-n"><?= $i + 1 ?></span>
                        <?php if ($p): ?>
                            <span class="sc-slot-name"><?= e($p['name']) ?></span>
                            <?php if ($p['knows_rules'] !== null && $p['knows_rules'] !== ''): ?>
                                <span class="sc-dot rules-<?= rules_tone($p['knows_rules']) ?>" title="<?= e(knows_rules_label($p['knows_rules'])) ?>"></span>
                            <?php endif; ?>
                            <?php if (is_admin() && !empty($p['user_id'])): ?>
                                <span class="p-acct" title="<?= e(t('player_account_bound', $p['account_name'] ?? ('#' . (int)$p['user_id']))) ?>">@</span>
                            <?php endif; ?>
                            <?php if (!empty($p['user_id']) && !empty($p['account_name'])
                                && mb_strtolower(trim($p['name'])) !== mb_strtolower(trim($p['account_name']))): ?>
                                <span class="p-signedby"><?= e(t('player_signed_by', $p['account_name'])) ?></span>
                            <?php endif; ?>
                            <?php if ($canMsg && !empty($p['email'])): ?>
                                <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                            <?php endif; ?>
                            <?php // Last, so the auto-margin parks it at the chip's right edge. ?>
                            <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                                <a class="sc-slot-x" href="delete_player.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('delete')) ?>">&times;</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="sc-slot-name sc-slot-free"><?= e(t('player_n', $i + 1)) ?></span>
                        <?php endif; ?>
                    </span>
                <?php endfor; ?>
                <?php if ($max > $trackSlots): ?>
                    <span class="sc-slot sc-slot-more"><?= e(t('cl_more_slots', $max - $trackSlots)) ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($reserves)): ?>
                <div class="sc-track sc-track-reserve">
                    <span class="sc-track-label"><?= e(t('reserve_tag')) ?></span>
                    <?php foreach ($reserves as $p): ?>
                        <span class="sc-slot sc-slot-on player-reserve">
                            <span class="sc-slot-name"><?= e($p['name']) ?></span>
                            <?php if ($canMsg && !empty($p['email'])): ?>
                                <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                            <?php endif; ?>
                            <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                                <a class="sc-slot-x" href="delete_player.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('delete')) ?>">&times;</a>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="sc-foot">
                <?php if (!$readonly && can_signup()): ?>
                    <a class="btn btn-small btn-primary sc-signup" href="sign_up.php?game=<?= (int)$g['id'] ?>">
                        <?= $open === 0 ? e(t('signup_reserve')) : e(t('signup')) ?>
                    </a>
                <?php endif; ?>
                <?php if (opt_bool('allow_discussions')): ?>
                    <details class="comment-add sc-disc">
                        <?php $cCount = !empty($g['comments']) ? count($g['comments']) : 0; ?>
                        <summary class="sc-comments<?= $cCount > 0 ? ' has-comments' : '' ?>"><?= e(t('comments_toggle')) ?><?= $cCount > 0 ? ' (' . $cCount . ')' : '' ?></summary>
                        <?php if (!empty($g['comments'])): ?>
                            <ul class="comment-list">
                                <?php foreach ($g['comments'] as $c): ?>
                                    <li><span class="c-name"><?= e($c['name']) ?>:</span> <?= nl2br(e($c['comment'])) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!$readonly): ?>
                            <form method="post" action="add_comment.php">
                                <?= csrf_field() ?>
                                <?= antibot_field() ?>
                                <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                                <input type="text" name="name" placeholder="<?= e(t('comment_name')) ?>" value="<?= e(current_user()['display_name'] ?? '') ?>">
                                <textarea name="comment" rows="2" placeholder="<?= e(t('comment_text')) ?>" required></textarea>
                                <button type="submit" class="btn btn-small btn-primary"><?= e(t('comment_submit')) ?></button>
                            </form>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </article>
<?php endif; ?>
