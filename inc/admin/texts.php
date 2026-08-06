<?php
/* =============================================================================
 *  inc/admin/texts.php — the admin-written pages linked from the footer.
 * -----------------------------------------------------------------------------
 *  Runs INSIDE admin.php's scope, so $flash / $tab_body exist and CSRF has been
 *  checked centrally.
 *
 *  Bodies are stored as raw HTML: only an admin can write them, and formatting
 *  is the point. Titles get the app's usual free-text rules, since they are
 *  link labels rather than markup.
 * ============================================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = (string)($_POST['body'] ?? '');

        if (!text_has_content($title) || text_too_long($title, TEXT_NAME_MAX)) {
            $flash = t('texts_title_required');
        } elseif ($id > 0 && custom_page($id)) {
            db_run('UPDATE custom_pages SET title = ?, body = ? WHERE id = ?', [$title, $body, $id]);
            log_action('page_edit', 'page #' . $id . ' ' . $title);
            $flash = t('texts_saved');
        } else {
            // New pages go to the end of the menu: sort_order one past the
            // current maximum, so adding one never reshuffles the existing
            // order an admin has already arranged.
            $next = (int)db_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM custom_pages');
            db_run('INSERT INTO custom_pages (title, body, sort_order) VALUES (?,?,?)',
                   [$title, $body, $next]);
            log_action('page_add', $title);
            $flash = t('texts_saved');
            // Land back on the list: the page is written, and leaving an empty
            // form open invites accidentally creating a second one.
            $_GET['new'] = null;
        }

    } elseif ($act === 'delete' && $id > 0) {
        $row = custom_page($id);
        if ($row) {
            db_run('DELETE FROM custom_pages WHERE id = ?', [$id]);
            log_action('page_delete', 'page #' . $id . ' ' . $row['title']);
            $flash = t('texts_deleted');
        }

    } elseif ($act === 'move' && $id > 0) {
        // Swap sort_order with the neighbour in the requested direction. Works
        // on the ORDERED list rather than on sort_order arithmetic, so rows
        // that share a value (or came from an older insert) still move sanely.
        $dir  = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        $all  = db_all('SELECT id, sort_order FROM custom_pages ORDER BY sort_order, id');
        $pos  = null;
        foreach ($all as $i => $r) if ((int)$r['id'] === $id) $pos = $i;
        $swap = $pos === null ? null : ($all[$pos + $dir] ?? null);
        if ($swap) {
            // Rewrite the whole list's order, which also normalises any ties.
            $tmp = $all;
            $moved = $tmp[$pos];
            $tmp[$pos] = $tmp[$pos + $dir];
            $tmp[$pos + $dir] = $moved;
            foreach ($tmp as $i => $r) {
                db_run('UPDATE custom_pages SET sort_order = ? WHERE id = ?', [$i + 1, (int)$r['id']]);
            }
            $flash = t('texts_moved');
        }
    }
}

// The form is only shown when it was asked for: ?edit=<id> to change an
// existing page, ?new=1 to start a blank one. Landing on the tab shows the list
// alone, so opening it to look around does not read as "fill this in".
$editing = null;
$editId  = (int)($_GET['edit'] ?? 0);
if ($editId > 0) $editing = custom_page($editId);
// A stale ?edit= for a deleted page falls back to the list rather than
// silently offering a "new page" form the admin did not ask for.
$showForm = ($editing !== null) || !empty($_GET['new']);

// Loaded by admin_shell only when actually needed — Trix is ~200KB and the
// bare list has no editor on it.
$needs_editor = $showForm;

$tab_body = tpl_capture('admin_texts', [
    'csrf'      => csrf_field(),
    'pages'     => db_all('SELECT id, title, sort_order FROM custom_pages ORDER BY sort_order, id'),
    'editing'   => $editing,
    'show_form' => $showForm,
]);
