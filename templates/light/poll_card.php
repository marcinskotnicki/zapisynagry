<?php
/* =============================================================================
 *  templates/light/poll_card.php — a poll on a table. Presentation only.
 * -----------------------------------------------------------------------------
 *  Rendered by front_event in the table's item list, with a distinct background
 *  from game cards. Lists each candidate with its vote tally and a vote (or
 *  cancel-vote) control. Cancel-vote is a tiny inline POST form; voting links to
 *  the full vote form (where name/email are collected).
 *
 *  RENDER VARS:
 *    $poll     — a poll row plus ['games'] = candidates, each carrying 'votes'
 *                and 'voted' (whether the current user has voted for it).
 *    $readonly — true in the archived/read-only view (hides all controls).
 *  $canVote (local) gates the controls: not readonly AND signups allowed.
 * ============================================================================= */
$canVote = !$readonly && can_signup();
?>
<article class="poll-card" id="poll-<?= (int)$poll['id'] ?>">
    <div class="poll-head">
        <span class="poll-tag"><?= e(t('poll_label')) ?></span>
        <span class="game-time"><?= e($poll['start_time']) ?></span>
        <?php if (!empty($poll['proposer_name'])): ?>
            <span class="poll-by"><?= e(t('poll_proposer')) ?>: <strong><?= e($poll['proposer_name']) ?></strong>
                <?php if (!$readonly && messaging_allowed() && !empty($poll['proposer_email'])): // mail the proposer ?>
                    <a class="msg-icon" href="message.php?poll_owner=<?= (int)$poll['id'] ?>" title="<?= e(t('msgbtn_poll_owner')) ?>" aria-label="<?= e(t('msgbtn_poll_owner')) ?>">&#9993;</a>
                <?php endif; ?>
            </span>
        <?php endif; ?>
        <?php if (!$readonly && messaging_allowed()): // mail everyone who voted ?>
            <a class="msg-icon msg-icon-all" href="message.php?poll=<?= (int)$poll['id'] ?>" title="<?= e(t('msgbtn_poll_all')) ?>" aria-label="<?= e(t('msgbtn_poll_all')) ?>">&#9993;</a>
        <?php endif; ?>
        <?php
        // "End voting now": the proposer's own account, or an admin. Accounts
        // only — guest-created polls just wait for threshold/deadline.
        $uid = current_user()['id'] ?? 0;
        $canEnd = !$readonly && is_logged_in()
                  && (((int)$poll['proposer_user_id'] === (int)$uid && $uid) || is_admin());
        ?>
        <?php if ($canEnd): ?>
            <a class="btn btn-small poll-end-btn" href="end_poll.php?poll=<?= (int)$poll['id'] ?>"><?= e(t('poll_end_now')) ?></a>
        <?php endif; ?>
        <?php // Editing follows the usual button rule (owner / admin / verified
              // guest), unlike ending which is account-only. ?>
        <?php if (!$readonly && verify_can_show_buttons($poll['proposer_user_id'])): ?>
            <a class="btn btn-small" href="edit_poll.php?poll=<?= (int)$poll['id'] ?>"><?= e(t('poll_edit')) ?></a>
        <?php endif; ?>
    </div>
    <?php if (!empty($poll['deadline'])): // when voting closes (auto-resolves after) ?>
        <p class="poll-deadline"><?= e(t('poll_deadline')) ?>: <strong><?= e(substr($poll['deadline'], 0, 16)) ?></strong></p>
    <?php endif; ?>
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
                <?php if (!empty($c['bgg_id'])): // candidates link to their BGG page ?>
                    <a class="poll-opt-name" href="https://boardgamegeek.com/boardgame/<?= (int)$c['bgg_id'] ?>" target="_blank" rel="noopener"><?= e($c['name']) ?></a>
                <?php else: ?>
                    <span class="poll-opt-name"><?= e($c['name']) ?></span>
                <?php endif; ?>
                <span class="poll-opt-votes"><?= e(t('poll_votes', (int)$c['votes'], (int)$c['required_players'])) ?></span>
                <?php if ($canVote): ?>
                    <?php if (!empty($c['voted'])): // already voted -> offer to cancel (inline POST) ?>
                        <form method="post" action="vote.php?poll_game=<?= (int)$c['id'] ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="poll_game" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-small"><?= e(t('poll_cancel_vote')) ?></button>
                        </form>
                    <?php else: // not voted -> link to the vote form ?>
                        <a class="btn btn-small btn-primary" href="vote.php?poll_game=<?= (int)$c['id'] ?>"><?= e(t('poll_vote')) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</article>
