<?php
/* =============================================================================
 *  templates/space/game_card.php — one game, as a mission module.
 * -----------------------------------------------------------------------------
 *  WHY THIS THEME FORKS THE CARD:
 *  the crew manifest is a NUMBERED roster — every seat has a slot number and a
 *  filled/open indicator, and standby (reserve) crew are listed in their own
 *  section below the primary roster rather than mixed into it. Light's card
 *  renders only players that exist, in one flat list, so neither the numbering
 *  nor the open slots have anything to hang off. Same structural reason the
 *  elvish theme forks this file, but a deliberately different presentation:
 *  elvish grows an organic vine, this reads as a flight manifest.
 *
 *  THE DRIFT RISK: classic forked this file with nothing tying it to light's,
 *  and silently lost a control for months. tests/test_space.php pins this file
 *  to light's on every control that matters, so that can't happen quietly
 *  again — add a control to light's card and that test says this one lacks it.
 *
 *  Class names reuse light's wherever the meaning is the same, so the base
 *  stylesheet keeps doing the work. New classes are prefixed sp- and exist
 *  only for structure light has no equivalent for.
 *
 *  RENDER VARS: identical to light's — $g (game row + ['players'], ['comments'])
 *  and $readonly.
 * ============================================================================= */
?>
<?php // Skipped entirely when the admin shows deleted games in full: the
      // active markup below renders instead, wrapped and forced read-only
      // by front_event.php. ?>
<?php if ((int)$g['is_archived'] === 1 && deleted_games_display() !== 'full'): // soft-deleted -> a scrubbed mission ?>
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
    // Split once: the manifest needs the confirmed count to number its slots and
    // know how many are open, and the signup label needs it to know whether it
    // is offering a seat or a place on standby.
    $crew    = [];
    $standby = [];
    foreach ($g['players'] as $p) {
        if ((int)$p['is_reserve'] === 1) { $standby[] = $p; } else { $crew[] = $p; }
    }
    $max  = (int)$g['max_players'];
    $open = max(0, $max - count($crew));
    ?>
    <article class="game-card" id="game-<?= (int)$g['id'] ?>">
        <div class="game-main">

            <?php // ---- Module header: status lamp, designation, launch time ---- ?>
            <div class="sp-header">
                <span class="sp-lamp<?= $open === 0 ? ' sp-lamp-full' : '' ?>" aria-hidden="true"></span>
                <div class="game-name-row">
                    <?php $gLink = game_link($g); ?>
                    <h3 class="game-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>
                    <?php if (!$readonly && messaging_allowed()): ?>
                        <a class="msg-icon msg-icon-all" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msgbtn_game_all')) ?>" aria-label="<?= e(t('msgbtn_game_all')) ?>">&#9993;</a>
                    <?php endif; ?>
                </div>
                <span class="game-time sp-clock"><?= e($g['start_time']) ?></span>
            </div>

            <?php if ($canButtons): ?>
                <span class="game-actions sp-actions">
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

            <?php if (!empty($g['thumbnail'])): ?>
                <div class="game-thumb"><img src="<?= e($g['thumbnail']) ?>" alt=""></div>
            <?php endif; ?>

            <?php // ---- Telemetry strip: the numbers, in fixed-width columns ---- ?>
            <div class="game-meta sp-telemetry">
                <span class="sp-cell"><span class="sp-key"><?= e(t('cl_weight')) ?></span><span class="weight-badge weight-<?= $bucket ?>"><?= e(number_format((float)$g['weight'], 1)) ?></span></span>
                <span class="sp-cell"><span class="sp-key"><?= e(t('cl_length')) ?></span><span class="game-length"><?= e(t('game_length_min', (int)$g['length_minutes'])) ?></span></span>
                <?php if (!empty($g['language'])): ?>
                    <span class="sp-cell"><span class="sp-key"><?= e(t('cl_version')) ?></span><span class="game-language"><?= e($g['language']) ?></span></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($g['brings_name'])): ?>
                <p class="game-brings"><span class="sp-key"><?= e(t('game_brings')) ?></span> <strong><?= e($g['brings_name']) ?></strong></p>
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

            <?php // ---- CREW MANIFEST: numbered slots, filled or open ---- ?>
            <div class="players sp-manifest">
                <span class="players-label"><?= e(t('players_label', count($g['players']), $max)) ?></span>
                <ul class="player-list sp-slots">
                    <?php $slot = 0; foreach ($crew as $p): $slot++; ?>
                        <li class="player sp-slot sp-slot-filled">
                            <span class="sp-num"><?= str_pad((string)$slot, 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="sp-who"><?= e($p['name']) ?><?php if ($p['knows_rules'] !== null && $p['knows_rules'] !== ''): ?><span class="p-knows rules-<?= rules_tone($p['knows_rules']) ?>" title="<?= e(knows_rules_label($p['knows_rules'])) ?>">&#128366;</span><?php endif; ?><?php if (is_admin() && !empty($p['user_id'])): ?><span class="p-acct" title="<?= e(t('player_account_bound', $p['account_name'] ?? ('#' . (int)$p['user_id']))) ?>">@</span><?php endif; ?></span>
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

                    <?php // Open slots — light's card has no element for a seat
                          // nobody occupies, which is why this file exists. ?>
                    <?php for ($i = 0; $i < $open; $i++): $slot++; ?>
                        <li class="player sp-slot sp-slot-open">
                            <span class="sp-num"><?= str_pad((string)$slot, 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="sp-who muted"><?= e(t('player_n', $slot)) ?></span>
                        </li>
                    <?php endfor; ?>
                </ul>

                <?php if (!empty($standby)): // standby crew, kept clear of the numbered roster ?>
                    <span class="players-label sp-standby-label"><?= e(t('reserve_tag')) ?></span>
                    <ul class="player-list sp-slots sp-standby">
                        <?php $r = 0; foreach ($standby as $p): $r++; ?>
                            <li class="player player-reserve sp-slot sp-slot-filled">
                                <span class="sp-num">R<?= (int)$r ?></span>
                                <span class="sp-who"><?= e($p['name']) ?><?php if ($p['knows_rules'] !== null && $p['knows_rules'] !== ''): ?><span class="p-knows rules-<?= rules_tone($p['knows_rules']) ?>" title="<?= e(knows_rules_label($p['knows_rules'])) ?>">&#128366;</span><?php endif; ?></span>
                                <?php if (!$readonly && messaging_allowed() && !empty($p['email'])): ?>
                                    <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                                <?php endif; ?>
                                <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                                    <a class="player-del" href="delete_player.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('delete')) ?>" rel="nofollow">&times;</a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!$readonly && can_signup()): ?>
                    <a class="btn btn-small signup-btn" href="sign_up.php?game=<?= (int)$g['id'] ?>">
                        <?= $open === 0 ? e(t('signup_reserve')) : e(t('signup')) ?>
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
