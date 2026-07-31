<?php
/* =============================================================================
 *  templates/steampunk/game_card.php — one game, as a pressure vessel.
 * -----------------------------------------------------------------------------
 *  LAYOUT is classic's on purpose (this theme was asked for on that footprint):
 *  a narrow instrument column (.gc-info) beside a wider crew plate
 *  (.gc-players) that overlaps it. Those class names are classic's, and the
 *  base positioning rules they carry are re-used rather than reinvented — only
 *  the material changes.
 *
 *  WHY IT FORKS: two reasons, both structural rather than decorative.
 *    1. Classic's two-panel split doesn't exist in light's card at all, so the
 *       requested layout is impossible without this file.
 *    2. THE GAUGE. The players panel carries a working pressure gauge whose
 *       needle points at how full the game is. It needs a needle element and a
 *       computed angle, which no amount of CSS on light's markup can invent.
 *       It reads live data — an ornament that always showed the same thing
 *       would just be a picture of a gauge.
 *
 *  THE DRIFT RISK is not hypothetical: classic forked this same file and its
 *  comment form silently lost the anti-bot field, so commenting from that
 *  theme was rejected outright until it was spotted. tests/test_steampunk.php
 *  pins this file to light's on every control, and tests/test_antibot.php now
 *  scans EVERY theme for a guarded form missing its field.
 *
 *  RENDER VARS: identical to light's — $g (game row + ['players'], ['comments'])
 *  and $readonly.
 * ============================================================================= */
?>
<?php if ((int)$g['is_archived'] === 1): // soft-deleted -> a decommissioned rig ?>
    <article class="game-card game-archived" id="game-<?= (int)$g['id'] ?>">
        <div class="gc-info">
            <h3 class="gc-name"><?= e($g['name']) ?></h3>
            <div class="gc-band gc-row"><?= e(t('game_archived_note')) ?></div>
            <?php if (!$readonly): ?>
                <div class="gc-band">
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
    $max = (int)$g['max_players'];
    // Slots shown: the full complement, but at least ten so a large game keeps
    // a readable plate (classic uses the same rule).
    $shownSlots = min($max, max(10, count($crew) + 1));

    // THE GAUGE READING. The needle sweeps 180 degrees from lower-left to
    // lower-right across the dial in img/gauge.svg, so 0 crew = -90deg and a
    // full complement = +90deg. Computed here because only PHP knows the
    // counts; CSS just rotates by whatever this says.
    $fillPct   = $max > 0 ? min(1, count($crew) / $max) : 0;
    $needleDeg = -90 + ($fillPct * 180);
    ?>
    <article class="game-card" id="game-<?= (int)$g['id'] ?>">
        <div class="gc-info">
            <?php if (!$readonly && verify_can_show_buttons($g['added_by_user_id'])): ?>
                <div class="gc-tabs">
                    <a class="gc-tab gc-tab-del" href="delete_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('delete')) ?></a>
                    <a class="gc-tab" href="edit_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('edit')) ?></a>
                    <?php if ($canMsg): ?>
                        <a class="msg-icon msg-icon-all" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msgbtn_game_all')) ?>" aria-label="<?= e(t('msgbtn_game_all')) ?>">&#9993;</a>
                    <?php endif; ?>
                </div>
            <?php elseif ($canMsg): ?>
                <div class="gc-tabs">
                    <a class="msg-icon msg-icon-all" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msgbtn_game_all')) ?>" aria-label="<?= e(t('msgbtn_game_all')) ?>">&#9993;</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($g['thumbnail'])): ?>
                <div class="gc-thumb"><img src="<?= e($g['thumbnail']) ?>" alt=""></div>
            <?php endif; ?>

            <?php $gLink = game_link($g); ?>
            <h3 class="gc-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>

            <div class="gc-band gc-waga weight-<?= $bucket ?>"><?= e(t('cl_weight')) ?>: <strong><?= e(number_format((float)$g['weight'], 2, ',', '')) ?></strong></div>
            <div class="gc-band gc-row"><?= e(t('cl_players')) ?>: <strong><?= count($crew) ?> / <?= $max ?></strong></div>
            <div class="gc-band gc-row"><?= e(t('cl_start')) ?>: <strong><?= e($g['start_time']) ?></strong></div>
            <?php if ((int)$g['length_minutes'] > 0): ?>
                <div class="gc-band gc-row"><?= e(t('cl_length')) ?>: <strong><?= e(t('game_length_min', (int)$g['length_minutes'])) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($g['brings_name'])): ?>
                <div class="gc-band gc-row"><?= e(t('game_brings')) ?>: <strong><?= e($g['brings_name']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($g['language'])): ?>
                <div class="gc-band gc-row"><?= e(t('cl_version')) ?>: <strong><?= e($g['language']) ?></strong></div>
            <?php endif; ?>
            <div class="gc-band gc-rules rules-<?= rules_tone($g['explain_rules']) ?>"><?= e(explain_rules_label($g['explain_rules'])) ?></div>

            <?php if (!empty($g['comment'])): ?>
                <div class="gc-band gc-comment"><?= e(t('cl_comment')) ?>:<br><?= nl2br(e($g['comment'])) ?></div>
            <?php endif; ?>

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
                                <button type="submit" class="btn btn-small btn-primary"><?= e(t('comment_submit')) ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php // ---- The crew plate: the gauge, then numbered berths ---- ?>
        <div class="gc-players">
            <?php // THE GAUGE. The needle's angle is inline because it is data,
                  // not styling — the stylesheet cannot know how full a game is. ?>
            <div class="sk-gauge" role="img"
                 aria-label="<?= e(t('cl_players')) ?>: <?= count($crew) ?> / <?= $max ?>">
                <span class="sk-needle" style="transform: rotate(<?= (int)round($needleDeg) ?>deg)"></span>
                <span class="sk-gauge-read"><?= count($crew) ?>/<?= $max ?></span>
            </div>

            <?php for ($i = 0; $i < $shownSlots; $i++): ?>
                <?php $p = $crew[$i] ?? null; ?>
                <?php if ($p !== null): ?>
                    <div class="gc-slot sk-berth">
                        <span class="sk-rivet" aria-hidden="true"></span>
                        <strong><?= e($p['name']) ?></strong>
                        <?php if ($p['knows_rules'] !== null && $p['knows_rules'] !== ''): ?>
                            <span class="gc-knows rules-<?= rules_tone($p['knows_rules']) ?>" title="<?= e(knows_rules_label($p['knows_rules'])) ?>"><?= e(knows_rules_label($p['knows_rules'])) ?></span>
                        <?php endif; ?>
                        <?php if (is_admin() && !empty($p['user_id'])): ?>
                            <span class="p-acct" title="<?= e(t('player_account_bound', $p['account_name'] ?? ('#' . (int)$p['user_id']))) ?>">@</span>
                        <?php endif; ?>
                        <?php if ($canMsg && !empty($p['email'])): ?>
                            <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                        <?php endif; ?>
                        <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                            <a class="gc-resign" href="delete_player.php?player=<?= (int)$p['id'] ?>"><?= e(t('delete')) ?></a>
                        <?php endif; ?>
                        <?php if (!empty($p['user_id']) && !empty($p['account_name'])
                            && mb_strtolower(trim($p['name'])) !== mb_strtolower(trim($p['account_name']))): ?>
                            <span class="p-signedby"><?= e(t('player_signed_by', $p['account_name'])) ?></span>
                        <?php endif; ?>
                    </div>
                <?php else: // an empty berth ?>
                    <div class="gc-slot sk-berth sk-berth-free">
                        <span class="sk-rivet" aria-hidden="true"></span>
                        <span class="muted"><?= e(t('player_n', $i + 1)) ?></span>
                    </div>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($max > $shownSlots): ?>
                <div class="gc-more-slots"><?= e(t('cl_more_slots', $max - $shownSlots)) ?></div>
            <?php endif; ?>

            <?php foreach ($reserves as $p): ?>
                <div class="gc-slot gc-reserve sk-berth">
                    <span class="sk-rivet" aria-hidden="true"></span>
                    <strong><?= e($p['name']) ?></strong> <span class="reserve-tag"><?= e(t('reserve_tag')) ?></span>
                    <?php if ($canMsg && !empty($p['email'])): ?>
                        <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                    <?php endif; ?>
                    <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                        <a class="gc-resign" href="delete_player.php?player=<?= (int)$p['id'] ?>"><?= e(t('delete')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!$readonly && can_signup()): ?>
                <a class="gc-signup" href="sign_up.php?game=<?= (int)$g['id'] ?>">
                    <?= count($crew) >= $max ? e(t('signup_reserve')) : e(t('signup')) ?>
                </a>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>
