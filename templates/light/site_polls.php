<?php
/* =============================================================================
 *  templates/light/site_polls.php — the admin's polls, on the front page.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY; every rule is in inc/sitepolls.php. One block per poll
 *  that is switched on, in one of four states:
 *
 *    ENDED            the results, with the winner(s) highlighted.
 *    CANNOT VOTE      the poll needs an account and this visitor has none:
 *                     a note saying so, plus results if they are public.
 *    VOTED, single    their vote stands: the results if public, otherwise
 *                     "you already voted".
 *    CAN VOTE         the form — and, for a multi-answer poll they already
 *                     voted in, the same form with their choices ticked so
 *                     they can change them.
 *
 *  RENDER VARS:
 *    $back — the address to return to after voting (this page).
 * ============================================================================= */
require_once __DIR__ . '/../../inc/sitepolls.php';
if (!site_polls_enabled()) return;
$spPolls = sp_front_polls();
if (!$spPolls) return;

/** One answer's tally line: the count and a bar of proportional length. */
$spBar = function ($n, $total, $isWinner) {
    // Share of all votes cast, so the bars of a poll add up to the whole.
    $pct = $total > 0 ? round($n / $total * 100) : 0;
    return '<div class="sp-result' . ($isWinner ? ' sp-winner' : '') . '">'
         . '<div class="sp-bar"><span style="width:' . (int)$pct . '%"></span></div>'
         . '<span class="sp-count">' . e(t('sp_votes', $n)) . '</span>'
         . '</div>';
};
?>
<section class="site-polls site-polls-<?= e(site_polls_position()) ?>">
<?php foreach ($spPolls as $sp): ?>
    <?php
    $spId      = (int)$sp['id'];
    $spOpts    = sp_options($spId);
    $spTally   = sp_tally($spId);
    $spTotal   = array_sum($spTally);
    $spEnded   = sp_concluded($sp);
    $spMulti   = (int)$sp['multi'] === 1;
    $spMine    = sp_my_votes($spId);
    $spCanVote = sp_can_vote($sp);
    // Results: always once it has ended, before that only if the admin allowed.
    $spShowRes = $spEnded || (int)$sp['show_results'] === 1;
    $spWinners = $spEnded ? sp_winners($spOpts, $spTally) : [];
    // The form is offered unless it has ended, voting needs an account they do
    // not have, or it is single-answer and they have already voted.
    $spForm    = !$spEnded && $spCanVote && ($spMulti || !$spMine);
    ?>
    <div class="card site-poll" id="site-poll-<?= $spId ?>">
        <h3 class="site-poll-title"><?= e($sp['title']) ?></h3>

        <?php if ($spForm): ?>
            <form method="post" action="site_poll_vote.php" class="site-poll-form">
                <?= csrf_field() ?>
                <?php // Guests can vote, so this is exactly the kind of form bots fill. ?>
                <?= antibot_field() ?>
                <input type="hidden" name="poll" value="<?= $spId ?>">
                <input type="hidden" name="back" value="<?= e($back ?? 'index.php') ?>">
                <?php foreach ($spOpts as $o): ?>
                    <?php $oid = (int)$o['id']; ?>
                    <div class="sp-option">
                        <label>
                            <?php if ($spMulti): ?>
                                <input type="checkbox" name="opt[]" value="<?= $oid ?>"<?= in_array($oid, $spMine, true) ? ' checked' : '' ?>>
                            <?php else: ?>
                                <input type="radio" name="opt" value="<?= $oid ?>" required>
                            <?php endif; ?>
                            <?= e($o['text']) ?>
                        </label>
                        <?php if ($spShowRes): ?>
                            <?= $spBar($spTally[$oid] ?? 0, $spTotal, false) ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php // The write-in, last. Shown only to people allowed to add one. ?>
                <?php if (sp_can_add_custom($sp)): ?>
                    <div class="sp-option sp-custom">
                        <label>
                            <?php if ($spMulti): ?>
                                <input type="checkbox" name="custom_on" value="1">
                            <?php else: ?>
                                <input type="radio" name="opt" value="custom" required>
                            <?php endif; ?>
                            <?= e(t('sp_custom')) ?>
                        </label>
                        <input type="text" name="custom_text" maxlength="<?= (int)TEXT_NAME_MAX ?>"
                               aria-label="<?= e(t('sp_custom')) ?>" placeholder="<?= e(t('sp_custom_placeholder')) ?>">
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= e(t($spMulti && $spMine ? 'sp_vote_again' : 'sp_vote')) ?>
                    </button>
                </div>
            </form>

        <?php else: ?>
            <?php if ($spEnded): ?>
                <p class="sp-status"><?= e(t('sp_ended')) ?></p>
            <?php elseif (!$spCanVote): ?>
                <p class="sp-status"><?= e(t('sp_login_to_vote')) ?></p>
            <?php elseif (!$spShowRes): ?>
                <p class="sp-status"><?= e(t('sp_already_voted')) ?></p>
            <?php endif; ?>

            <?php if ($spShowRes): ?>
                <div class="sp-results">
                    <?php foreach ($spOpts as $o): ?>
                        <?php $oid = (int)$o['id']; $win = in_array($oid, $spWinners, true); ?>
                        <div class="sp-option<?= $win ? ' sp-option-winner' : '' ?><?= in_array($oid, $spMine, true) ? ' sp-option-mine' : '' ?>">
                            <span class="sp-option-text"><?= e($o['text']) ?></span>
                            <?= $spBar($spTally[$oid] ?? 0, $spTotal, $win) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</section>
