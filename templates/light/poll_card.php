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
            <span class="poll-by"><?= e(t('poll_proposer')) ?>: <strong><?= e($poll['proposer_name']) ?></strong></span>
        <?php endif; ?>
    </div>
    <p class="game-rules"><?= e(explain_rules_label($poll['explain_rules'])) ?></p>
    <?php if (!empty($poll['comment'])): ?>
        <p class="game-comment"><?= nl2br(e($poll['comment'])) ?></p>
    <?php endif; ?>

    <ul class="poll-options">
        <?php foreach ($poll['games'] as $c): // one row per candidate ?>
            <li class="poll-option">
                <?php if (!empty($c['thumbnail'])): ?>
                    <img class="poll-opt-thumb" src="<?= e($c['thumbnail']) ?>" alt="">
                <?php endif; ?>
                <span class="poll-opt-name"><?= e($c['name']) ?></span>
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
