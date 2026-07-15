<?php
/* =============================================================================
 *  templates/light/poll_candidate_form.php — add a candidate game to a poll.
 * -----------------------------------------------------------------------------
 *  PRESENTATION ONLY. Like the manual add-game form, but with the candidate-only
 *  "required players" field (the vote threshold) and no signup/bringer fields —
 *  candidates persist into the poll, not as a real game.
 *
 *  A BGG-sourced candidate locks its image (hidden bgg_id + thumbnail, shown as
 *  a preview); a manual candidate shows the predefined-thumbnail picker.
 *
 *  RENDER VARS:
 *    $table  — the table the poll belongs to.
 *    $cand   — prefill values for the candidate.
 *    $source — 'manual' or 'bgg' (drives the image area + hidden fields).
 *    $thumbs — predefined thumbnails for the manual picker (empty for BGG).
 *    $error  — message above the form, or null.
 *    $csrf   — hidden CSRF field.
 * ============================================================================= */
$isBgg = ($source === 'bgg');   // BGG candidates keep a locked image
?>
<div class="card">
    <h1><?= e(t('addpoll_candidate_title')) ?></h1>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="add_poll_game.php?table=<?= (int)$table['id'] ?>" class="game-form">
        <?= $csrf ?>
        <input type="hidden" name="mode" value="save">
        <input type="hidden" name="table" value="<?= (int)$table['id'] ?>">
        <input type="hidden" name="source" value="<?= e($source) ?>">
        <?php if ($isBgg): // carry the locked BGG identity + image through ?>
            <input type="hidden" name="bgg_id" value="<?= e($cand['bgg_id']) ?>">
            <input type="hidden" name="thumbnail" value="<?= e($cand['thumbnail']) ?>">
        <?php endif; ?>

        <div class="field">
            <label for="name"><?= e(t('addgame_name')) ?></label>
            <input type="text" id="name" name="name" value="<?= e($cand['name']) ?>" required>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="length_minutes"><?= e(t('f_length')) ?></label>
                <input type="number" id="length_minutes" name="length_minutes" min="0" value="<?= e($cand['length_minutes']) ?>">
            </div>
            <div class="field">
                <label for="weight"><?= e(t('f_weight')) ?></label>
                <input type="number" id="weight" name="weight" min="1" max="5" step="0.01" value="<?= e($cand['weight']) ?>">
            </div>
            <div class="field">
                <label for="max_players"><?= e(t('f_maxplayers')) ?></label>
                <input type="number" id="max_players" name="max_players" min="1" value="<?= e($cand['max_players']) ?>">
            </div>
            <div class="field">
                <label for="required_players"><?= e(t('poll_required')) ?></label>
                <input type="number" id="required_players" name="required_players" min="1" value="<?= e($cand['required_players']) ?>">
            </div>
        </div>

        <?php if ($isBgg): // locked BGG image preview ?>
            <div class="field">
                <label><?= e(t('f_thumbnail')) ?></label>
                <?php if (!empty($cand['thumbnail'])): ?>
                    <img class="bgg-thumb" src="<?= e($cand['thumbnail']) ?>" alt="">
                <?php else: ?>
                    <span class="muted"><?= e(t('f_no_thumb')) ?></span>
                <?php endif; ?>
            </div>
        <?php else: // manual: pick from predefined thumbnails (or none) ?>
            <div class="field">
                <label><?= e(t('f_thumbnail')) ?></label>
                <?php if (empty($thumbs)): ?>
                    <span class="muted"><?= e(t('f_no_thumb')) ?></span>
                <?php else: ?>
                    <div class="thumb-picker">
                        <label class="thumb-opt">
                            <input type="radio" name="thumbnail" value="" <?= $cand['thumbnail'] === '' ? 'checked' : '' ?>>
                            <span class="thumb-none-box"><?= e(t('no')) ?></span>
                        </label>
                        <?php foreach ($thumbs as $tn): ?>
                            <label class="thumb-opt">
                                <input type="radio" name="thumbnail" value="<?= e($tn['filename']) ?>" <?= $cand['thumbnail'] === $tn['filename'] ? 'checked' : '' ?>>
                                <img src="<?= e($tn['filename']) ?>" alt="">
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="language"><?= e(t('f_language')) ?></label>
            <?php
            // Same admin-configured dropdown as the game form (see add_game_form).
            $langOpts = game_language_options();
            $curLang  = (string)($cand['language'] ?? '');
            ?>
            <select id="language" name="language">
                <option value=""><?= e(t('f_language_none')) ?></option>
                <?php foreach ($langOpts as $lo): ?>
                    <option value="<?= e($lo) ?>"<?= $curLang === $lo ? ' selected' : '' ?>><?= e($lo) ?></option>
                <?php endforeach; ?>
                <?php if ($curLang !== '' && !in_array($curLang, $langOpts, true)): ?>
                    <option value="<?= e($curLang) ?>" selected><?= e($curLang) ?></option>
                <?php endif; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary"><?= e(t('poll_addgame')) ?></button>
        <a class="btn" href="add_poll.php?table=<?= (int)$table['id'] ?>"><?= e(t('cancel')) ?></a>
    </form>
</div>
