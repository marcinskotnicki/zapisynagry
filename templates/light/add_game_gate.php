<?php
/* =============================================================================
 *  templates/light/add_game_gate.php — the add-game gate. Presentation only.
 * -----------------------------------------------------------------------------
 *  The first screen of adding a game: one name field and two submit buttons that
 *  set go=bgg / go=manual (add_game.php routes on that). REUSED for the poll-
 *  candidate gate by passing a different $action — which also suppresses the
 *  admin "adding a game" note and the start-a-poll button.
 *
 *  RENDER VARS:
 *    $table     — the table to add to (its id goes in the form action + hidden).
 *    $csrf      — hidden CSRF field.
 *    $action    — target script (default 'add_game.php'; 'add_poll_game.php' for
 *                 the candidate gate).
 *    $show_poll — show the "start a poll instead" button (game gate only).
 *    $title     — heading (default add-game title).
 * ============================================================================= */
$action    = $action    ?? 'add_game.php';   // where the gate buttons post to
$show_poll  = $show_poll ?? false;           // third "start a poll" button?
$title      = $title     ?? t('addgame_title');
?>
<div class="card card-narrow">
    <h1><?= e($title) ?></h1>

    <?php if ($action === 'add_game.php' && opt('msg_adding_game') !== ''): // admin note, game gate only ?>
        <p class="event-msg"><?= e(opt('msg_adding_game')) ?></p>
    <?php endif; ?>

    <?php // The visual hierarchy (top to bottom, most to least used): BGG add is
          // the big primary button; start-a-poll is a medium secondary; manual
          // (outside BGG) is a small secondary — it submits the SAME form from
          // outside it via the form="" attribute, so the typed name still travels. ?>
    <form method="post" action="<?= e($action) ?>?table=<?= (int)$table['id'] ?>" class="gate-form" id="gate-form">
        <?= $csrf ?>
        <input type="hidden" name="table" value="<?= (int)$table['id'] ?>">

        <label for="game_name"><?= e(t('addgame_name')) ?></label>
        <input type="text" id="game_name" name="name" autofocus>

        <div class="gate-buttons">
            <button type="submit" name="go" value="bgg" class="btn btn-primary btn-big">
                <?= e(t('addgame_from_bgg')) ?>
            </button>
        </div>
    </form>

    <?php if ($show_poll && opt_bool('allow_polls')): // medium: start a poll instead ?>
        <p class="gate-poll">
            <a class="btn btn-secondary" href="add_poll.php?table=<?= (int)$table['id'] ?>"><?= e(t('addpoll_button')) ?></a>
        </p>
    <?php endif; ?>

    <p class="gate-manual">
        <button type="submit" form="gate-form" name="go" value="manual" class="btn btn-secondary btn-small-gate">
            <?= e(t('addgame_manual')) ?>
        </button>
    </p>

    <p class="muted"><a href="index.php"><?= e(t('back')) ?></a></p>
</div>
