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

    <?php if ($action === 'add_game.php' && opt_msg('msg_adding_game') !== ''): // admin note, game gate only ?>
        <p class="event-msg"><?= e(opt_msg('msg_adding_game')) ?></p>
    <?php endif; ?>

    <?php // The visual hierarchy (top to bottom, most to least used): BGG add is
          // the big primary button; start-a-poll is a medium secondary; manual
          // (outside BGG) is a small secondary — it submits the SAME form from
          // outside it via the form="" attribute, so the typed name still travels. ?>
    <form method="post" action="<?= e($action) ?>?table=<?= (int)$table['id'] ?>" class="gate-form" id="gate-form">
        <?= $csrf ?>
        <input type="hidden" name="table" value="<?= (int)$table['id'] ?>">

        <?php // The club-shelf button. It is the same KIND of step as the BGG
              // search — pick a known game — so it looks the same; only its
              // position changes with the admin's preference.
              //
              // formnovalidate throughout: the club list does not need the name
              // field, which the BGG search beside it requires.
              //
              // PREFERRED: above the name field, because for a club whose
              // cabinet is the usual source, typing a name to search BGG is the
              // fallback rather than the first move. ?>
        <?php if (club_shelf_pick_enabled() && library_prefer_club()): ?>
            <div class="gate-buttons gate-buttons-lead">
                <button type="submit" name="go" value="club" class="btn btn-primary btn-big" formnovalidate>
                    <?= e(t('addgame_from_club')) ?>
                </button>
            </div>
        <?php endif; ?>

        <?php /* ABOVE the name box, because it does not use it: picking from a
               * library is choosing a game, not searching for one, and a button
               * sitting under a field it ignores reads as though it needs it
               * filled in first.
               *
               * For somebody who keeps a library the game they are bringing is
               * almost always already in it, so this is the shortest route and
               * goes first. Only shown when they have one with games in it —
               * otherwise it is a dead end, and they are the only person who
               * could tell it was empty. */ ?>
        <?php if (!empty($own_pick)): ?>
            <div class="gate-buttons">
                <button type="submit" name="go" value="mine" class="btn btn-primary btn-big" formnovalidate>
                    <?php /* Named when the library is somebody else's — on a
                           * poll restricted to the proposer's shelf, "my
                           * library" is wrong for everyone but them. */ ?>
                    <?= e(!empty($library_owner)
                            ? t('addgame_from_owner', $library_owner)
                            : t('addgame_from_own')) ?>
                </button>
            </div>
        <?php endif; ?>

        <?php /* THE SEARCH BOX AND ITS BUTTON, boxed together because they are
               * one action in two parts and the only pair on this screen that
               * belongs to each other — every other button here ignores the
               * field entirely.
               *
               * WITHOUT A BGG CODE the whole group goes: the box exists only to
               * feed that search, and leaving it would ask for a name with
               * nowhere to send it. Everything else still works — a game can be
               * typed in by hand or picked from a library. */ ?>
        <?php /* A poll restricted to the proposer's library offers ONE route.
               * Everything below is hidden — and refused server-side, so this
               * is presentation rather than the rule itself. */ ?>
        <?php if (empty($library_only)): ?>
        <?php if (bgg_configured()): ?>
            <div class="gate-search">
                <div class="field field-game_name">
                    <label for="game_name"><?= e(t('addgame_name')) ?></label>
                    <input type="text" id="game_name" name="name"<?= (club_shelf_pick_enabled() && library_prefer_club()) ? '' : ' autofocus' ?>>
                </div>
                <button type="submit" name="go" value="bgg" class="btn btn-primary btn-big">
                    <?= e(t('addgame_from_bgg')) ?>
                </button>
            </div>
        <?php endif; ?>

        <div class="gate-buttons">
            <?php // NOT preferred: under the BGG group, as a second option. ?>
            <?php if (club_shelf_pick_enabled() && !library_prefer_club()): ?>
                <button type="submit" name="go" value="club" class="btn btn-primary btn-big" formnovalidate>
                    <?= e(t('addgame_from_club')) ?>
                </button>
            <?php endif; ?>
        </div>
        <?php endif; // library_only ?>
    </form>

    <?php if ($show_poll && opt_bool('allow_polls')): // medium: start a poll instead ?>
        <p class="gate-poll">
            <a class="btn btn-secondary" href="add_poll.php?table=<?= (int)$table['id'] ?>"><?= e(t('addpoll_button')) ?></a>
        </p>
    <?php endif; ?>

    <?php // Also a route into the poll, so it goes when restricted. ?>
    <?php if (empty($library_only)): ?>
        <p class="gate-manual">
            <button type="submit" form="gate-form" name="go" value="manual" class="btn btn-secondary btn-small-gate">
                <?= e(t('addgame_manual')) ?>
            </button>
        </p>
    <?php endif; ?>

    <p><a class="btn" href="index.php"><?= e(t('back')) ?></a></p>
</div>
