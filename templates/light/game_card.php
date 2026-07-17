<?php
/* =============================================================================
 *  templates/light/game_card.php — one game on a table. Presentation only.
 * -----------------------------------------------------------------------------
 *  Two states:
 *    - SOFT-DELETED ($g['is_archived']==1): a greyed card with just the name and
 *      a "bring the game back" button (hidden in read-only view).
 *    - ACTIVE: thumbnail, name + edit/delete (when the verification button rule
 *      allows) + a "message everyone" envelope, the weight/time/length meta,
 *      bringer/rules/comment, the player list (with per-player remove + message
 *      envelopes), a signup button, and the discussion thread when enabled.
 *
 *  Control visibility rules:
 *    - edit/delete & per-player delete: verify_can_show_buttons(owner id).
 *    - message envelopes: messaging_allowed() AND not read-only (and,
 *      per player, only when that player left an email).
 *    - signup label flips to "reserve" once confirmed players reach max.
 *
 *  RENDER VARS:
 *    $g        — the game row, plus ['players'] and ['comments'].
 *    $readonly — archived/read-only view (hides every control).
 * ============================================================================= */
?>
<?php if ((int)$g['is_archived'] === 1): // soft-deleted -> greyed bring-back card ?>
    <article class="game-card game-archived" id="game-<?= (int)$g['id'] ?>">
        <div class="game-main">
            <h3 class="game-name"><?= e($g['name']) ?></h3>
            <p class="muted"><?= e(t('game_archived_note')) ?></p>
            <?php if (!$readonly): ?>
                <a class="btn btn-small" href="bring_back.php?game=<?= (int)$g['id'] ?>"><?= e(t('bringback_button')) ?></a>
                <?php if (is_admin()): // admins may remove the archived game for good ?>
                    <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('game_purge_button')) ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </article>
<?php else: // active game -> the full card ?>
    <?php $bucket = weight_bucket($g['weight']); // 1..5, drives the weight-badge colour class ?>
    <article class="game-card" id="game-<?= (int)$g['id'] ?>">
        <?php
        // Left column: thumbnail with the edit/delete buttons stacked BELOW it
        // (the column was mostly empty air under the image). The column renders
        // whenever there is an image OR buttons — so a manual game without a
        // thumbnail still gets its controls.
        $canButtons = !$readonly && verify_can_show_buttons($g['added_by_user_id']);
        ?>
        <?php if (!empty($g['thumbnail']) || $canButtons): ?>
            <div class="game-thumb">
                <?php if (!empty($g['thumbnail'])): ?>
                    <img src="<?= e($g['thumbnail']) ?>" alt="">
                <?php endif; ?>
                <?php if ($canButtons): ?>
                    <span class="game-actions">
                        <a class="btn btn-small" href="edit_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('edit')) ?></a>
                        <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('delete')) ?></a>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="game-main">
            <div class="game-name-row">
                <?php $gLink = game_link($g); // BGG page, or the custom link (option-gated) ?>
                <h3 class="game-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>
                <?php if (!$readonly && messaging_allowed()): // message all players ?>
                    <a class="msg-icon" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msg_envelope')) ?>">&#9993;</a>
                <?php endif; ?>
            </div>

            <div class="game-meta">
                <span class="weight-badge weight-<?= $bucket ?>" title="<?= e(number_format((float)$g['weight'], 1)) ?>"><?= e(number_format((float)$g['weight'], 1)) ?></span>
                <span class="game-time"><?= e($g['start_time']) ?></span>
                <span class="game-length"><?= e(t('game_length_min', (int)$g['length_minutes'])) ?></span>
            </div>

            <?php if (!empty($g['brings_name'])): ?>
                <p class="game-brings"><?= e(t('game_brings')) ?>: <strong><?= e($g['brings_name']) ?></strong></p>
            <?php endif; ?>
            <?php if (!empty($g['language'])): ?>
                <p class="game-language"><?= e(t('f_language')) ?>: <strong><?= e($g['language']) ?></strong></p>
            <?php endif; ?>
            <p class="game-rules rules-<?= rules_tone($g['explain_rules']) ?>"><?= e(explain_rules_label($g['explain_rules'])) ?></p>
            <?php if (!empty($g['comment'])): ?>
                <p class="game-comment"><?= nl2br(e($g['comment'])) ?></p>
            <?php endif; ?>

            <?php // Players: count label, the list (reserves tagged), then a signup button. ?>
            <div class="players">
                <span class="players-label"><?= e(t('players_label', count($g['players']), (int)$g['max_players'])) ?></span>
                <?php if (!empty($g['players'])): ?>
                    <ul class="player-list">
                        <?php foreach ($g['players'] as $p): ?>
                            <li class="player<?= (int)$p['is_reserve'] === 1 ? ' player-reserve' : '' ?>">
                                <?= e($p['name']) ?><?php if ($p['knows_rules'] !== null && $p['knows_rules'] !== ''): // rules-knowledge dot: green knows / amber somewhat / red doesn't; tooltip spells it out ?><span class="p-knows rules-<?= rules_tone($p['knows_rules']) ?>" title="<?= e(knows_rules_label($p['knows_rules'])) ?>">&#9679;</span><?php endif; ?><?php if (is_admin() && !empty($p['user_id'])): // admin-only: entry is BOUND to an account (created while its owner was logged in) ?><span class="p-acct" title="<?= e(t('player_account_bound', $p['account_name'] ?? ('#' . (int)$p['user_id']))) ?>">@</span><?php endif; ?><?php if ((int)$p['is_reserve'] === 1): ?> <span class="reserve-tag"><?= e(t('reserve_tag')) ?></span><?php endif; ?>
                                <?php if (!$readonly && messaging_allowed() && !empty($p['email'])): // message this player ?>
                                    <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msg_envelope')) ?>">&#9993;</a>
                                <?php endif; ?>
                                <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): // remove this signup ?>
                                    <a class="player-del" href="delete_player.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('delete')) ?>">&times;</a>
                                <?php endif; ?>
                                <?php // "Signed up by X": the entry is bound to an account whose name
                                      // DIFFERS from the typed player name — i.e. someone signed up someone
                                      // else. Shown to everyone, so the signed-up person understands why
                                      // they don't get a remove button as a guest.
                                if (!empty($p['user_id']) && !empty($p['account_name'])
                                    && mb_strtolower(trim($p['name'])) !== mb_strtolower(trim($p['account_name']))): ?>
                                    <span class="p-signedby"><?= e(t('player_signed_by', $p['account_name'])) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!$readonly && can_signup()):
                    // Confirmed (non-reserve) count decides whether to offer a seat
                    // or a reserve spot — the label flips when the game is full.
                    $confirmed = 0;
                    foreach ($g['players'] as $pp) { if ((int)$pp['is_reserve'] === 0) $confirmed++; }
                    $isFull = $confirmed >= (int)$g['max_players'];
                ?>
                    <a class="btn btn-small signup-btn" href="sign_up.php?game=<?= (int)$g['id'] ?>">
                        <?= $isFull ? e(t('signup_reserve')) : e(t('signup')) ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php // Discussion: existing comments + a collapsible add-comment form (native <details>). ?>
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
                                <input type="hidden" name="game" value="<?= (int)$g['id'] ?>">
                                <input type="text" name="name" placeholder="<?= e(t('comment_name')) ?>" value="<?= e(current_user()['display_name'] ?? '') ?>">
                                <textarea name="comment" rows="2" placeholder="<?= e(t('comment_text')) ?>" required></textarea>
                                <button type="submit" class="btn btn-small btn-primary"><?= e(t('comment_submit')) ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>
