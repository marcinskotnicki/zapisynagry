<?php
/* =============================================================================
 *  templates/elvish/game_card.php — one game, as an illuminated ledger entry.
 * -----------------------------------------------------------------------------
 *  WHY THIS THEME FORKS THE CARD (light's version is otherwise fine):
 *  the signature element here is the VINE OF SEATS — the player list drawn as
 *  a stem with a leaf for every taken seat and an unopened bud for every free
 *  one. Light's card only ever renders players that EXIST, so free seats have
 *  no element to hang a bud on. That's an information-architecture difference,
 *  not decoration, which is the one thing CSS alone genuinely cannot do here.
 *  (The classic theme forked this same file for the same kind of reason.)
 *
 *  THE DRIFT RISK, and what's done about it: classic's fork silently lost a
 *  feature once — its comment button diverged from light's for months because
 *  nothing tied them together. tests/test_elvish.php pins this file to light's
 *  on every control that matters (signup, edit/delete, per-player remove,
 *  message envelopes, the comment form and its CSRF + anti-bot fields), so the
 *  same thing can't happen quietly again. Add a control to light's card and
 *  that test tells you this one is missing it.
 *
 *  Everything below reuses light's class names wherever the meaning is the
 *  same, so the base stylesheet still does the heavy lifting and this theme's
 *  CSS only has to restyle. New classes are prefixed el- and exist only for
 *  structure light has no equivalent for (the vine, the cartouche).
 *
 *  RENDER VARS: identical to light's — $g (game row + ['players'], ['comments'])
 *  and $readonly.
 * ============================================================================= */
?>
<?php // Skipped entirely when the admin shows deleted games in full: the
      // active markup below renders instead, wrapped and forced read-only
      // by front_event.php. ?>
<?php if ((int)$g['is_archived'] === 1 && deleted_games_display() !== 'full'): // soft-deleted -> a faded entry ?>
    <article class="game-card game-archived" id="game-<?= (int)$g['id'] ?>">
        <div class="game-main">
            <h3 class="game-name"><?= e($g['name']) ?></h3>
            <p class="muted"><?= e(t('game_archived_note')) ?></p>
            <?php if (!$readonly): ?>
                <a class="btn btn-small" href="bring_back.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('bringback_button')) ?></a>
                <?php if (is_admin()): ?>
                    <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('game_purge_button')) ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </article>
<?php else: ?>
    <?php
    $bucket     = weight_bucket($g['weight']);
    $canButtons = !$readonly && verify_can_show_buttons($g['added_by_user_id']);
    // Confirmed vs reserve, counted once: the vine below needs the confirmed
    // count to know how many buds to draw, and the signup label needs it to
    // know whether it is offering a seat or a place on the reserve list.
    $confirmed = [];
    $reserves  = [];
    foreach ($g['players'] as $p) {
        if ((int)$p['is_reserve'] === 1) { $reserves[] = $p; } else { $confirmed[] = $p; }
    }
    $max  = (int)$g['max_players'];
    $free = max(0, $max - count($confirmed));
    ?>
    <article class="game-card" id="game-<?= (int)$g['id'] ?>">
        <div class="game-main">

            <?php // ---- Cartouche: the name, its flourish, and the controls ---- ?>
            <div class="el-cartouche">
                <div class="game-name-row">
                    <?php $gLink = game_link($g); ?>
                    <h3 class="game-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>
                    <?php if (!$readonly && messaging_allowed()): ?>
                        <a class="msg-icon msg-icon-all" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msgbtn_game_all')) ?>" aria-label="<?= e(t('msgbtn_game_all')) ?>">&#9993;</a>
                    <?php endif; ?>
                </div>
                <?php if ($canButtons): ?>
                    <span class="game-actions">
                        <a class="btn btn-small" href="edit_game.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('edit')) ?></a>
                        <?php // Admin-only: move this game to another table on the same day.
                              // Guarded again server-side in move_item.php — hiding a button
                              // is a UI courtesy, not a permission check. ?>
                        <?php if (is_admin()): ?>
                            <a class="btn btn-small" href="move_item.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('move_btn')) ?></a>
                        <?php endif; ?>
                        <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('delete')) ?></a>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($g['thumbnail'])): ?>
                <div class="game-thumb"><img src="<?= e($g['thumbnail']) ?>" alt=""></div>
            <?php endif; ?>

            <?php // ---- Attributes, as one inscribed line rather than a stack ---- ?>
            <div class="game-meta">
                <span class="weight-badge weight-<?= $bucket ?>" title="<?= e(t('cl_weight')) ?>"><?= e(number_format((float)$g['weight'], 1)) ?></span>
                <span class="game-time"><?= e($g['start_time']) ?></span>
                <span class="game-length"><?= e(t('game_length_min', (int)$g['length_minutes'])) ?></span>
                <?php if (!empty($g['language'])): ?>
                    <span class="game-language"><?= e($g['language']) ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($g['brings_name'])): ?>
                <p class="game-brings"><?= e(t('game_brings')) ?>: <strong><?= e($g['brings_name']) ?></strong></p>
            <?php endif; ?>
            <p class="game-rules rules-<?= rules_tone($g['explain_rules']) ?>"><?= e(explain_rules_label($g['explain_rules'])) ?></p>
            <?php // Rules / manual link, right after the rules line it belongs with.
                  // manual_link() returns null when the admin has the feature off, so
                  // this disappears everywhere at once without touching stored data. ?>
            <?php if (($mlink = manual_link($g)) !== null): ?>
                <a class="btn btn-small game-manual" href="<?= e($mlink) ?>" target="_blank" rel="noopener noreferrer"
                   title="<?= e(t('game_manual_title')) ?>"><?= e(t('game_manual_btn')) ?></a>
            <?php endif; ?>
            <?php if (!empty($g['comment'])): ?>
                <p class="game-comment"><?= nl2br(e($g['comment'])) ?></p>
            <?php endif; ?>

            <?php // ---- THE VINE: a leaf per taken seat, a bud per free one ---- ?>
            <div class="players">
                <span class="players-label"><?= e(t('players_label', count($g['players']), $max)) ?></span>
                <ul class="player-list el-vine">
                    <?php foreach ($confirmed as $p): ?>
                        <li class="player el-leaf">
                            <?= e($p['name']) ?><?php if ($p['knows_rules'] !== null && $p['knows_rules'] !== ''): ?><span class="p-knows rules-<?= rules_tone($p['knows_rules']) ?>" title="<?= e(knows_rules_label($p['knows_rules'])) ?>">&#128366;</span><?php endif; ?><?php if (is_admin() && !empty($p['user_id'])): ?><span class="p-acct" title="<?= e(t('player_account_bound', $p['account_name'] ?? ('#' . (int)$p['user_id']))) ?>">@</span><?php endif; ?>
                            <?php if (!$readonly && messaging_allowed() && !empty($p['email'])): ?>
                                <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                            <?php endif; ?>
                            <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                                <a class="player-del" href="delete_player.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('delete')) ?>" rel="nofollow">&times;</a>
                            <?php endif; ?>
                            <?php $signedBy = player_signed_up_by($p);
                                if ($signedBy !== ''): ?>
                                <span class="p-signedby"><?= e(t('player_signed_by', $signedBy)) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>

                    <?php // Free seats: the buds. Light's card has no element for
                          // these at all — drawing them is why this file exists. ?>
                    <?php for ($i = 0; $i < $free; $i++): ?>
                        <li class="player el-bud"><span class="muted"><?= e(t('player_n', count($confirmed) + $i + 1)) ?></span></li>
                    <?php endfor; ?>

                    <?php foreach ($reserves as $p): ?>
                        <li class="player player-reserve el-leaf">
                            <?= e($p['name']) ?><?php if ($p['knows_rules'] !== null && $p['knows_rules'] !== ''): ?><span class="p-knows rules-<?= rules_tone($p['knows_rules']) ?>" title="<?= e(knows_rules_label($p['knows_rules'])) ?>">&#128366;</span><?php endif; ?> <span class="reserve-tag"><?= e(t('reserve_tag')) ?></span>
                            <?php if (!$readonly && messaging_allowed() && !empty($p['email'])): ?>
                                <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                            <?php endif; ?>
                            <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                                <a class="player-del" href="delete_player.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('delete')) ?>" rel="nofollow">&times;</a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if (!$readonly && can_signup()): ?>
                    <a class="btn btn-small signup-btn" href="sign_up.php?game=<?= (int)$g['id'] ?>">
                        <?= $free === 0 ? e(t('signup_reserve')) : e(t('signup')) ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if (opt_bool('allow_discussions')): ?>
                <div class="discussion">
                    <?php if (!empty($g['comments'])): ?>
                        <ul class="comment-list">
                            <?php foreach ($g['comments'] as $c): ?>
                                <li><span class="c-name"><?= e($c['name']) ?>:</span> <?= nl2br(e($c['comment'])) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!$readonly): ?>
                        <details class="comment-add">
                            <summary><?= e(t('comment_add')) ?></summary>
                            <form method="post" action="add_comment.php">
                                <?= csrf_field() ?>
                                <?= antibot_field() ?>
                                <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                                <input type="text" name="name" placeholder="<?= e(t('comment_name')) ?>" value="<?= e(current_user()['display_name'] ?? '') ?>">
                                <textarea name="comment" rows="2" placeholder="<?= e(t('comment_text')) ?>" required></textarea>
                                <?= captcha_html('comment') ?>
                                <button type="submit" class="btn btn-small btn-primary"><?= e(t('comment_submit')) ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>
