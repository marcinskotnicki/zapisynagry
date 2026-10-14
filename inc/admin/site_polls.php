<?php
/* =============================================================================
 *  inc/admin/site_polls.php — the Polls tab: the admin's own opinion polls.
 * -----------------------------------------------------------------------------
 *  Runs INSIDE admin.php's scope: $flash / $tab_body are declared there and the
 *  CSRF token has been checked centrally for every POST.
 *
 *  Two views:
 *    the LIST       every poll, how long it has left, details, on/off;
 *                   plus the form for a new one.
 *    the DETAILS    ?poll=N — every answer with its votes, including voters'
 *                   answers still waiting for approval; add, approve, delete.
 *
 *  Every rule about voting itself is in inc/sitepolls.php.
 * ============================================================================= */
require_once __DIR__ . '/../sitepolls.php';

$spDetail = (int)($_GET['poll'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act  = $_POST['action'] ?? '';
    $pid  = (int)($_POST['poll'] ?? 0);
    $oid  = (int)($_POST['option'] ?? 0);
    $poll = $pid > 0 ? sp_get($pid) : null;

    if ($act === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        /* Answers one per line. Blank lines dropped, and duplicates too — two
         * identical answers would only split the votes between them. */
        $answers = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['answers'] ?? '')) as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array(mb_strtolower($line), array_map('mb_strtolower', $answers), true)) {
                $answers[] = $line;
            }
        }
        if (!text_has_content($title) || text_too_long($title, TEXT_NAME_MAX)) {
            $flash = t('sp_err_title');
        } elseif (count($answers) < 2 && empty($_POST['allow_custom'])) {
            /* Two answers at least, unless voters may add their own: a poll with
             * one answer and no way to add another is not a question. */
            $flash = t('sp_err_answers');
        } else {
            $newId = sp_create($_POST + ['title' => $title], $answers);
            log_action('site_poll_create', 'poll #' . $newId . ' ' . $title);
            $flash = t('sp_created');
            $spDetail = 0;
        }

    } elseif ($act === 'toggle' && $poll) {
        $to = (int)$poll['is_enabled'] === 1 ? 0 : 1;
        db_run('UPDATE site_polls SET is_enabled = ? WHERE id = ?', [$to, $pid]);
        log_action('site_poll_' . ($to ? 'enable' : 'disable'), 'poll #' . $pid);
        $flash = t($to ? 'sp_enabled' : 'sp_disabled');

    } elseif ($act === 'delete_poll' && $poll) {
        // Answers and votes go with it (ON DELETE CASCADE).
        db_run('DELETE FROM site_polls WHERE id = ?', [$pid]);
        log_action('site_poll_delete', 'poll #' . $pid . ' ' . $poll['title']);
        $flash = t('sp_deleted');
        $spDetail = 0;

    } elseif ($act === 'add_option' && $poll) {
        $text = trim((string)($_POST['text'] ?? ''));
        if (text_has_content($text) && !text_too_long($text, TEXT_NAME_MAX)) {
            $next = (int)db_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM site_poll_options WHERE poll_id = ?', [$pid]);
            db_run('INSERT INTO site_poll_options (poll_id, text, sort_order) VALUES (?,?,?)', [$pid, $text, $next]);
            log_action('site_poll_option_add', 'poll #' . $pid . ' — ' . $text);
            $flash = t('sp_option_added');
        } else {
            $flash = t('sp_err_custom_text');
        }
        $spDetail = $pid;

    } elseif (in_array($act, ['approve_option', 'delete_option'], true) && $poll) {
        // The answer must belong to THIS poll — a posted id from another poll is ignored.
        $opt = db_one('SELECT * FROM site_poll_options WHERE id = ? AND poll_id = ?', [$oid, $pid]);
        if ($opt && $act === 'approve_option') {
            db_run('UPDATE site_poll_options SET is_approved = 1 WHERE id = ?', [$oid]);
            log_action('site_poll_option_approve', 'poll #' . $pid . ' — ' . $opt['text']);
            $flash = t('sp_option_approved');
        } elseif ($opt) {
            // Its votes go too (ON DELETE CASCADE) — a vote for nothing counts for nothing.
            db_run('DELETE FROM site_poll_options WHERE id = ?', [$oid]);
            log_action('site_poll_option_delete', 'poll #' . $pid . ' — ' . $opt['text']);
            $flash = t('sp_option_deleted');
        }
        $spDetail = $pid;
    }
}

if ($spDetail > 0 && ($detailPoll = sp_get($spDetail))) {
    $tab_body = tpl_capture('admin_site_poll', [
        'poll'    => $detailPoll,
        'options' => sp_options($spDetail, true),
        'tally'   => sp_tally($spDetail),
        'csrf'    => csrf_field(),
    ]);
    return;
}

$tab_body = tpl_capture('admin_site_polls', [
    'polls' => db_all('SELECT * FROM site_polls ORDER BY id DESC'),
    // Answers awaiting approval, per poll — so the list says where to look.
    'pending' => array_column(db_all('SELECT poll_id, COUNT(*) AS n FROM site_poll_options
                                       WHERE is_approved = 0 GROUP BY poll_id'), 'n', 'poll_id'),
    'csrf'  => csrf_field(),
]);
