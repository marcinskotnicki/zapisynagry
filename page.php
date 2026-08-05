<?php
/* =============================================================================
 *  page.php — one admin-written page, linked from the footer menu.
 * -----------------------------------------------------------------------------
 *  No option gates this: a page only exists because an admin created one, and
 *  the footer menu disappears by itself when there are none.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';

$page = custom_page((int)($_GET['id'] ?? 0));
if (!$page) {
    // A deleted page may still be linked from someone's bookmark or an old
    // print-out; the front page is a more useful landing than an error.
    redirect('index.php');
}

tpl_render('header', ['page_title' => $page['title']]);
tpl_render('custom_page', ['page' => $page]);
tpl_render('footer');
