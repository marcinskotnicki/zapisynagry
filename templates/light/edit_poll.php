<?php
/* =============================================================================
 *  templates/light/edit_poll.php — edit a live poll. Presentation only.
 * -----------------------------------------------------------------------------
 *  Two independent parts on one page:
 *    - the start-time form (posts action=save),
 *    - the candidate list, each row with its own tiny remove form
 *      (action=remove), plus a link into add_poll_game.php's live mode.
 *  They're separate forms so removing a game never depends on the time field
 *  validating, and vice versa.
 *
 *  RENDER VARS:
 *    $poll   — the polls row being edited.
 *    $cands  — poll_games rows (candidates), in display order.
 *    $day    — the event_days row (for the time hint).
 *    $bounds — ['min','max'] to clamp the time input, or null.
 *    $error  — message above the forms, or null.
 *    $csrf   — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('poll_edit_title')) ?></h1>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <?php // ---- Start time ---------------------------------------------- ?>
    <form method="post" action="edit_poll.php?poll=<?= (int)$poll['id'] ?>">
        <?= $csrf ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="poll" value="<?= (int)$poll['id'] ?>">
        <div class="field">
            <label for="start_time"><?= e(t('poll_start')) ?></label>
            <input type="time" id="start_time" name="start_time" value="<?= e($poll['start_time']) ?>"
                <?= $bounds ? 'min="' . e($bounds['min']) . '" max="' . e($bounds['max']) . '"' : '' ?>>
            <?php if ($bounds): ?>
                <p class="field-note"><?= e(t('f_start_range', $bounds['min'], $bounds['max'])) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <label for="p_comment"><?= e(t('poll_comment')) ?></label>
            <textarea id="p_comment" name="comment" rows="3"><?= e((string)$poll['comment']) ?></textarea>
        </div>

        <?php if (poll_optin_relevant($poll)): // hidden when nothing is restricted anyway ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="allow_others" value="1" <?= (int)$poll['allow_others_add'] === 1 ? 'checked' : '' ?>>
                    <?= e(t('poll_allow_others')) ?>
                </label>
            </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
    </form>

    <?php // ---- Candidates ---------------------------------------------- ?>
    <h2><?= e(t('poll_candidates')) ?></h2>
    <p class="muted"><?= e(t('poll_edit_note')) ?></p>

    <ul class="poll-cand-list">
        <?php foreach ($cands as $c): ?>
            <li class="poll-cand-row">
                <span class="poll-cand-name"><?= e($c['name']) ?></span>
                <span class="muted">
                    <?= e(t('poll_needs', (int)$c['required_players'])) ?>
                    — <?= (int)poll_candidate_votes((int)$c['id']) ?> <?= e(t('poll_votes_label')) ?>
                </span>
                <?php if (count($cands) > 1): // a poll must keep at least one option ?>
                    <form method="post" action="edit_poll.php?poll=<?= (int)$poll['id'] ?>" class="inline">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="poll" value="<?= (int)$poll['id'] ?>">
                        <input type="hidden" name="cand" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="btn btn-small btn-danger"><?= e(t('poll_cand_remove')) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <p>
        <a class="btn" href="add_poll_game.php?poll=<?= (int)$poll['id'] ?>"><?= e(t('poll_add_game')) ?></a>
        <a class="btn" href="index.php#poll-<?= (int)$poll['id'] ?>"><?= e(t('cancel')) ?></a>
    </p>
</div>
