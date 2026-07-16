<?php
/* =============================================================================
 *  templates/light/end_poll_confirm.php — "end voting now?" confirm. Presentation.
 * -----------------------------------------------------------------------------
 *  Shown to the poll's proposer (or an admin) before force-resolving the poll:
 *  the current leader (best fill ratio, earlier candidate wins ties) becomes a
 *  real game immediately.
 *
 *  RENDER VARS:
 *    $poll — the polls row being ended.
 *    $csrf — hidden CSRF field.
 * ============================================================================= */
?>
<div class="card card-narrow">
    <h1><?= e(t('poll_end_title')) ?></h1>
    <p><?= e(t('poll_end_confirm')) ?></p>

    <form method="post" action="end_poll.php?poll=<?= (int)$poll['id'] ?>">
        <?= $csrf ?>
        <input type="hidden" name="poll" value="<?= (int)$poll['id'] ?>">
        <button type="submit" class="btn btn-danger"><?= e(t('poll_end_yes')) ?></button>
        <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
    </form>
</div>
