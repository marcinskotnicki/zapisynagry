<?php
/* =============================================================================
 *  prefs.php — save the visitor's theme / language choice (cookie-based).
 * -----------------------------------------------------------------------------
 *  Target of the topbar dropdowns (guests) and the user-panel preferences card
 *  (accounts). POST only, CSRF-protected. Each preference is applied only when
 *  BOTH hold: the value exists (a real theme dir / language file) AND the admin
 *  allows switching for this visitor type (allow_user_* / allow_guest_*) — the
 *  same *_switch_allowed() gates that decide whether the pickers render at all,
 *  so UI and enforcement can never disagree.
 *
 *  'back' is the relative URL to return to (the form embeds its own page).
 *  Anything that smells absolute ('://', leading '//') falls back to index.php
 *  so the redirect can't be aimed off-site.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();

// Sanitised return target (relative paths only).
$back = (string)($_POST['back'] ?? 'index.php');
if ($back === '' || strpos($back, '://') !== false || substr($back, 0, 2) === '//') {
    $back = 'index.php';
}

// Theme: setter validates existence again; the gate checks the admin toggle.
if (isset($_POST['template']) && tpl_switch_allowed() && tpl_exists((string)$_POST['template'])) {
    tpl_set_cookie((string)$_POST['template']);
}
// Language: same pattern.
if (isset($_POST['lang']) && lang_switch_allowed() && lang_exists((string)$_POST['lang'])) {
    lang_set_cookie((string)$_POST['lang']);
}

redirect($back);
