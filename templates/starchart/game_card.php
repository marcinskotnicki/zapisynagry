<?php
/* =============================================================================
 *  templates/starchart/game_card.php — one game as a departure listing.
 * -----------------------------------------------------------------------------
 *  STARTS FROM classic's card, and then changes the part that matters. Classic
 *  puts a DARK info panel beside a LIGHT roster. This theme inverts that — an
 *  ivory chart card beside a nebula-dark roster — and lifts the two facts you
 *  scan a listing board for, the DEPARTURE TIME and the difficulty, out of the
 *  band stack into a strip of their own at the top.
 *
 *  So unlike templates/ironworks/game_card.php, which is classic's markup
 *  verbatim and restyled entirely in CSS, this one genuinely diverges — the
 *  departure strip has no equivalent in classic to restyle into, and leaving
 *  the time buried as the third grey band would have wasted the whole idea.
 *
 *  WHAT IS NOT ALLOWED TO DIVERGE is the control set: every button, gate and
 *  label classic's card carries is carried here, and tests/test_starchart.php
 *  diffs this file against light's for exactly that. classic itself once forked
 *  game_card.php and silently lost its anti-bot field, breaking commenting on
 *  that theme for months.
 *
 *  RENDER VARS (identical to classic's and light's):
 *    $g        — the game row, plus ['players'] and ['comments'].
 *    $readonly — archived/read-only view (hides every control).
 * ============================================================================= */
?>
<?php // Skipped entirely when the admin shows deleted games in full: the
      // active markup below renders instead, wrapped and forced read-only
      // by front_event.php. ?>
<?php if ((int)$g['is_archived'] === 1 && deleted_games_display() !== 'full'): // soft-deleted -> dimmed card + bring-back ?>
    <article class="game-card game-archived" id="game-<?= (int)$g['id'] ?>">
        <div class="gc-info">
            <h3 class="gc-name"><?= e($g['name']) ?></h3>
            <div class="gc-band gc-row"><?= e(t('game_archived_note')) ?></div>
            <?php if (!$readonly): ?>
                <div class="gc-band">
                    <a class="btn btn-small" href="bring_back.php?game=<?= (int)$g['id'] ?>"><?= e(t('bringback_button')) ?></a>
                    <?php if (is_admin()): // admins may remove the archived game for good ?>
                        <a class="btn btn-small btn-danger" href="delete_game.php?game=<?= (int)$g['id'] ?>"><?= e(t('game_purge_button')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </article>
<?php else: ?>
    <?php
    // Split players into confirmed (fill the numbered slots, in signup order)
    // and reserves (listed after the slots). $g['players'] arrives ordered
    // is_reserve ASC, id ASC, so both keep first-come order.
    $confirmed = [];
    $reserves  = [];
    foreach ($g['players'] as $p) {
        if ((int)$p['is_reserve'] === 0) { $confirmed[] = $p; } else { $reserves[] = $p; }
    }
    $max      = (int)$g['max_players'];
    $isFull   = count($confirmed) >= $max;
    // SLOT CAP: some games allow 100+ players; rendering a button per free seat
    // would flood the panel. Show at most 10 slots — but always keep at least
    // one empty signup row visible while seats remain (so a full-looking list
    // grows as people join). The hidden remainder is summarised in a note.
    $shownSlots = min($max, max(10, count($confirmed) + 1));
    $canMsg   = !$readonly && messaging_allowed();   // shared gate (guests allowed when toggled)
    ?>
    <article class="game-card" id="game-<?= (int)$g['id'] ?>">
        <div class="gc-info">
            <?php if (!$readonly && verify_can_show_buttons($g['added_by_user_id'])): // the classic top tabs ?>
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

            <?php // THE DEPARTURE STRIP. The time is what a listing board is read
                  // for, so it leads at display size rather than sitting third in
                  // a stack of identical grey bands. The weight rides alongside as
                  // a chip, keeping its 1..5 colour bucket. Both are removed from
                  // the band stack below — they are shown here instead, not twice. ?>
            <?php $bucket = weight_bucket($g['weight']); ?>
            <div class="sh-depart">
                <span class="sh-time" title="<?= e(t('cl_start')) ?>"><?= e($g['start_time']) ?></span>
                <span class="sh-weight weight-<?= $bucket ?>" title="<?= e(t('cl_weight')) ?>"><?= e(number_format((float)$g['weight'], 2, ',', '')) ?></span>
            </div>

            <?php if (!empty($g['thumbnail'])): ?>
                <div class="gc-thumb"><img src="<?= e($g['thumbnail']) ?>" alt=""></div>
            <?php endif; ?>

            <?php $gLink = game_link($g); // BGG page, or the custom link (option-gated) ?>
            <h3 class="gc-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>

            <div class="gc-band gc-row"><?= e(t('cl_players')) ?>: <strong><?= count($confirmed) ?> / <?= $max ?></strong></div>
            <?php if ((int)$g['length_minutes'] > 0): // the other themes already show this ?>
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

            <?php // Discussion thread + the "+ add comment" pill (native <details>). ?>
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
                        <?php // Same class (and so the same pill) as the poll card's
                              // comment box — the theme's "+" comes from CSS. ?>
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

        <?php // Right panel: numbered slots 1..max, then reserves. ?>
        <div class="gc-players">
            <?php for ($i = 0; $i < $shownSlots; $i++): ?>
                <?php $p = $confirmed[$i] ?? null; ?>
                <?php if ($p !== null): // filled slot ?>
                    <div class="gc-slot">
                        <span><?= e(t('player_n', $i + 1)) ?>: <strong><?= e($p['name']) ?></strong><?php if (is_admin() && !empty($p['user_id'])): // admin-only account-bound marker ?><span class="p-acct" title="<?= e(t('player_account_bound', $p['account_name'] ?? ('#' . (int)$p['user_id']))) ?>">@</span><?php endif; ?>
                            <?php // Same "signed up by X" note as the light theme (see there for the why).
                            $signedBy = player_signed_up_by($p);
                                if ($signedBy !== ''): ?>
                                <span class="p-signedby"><?= e(t('player_signed_by', $signedBy)) ?></span>
                            <?php endif; ?>
                            <?php $kn = knows_rules_label($p['knows_rules']); ?>
                            <?php if ($kn !== ''): ?><span class="gc-knows rules-<?= rules_tone($p['knows_rules']) ?>">(<?= e(mb_strtolower($kn)) ?>)</span><?php endif; ?>
                        </span>
                        <?php if ($canMsg && !empty($p['email'])): ?>
                            <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                        <?php endif; ?>
                        <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                            <a class="gc-resign" href="delete_player.php?player=<?= (int)$p['id'] ?>"><?= e(t('resign')) ?></a>
                        <?php endif; ?>
                    </div>
                <?php elseif (!$readonly && can_signup()): // empty slot -> dark sign-up button ?>
                    <a class="gc-signup" href="sign_up.php?game=<?= (int)$g['id'] ?>"><?= e(t('signup')) ?></a>
                <?php else: // empty slot, but signups not possible -> plain empty row ?>
                    <div class="gc-slot"><span class="muted"><?= e(t('player_n', $i + 1)) ?>: —</span></div>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($max > $shownSlots): // the summarised hidden free seats ?>
                <p class="gc-more-slots"><?= e(t('cl_more_slots', $max - $shownSlots)) ?></p>
            <?php endif; ?>

            <?php foreach ($reserves as $p): // reserves listed under the slots ?>
                <div class="gc-slot gc-reserve">
                    <span><strong><?= e($p['name']) ?></strong> <?= e(t('reserve_tag')) ?></span>
                    <?php if ($canMsg && !empty($p['email'])): ?>
                        <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msgbtn_player')) ?>" aria-label="<?= e(t('msgbtn_player')) ?>">&#9993;</a>
                    <?php endif; ?>
                    <?php if (!$readonly && verify_can_show_buttons($p['user_id'])): ?>
                        <a class="gc-resign" href="delete_player.php?player=<?= (int)$p['id'] ?>"><?= e(t('resign')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!$readonly && can_signup() && $isFull): // full -> offer the reserve list ?>
                <a class="gc-signup" href="sign_up.php?game=<?= (int)$g['id'] ?>"><?= e(t('signup_reserve')) ?></a>
            <?php endif; ?>
        </div>
    </article>
<?php endif; ?>
