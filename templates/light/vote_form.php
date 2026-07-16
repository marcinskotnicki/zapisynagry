<?php
/* =============================================================================
 *  templates/light/vote_form.php — vote for a poll candidate. Presentation only.
 * -----------------------------------------------------------------------------
 *  Same shape as the sign-up form (name / email / knows-rules), because a vote
 *  doubles as a provisional signup: if the candidate reaches its threshold the
 *  voters become the new game's players.
 *
 *  RENDER VARS:
 *    $cand  — the poll candidate (poll_games row) being voted for.
 *    $form  — prefilled ['name','email','knows'].
 *    $error — message above the form, or null.
 *    $csrf  — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('vote_title')) ?></h1>
    <p class="muted"><?= e($cand['name']) ?></p>

    <?php if (opt('msg_voting') !== ''): // optional admin-configured note ?>
        <p class="event-msg"><?= e(opt('msg_voting')) ?></p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="vote.php?poll_game=<?= (int)$cand['id'] ?>">
        <?= $csrf ?>
        <input type="hidden" name="poll_game" value="<?= (int)$cand['id'] ?>">

        <label for="name"><?= e(t('signup_name')) ?></label>
        <input type="text" id="name" name="name" value="<?= e($form['name']) ?>" required>

        <label for="email"><?= e(t('signup_email')) ?><?= opt_bool('require_email') ? ' *' : '' ?></label>
        <?php if (opt('msg_email_field') !== ''): ?>
            <p class="field-note"><?= e(opt('msg_email_field')) ?></p>
        <?php endif; ?>
        <input type="email" id="email" name="email" value="<?= e($form['email']) ?>">

        <label for="knows"><?= e(t('signup_knows')) ?></label>
        <select id="knows" name="knows">
            <?php foreach ([0 => 'knows_yes', 1 => 'knows_somewhat', 2 => 'knows_no'] as $code => $k): ?>
                <option value="<?= $code ?>"<?= (int)$form['knows'] === $code ? ' selected' : '' ?>><?= e(t($k)) ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-primary"><?= e(t('poll_vote')) ?></button>
        <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
    </form>
</div>
