<?php
/* =============================================================================
 *  templates/blossom/game_card.php — one game as a storybook card.
 * -----------------------------------------------------------------------------
 *  THE READING: those pastel board games (Mycelia, Splendent Vale, the dragon
 *  village) put a big soft illustration on top and the words underneath, like a
 *  picture book page. So a game here is not a row with a thumbnail beside it —
 *  it is a CARD WITH A HERO BAND: art across the full width, the title under it,
 *  then the details as soft chips.
 *
 *  LAYOUT IS DELIBERATELY UNLIKE THE OTHERS. light/dark/classic put the
 *  thumbnail in a LEFT COLUMN beside the text; schematic and glass run a
 *  horizontal strip. This is the only vertical one, which is what lets the
 *  two-column staggered grid work — cards of similar width and varying height,
 *  read down rather than across.
 *
 *  WHY IT FORKS AT ALL (the bar for forking is high — see docs/TECHNICAL.md):
 *    1. THE HERO BAND needs the image OUT of the controls column and spanning
 *       the card. Light's markup nests the thumbnail and the edit/delete
 *       buttons in one .game-thumb box; no CSS reorders one out of the other.
 *    2. THE META CHIPS want time, length and weight as siblings in one strip
 *       with the bringer and language, which light spreads across a meta row
 *       and two separate paragraphs.
 *
 *  THE DRIFT RISK IS REAL: classic forked this file and silently lost its
 *  anti-bot field, breaking commenting on that theme until it was noticed.
 *  tests/test_newthemes.php pins every control below against light's card.
 *
 *  RENDER VARS (identical to light's):
 *    $g        — the game row, plus ['players'] and ['comments'].
 *    $readonly — archived/read-only view (hides every control).
 * ============================================================================= */
?>
<?php // Same gate as light: with deleted_games_display() = 'full' the name-only
      // card is skipped and the full card renders instead, wrapped read-only by
      // front_event.php. ?>
<?php if ((int)$g['is_archived'] === 1 && deleted_games_display() !== 'full'): ?>
    <article class="game-card game-archived bl-card" id="game-<?= (int)$g['id'] ?>">
        <div class="bl-body">
            <h3 class="game-name"><?= e($g['name']) ?></h3>
            <p class="muted"><?= e(t('game_archived_note')) ?></p>
            <?php if (!$readonly): ?>
                <div class="bl-actions">
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
    $bucket     = weight_bucket($g['weight']);
    $canButtons = !$readonly && verify_can_show_buttons($g['added_by_user_id']);
    $confirmed  = 0;
    foreach ($g['players'] as $pp) { if ((int)$pp['is_reserve'] === 0) $confirmed++; }
    $isFull = $confirmed >= (int)$g['max_players'];
    ?>
    <article class="game-card bl-card" id="game-<?= (int)$g['id'] ?>">

        <?php // HERO BAND. Rendered only when there is art — a manual game with no
              // thumbnail gets a plain card rather than an empty grey box. ?>
        <?php if (!empty($g['thumbnail'])): ?>
            <div class="bl-hero">
                <img src="<?= e($g['thumbnail']) ?>" alt="">
                <span class="weight-badge weight-<?= $bucket ?> bl-hero-weight" title="<?= e(number_format((float)$g['weight'], 1)) ?>"><?= e(number_format((float)$g['weight'], 1)) ?></span>
            </div>
        <?php endif; ?>

        <div class="bl-body">
            <div class="game-name-row">
                <?php $gLink = game_link($g); ?>
                <h3 class="game-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>
                <?php if (!$readonly && messaging_allowed()): ?>
                    <a class="msg-icon msg-icon-all" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msgbtn_game_all')) ?>" aria-label="<?= e(t('msgbtn_game_all')) ?>">&#9993;</a>
                <?php endif; ?>
            </div>

            <?php // META CHIPS. The weight badge only appears here when there was no
                  // hero band to float it over, so it is never shown twice. ?>
            <div class="game-meta bl-chips">
                <?php if (empty($g['thumbnail'])): ?>
                    <span class="weight-badge weight-<?= $bucket ?>" title="<?= e(number_format((float)$g['weight'], 1)) ?>"><?= e(number_format((float)$g['weight'], 1)) ?></span>
                <?php endif; ?>
                <span class="game-time"><?= e($g['start_time']) ?></span>
                <span class="game-length"><?= e(t('game_length_min', (int)$g['length_minutes'])) ?></span>
                <?php if (!empty($g['language'])): ?>
                    <?php // The chip shows the value alone to stay compact; the label
                          // rides along as a title so the meaning is never lost. ?>
                    <span class="bl-chip bl-chip-lang" title="<?= e(t('f_language')) ?>"><?= e($g['language']) ?></span>
                <?php endif; ?>
            </div>

            <?php if (!empty($g['brings_name'])): ?>
                <p class="game-brings"><?= e(t('game_brings')) ?>: <strong><?= e($g['brings_name']) ?></strong></p>
            <?php endif; ?>
            <p class="game-rules rules-<?= rules_tone($g['explain_rules']) ?>"><?= e(explain_rules_label($g['explain_rules'])) ?></p>
            <?php if (($mlink = manual_link($g)) !== null): ?>
                <a class="btn btn-small game-manual" href="<?= e($mlink) ?>" target="_blank" rel="noopener noreferrer"
                   title="<?= e(t('game_manual_title')) ?>"><?= e(t('game_manual_btn')) ?></a>
            <?php endif; ?>
            <?php if (!empty($g['comment'])): ?>
                <p class="game-comment"><?= nl2br(e($g['comment'])) ?></p>
            <?php endif; ?>

            <div class="players">
                <span class="players-label"><?= e(t('players_label', count($g['players']), (int)$g['max_players'])) ?></span>
                <?php if (!empty($g['players'])): ?>
                    <ul class="player-list">
                        <?php foreach ($g['players'] as $p): ?>
                            <li class="player<?= (int)$p['is_reserve'] === 1 ? ' player-reserve' : '' ?>">
                                <?= e($p['name']) ?><?php if ($p['knows_rules'] !== null && $p['knows_rules'] !== ''): ?><span class="p-knows rules-<?= rules_tone($p['knows_rules']) ?>" title="<?= e(knows_rules_label($p['knows_rules'])) ?>">&#128366;</span><?php endif; ?><?php if (is_admin() && !empty($p['user_id'])): ?><span class="p-acct" title="<?= e(t('player_account_bound', $p['account_name'] ?? ('#' . (int)$p['user_id']))) ?>">@</span><?php endif; ?><?php if ((int)$p['is_reserve'] === 1): ?> <span class="reserve-tag"><?= e(t('reserve_tag')) ?></span><?php endif; ?>
                                <?php if (!$readonly && messaging_allowed() && !empty($p['email'])): ?>
                                    <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                                <?php endif; ?>
                                <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                                    <a class="player-del" href="delete_player.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('delete')) ?>">&times;</a>
                                <?php endif; ?>
                                <?php $signedBy = player_signed_up_by($p);
                                    if ($signedBy !== ''): ?>
                                    <span class="p-signedby"><?= e(t('player_signed_by', $signedBy)) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!$readonly && can_signup()): ?>
                    <a class="btn btn-small signup-btn" href="sign_up.php?game=<?= (int)$g['id'] ?>">
                        <?= $isFull ? e(t('signup_reserve')) : e(t('signup')) ?>
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
                                <button type="submit" class="btn btn-small btn-primary"><?= e(t('comment_submit')) ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php // FOOTER CONTROLS. Light stacks these under the thumbnail in the
                  // left column; there is no such column here, so they sit at the
                  // bottom of the card where they read as "things you can do to
                  // this game" rather than as part of the picture. ?>
            <?php if ($canButtons): ?>
                <div class="bl-actions">
                    <a class="btn btn-small" href="edit_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('edit')) ?></a>
                    <?php // Re-checked server-side in move_item.php — hiding a button
                          // is a courtesy, not a permission check. ?>
                    <?php if (is_admin()): ?>
                        <a class="btn btn-small" href="move_item.php?game=<?= (int)$g['id'] ?>"><?= e(t('move_btn')) ?></a>
                    <?php endif; ?>
                    <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('delete')) ?></a>
                </div>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>
