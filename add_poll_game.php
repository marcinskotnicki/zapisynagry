<?php
/* =============================================================================
 *  add_poll_game.php — add one candidate game to the in-progress poll draft.
 * -----------------------------------------------------------------------------
 *  Mirrors add_game.php's gate/manual/BGG steps, but a candidate has only the
 *  game-shape fields plus "required players" (the vote threshold), and it's
 *  appended to the SESSION draft rather than written to the database (the poll
 *  persists only on Finish, back in add_poll.php).
 *
 *  ROUTING (first match wins): POST mode=save -> append; GET ?id=<bggId> -> BGG
 *  detail form; POST go=manual/bgg -> manual form / search list; else the gate.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/bgg.php';

// Must have a poll being built (the draft holds the target table).
if (!isset($_SESSION['poll_draft'])) { redirect('index.php'); }
$draft   = &$_SESSION['poll_draft'];
$tableId = (int)$draft['table_id'];

$table = db_one('SELECT * FROM game_tables WHERE id = ?', [$tableId]);
$event = $table ? db_one('SELECT * FROM events WHERE id = ?', [$table['event_id']]) : null;
if (!$table || !$event || (int)$event['is_archived'] === 1 || !opt_bool('allow_polls') || !can_add_games()) {
    redirect('index.php');
}

/**
 * Default candidate prefill. required_players defaults to the max — the poll
 * resolves once that many people vote for the candidate.
 * @return array
 */
function poll_candidate_defaults() {
    return [
        'name' => '', 'length_minutes' => 60, 'weight' => 2.0, 'max_players' => 4,
        'thumbnail' => '', 'bgg_id' => '', 'language' => '', 'required_players' => 4, 'source' => 'manual',
    ];
}

$mode = $_POST['mode'] ?? '';
$go   = $_POST['go'] ?? '';

/* ---- SAVE: append the candidate to the draft ----------------------------- */
if ($mode === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cand = [
        'name'             => trim($_POST['name'] ?? ''),
        'length_minutes'   => max(0, (int)($_POST['length_minutes'] ?? 0)),
        'weight'           => min(5, max(1, (float)($_POST['weight'] ?? 1))),
        'max_players'      => max(1, (int)($_POST['max_players'] ?? 1)),
        'required_players' => max(1, (int)($_POST['required_players'] ?? 1)),
        'thumbnail'        => trim($_POST['thumbnail'] ?? ''),
        'bgg_id'           => (int)($_POST['bgg_id'] ?? 0),
        'language'         => trim($_POST['language'] ?? ''),
        'source'           => ($_POST['source'] ?? 'manual') === 'bgg' ? 'bgg' : 'manual',
    ];
    if ($cand['name'] === '') {
        // Name is the only hard requirement; re-render with the error.
        tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
        tpl_render('poll_candidate_form', [
            'table' => $table, 'cand' => $cand, 'source' => $cand['source'],
            'thumbs' => $cand['source'] === 'bgg' ? [] : db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
            'error' => t('error_name_required'), 'csrf' => csrf_field(),
        ]);
        tpl_render('footer');
        exit;
    }
    $draft['games'][] = $cand;                       // append to the session draft
    redirect('add_poll.php?table=' . $tableId);      // back to the poll screen
}

/* ---- BGG detail -> prefilled candidate form ------------------------------ */
if (isset($_GET['id'])) {
    require __DIR__ . '/inc/bgg.php';                // (already required above; harmless)
    $detail = bgg_thing((int)$_GET['id']);
    $cand = poll_candidate_defaults();
    if ($detail) {
        // Overlay BGG values onto the defaults (keep a default where BGG had none).
        $cand['name']           = $detail['name'];
        $cand['length_minutes'] = $detail['length'] ?: $cand['length_minutes'];
        $cand['weight']         = $detail['weight'] ?: $cand['weight'];
        $cand['max_players']    = $detail['maxplayers'] ?: $cand['max_players'];
        $cand['thumbnail']      = $detail['image'] ?: $detail['thumbnail'];
        $cand['bgg_id']         = $detail['id'];
        $cand['source']         = 'bgg';
    }
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    tpl_render('poll_candidate_form', [
        'table' => $table, 'cand' => $cand, 'source' => 'bgg',
        'thumbs' => [], 'error' => null, 'csrf' => csrf_field(),   // image locked to BGG
    ]);
    tpl_render('footer');
    exit;
}

/* ---- Gate buttons (manual / bgg) ----------------------------------------- */
if ($go === 'manual') {
    $cand = poll_candidate_defaults();
    $cand['name'] = trim($_POST['name'] ?? '');      // carry the gate's typed name
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    tpl_render('poll_candidate_form', [
        'table' => $table, 'cand' => $cand, 'source' => 'manual',
        'thumbs' => db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
        'error' => null, 'csrf' => csrf_field(),
    ]);
    tpl_render('footer');
    exit;
}

if ($go === 'bgg') {
    $query = trim($_POST['name'] ?? '');
    $results = $query !== '' ? bgg_search($query) : [];
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    // Reuse the shared BGG list, but point its links back here (not add_game.php).
    tpl_render('add_game_bgg_list', [
        'table' => $table, 'query' => $query, 'results' => $results,
        'link_base' => 'add_poll_game.php',
    ]);
    tpl_render('footer');
    exit;
}

/* ---- Gate (default) ------------------------------------------------------ */
tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
tpl_render('add_game_gate', [
    'table'     => $table,
    'csrf'      => csrf_field(),
    'action'    => 'add_poll_game.php',
    'show_poll' => false,            // no nested-poll button when adding a candidate
    'title'     => t('addpoll_candidate_title'),
]);
tpl_render('footer');
