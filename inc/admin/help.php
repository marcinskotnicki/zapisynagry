<?php
/* =============================================================================
 *  inc/admin/help.php — the getting-started guide, inside the panel.
 * -----------------------------------------------------------------------------
 *  Renders docs/PIERWSZE-KROKI.md or docs/GETTING-STARTED.md, whichever matches
 *  the language the admin is reading the panel in.
 *
 *  WHY RENDER THE FILE rather than keep the text in the language files: it is a
 *  long document with headings, lists and a contents list, and putting that
 *  through t() would mean one enormous unreadable string per language and no
 *  way to review a change to it. As Markdown files they can be read, diffed and
 *  edited by somebody who is not a programmer — which is the point, since the
 *  guide is likely to be reworded far more often than the code around it.
 *
 *  ADMIN-ONLY comes free: admin.php calls require_admin() before it includes
 *  any tab, so there is no separate check to forget here.
 * ============================================================================= */
require_once __DIR__ . '/../markdown.php';

/* Which document. Keyed by language, with Polish as the fallback because that
 * is the language of the clubs this is written for — and a fallback that
 * matches most readers beats one that matches the code's own language. */
$helpDocs = [
    'pl' => 'PIERWSZE-KROKI.md',
    'en' => 'GETTING-STARTED.md',
];
$helpLang = lang_current();
$helpFile = $APP_ROOT . '/help/' . ($helpDocs[$helpLang] ?? $helpDocs['pl']);

/* NOT in docs/. That folder is deliberately never deployed — see
 * update_skipped_paths() — because it holds developer documentation that has no
 * business in a web root. This guide is the opposite: it is for the admin using
 * the live site, so it needs a folder that DOES ship, and help/ is it.
 *
 * Still checked for, because an install that predates this folder will not have
 * it until the next update. Say so plainly instead of rendering an empty page
 * that looks like a broken tab. */
$helpBody = is_file($helpFile) ? (string)file_get_contents($helpFile) : null;

$tab_body = tpl_capture('admin_help', [
    'html'    => $helpBody === null ? null : md_render($helpBody),
    // Shown when the guide is missing, so an admin can tell somebody WHICH
    // file did not arrive rather than just "it is broken".
    'missing' => $helpBody === null ? 'help/' . basename($helpFile) : null,
]);
