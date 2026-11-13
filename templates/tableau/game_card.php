<?php
/* =============================================================================
 *  templates/tableau/game_card.php — the TABLEAU game card. Presentation only.
 * -----------------------------------------------------------------------------
 *  Classic's LAYOUT, schematic's FEEL: a portrait card per game with its seat
 *  sheet beside it (as in classic), dressed like a modern board game's
 *  graphic design — cream card stock on a dark table, a colour band per table,
 *  small spaced-out capitals for every label, data in labelled cells.
 *
 *    LEFT  (.gc-info)    — the card: owner tools, a colour band with the start
 *                          time, box art, the name, a grid of labelled cells
 *                          (weight / players / length / brings / version), the
 *                          rules line, the comment and the discussion.
 *    RIGHT (.gc-players) — the seat sheet: a capacity header with a fill bar,
 *                          NUMBERED SEATS (1..max_players) — a taken seat names
 *                          the player, a free one is a hatched "sign up" row —
 *                          then the reserves.
 *
 *  Same data contract and permission rules as classic's card (it started as a
 *  copy of it, so it carries every hook the base card's tests pin):
 *    RENDER VARS: $g (game row incl. ['players'] and ['comments']), $readonly,
 *                 $table_no (the table's number, for the colour band).
 *    - edit/delete tools + per-player resign/edit: verify_can_show_buttons()
 *      with the stored email, exactly like every other theme.
 *    - message envelopes: messaging_allowed() AND not read-only.
 *    - every free seat links to the same sign_up.php; seat numbers are cosmetic.
 *  Styling lives in templates/tableau/css/style.css.
 * ============================================================================= */
$chan = table_chan_class($table_no ?? null);
?>
<?php // Skipped entirely when the admin shows deleted games in full: the
      // active markup below renders instead, wrapped and forced read-only
      // by front_event.php. ?>
<?php if ((int)$g['is_archived'] === 1 && deleted_games_display() !== 'full'): // soft-deleted -> dimmed card + bring-back ?>
    <article class="game-card game-archived <?= $chan ?>" id="game-<?= (int)$g['id'] ?>">
        <div class="gc-info">
            <h3 class="gc-name"><?= e($g['name']) ?></h3>
            <div class="gc-note"><?= e(t('game_archived_note')) ?></div>
            <?php if (!$readonly): ?>
                <div class="gc-note">
                    <a class="btn btn-small" href="bring_back.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('bringback_button')) ?></a>
                    <?php if (is_admin()): // admins may remove the archived game for good ?>
                        <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('game_purge_button')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
<?php else: ?>
    <?php
    // Split players into confirmed (fill the numbered seats, in signup order)
    // and reserves (listed after the seats). $g['players'] arrives ordered
    // is_reserve ASC, id ASC, so both keep first-come order.
    $confirmed = [];
    $reserves  = [];
    foreach ($g['players'] as $p) {
        if ((int)$p['is_reserve'] === 0) { $confirmed[] = $p; } else { $reserves[] = $p; }
    }
    $max      = (int)$g['max_players'];
    $taken    = count($confirmed);
    $isFull   = $taken >= $max;
    // SEAT CAP, as in classic: at most 10 rows, but always one free sign-up row
    // while seats remain; the hidden remainder is summarised in a note.
    $shownSlots = min($max, max(10, $taken + 1));
    $canMsg   = !$readonly && messaging_allowed();   // shared gate (guests allowed when toggled)
    $fillPct  = $max > 0 ? min(100, (int)round($taken * 100 / $max)) : 0;
    ?>
    <article class="game-card <?= $chan ?><?= $isFull ? ' gc-full' : '' ?>" id="game-<?= (int)$g['id'] ?>">
        <div class="gc-info">
            <?php // Owner tools: delete / edit / move, and the "message everyone" envelope. ?>
            <?php if (!$readonly && verify_can_show_buttons($g['added_by_user_id'], $g['brings_email'] ?? null)): ?>
                <div class="gc-tabs">
                    <a class="gc-tab gc-tab-del" href="delete_game.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('delete')) ?></a>
                    <a class="gc-tab" href="edit_game.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('edit')) ?></a>
                    <?php // Admin-only: move this game to another table on the same day.
                          // Guarded again server-side in move_item.php. ?>
                    <?php if (is_admin()): ?>
                        <a class="gc-tab" href="move_item.php?game=<?= (int)$g['id'] ?>" rel="nofollow"><?= e(t('move_btn')) ?></a>
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

            <?php // The colour band: the table's colour, the start time large,
                  // the game's number small — the card's "header strip". ?>
            <div class="gc-band-top">
                <span class="gc-time" data-label="<?= e(t('cl_start')) ?>"><?= e($g['start_time']) ?></span>
                <span class="gc-num">#<?= str_pad((string)(int)$g['id'], 3, '0', STR_PAD_LEFT) ?></span>
            </div>
            <?php // Add this game to a calendar — for EVERYONE, so outside the owner-only block above. ?>
            <?= game_calendar_html($g) ?>

            <?php if (!empty($g['thumbnail'])): ?>
                <div class="gc-thumb"><img src="<?= e($g['thumbnail']) ?>" alt=""></div>
            <?php endif; ?>

            <?php $gLink = game_link($g); // BGG page, or the custom link (option-gated) ?>
            <h3 class="gc-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>

            <?php // The data, as labelled cells: a small key over a bold value. ?>
            <?php $bucket = weight_bucket($g['weight']); ?>
            <div class="gc-cells">
                <div class="gc-cell gc-cell-weight weight-<?= $bucket ?>">
                    <span class="gc-key"><?= e(t('cl_weight')) ?></span>
                    <span class="gc-val"><?= e(number_format((float)$g['weight'], 1, ',', '')) ?></span>
                </div>
                <div class="gc-cell">
                    <span class="gc-key"><?= e(t('cl_players')) ?></span>
                    <span class="gc-val"><?= $taken ?>/<?= $max ?></span>
                </div>
                <?php if ((int)$g['length_minutes'] > 0): ?>
                    <div class="gc-cell">
                        <span class="gc-key"><?= e(t('cl_length')) ?></span>
                        <span class="gc-val"><?= (int)$g['length_minutes'] ?>&prime;</span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($g['language'])): ?>
                    <div class="gc-cell">
                        <span class="gc-key"><?= e(t('cl_version')) ?></span>
                        <span class="gc-val"><?= e($g['language']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($g['brings_name'])): ?>
                    <div class="gc-cell gc-cell-wide">
                        <span class="gc-key"><?= e(game_brings_label($g)) ?></span>
                        <span class="gc-val"><?= e($g['brings_name']) ?><?= game_club_note_html($g) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="gc-rules rules-<?= rules_tone($g['explain_rules']) ?>"><?= e(explain_rules_label($g['explain_rules'])) ?></div>
            <?php // Rules / manual link, right after the rules line it belongs with
                  // (null when the admin has the feature off). ?>
            <?php if (($mlink = manual_link($g)) !== null): ?>
                <a class="btn btn-small game-manual" href="<?= e($mlink) ?>" target="_blank" rel="noopener noreferrer"
                   title="<?= e(t('game_manual_title')) ?>"><?= e(t('game_manual_btn')) ?></a>
            <?php endif; ?>

            <?php if (!empty($g['comment'])): ?>
                <div class="gc-comment"><span class="gc-key"><?= e(t('cl_comment')) ?></span><?= nl2br(e($g['comment'])) ?></div>
            <?php endif; ?>

            <?php // Discussion thread + the "+ add comment" pill (native <details>). ?>
            <?php if (opt_bool('allow_discussions')): ?>
                <div class="discussion">
                    <?php if (!empty($g['comments'])): ?>
                        <ul class="comment-list">
                            <?php foreach ($g['comments'] as $c): ?>
                                <li><span class="c-name"><?= e($c['name']) ?>:</span> <?= nl2br(e($c['comment'])) ?><?= comment_actions_html($c, 'game') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!$readonly): ?>
                        <?php // Same class (and so the same pill) as the poll card's comment box. ?>
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

        <?php // Right: the seat sheet — capacity header, numbered seats, reserves. ?>
        <div class="gc-players">
            <div class="gc-sheet-head">
                <span class="gc-key"><?= e(t('cl_players')) ?></span>
                <span class="gc-val"><?= $taken ?>/<?= $max ?></span>
                <span class="gc-fill" aria-hidden="true"><span style="width: <?= $fillPct ?>%"></span></span>
            </div>
            <?php for ($i = 0; $i < $shownSlots; $i++): ?>
                <?php $p = $confirmed[$i] ?? null; ?>
                <?php if ($p !== null): // a taken seat ?>
                    <div class="gc-slot">
                        <span class="gc-seat-n"><?= $i + 1 ?></span>
                        <span class="gc-who"><strong><?= player_name_html($p) ?></strong><?php if (is_admin() && !empty($p['user_id'])): // admin-only account-bound marker ?><span class="p-acct" title="<?= e(t('player_account_bound', $p['account_name'] ?? ('#' . (int)$p['user_id']))) ?>">@</span><?php endif; ?>
                            <?php // Same "signed up by X" note as the light theme (see there for the why).
                            $signedBy = player_signed_up_by($p);
                                if ($signedBy !== ''): ?>
                                <span class="p-signedby"><?= e(t('player_signed_by', $signedBy)) ?></span>
                            <?php endif; ?>
                            <?php $kn = knows_rules_label($p['knows_rules']); ?>
                            <?php if ($kn !== ''): ?><span class="gc-knows rules-<?= rules_tone($p['knows_rules']) ?>"><?= e(mb_strtolower($kn)) ?></span><?php endif; ?>
                        </span>
                        <?php if ($canMsg && !empty($p['email'])): ?>
                            <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(player_msg_title($p)) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                        <?php endif; ?>
                        <?php if (!$readonly && verify_can_show_buttons($p['user_id'], $p['email'] ?? null)): ?>
                            <?= player_edit_html($p) /* pen: same rule as the remove button */ ?>
                            <a class="gc-resign" href="delete_player.php?player=<?= (int)$p['id'] ?>" rel="nofollow"><?= e(t('resign')) ?></a>
                        <?php endif; ?>
                    </div>
                <?php elseif (!$readonly && can_signup()): // a free seat -> sign-up row ?>
                    <a class="gc-signup gc-seat-free" href="sign_up.php?game=<?= (int)$g['id'] ?>"><span class="gc-seat-n"><?= $i + 1 ?></span><?= e(t('signup')) ?></a>
                <?php else: // free, but sign-ups are not possible -> a plain empty seat ?>
                    <div class="gc-slot gc-seat-empty"><span class="gc-seat-n"><?= $i + 1 ?></span><span class="muted">—</span></div>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($max > $shownSlots): // the summarised hidden free seats ?>
                <p class="gc-more-slots"><?= e(t('cl_more_slots', $max - $shownSlots)) ?></p>
            <?php endif; ?>

            <?php // Reserve heading, once, instead of tagging every name (see classic). ?>
            <?php if (!empty($reserves)): ?>
                <div class="gc-reserve-head reserve-tag"><?= e(t('reserve_tag')) ?></div>
            <?php endif; ?>
            <?php foreach ($reserves as $p): // reserves listed under the seats ?>
                <div class="gc-slot gc-reserve">
                    <span class="gc-who"><strong><?= player_name_html($p) ?></strong>
                        <?php // A reserve's rules knowledge matters as much as a confirmed player's. ?>
                        <?php $kn = knows_rules_label($p['knows_rules']); ?><?php if ($kn !== ''): ?><span class="gc-knows rules-<?= rules_tone($p['knows_rules']) ?>"><?= e(mb_strtolower($kn)) ?></span><?php endif; ?>
                    </span>
                    <?php if ($canMsg && !empty($p['email'])): ?>
                        <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(player_msg_title($p)) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                    <?php endif; ?>
                    <?php if (!$readonly && verify_can_show_buttons($p['user_id'], $p['email'] ?? null)): ?>
                        <?= player_edit_html($p) /* pen: same rule as the remove button */ ?>
                        <a class="gc-resign" href="delete_player.php?player=<?= (int)$p['id'] ?>" rel="nofollow"><?= e(t('resign')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!$readonly && can_signup() && $isFull): // full -> offer the reserve list ?>
                <a class="gc-signup gc-signup-reserve" href="sign_up.php?game=<?= (int)$g['id'] ?>"><?= e(t('signup_reserve')) ?></a>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>
