<?php
/* =============================================================================
 *  templates/light/edit_poll.php — edit a live poll. Presentation only.
 * -----------------------------------------------------------------------------
 *  Two independent parts on one page:
 *    - the settings form (posts action=save): proposer name/email, start time,
 *      voting deadline, rules explanation, the per-poll email rule, description
 *      and the two opt-ins — the same set the game edit form offers,
 *    - the candidate list, each row with its own tiny remove form
 *      (action=remove), plus a link into add_poll_game.php's live mode.
 *  They're separate forms so removing a game never depends on the time field
 *  validating, and vice versa.
 *
 *  The settings form also carries the day's date/opening-hour/grace as data-*
 *  attributes, which js/scripts.js reads to preview when voting will close.
 *
 *  RENDER VARS:
 *    $poll   — the polls row being edited.
 *    $cands  — poll_games rows (candidates), in display order.
 *    $day    — the event_days row (for the time hint).
 *    $bounds — ['min','max'] to clamp the time input, or null.
 *    $deadline_hours — the stored deadline expressed as hours before the start
 *      (0 = none), since that's how the form edits it.
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
    <form method="post" action="edit_poll.php?poll=<?= (int)$poll['id'] ?>"
        data-day-date="<?= e($day['day_date'] ?? '') ?>"
        data-day-start="<?= e($day['start_time'] ?? '') ?>"
        data-grace-hours="<?= (int)opt_int('overnight_grace_hours') ?>">
        <?= $csrf ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="poll" value="<?= (int)$poll['id'] ?>">

        <?php // Proposer details, same pair the game edit form offers. Changing
              // the email moves the verification target for a guest-made poll,
              // exactly as it does on a game. ?>
        <div class="field-row">
            <div class="field">
                <label for="p_name"><?= e(t('poll_name')) ?></label>
                <input type="text" id="p_name" name="name" value="<?= e($poll['proposer_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="p_email"><?= e(t('poll_email')) ?></label>
                <input type="email" id="p_email" name="email" value="<?= e($poll['proposer_email'] ?? '') ?>">
                <?php if (opt('msg_email_field') !== ''): // optional admin note above email inputs ?>
                    <p class="field-note"><?= e(opt('msg_email_field')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label for="start_time"><?= e(t('poll_start')) ?></label>
            <input type="time" id="start_time" name="start_time" value="<?= e($poll['start_time']) ?>"
                <?= $bounds ? 'min="' . e($bounds['min']) . '" max="' . e($bounds['max']) . '"' : '' ?>>
            <?php if ($bounds): ?>
                <p class="field-note"><?= e(t('f_start_range', $bounds['min'], $bounds['max'])) ?></p>
            <?php endif; ?>
        </div>
        <div class="field">
            <?php // Hours BEFORE the start when voting closes; 0 removes the deadline.
                  // Moving the start time above shifts this along with it. ?>
            <label for="p_deadline"><?= e(t('poll_deadline_hours')) ?></label>
            <input type="number" id="p_deadline" name="deadline_hours" min="0" value="<?= (int)($deadline_hours ?? 0) ?>">
            <?php // Filled in by initPollDeadlinePreview() in js/scripts.js. ?>
            <p class="field-note poll-deadline-preview"></p>
        </div>
        <div class="field">
            <label for="p_comment"><?= e(t('poll_comment')) ?></label>
            <textarea id="p_comment" name="comment" rows="3"><?= e((string)$poll['comment']) ?></textarea>
        </div>

        <div class="field">
            <label for="p_explain"><?= e(t('poll_explain')) ?></label>
            <select id="p_explain" name="explain_rules">
                <?php foreach ([0 => 'rules_explain', 1 => 'rules_summary', 2 => 'rules_known'] as $code => $k): ?>
                    <option value="<?= $code ?>"<?= (int)$poll['explain_rules'] === $code ? ' selected' : '' ?>><?= e(t($k)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php // Per-poll email rule — only exists in option mode 2, where the
              // proposer decides. In the other modes the box isn't rendered and
              // the controller leaves the stored value alone. ?>
        <?php if (email_require_mode() === 2): ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="require_email" value="1" <?= (int)($poll['require_email'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <?= e(t('f_require_email')) ?>
                </label>
            </div>
        <?php endif; ?>

        <?php if (poll_optin_relevant($poll)): // hidden when nothing is restricted anyway ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="allow_others" value="1" <?= (int)$poll['allow_others_add'] === 1 ? 'checked' : '' ?>>
                    <?= e(t('poll_allow_others')) ?>
                </label>
            </div>
        <?php endif; ?>
        <?php // Run to the deadline instead of ending on the first full candidate. ?>
        <div class="field field-check">
            <label>
                <input type="checkbox" name="wait_deadline" value="1" <?= (int)($poll['wait_for_deadline'] ?? 0) === 1 ? 'checked' : '' ?>>
                <?= e(t('poll_wait_deadline')) ?>
            </label>
            <p class="field-note"><?= e(t('poll_wait_deadline_note')) ?></p>
        </div>
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
