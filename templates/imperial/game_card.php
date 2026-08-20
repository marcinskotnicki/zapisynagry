<?php
/* =============================================================================
 *  templates/imperial/game_card.php — one game, as a sanctioned requisition.
 * -----------------------------------------------------------------------------
 *  THE READING: this is a Departmento Munitorum manifest. Games are sanctioned
 *  deployments, players are assigned crew, the table is a muster point. That's
 *  a real fit rather than a costume — the app already IS a roster of timed
 *  assignments with people attached, which is exactly what these documents are.
 *
 *  LAYOUT is the classic/steampunk two-panel split (a narrow record column
 *  beside a wider roster panel that overlaps it), as asked. The differences
 *  from steampunk are deliberate and structural, not just paint:
 *    - a DESIGNATION line above the name: every record here is numbered,
 *      because a bureaucracy numbers everything;
 *    - the purity seal hanging off the record column;
 *    - the roster is a numbered muster list with vacancies stated, rather
 *      than steampunk's rivet-and-berth treatment.
 *
 *  WHY IT FORKS:
 *    1. The two-panel split doesn't exist in light's card at all.
 *    2. THE SEAL stamps the game's WEIGHT into the wax — it reports live data,
 *      so it needs an element and a value, which CSS alone can't invent.
 *    3. Vacancies are listed as their own rows; light renders only players
 *      that exist.
 *
 *  THE DRIFT RISK is not hypothetical — classic forked this file and silently
 *  lost its anti-bot field, breaking commenting on that theme until it was
 *  caught. tests/test_imperial.php pins this file to light's on every control,
 *  and tests/test_antibot.php scans every theme for a guarded form missing its
 *  hidden fields.
 *
 *  RENDER VARS: identical to light's — $g (game row + ['players'], ['comments'])
 *  and $readonly.
 * ============================================================================= */
?>
<?php // Skipped entirely when the admin shows deleted games in full: the
      // active markup below renders instead, wrapped and forced read-only
      // by front_event.php. ?>
<?php if ((int)$g['is_archived'] === 1 && deleted_games_display() !== 'full'): // struck from the record ?>
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
    $max  = (int)$g['max_players'];
    $open = max(0, $max - count($crew));
    // Show the full complement, but at least ten rows so a large muster still
    // reads as a list (the same rule classic and steampunk use).
    $shownSlots = min($max, max(10, count($crew) + 1));
    ?>
    <article class="game-card" id="game-<?= (int)$g['id'] ?>">
        <div class="gc-info">
            <?php if (!$readonly && verify_can_show_buttons($g['added_by_user_id'])): ?>
                <div class="gc-tabs">
                    <a class="gc-tab gc-tab-del" href="delete_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('delete')) ?></a>
                    <a class="gc-tab" href="edit_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('edit')) ?></a>
                    <?php // Admin-only: move this game to another table on the same day.
                          // Guarded again server-side in move_item.php — hiding a button
                          // is a UI courtesy, not a permission check. ?>
                    <?php if (is_admin()): ?>
                        <a class="gc-tab" href="move_item.php?game=<?= (int)$g['id'] ?>"><?= e(t('move_btn')) ?></a>
                    <?php endif; ?>
                    <?php if ($canMsg): ?>
                        <a class="msg-icon msg-icon-all" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msgbtn_game_all')) ?>" aria-label="<?= e(t('msgbtn_game_all')) ?>">&#9993;</a>
                    <?php endif; ?>
                </div>
            <?php elseif ($canMsg): ?>
                <div class="gc-tabs">
                    <a class="msg-icon msg-icon-all" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msgbtn_game_all')) ?>" aria-label="<?= e(t('msgbtn_game_all')) ?>">&#9993;</a>
                </div>
            <?php endif; ?>

            <?php // Designation: a bureaucracy numbers every record it keeps, so
                  // the game's own id becomes its file reference. Real data, not
                  // invented decoration — it is the row you would quote to find
                  // this entry again. ?>
            <div class="im-designation">
                <span class="im-ref">M<?= str_pad((string)(int)$g['id'], 5, '0', STR_PAD_LEFT) ?></span>
                <span class="im-sanction"><?= e(t('cl_start')) ?> <span class="sanction-time"><?= e($g['start_time']) ?></span></span>
            </div>

            <?php if (!empty($g['thumbnail'])): ?>
                <div class="gc-thumb"><img src="<?= e($g['thumbnail']) ?>" alt=""></div>
            <?php endif; ?>

            <?php $gLink = game_link($g); ?>
            <h3 class="gc-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>

            <?php // ---- THE SEAL: the weight, stamped into wax ---- ?>
            <div class="im-seal weight-<?= $bucket ?>"
                 title="<?= e(t('cl_weight')) ?>: <?= e(number_format((float)$g['weight'], 2, ',', '')) ?>">
                <span class="im-seal-value"><?= e(number_format((float)$g['weight'], 1, ',', '')) ?></span>
                <span class="im-seal-label"><?= e(t('cl_weight')) ?></span>
            </div>

            <div class="gc-band gc-row"><?= e(t('cl_players')) ?>: <strong><?= count($crew) ?> / <?= $max ?></strong></div>
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
            <?php // Rules / manual link, right after the rules line it belongs with.
                  // manual_link() returns null when the admin has the feature off, so
                  // this disappears everywhere at once without touching stored data. ?>
            <?php if (($mlink = manual_link($g)) !== null): ?>
                <a class="btn btn-small game-manual" href="<?= e($mlink) ?>" target="_blank" rel="noopener noreferrer"
                   title="<?= e(t('game_manual_title')) ?>"><?= e(t('game_manual_btn')) ?></a>
            <?php endif; ?>

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
                                <?= captcha_html('comment') ?>
                                <button type="submit" class="btn btn-small btn-primary"><?= e(t('comment_submit')) ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php // ---- The muster roll: numbered assignments, vacancies stated ---- ?>
        <div class="gc-players">
            <div class="im-muster-head">
                <span><?= e(t('cl_players')) ?></span>
                <span class="im-muster-count"><?= count($crew) ?>/<?= $max ?></span>
            </div>

            <?php for ($i = 0; $i < $shownSlots; $i++): ?>
                <?php $p = $crew[$i] ?? null; ?>
                <?php if ($p !== null): ?>
                    <div class="gc-slot im-assigned">
                        <span class="im-line-no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
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
                        <?php // Before the delete link, never after: .gc-resign is
                              // pushed right by an auto margin, so anything after
                              // it lands to its right and strands the button. ?>
                        <?php $signedBy = player_signed_up_by($p);
                            if ($signedBy !== ''): ?>
                            <span class="p-signedby"><?= e(t('player_signed_by', $signedBy)) ?></span>
                        <?php endif; ?>
                        <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                            <a class="gc-resign" href="delete_player.php?player=<?= (int)$p['id'] ?>"><?= e(t('delete')) ?></a>
                        <?php endif; ?>
                    </div>
                <?php else: // a vacancy on the roll ?>
                    <div class="gc-slot im-vacant">
                        <span class="im-line-no"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <span class="muted"><?= e(t('player_n', $i + 1)) ?></span>
                    </div>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($max > $shownSlots): ?>
                <div class="gc-more-slots"><?= e(t('cl_more_slots', $max - $shownSlots)) ?></div>
            <?php endif; ?>

            <?php foreach ($reserves as $p): ?>
                <div class="gc-slot gc-reserve im-assigned">
                    <span class="im-line-no">R</span>
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
                    <?= $open === 0 ? e(t('signup_reserve')) : e(t('signup')) ?>
                </a>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>
