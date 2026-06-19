<?php
/* =============================================================================
 *  templates/light/admin_thumbnails.php — Thumbnails tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  A multi-file upload form (these become the predefined fallback images offered
 *  when a game has no BGG image) and a grid of existing thumbnails, each with its
 *  own delete form. The controller converts/resizes uploads on save.
 *
 *  RENDER VARS:
 *    $csrf   — hidden CSRF field.
 *    $thumbs — existing thumbnails, each {id, filename}.
 * ============================================================================= */
?>
<form method="post" action="admin.php?tab=thumbnails" enctype="multipart/form-data" class="thumb-upload">
    <?= $csrf ?>
    <div class="field">
        <label for="thumb_files"><?= e(t('thumb_choose')) ?></label>
        <input type="file" id="thumb_files" name="files[]" accept="image/*" multiple required>
    </div>
    <button type="submit" class="btn btn-primary"><?= e(t('thumb_upload')) ?></button>
</form>

<h3><?= e(t('thumb_existing')) ?></h3>
<?php if (empty($thumbs)): ?>
    <p class="muted"><?= e(t('thumb_none')) ?></p>
<?php else: ?>
<div class="thumb-grid">
    <?php foreach ($thumbs as $tn): // each thumbnail gets its own delete form ?>
        <figure class="thumb-item">
            <img src="<?= e($tn['filename']) ?>" alt="">
            <form method="post" action="admin.php?tab=thumbnails">
                <?= $csrf ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$tn['id'] ?>">
                <button class="btn btn-small btn-danger"><?= e(t('delete')) ?></button>
            </form>
        </figure>
    <?php endforeach; ?>
</div>
<?php endif; ?>
