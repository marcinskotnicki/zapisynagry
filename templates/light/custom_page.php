<?php
/* =============================================================================
 *  templates/light/custom_page.php — one admin-written page.
 * -----------------------------------------------------------------------------
 *  RENDER VARS:
 *    $page — the custom_pages row.
 *
 *  The BODY is printed unescaped on purpose: only an admin can write it, and
 *  the whole point is to allow formatting, links and embedded logos. The TITLE
 *  is escaped, because it is a plain label rather than markup and is also used
 *  as the <title> and in the footer menu.
 * ============================================================================= */
?>
<div class="card custom-page">
    <h1><?= e($page['title']) ?></h1>
    <div class="custom-page-body"><?= $page['body'] ?></div>
</div>
