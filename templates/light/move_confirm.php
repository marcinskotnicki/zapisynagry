<?php
/* =============================================================================
 *  templates/light/move_confirm.php — pick a destination table. Presentation only.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $kind       — 'game' | 'poll'.
 *    $row        — the game or poll being moved.
 *    $tables     — candidate destination tables (same day, excluding the current one).
 *    $active_day — 1-based day index, for the back link.
 *    $error      — validation message, or ''.
 *    $csrf, $antibot — hidden fields.
 * ============================================================================= */
?>
<div class="card">
    <h1><?= e(t('move_title')) ?></h1>

    <?php // Name the thing being moved: an admin who clicked the wrong card
          // should be able to tell before confirming, not after. ?>
    <p class="move-subject">
        <strong><?= e($row['name'] ?? t('poll_label')) ?></strong>
        <?php if (!empty($row['start_time'])): ?>
            <span class="muted"><?= e($row['start_time']) ?></span>
        <?php endif; ?>
    </p>

    <?php if ($error !== ''): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (!$tables): ?>
        <?php // Nothing to move to — say so plainly rather than showing an
              // empty dropdown and a button that cannot work. ?>
        <p class="muted"><?= e(t('move_no_tables')) ?></p>
        <p><a class="btn" href="index.php?day=<?= (int)$active_day ?>"><?= e(t('back')) ?></a></p>
    <?php else: ?>
        <form method="post" action="move_item.php">
            <?= $csrf ?>
            <?= $antibot ?>
            <input type="hidden" name="<?= e($kind) ?>" value="<?= (int)$row['id'] ?>">
            <div class="field">
                <label for="move_table"><?= e(t('move_target')) ?></label>
                <select id="move_table" name="table">
                    <?php foreach ($tables as $t): ?>
                        <option value="<?= (int)$t['id'] ?>">
                            <?= e(t('table_label', $t['table_number'])) ?><?php
                                // The optional table name, when the club uses them —
                                // "Table 4" alone is hard to pick out of a list.
                                if (!empty($t['table_name'])) {
                                    echo ' — ' . e($t['table_name']);
                                }
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" name="choice" value="move" class="btn btn-primary"><?= e(t('move_confirm')) ?></button>
                <button type="submit" name="choice" value="back" class="btn"><?= e(t('back')) ?></button>
            </div>
        </form>
    <?php endif; ?>
</div>
