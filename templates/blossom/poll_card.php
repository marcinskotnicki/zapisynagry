<?php
/* =============================================================================
 *  templates/blossom/poll_card.php — a poll as a storybook card.
 * -----------------------------------------------------------------------------
 *  A LIGHTER FORK THAN THE GAME CARD, on purpose. The candidate list — art,
 *  name, weight, a fill bar, a vote button — already reads as a picture-book
 *  list once restyled, so nothing about its structure needed moving. What
 *  changes is the FRAME: the card opens with a ribbon carrying the POLL tag and
 *  the closing time, matching the hero band on a game card so the two sit
 *  together in the staggered grid without one looking like a stray.
 *
 *  Everything below the ribbon is light's markup unchanged, which is the point:
 *  every control and every translation key comes along for free, and
 *  tests/test_newthemes.php checks that against light's file.
 *
 *  RENDER VARS (identical to light's):
 *    $poll     — a poll row plus ['games'] = candidates with 'votes'/'voted'.
 *    $readonly — archived/read-only view (hides all controls).
 * ============================================================================= */
$canVote = !$readonly && can_signup();
?>
<article class="poll-card bl-poll" id="poll-<?= (int)$poll['id'] ?>">
    <?php // The ribbon: what this is, and when it closes. Mirrors the hero
          // band on a game card so both card kinds open the same way. ?>
    <div class="bl-ribbon">
        <?php if (!empty($poll['deadline'])): ?>
            <span class="bl-ribbon-until"><?= e(t('poll_deadline')) ?>: <strong><?= e(substr($poll['deadline'], 0, 16)) ?></strong></span>
        <?php endif; ?>
    </div>
    <?php
    // "End voting now": the proposer's own account, or an admin. Accounts only —
    // guest-created polls just wait for their threshold or deadline.
    $uid = current_user()['id'] ?? 0;
    $canEnd = !$readonly && is_logged_in()
              && (((int)$poll['proposer_user_id'] === (int)$uid && $uid) || is_admin());
    // Editing and deleting follow the usual button rule (owner / admin /
    // verified guest), unlike ending which is account-only.
    $canEditPoll = !$readonly && verify_can_show_buttons($poll['proposer_user_id']);
    $canMsgAll   = !$readonly && messaging_allowed();
    $canAddCand  = !$readonly && poll_can_add_candidate($poll);
    ?>

    <?php // Second row: what this is and when. UNCONDITIONAL — the start time,
          // proposer name and the tag are information every viewer gets, not
          // controls, so they must not disappear along with the buttons above
          // just because this particular viewer has none. (They used to: this
          // whole block was accidentally nested inside the actions guard, so a
          // guest with no visible buttons lost the poll's start time and
          // proposer along with them.) The tag sits LAST in the markup so it
          // lands at the right-hand end of the row (see .poll-tag's auto
          // margin); as the leftmost red pill it used to read as a delete
          // button. ?>
    <div class="poll-head">
        <?php // data-label lets the classic theme swap the clock glyph for the word
              // (see its CSS); the other themes keep the icon and ignore it. ?>
        <span class="game-time" data-label="<?= e(t('cl_start')) ?>"><?= e($poll['start_time']) ?></span>
        <?php if (!empty($poll['proposer_name'])): ?>
            <span class="poll-by"><?= e(t('poll_proposer')) ?>: <strong><?= e($poll['proposer_name']) ?></strong>
                <?php // Stays inline with the name — it messages that person,
                      // so it belongs to the name rather than to the actions. ?>
                <?php if (!$readonly && messaging_allowed() && !empty($poll['proposer_email'])): ?>
                    <a class="msg-icon" href="message.php?poll_owner=<?= (int)$poll['id'] ?>" title="<?= e(t('msgbtn_poll_owner')) ?>" aria-label="<?= e(t('msgbtn_poll_owner')) ?>">&#9993;</a>
                <?php endif; ?>
            </span>
        <?php endif; ?>
        <span class="poll-tag"><?= e(t('poll_label')) ?></span>
    </div>

    <p class="game-rules rules-<?= rules_tone($poll['explain_rules']) ?>"><?= e(explain_rules_label($poll['explain_rules'])) ?></p>
    <?php if (!empty($poll['comment'])): ?>
        <p class="game-comment"><?= nl2br(e($poll['comment'])) ?></p>
    <?php endif; ?>

    <ul class="poll-options">
        <?php foreach ($poll['games'] as $c): // one row per candidate ?>
            <li class="poll-option">
                <?php if (!empty($c['thumbnail'])): ?>
                    <img class="poll-opt-thumb" src="<?= e($c['thumbnail']) ?>" alt="">
                <?php endif; ?>
                <?php // Name plus its details are one block so the votes count and
                      // the vote button stay on the right of the row. ?>
                <div class="poll-opt-main">
                    <?php $cLink = game_link($c); ?>
                    <?php // game_link() rather than a hand-built BGG URL: it also honours
                          // a custom link on a non-BGG candidate, and re-checks the admin
                          // option on the way out, so switching custom links off hides them
                          // everywhere at once without touching stored data. ?>
                    <?php if ($cLink): ?>
                        <a class="poll-opt-name" href="<?= e($cLink) ?>" target="_blank" rel="noopener"><?= e($c['name']) ?></a>
                    <?php else: ?>
                        <span class="poll-opt-name"><?= e($c['name']) ?></span>
                    <?php endif; ?>
                    <?php // The same three facts a game card shows, so a candidate can
                          // be judged without opening its BGG page. Weight reuses the
                          // game card's badge and its 1..5 colour buckets. ?>
                    <p class="poll-opt-meta">
                        <span class="weight-badge weight-<?= weight_bucket($c['weight']) ?>" title="<?= e(t('cl_weight')) ?>"><?= e(number_format((float)$c['weight'], 1)) ?></span>
                        <?php if ((int)$c['length_minutes'] > 0): ?>
                            <span class="poll-opt-len"><?= e(t('game_length_min', (int)$c['length_minutes'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($c['language'])): ?>
                            <span class="poll-opt-lang"><?= e($c['language']) ?></span>
                        <?php endif; ?>
                        <?php // The candidate's own rules link, if it has one. Uses the
                              // same helper as a game card, so the admin toggle governs
                              // both in one place. ?>
                        <?php if (($cmlink = manual_link($c)) !== null): ?>
                            <a class="poll-opt-manual game-manual" href="<?= e($cmlink) ?>"
                               target="_blank" rel="noopener noreferrer"
                               title="<?= e(t('game_manual_title')) ?>"><?= e(t('game_manual_btn')) ?></a>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="poll-opt-votes"><?= e(t('poll_votes', (int)$c['votes'], (int)$c['required_players'])) ?></span>
                <?php
                // Progress towards the candidate's REQUIRED player count — the
                // number that decides the poll — not max_players. Capped at 100%
                // so an over-subscribed option doesn't overflow its track, and
                // skipped entirely when there's no threshold to fill.
                $need = (int)$c['required_players'];
                if ($need > 0):
                    $pct  = min(100, (int)round((int)$c['votes'] / $need * 100));
                    $full = (int)$c['votes'] >= $need;
                ?>
                    <?php // aria-hidden: the "votes: X / Y" text right above already
                          // states this, so announcing it twice just adds noise. ?>
                    <span class="poll-opt-bar<?= $full ? ' is-full' : '' ?>" aria-hidden="true"><span style="width: <?= $pct ?>%"></span></span>
                <?php /* WHO voted, when the proposer asked for it. Names only —
                       * the email a guest gave when voting is not the club's to
                       * publish, and nothing here needs it. */ ?>
                <?php if (!empty($poll['show_results']) && !empty($c['voters'])): ?>
                    <span class="poll-opt-voters"><span class="poll-opt-voters-label"><?= e(t('poll_voters_label')) ?></span> <?= e(implode(', ', $c['voters'])) ?></span>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($canVote): ?>
                    <?php if (!empty($c['voted'])): // already voted -> offer to cancel (inline POST) ?>
                        <form method="post" action="vote.php?poll_game=<?= (int)$c['id'] ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="poll_game" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-small"><?= e(t('poll_cancel_vote')) ?></button>
                        </form>
                    <?php else: // not voted -> link to the vote form ?>
                        <a class="btn btn-small btn-primary" href="vote.php?poll_game=<?= (int)$c['id'] ?>" rel="nofollow"><?= e(t('poll_vote')) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php // Discussion: same markup as a game card, posting to add_comment.php
          // with a poll id instead of a game id. ?>
    <?php if (opt_bool('allow_discussions')): ?>
        <div class="discussion">
            <?php if (!empty($poll['comments'])): ?>
                <ul class="comment-list">
                    <?php foreach ($poll['comments'] as $c): ?>
                        <li><span class="c-name"><?= e($c['name']) ?>:</span> <?= nl2br(e($c['comment'])) ?><?= comment_delete_html($c, 'poll') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!$readonly): ?>
                <details class="comment-add">
                    <summary><?= e(t('comment_add')) ?></summary>
                    <form method="post" action="add_comment.php">
                        <?= csrf_field() ?>
                        <?= antibot_field() ?>
                        <input type="hidden" name="poll" value="<?= (int)$poll['id'] ?>">
                        <input type="text" name="name" placeholder="<?= e(t('comment_name')) ?>" value="<?= e(current_user()['display_name'] ?? guest_identity()['name']) ?>">
                        <textarea name="comment" rows="2" placeholder="<?= e(t('comment_text')) ?>" required></textarea>
                        <?= captcha_html('comment') ?>
                        <button type="submit" class="btn btn-small btn-primary"><?= e(t('comment_submit')) ?></button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php // Skip the row entirely when it would be empty — the read-only
          // (archived) view shows no controls at all. ?>
    <?php if ($canEditPoll || $canEnd || $canMsgAll || $canAddCand): ?>
        <?php // First row: the actions, delete first, mirroring the game card's
              // DELETE / EDIT tab order. Wraps on narrow screens. ?>
        <div class="poll-actions bl-actions">
            <?php if ($canEditPoll): ?>
                <?php // delete_poll.php re-checks this — the button only hides. ?>
                <a class="btn btn-small btn-danger poll-del-btn" href="delete_poll.php?poll=<?= (int)$poll['id'] ?>" rel="nofollow"><?= e(t('poll_delete')) ?></a>
                <a class="btn btn-small" href="edit_poll.php?poll=<?= (int)$poll['id'] ?>" rel="nofollow"><?= e(t('poll_edit')) ?></a>
                <?php if (is_admin()): // move this poll to another table on the same day ?>
                    <a class="btn btn-small" href="move_item.php?poll=<?= (int)$poll['id'] ?>" rel="nofollow"><?= e(t('move_btn')) ?></a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canEnd): ?>
                <a class="btn btn-small poll-end-btn" href="end_poll.php?poll=<?= (int)$poll['id'] ?>" rel="nofollow"><?= e(t('poll_end_now')) ?></a>
            <?php endif; ?>
            <?php // Shown to anyone allowed to add — the proposer, an admin, or
                  // everyone when the proposer opted in. ?>
            <?php if ($canAddCand): ?>
                <a class="btn btn-small" href="add_poll_game.php?poll=<?= (int)$poll['id'] ?>"><?= e(t('poll_add_game')) ?></a>
            <?php endif; ?>
            <?php if ($canMsgAll): // mail everyone who voted ?>
                <a class="msg-icon msg-icon-all" href="message.php?poll=<?= (int)$poll['id'] ?>" title="<?= e(t('msgbtn_poll_all')) ?>" aria-label="<?= e(t('msgbtn_poll_all')) ?>">&#9993;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>
