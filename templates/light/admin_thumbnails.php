<?php
/* =============================================================================
 *  templates/light/admin_thumbnails.php — Thumbnails tab. Presentation only.
 * -----------------------------------------------------------------------------
 *  A multi-file upload form (these become the predefined fallback images offered
 *  when a game has no BGG image) and a grid of existing thumbnails, each with its
 *  own delete form. The controller converts/resizes uploads on save.
 *
 *  Below the thumbnails sits the SITE ICON section: upload one square-ish image
 *  and the controller renders the favicon + home-screen icon set under /icons.
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
<fieldset class="icon-section">
<legend><?= e(t('icon_section')) ?></legend>
<p class="muted"><?= e(t('icon_hint')) ?></p>

<?php if (opt('site_icon') !== ''): // an icon is set -> preview + remove ?>
<p>
<?php // ?v= busts the browser cache after a replacement upload. ?>
<img src="icons/favicon-32.png?v=<?= e(opt('site_icon')) ?>" alt="" width="32" height="32">
<img src="icons/apple-touch-icon.png?v=<?= e(opt('site_icon')) ?>" alt="" width="64" height="64">
</p>
<form method="post" action="admin.php?tab=thumbnails" class="inline">
<?= $csrf ?>
<input type="hidden" name="action" value="icon_delete">
<button class="btn btn-small btn-danger"><?= e(t('icon_delete')) ?></button>
</form>
<?php else: ?>
<p class="muted"><?= e(t('icon_none')) ?></p>
<?php endif; ?>

<form method="post" action="admin.php?tab=thumbnails" enctype="multipart/form-data" class="icon-upload">
<?= $csrf ?>
<input type="hidden" name="action" value="icon_upload">
<div class="field">
<input type="file" name="icon" accept="image/*" required>
</div>
<button type="submit" class="btn btn-primary"><?= e(t('icon_upload')) ?></button>
</form>
</fieldset>
<?php endif; ?>
