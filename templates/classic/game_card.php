<?php
/* =============================================================================
 *  templates/classic/game_card.php — the CLASSIC game card. Presentation only.
 * -----------------------------------------------------------------------------
 *  Overrides light's game_card for the "classic" theme (the old app's look):
 *    LEFT  (.gc-info)    — dark rounded card: action tabs (delete/edit), the
 *                          thumbnail, name, red "waga" band, centred info rows
 *                          (players / start / brings / language), the green
 *                          rules label, comment + discussion as black bands,
 *                          and the "+ add comment" pill.
 *    RIGHT (.gc-players) — light grey panel of NUMBERED SLOTS (1..max_players):
 *                          a filled slot shows "Gracz N: name (rules note)" with
 *                          a red resign button; an empty slot is a dark
 *                          "Zapisz się" button. Reserves are listed after.
 *
 *  Same data contract and permission rules as light's card:
 *    RENDER VARS: $g (game row incl. ['players'] and ['comments']), $readonly.
 *    - edit/delete tabs + per-player resign: verify_can_show_buttons(owner id).
 *    - message envelopes: messaging_allowed() AND not read-only.
 *    - every slot links to the same sign_up.php; slot numbers are cosmetic.
 *  Styling lives in templates/classic/css/style.css (the gc-* classes).
 * ============================================================================= */
?>
<?php if ((int)$g['is_archived'] === 1): // soft-deleted -> dimmed card + bring-back ?>
    <article class="game-card game-archived" id="game-<?= (int)$g['id'] ?>">
        <div class="gc-info">
            <h3 class="gc-name"><?= e($g['name']) ?></h3>
            <div class="gc-band gc-row"><?= e(t('game_archived_note')) ?></div>
            <?php if (!$readonly): ?>
                <div class="gc-band">
                    <a class="btn btn-small" href="bring_back.php?game=<?= (int)$g['id'] ?>"><?= e(t('bringback_button')) ?></a>
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
                    <?php if ($canMsg): ?>
                        <a class="msg-icon" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msg_envelope')) ?>">&#9993;</a>
                    <?php endif; ?>
                </div>
            <?php elseif ($canMsg): ?>
                <div class="gc-tabs">
                    <a class="msg-icon" href="message.php?game=<?= (int)$g['id'] ?>" title="<?= e(t('msg_envelope')) ?>">&#9993;</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($g['thumbnail'])): ?>
                <div class="gc-thumb"><img src="<?= e($g['thumbnail']) ?>" alt=""></div>
            <?php endif; ?>

            <?php $gLink = game_link($g); // BGG page, or the custom link (option-gated) ?>
            <h3 class="gc-name"><?php if ($gLink): ?><a href="<?= e($gLink) ?>" target="_blank" rel="noopener"><?= e($g['name']) ?></a><?php else: ?><?= e($g['name']) ?><?php endif; ?></h3>

            <?php // Waga band, coloured by the 1..5 weight bucket (like the other
                  // themes); Polish-style decimal comma to match the old look. ?>
            <?php $bucket = weight_bucket($g['weight']); ?>
            <div class="gc-band gc-waga weight-<?= $bucket ?>"><?= e(t('cl_weight')) ?>: <strong><?= e(number_format((float)$g['weight'], 2, ',', '')) ?></strong></div>
            <div class="gc-band gc-row"><?= e(t('cl_players')) ?>: <strong><?= count($confirmed) ?> / <?= $max ?></strong></div>
            <div class="gc-band gc-row"><?= e(t('cl_start')) ?>: <strong><?= e($g['start_time']) ?></strong></div>
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
                        <details class="gc-addcomment">
                            <summary>+ <?= e(t('comment_add')) ?></summary>
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

        <?php // Right panel: numbered slots 1..max, then reserves. ?>
        <div class="gc-players">
            <?php for ($i = 0; $i < $shownSlots; $i++): ?>
                <?php $p = $confirmed[$i] ?? null; ?>
                <?php if ($p !== null): // filled slot ?>
                    <div class="gc-slot">
                        <span><?= e(t('player_n', $i + 1)) ?>: <strong><?= e($p['name']) ?></strong>
                            <?php $kn = knows_rules_label($p['knows_rules']); ?>
                            <?php if ($kn !== ''): ?><span class="gc-knows rules-<?= rules_tone($p['knows_rules']) ?>">(<?= e(mb_strtolower($kn)) ?>)</span><?php endif; ?>
                        </span>
                        <?php if ($canMsg && !empty($p['email'])): ?>
                            <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msg_envelope')) ?>">&#9993;</a>
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
                        <a class="msg-icon" href="message.php?player=<?= (int)$p['id'] ?>" title="<?= e(t('msg_envelope')) ?>">&#9993;</a>
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
