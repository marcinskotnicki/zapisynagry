<?php
/* =============================================================================
 *  templates/light/admin_texts.php — manage the footer pages.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $csrf    — hidden CSRF field.
 *    $pages   — existing pages, in menu order.
 *    $editing — the page being edited, or null for a new one.
 * ============================================================================= */
?>
<h3><?= e(t('texts_title')) ?></h3>
<p class="muted"><?= e(t('texts_intro')) ?></p>

<?php if (!$pages): ?>
    <p class="muted"><?= e(t('texts_none')) ?></p>
<?php else: ?>
    <table class="grid">
        <thead>
            <tr>
                <th><?= e(t('texts_page_title')) ?></th>
                <th><?= e(t('texts_order')) ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pages as $i => $p): ?>
                <tr>
                    <td><a href="page.php?id=<?= (int)$p['id'] ?>"><?= e($p['title']) ?></a></td>
                    <td class="nowrap">
                        <?php // Menu order. Arrows rather than a number field: the
                              // only thing an admin wants here is "this one above
                              // that one", and a swap cannot produce a tie. ?>
                        <?php if ($i > 0): ?>
                            <form method="post" action="admin.php?tab=texts" class="inline">
                                <?= $csrf ?>
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="dir" value="up">
                                <button class="btn btn-small" title="<?= e(t('texts_move_up')) ?>">&uarr;</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($i < count($pages) - 1): ?>
                            <form method="post" action="admin.php?tab=texts" class="inline">
                                <?= $csrf ?>
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="dir" value="down">
                                <button class="btn btn-small" title="<?= e(t('texts_move_down')) ?>">&darr;</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn btn-small" href="admin.php?tab=texts&amp;edit=<?= (int)$p['id'] ?>"><?= e(t('edit')) ?></a>
                        <form method="post" action="admin.php?tab=texts" class="inline"
                              onsubmit="return confirm('<?= e(t('texts_delete_confirm')) ?>');">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<fieldset>
    <legend><?= e($editing ? t('texts_edit_page') : t('texts_new_page')) ?></legend>
    <form method="post" action="admin.php?tab=texts">
        <?= $csrf ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="field">
            <label for="page_title"><?= e(t('texts_page_title')) ?></label>
            <input type="text" id="page_title" name="title" maxlength="200"
                   value="<?= e($editing['title'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="page_body_editor"><?= e(t('texts_page_body')) ?></label>
            <?php // Trix edits a HIDDEN input and writes HTML into it, so the
                  // field the controller reads is unchanged — without JS this
                  // degrades to nothing being editable here, which is why the
                  // plain textarea below stays as the fallback.
                  //
                  // The input must come BEFORE the editor element that names it,
                  // or Trix cannot find it at upgrade time. ?>
            <input type="hidden" id="page_body" name="body" value="<?= e($editing['body'] ?? '') ?>">
            <trix-editor input="page_body" id="page_body_editor" class="trix-content"></trix-editor>
            <p class="field-note"><?= e(t('texts_page_body_note')) ?></p>
        </div>
        <button type="submit" class="btn btn-primary"><?= e(t('save')) ?></button>
        <?php if ($editing): ?>
            <a class="btn" href="admin.php?tab=texts"><?= e(t('cancel')) ?></a>
        <?php endif; ?>
    </form>
</fieldset>
