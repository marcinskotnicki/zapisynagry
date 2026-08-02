<?php
/* =============================================================================
 *  templates/schematic/poll_card.php — a poll, as an unresolved console strip.
 * -----------------------------------------------------------------------------
 *  Forked alongside game_card.php so a poll reads as the SAME kind of object as
 *  the games around it: an identity block keyed by table colour, then the body.
 *  Restyling light's markup could not do that — its poll card has no identity
 *  block and stacks vertically, which is the opposite of this theme's
 *  left-to-right reading order.
 *
 *  The candidates become a row of MODULES rather than a list: each is a small
 *  panel with its own vote gauge, so the poll reads as several options being
 *  compared side by side, which is what a poll actually is. Light stacks them,
 *  which reads as a queue.
 *
 *  Pinned to light's poll card by tests/test_schematic.php on every control —
 *  vote, cancel-vote, edit, delete, end, add-candidate, the message envelopes,
 *  and the comment form's hidden fields.
 *
 *  RENDER VARS: identical to light's — $poll (with ['games'], ['comments']) and
 *  $readonly.
 * ============================================================================= */
// These four guards are light's, copied verbatim rather than reinvented — the
// "end voting now" rule in particular is ACCOUNT-ONLY (a verified guest may
// edit and delete but not force a resolve), which is easy to get subtly wrong.
$uid = current_user()['id'] ?? 0;
$canEnd = !$readonly && is_logged_in()
          && (((int)$poll['proposer_user_id'] === (int)$uid && $uid) || is_admin());
$canEditPoll = !$readonly && verify_can_show_buttons($poll['proposer_user_id']);
$canMsgAll   = !$readonly && messaging_allowed();
$canAddCand  = !$readonly && poll_can_add_candidate($poll);
// THE COLOUR KEY comes from the table NUMBER, not its row id. Two reasons:
    // the number is what the visitor sees ("Table #2") and is 1,2,3… per event,
    // so adjacent tables always differ and the key is stable; and it is the one
    // value the TIMELINE can also derive, which is what lets a game's band
    // there match its strip here. Falls back to the row id if the render var is
    // ever absent, so an older caller still gets a colour rather than none.
    $chan = (((int)($table_no ?? $poll['table_id'])) % 5) + 1;
?>
<article class="poll-card sc-strip sc-poll sc-chan-<?= $chan ?>" id="poll-<?= (int)$poll['id'] ?>">

    <?php // Identity block, same shape as a game's — but tagged, because this
          // one is not settled yet. ?>
    <div class="sc-id">
        <span class="sc-id-time"><?= e($poll['start_time']) ?></span>
        <span class="sc-id-num poll-tag"><?= e(t('poll_label')) ?></span>
    </div>

    <div class="sc-body">
        <div class="sc-head">
            <h3 class="game-name sc-poll-title">
                <?php if (!empty($poll['proposer_name'])): ?>
                    <span class="poll-by"><?= e(t('poll_proposer')) ?>: <strong><?= e($poll['proposer_name']) ?></strong></span>
                    <?php if (!$readonly && messaging_allowed() && !empty($poll['proposer_email'])): ?>
                        <a class="msg-icon" href="message.php?poll_owner=<?= (int)$poll['id'] ?>" title="<?= e(t('msgbtn_poll_owner')) ?>" aria-label="<?= e(t('msgbtn_poll_owner')) ?>">&#9993;</a>
                    <?php endif; ?>
                <?php endif; ?>
            </h3>
            <?php if ($canMsgAll): ?>
                <a class="msg-icon msg-icon-all" href="message.php?poll=<?= (int)$poll['id'] ?>" title="<?= e(t('msgbtn_poll_all')) ?>" aria-label="<?= e(t('msgbtn_poll_all')) ?>">&#9993;</a>
            <?php endif; ?>
            <?php if ($canEditPoll || $canEnd || $canAddCand): ?>
                <span class="sc-actions">
                    <?php if ($canEditPoll): ?>
                        <a class="btn btn-small btn-danger poll-del-btn" href="delete_poll.php?poll=<?= (int)$poll['id'] ?>"><?= e(t('poll_delete')) ?></a>
                        <a class="btn btn-small" href="edit_poll.php?poll=<?= (int)$poll['id'] ?>"><?= e(t('poll_edit')) ?></a>
                    <?php endif; ?>
                    <?php if ($canEnd): ?>
                        <a class="btn btn-small poll-end-btn" href="end_poll.php?poll=<?= (int)$poll['id'] ?>"><?= e(t('poll_end_now')) ?></a>
                    <?php endif; ?>
                    <?php if ($canAddCand): ?>
                        <a class="btn btn-small" href="add_poll_game.php?poll=<?= (int)$poll['id'] ?>"><?= e(t('poll_add_game')) ?></a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($poll['deadline'])): ?>
            <div class="sc-cells">
                <span class="sc-cell">
                    <span class="sc-key"><?= e(t('poll_deadline')) ?></span>
                    <span class="sc-val"><?= e(substr($poll['deadline'], 0, 16)) ?></span>
                </span>
            </div>
        <?php endif; ?>

        <?php // ---- Candidates as side-by-side modules ---- ?>
        <ul class="poll-options sc-modules">
            <?php foreach ($poll['games'] as $c):
                // Field names are light's: 'votes' (not vote_count) and the
                // per-viewer 'voted' flag the controller already resolved.
                $votes = (int)$c['votes'];
                $need  = max(1, (int)$c['required_players']);
                $pct   = min(100, (int)round($votes / $need * 100));
                $full  = $votes >= $need;
                $mine  = !empty($c['voted']);
            ?>
                <li class="poll-option sc-module<?= $full ? ' sc-module-full' : '' ?>">
                    <div class="sc-module-head">
                        <?php if (!empty($c['bgg_id'])): // candidates link to their BGG page ?>
                            <a class="poll-opt-name" href="https://boardgamegeek.com/boardgame/<?= (int)$c['bgg_id'] ?>" target="_blank" rel="noopener"><?= e($c['name']) ?></a>
                        <?php else: ?>
                            <span class="poll-opt-name"><?= e($c['name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="poll-opt-meta">
                        <span class="weight-badge weight-<?= weight_bucket($c['weight']) ?>"><?= e(number_format((float)$c['weight'], 1, ',', '')) ?></span>
                        <?php if ((int)$c['length_minutes'] > 0): ?><span class="sc-val"><?= (int)$c['length_minutes'] ?>&#8242;</span><?php endif; ?>
                        <span class="poll-opt-votes"><?= e(t('poll_votes', $votes, $need)) ?></span>
                        <?php // The candidate's own rules link, if it has one. Uses the
                              // same helper as a game card, so the admin toggle governs
                              // both in one place. ?>
                        <?php if (($cmlink = manual_link($c)) !== null): ?>
                            <a class="poll-opt-manual game-manual" href="<?= e($cmlink) ?>"
                               target="_blank" rel="noopener noreferrer"
                               title="<?= e(t('game_manual_title')) ?>"><?= e(t('game_manual_btn')) ?></a>
                        <?php endif; ?>
                    </p>
                    <?php // The gauge: a ruled bar with its own scale, the way a
                          // track on these boards carries tick marks. ?>
                    <span class="poll-opt-bar<?= $full ? ' is-full' : '' ?>" aria-hidden="true"><span style="width: <?= $pct ?>%"></span></span>
                    <?php if (!$readonly): ?>
                        <?php if ($mine): ?>
                            <form method="post" action="vote.php?poll_game=<?= (int)$c['id'] ?>" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="poll_game" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="btn btn-small"><?= e(t('poll_cancel_vote')) ?></button>
                            </form>
                        <?php else: ?>
                            <a class="btn btn-small btn-primary" href="vote.php?poll_game=<?= (int)$c['id'] ?>"><?= e(t('poll_vote')) ?></a>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (opt_bool('allow_discussions')): ?>
            <div class="sc-foot">
                <details class="comment-add sc-disc">
                    <?php $cCount = !empty($poll['comments']) ? count($poll['comments']) : 0; ?>
                    <summary class="sc-comments<?= $cCount > 0 ? ' has-comments' : '' ?>"><?= e(t('comments_toggle')) ?><?= $cCount > 0 ? ' (' . $cCount . ')' : '' ?></summary>
                    <?php if (!empty($poll['comments'])): ?>
                        <ul class="comment-list">
                            <?php foreach ($poll['comments'] as $c): ?>
                                <li><span class="c-name"><?= e($c['name']) ?>:</span> <?= nl2br(e($c['comment'])) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!$readonly): ?>
                        <form method="post" action="add_comment.php">
                            <?= csrf_field() ?>
                            <?= antibot_field() ?>
                            <input type="hidden" name="poll" value="<?= (int)$poll['id'] ?>">
                            <input type="text" name="name" placeholder="<?= e(t('comment_name')) ?>" value="<?= e(current_user()['display_name'] ?? guest_identity()['name']) ?>">
                            <textarea name="comment" rows="2" placeholder="<?= e(t('comment_text')) ?>" required></textarea>
                            <button type="submit" class="btn btn-small btn-primary"><?= e(t('comment_submit')) ?></button>
                        </form>
                    <?php endif; ?>
                </details>
            </div>
        <?php endif; ?>
    </div>
</article>
