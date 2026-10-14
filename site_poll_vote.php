<?php
/* =============================================================================
 *  site_poll_vote.php — a vote in one of the admin's opinion polls.
 * -----------------------------------------------------------------------------
 *  POST only, behind the CSRF token, and always back to the page the vote came
 *  from. Every rule — who may vote, single versus multiple answers, adding an
 *  answer of your own — is decided by sp_cast() in inc/sitepolls.php; this file
 *  only reads the form and reports the outcome.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sitepolls.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !site_polls_enabled()) redirect('index.php');
csrf_check();
/* Guests can vote, so the same bot screening as every other public form. The
 * form renders the field; without this check it would be decoration. */
antibot_check('form');   // bootstrap.php has already loaded inc/antibot.php

$poll = sp_get((int)($_POST['poll'] ?? 0));

/* Back to where the vote was cast — but only ever to the front page, with its
 * own query string. A free-form "back" would make this an open redirect. */
$back = (string)($_POST['back'] ?? '');
if (!preg_match('#^index\.php(\?[A-Za-z0-9_=&%.-]*)?$#', $back)) $back = 'index.php';

if (!$poll) redirect($back);

/* The form's two shapes. A single-answer poll posts one radio value — an
 * answer's id, or "custom" for the write-in. A multi-answer poll posts the
 * ticked ids as a list, and a separate tick for the write-in. Either way the
 * write-in only counts when its box/radio was chosen AND something was typed. */
$custom = '';
if ((int)$poll['multi'] === 1) {
    $ids = array_map('intval', (array)($_POST['opt'] ?? []));
    if (!empty($_POST['custom_on'])) $custom = (string)($_POST['custom_text'] ?? '');
} else {
    $pick = (string)($_POST['opt'] ?? '');
    if ($pick === 'custom') {
        $ids = [];
        $custom = (string)($_POST['custom_text'] ?? '');
    } else {
        $ids = $pick !== '' ? [(int)$pick] : [];
    }
}

$err = sp_cast($poll, $ids, $custom);
if ($err === '') {
    flash_set(t('sp_voted'));
    log_action('site_poll_vote', 'poll #' . (int)$poll['id']);
} else {
    flash_set(t($err), 'error');
}
redirect($back . '#site-poll-' . (int)$poll['id']);
