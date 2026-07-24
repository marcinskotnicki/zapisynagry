<?php
/* =============================================================================
 *  templates/light/poll_main.php — the poll build screen. Presentation only.
 * -----------------------------------------------------------------------------
 *  The working screen while assembling a poll (the data lives in the session
 *  draft until "Finish"). Top: poll-level fields (proposer name/email, start
 *  time, rules, comment, add-self). Middle: the candidate list with per-row
 *  remove buttons. Bottom: the three action buttons, distinguished by the "do"
 *  value add_poll.php reads (addgame / finish / cancel).
 *
 *  RENDER VARS:
 *    $table — the table the poll is for.
 *    $draft — the session draft (poll-level fields + ['games'] candidates).
 *    $error — message above the form, or null.
 *    $csrf  — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card">
    <h1><?= e(t('addpoll_title')) ?></h1>

    <?php if (opt('msg_adding_poll') !== ''): // optional admin-configured note ?>
        <p class="event-msg"><?= e(opt('msg_adding_poll')) ?></p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="add_poll.php?table=<?= (int)$table['id'] ?>" class="poll-form">
        <?= $csrf ?>
        <input type="hidden" name="table" value="<?= (int)$table['id'] ?>">

        <div class="field-row">
            <div class="field">
                <label for="p_name"><?= e(t('poll_name')) ?></label>
                <input type="text" id="p_name" name="name" value="<?= e($draft['name']) ?>">
            </div>
            <div class="field">
                <label for="p_email"><?= e(t('poll_email')) ?></label>
                <input type="email" id="p_email" name="email" value="<?= e($draft['email']) ?>">
                <?php if (opt('msg_email_field') !== ''): // optional admin note above email inputs ?>
                    <p class="field-note"><?= e(opt('msg_email_field')) ?></p>
                <?php endif; ?>
            </div>
            <div class="field">
                <?php // Hours BEFORE the start when voting closes; 0 disables the deadline. ?>
                <label for="p_deadline"><?= e(t('poll_deadline_hours')) ?></label>
                <input type="number" id="p_deadline" name="deadline_hours" min="0" value="<?= (int)$draft['deadline_hours'] ?>">
            </div>
            <div class="field">
                <label for="p_start"><?= e(t('poll_start')) ?></label>
                <?php // Same event-hours clamp as games (the poll resolves into a game at this time). ?>
                <?php $pBounds = start_time_bounds(db_one('SELECT * FROM event_days WHERE id = ?', [$table['day_id']])); ?>
                <input type="time" id="p_start" name="start_time" value="<?= e($draft['start_time']) ?>"
                    <?= $pBounds ? 'min="' . e($pBounds['min']) . '" max="' . e($pBounds['max']) . '"' : '' ?>>
                <?php if ($pBounds): ?>
                    <p class="field-note"><?= e(t('f_start_range', $pBounds['min'], $pBounds['max'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label for="p_explain"><?= e(t('poll_explain')) ?></label>
            <select id="p_explain" name="explain_rules">
                <?php foreach ([0 => 'rules_explain', 1 => 'rules_summary', 2 => 'rules_known'] as $code => $k): ?>
                    <option value="<?= $code ?>"<?= (int)$draft['explain_rules'] === $code ? ' selected' : '' ?>><?= e(t($k)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="p_comment"><?= e(t('poll_comment')) ?></label>
            <textarea id="p_comment" name="comment" rows="2"><?= e($draft['comment']) ?></textarea>
        </div>

        <div class="field field-check">
            <label>
                <input type="checkbox" name="add_self" value="1" <?= (int)$draft['add_self'] === 1 ? 'checked' : '' ?>>
                <?= e(t('poll_addself')) ?>
            </label>
        </div>

        <?php // Opt-in (off by default): anyone may add candidate games. The
              // proposer can still remove any of them from the edit screen.
              // Hidden when it wouldn't restrict anyone anyway (see the helper). ?>
        <?php if (poll_optin_relevant()): ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="allow_others" value="1" <?= (int)($draft['allow_others'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <?= e(t('poll_allow_others')) ?>
                </label>
            </div>
        <?php endif; ?>

        <?php if (email_require_mode() === 2): // per-poll rule: proposer decides (their own email then required too) ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="require_email" value="1" <?= (int)($draft['require_email'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <?= e(t('f_require_email')) ?>
                </label>
            </div>
        <?php endif; ?>

        <h3><?= e(t('poll_candidates')) ?></h3>
        <?php if (empty($draft['games'])): ?>
            <p class="muted"><?= e(t('poll_no_candidates')) ?></p>
        <?php else: ?>
            <ul class="poll-candidate-list">
                <?php foreach ($draft['games'] as $idx => $g): // remove button value is "rem:<idx>" ?>
                    <li>
                        <span class="cand-name"><?= e($g['name']) ?></span>
                        <span class="muted"><?= e(t('poll_req_short', (int)$g['required_players'])) ?></span>
                        <button type="submit" name="do" value="rem:<?= (int)$idx ?>" class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="poll-buttons">
            <button type="submit" name="do" value="addgame" class="btn"><?= e(t('poll_addgame')) ?></button>
            <button type="submit" name="do" value="finish" class="btn btn-primary"><?= e(t('poll_finish')) ?></button>
            <button type="submit" name="do" value="cancel" class="btn"><?= e(t('cancel')) ?></button>
        </div>
    </form>
</div>
