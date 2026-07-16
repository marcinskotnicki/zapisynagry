<?php
/* =============================================================================
 *  templates/light/add_game_form.php — the add/EDIT game form. Presentation.
 * -----------------------------------------------------------------------------
 *  One form used in three situations, controlled by the vars below:
 *    - manual add  ($source='manual', $is_edit=false): full form + thumb picker.
 *    - BGG add      ($source='bgg'):  image locked (hidden bgg_id + thumbnail).
 *    - edit        ($is_edit=true):   posts to edit_game.php with a hidden game
 *                  id, uses the "Save" label, and hides the add-self checkbox.
 *  It always posts mode=save; the target controller (add_game.php or
 *  edit_game.php) is whatever $action points at.
 *
 *  RENDER VARS:
 *    $table   — the table (id goes in a hidden field).
 *    $game    — prefill values (a defaults array when adding, the row when editing).
 *    $source  — 'manual' or 'bgg'.
 *    $thumbs  — predefined thumbnails for the manual picker (empty for BGG).
 *    $captcha — captcha HTML, or '' when none is required (e.g. on edit).
 *    $error   — message above the form, or null.
 *    $csrf    — hidden CSRF field.
 *    $action  — form target (default 'add_game.php?table=ID').
 *    $is_edit — edit mode (hidden game id + Save label + no add-self).
 *    $title   — heading (default add-game title).
 * ============================================================================= */
$isBgg   = ($source === 'bgg');                                  // image locked to BGG?
$action  = $action  ?? ('add_game.php?table=' . (int)$table['id']);   // where to post
$is_edit = $is_edit ?? false;
$title   = $title   ?? t('addgame_title');
$captcha = $captcha ?? '';                                       // '' = no captcha block
?>
<div class="card">
    <h1><?= e($title) ?></h1>

    <?php if (!empty($error)): ?>
        <p class="msg msg-error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>" class="game-form">
        <?= $csrf ?>
        <input type="hidden" name="mode" value="save">
        <input type="hidden" name="table" value="<?= (int)$table['id'] ?>">
        <input type="hidden" name="source" value="<?= e($source) ?>">
        <?php if ($is_edit): // edit mode carries the game id ?>
            <input type="hidden" name="game" value="<?= (int)$game['id'] ?>">
        <?php endif; ?>
        <?php if ($isBgg): // BGG identity + locked image travel as hidden fields ?>
            <input type="hidden" name="bgg_id" value="<?= e($game['bgg_id']) ?>">
            <input type="hidden" name="thumbnail" value="<?= e($game['thumbnail']) ?>">
        <?php endif; ?>

        <div class="field">
            <label for="name"><?= e(t('addgame_name')) ?></label>
            <input type="text" id="name" name="name" value="<?= e($game['name']) ?>" required>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="length_minutes"><?= e(t('f_length')) ?></label>
                <input type="number" id="length_minutes" name="length_minutes" min="0" value="<?= e($game['length_minutes']) ?>">
            </div>
            <div class="field">
                <label for="weight"><?= e(t('f_weight')) ?></label>
                <input type="number" id="weight" name="weight" min="1" max="5" step="0.01" value="<?= e($game['weight']) ?>">
            </div>
            <div class="field">
                <label for="max_players"><?= e(t('f_maxplayers')) ?></label>
                <input type="number" id="max_players" name="max_players" min="1" value="<?= e($game['max_players']) ?>">
            </div>
            <div class="field">
                <label for="start_time"><?= e(t('f_start')) ?></label>
                <input type="time" id="start_time" name="start_time" value="<?= e($game['start_time']) ?>">
            </div>
        </div>

        <?php if ($isBgg): // BGG: show the locked image (or "none") ?>
            <div class="field">
                <label><?= e(t('f_thumbnail')) ?></label>
                <?php if (!empty($game['thumbnail'])): ?>
                    <img class="bgg-thumb" src="<?= e($game['thumbnail']) ?>" alt="">
                <?php else: ?>
                    <span class="muted"><?= e(t('f_no_thumb')) ?></span>
                <?php endif; ?>
            </div>
        <?php else: // manual: choose a predefined thumbnail (radio picker), or none ?>
            <div class="field">
                <label><?= e(t('f_thumbnail')) ?></label>
                <?php if (empty($thumbs)): ?>
                    <span class="muted"><?= e(t('f_no_thumb')) ?></span>
                <?php else: ?>
                    <div class="thumb-picker">
                        <label class="thumb-opt">
                            <input type="radio" name="thumbnail" value="" <?= $game['thumbnail'] === '' ? 'checked' : '' ?>>
                            <span class="thumb-none-box"><?= e(t('no')) ?></span>
                        </label>
                        <?php foreach ($thumbs as $tn): ?>
                            <label class="thumb-opt">
                                <input type="radio" name="thumbnail" value="<?= e($tn['filename']) ?>" <?= $game['thumbnail'] === $tn['filename'] ? 'checked' : '' ?>>
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
            // Choices come from the admin-configured list (one per line in the
            // Options tab). '' = not specified. If an edited game carries a value
            // that's no longer on the list, keep it as an extra option so editing
            // never silently drops it.
            $langOpts = game_language_options();
            $curLang  = (string)($game['language'] ?? '');
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

        <div class="field-row">
            <div class="field">
                <label for="brings_name"><?= e(t('f_brings')) ?></label>
                <input type="text" id="brings_name" name="brings_name" value="<?= e($game['brings_name']) ?>">
            </div>
            <div class="field">
                <label for="brings_email"><?= e(t('f_email')) ?><?= opt_bool('require_email') ? ' *' : '' ?></label>
                <?php if (opt('msg_email_field') !== ''): ?>
                    <p class="field-note"><?= e(opt('msg_email_field')) ?></p>
                <?php endif; ?>
                <input type="email" id="brings_email" name="brings_email" value="<?= e($game['brings_email']) ?>">
            </div>
        </div>

        <div class="field">
            <label for="explain_rules"><?= e(t('f_explain')) ?></label>
            <select id="explain_rules" name="explain_rules">
                <?php foreach ([0 => 'rules_explain', 1 => 'rules_summary', 2 => 'rules_known'] as $code => $k): ?>
                    <option value="<?= $code ?>"<?= (int)$game['explain_rules'] === $code ? ' selected' : '' ?>><?= e(t($k)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (!$is_edit): // add-self only makes sense when creating a new game ?>
            <div class="field field-check">
                <label>
                    <input type="checkbox" name="add_self" value="1" <?= (int)$game['add_self'] === 1 ? 'checked' : '' ?>>
                    <?= e(t('f_addself')) ?>
                </label>
            </div>
        <?php endif; ?>

        <?php // Custom link: manual games only (BGG games link via bgg_id) and only
              // while the admin allows it. Bare domains get https:// on save. ?>
        <?php if (!$isBgg && opt_bool('allow_custom_game_links')): ?>
            <div class="field">
                <label for="link"><?= e(t('f_link')) ?></label>
                <input type="url" id="link" name="link" value="<?= e($game['link'] ?? '') ?>" placeholder="https://">
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="comment"><?= e(t('f_comment')) ?></label>
            <textarea id="comment" name="comment" rows="2"><?= e($game['comment']) ?></textarea>
        </div>

        <?= $captcha ?>

        <button type="submit" class="btn btn-primary"><?= $is_edit ? e(t('save')) : e(t('f_save')) ?></button>
        <a class="btn" href="index.php"><?= e(t('cancel')) ?></a>
    </form>
</div>
