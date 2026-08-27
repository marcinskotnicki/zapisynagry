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
require __DIR__ . '/inc/polls.php';
require __DIR__ . '/inc/verify.php';
require __DIR__ . '/inc/notify.php';

/* ---- Target: an EXISTING poll (live edit) or the in-progress draft --------
 * Live mode is entered from edit_poll.php, which parks the poll id in the
 * session so the multi-step BGG/manual flow below keeps its target without
 * every template having to thread a ?poll= through its forms. It also means
 * this whole file is reused rather than duplicated for editing.            */
// ?poll=N enters live mode directly — that's the route for someone who ISN'T
// the proposer, on a poll whose owner ticked "let others add games". The
// proposer's own route (edit_poll.php) parks the same key.
if (isset($_GET['poll'])) { $_SESSION['poll_live_edit'] = (int)$_GET['poll']; }

$livePollId = (int)($_SESSION['poll_live_edit'] ?? 0);
$livePoll   = $livePollId ? db_one('SELECT * FROM polls WHERE id = ?', [$livePollId]) : null;

if ($livePoll) {
    // One shared rule: proposer/admin, already-verified this session, or the
    // poll opted in to letting anyone add.
    if (!poll_can_add_candidate($livePoll)) {
        unset($_SESSION['poll_live_edit']);
        redirect('index.php');
    }
    $tableId = (int)$livePoll['table_id'];

    /* RESTRICTED TO THE PROPOSER'S LIBRARY, when they asked for it.
     *
     * The proposer themself is exempt: it is their poll and their library, and
     * the restriction exists to shape what OTHERS may add. Somebody who has
     * verified as the owner this session counts as them.
     *
     * Enforced here, not merely by hiding routes, because every other way in
     * (?go=bgg, ?go=manual, a bookmarked ?club=) is a plain GET that anybody
     * could type. */
    require_once __DIR__ . '/inc/verify.php';
    $ownerHere = verify_decision($livePoll['proposer_user_id'], $livePoll['proposer_email']) === 'allow'
              || !empty($_SESSION['poll_edit_ok'][(int)$livePoll['id']]);
    $libraryOnly = !$ownerHere && poll_others_from_library($livePoll);
    if ($libraryOnly) {
        /* $go is read here rather than reusing the one set further down: this
         * check has to happen before any route branches on it, and reading the
         * POST directly keeps the two from drifting apart. */
        $goNow = (string)($_POST['go'] ?? '');
        $allowed = isset($_GET['mine']) || $goNow === 'mine' || $goNow === '';
        if (!$allowed) redirect('add_poll_game.php');
    }
} else {
    // Draft mode: a poll being built (the draft holds the target table).
    if (!isset($_SESSION['poll_draft'])) { redirect('index.php'); }
    $draft   = &$_SESSION['poll_draft'];
    $tableId = (int)$draft['table_id'];
}

$table = db_one('SELECT * FROM game_tables WHERE id = ?', [$tableId]);
$event = $table ? db_one('SELECT * FROM events WHERE id = ?', [$table['event_id']]) : null;
if (!$table || !$event || (int)$event['is_archived'] === 1 || !opt_bool('allow_polls') || !can_add_games()) {
    redirect('index.php');
}

/* ---- Target: an EXISTING candidate being EDITED --------------------------
 * ?edit=<poll_games id> turns this whole page into an editor for a candidate
 * that is already in a live poll, reusing the same form the add flow renders
 * so the two can never offer different fields.
 *
 * GUARDED MORE TIGHTLY THAN ADDING: poll_can_manage(), not
 * poll_can_add_candidate(). "Let others add games" lets a passer-by contribute
 * an option; it must not let them rewrite one that people have already voted
 * on. Owner, admin, or somebody who passed the poll's verification challenge
 * this session — the same three that may remove a candidate.
 *
 * Scoped to the live poll: an id from another poll simply does not resolve, so
 * a hand-edited number cannot reach across polls.                          */
$editId   = (int)($_GET['edit'] ?? $_POST['edit_cand'] ?? 0);
$editCand = null;
if ($editId > 0 && $livePoll) {
    if (!poll_can_manage($livePoll)) {
        unset($_SESSION['poll_live_edit']);
        redirect('index.php');
    }
    $editCand = db_one('SELECT * FROM poll_games WHERE id = ? AND poll_id = ?',
                       [$editId, (int)$livePoll['id']]);
    if (!$editCand) redirect('edit_poll.php?poll=' . (int)$livePoll['id']);
}
// The form posts back here; cancelling returns to wherever the flow started.
$candCancel = $editCand
    ? 'edit_poll.php?poll=' . (int)$livePoll['id']
    : 'add_poll.php?table=' . $tableId;

/**
 * Default candidate prefill. required_players defaults to the max — the poll
 * resolves once that many people vote for the candidate.
 * @return array
 */
function poll_candidate_defaults() {
    return [
        'name' => '', 'length_minutes' => 60, 'weight' => 2.0, 'max_players' => 4,
        'thumbnail' => '', 'bgg_id' => '', 'language' => '', 'required_players' => 4, 'source' => 'manual',
        'manual_link' => '', 'link' => '',
    ];
}

/**
 * Turn a stored poll_games row into the shape the form prefills from.
 *
 * The row's own bgg_id decides which form it is — a BGG candidate keeps its
 * locked image and identity, a manual one gets the thumbnail picker — so an
 * edit cannot accidentally convert one kind into the other.
 *
 * @param array $row  A poll_games row.
 * @return array
 */
function poll_candidate_from_row($row) {
    return [
        'name'             => (string)$row['name'],
        'length_minutes'   => (int)$row['length_minutes'],
        'weight'           => (float)$row['weight'],
        'max_players'      => (int)$row['max_players'],
        'required_players' => (int)$row['required_players'],
        'thumbnail'        => (string)($row['thumbnail'] ?? ''),
        'bgg_id'           => (int)($row['bgg_id'] ?? 0),
        'language'         => (string)($row['language'] ?? ''),
        'manual_link'      => (string)($row['manual_link'] ?? ''),
        'link'             => (string)($row['link'] ?? ''),
        'source'           => !empty($row['bgg_id']) ? 'bgg' : 'manual',
    ];
}

$mode = $_POST['mode'] ?? '';
$go   = $_POST['go'] ?? '';
// Only a LIVE poll can restrict; a draft is still the proposer's own.
$libraryOnly = $libraryOnly ?? false;

/* ---- SAVE: append the candidate to the draft ----------------------------- */
if ($mode === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    antibot_check('form');
    $cand = [
        'name'             => trim($_POST['name'] ?? ''),
        'length_minutes'   => max(0, (int)($_POST['length_minutes'] ?? 0)),
        'weight'           => min(5, max(1, (float)($_POST['weight'] ?? 1))),
        'max_players'      => max(1, (int)($_POST['max_players'] ?? 1)),
        'required_players' => max(1, (int)($_POST['required_players'] ?? 1)),
        'manual_link'      => game_link_sanitize($_POST['manual_link'] ?? ''),
        /* Custom game link, same rules as a full game's: sanitised here, and
         * game_link() re-checks the admin option when rendering, so switching
         * the option off hides existing links without destroying them. A BGG
         * candidate never needs one — it links by bgg_id. */
        'link'             => game_link_sanitize($_POST['link'] ?? ''),
        'thumbnail'        => trim($_POST['thumbnail'] ?? ''),
        'bgg_id'           => (int)($_POST['bgg_id'] ?? 0),
        'language'         => trim($_POST['language'] ?? ''),
        'source'           => ($_POST['source'] ?? 'manual') === 'bgg' ? 'bgg' : 'manual',
    ];
    if ($cand['name'] === '' || !text_has_content($cand['name'])
        || text_too_long($cand['name'], TEXT_NAME_MAX)) {
        // Name is the only hard requirement; re-render with the error.
        tpl_render('header', ['page_title' => $editCand ? t('poll_cand_edit_title') : t('addpoll_candidate_title')]);
        tpl_render('poll_candidate_form', [
            'table' => $table, 'cand' => $cand, 'source' => $cand['source'],
            'thumbs' => $cand['source'] === 'bgg' ? [] : db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
            'error' => t('error_name_required'), 'csrf' => csrf_field(),
            // Keep the edit target across the failed submit, or saving again
            // would append a duplicate instead of updating the row.
            'edit_id' => $editCand ? (int)$editCand['id'] : 0,
            'cancel_url' => $candCancel,

        // Editions to offer beside the name; [] unless enabled and BGG has several.
        'versions' => game_pick_versions($cand['bgg_id'] ?? 0),]);
        tpl_render('footer');
        exit;
    }
    /* ---- EDIT: update the row in place --------------------------------- *
     * Before the add branches below, because an edit is never also an add.
     * poll_id is in the WHERE as well as the id: the row was already scoped
     * when it was loaded, and repeating it here means a tampered edit_cand
     * cannot update a candidate belonging to another poll.                */
    if ($editCand) {
        db_run(
            'UPDATE poll_games
                SET name = ?, length_minutes = ?, weight = ?, max_players = ?,
                    thumbnail = ?, language = ?, required_players = ?,
                    manual_link = ?, link = ?
              WHERE id = ? AND poll_id = ?',
            [$cand['name'], $cand['length_minutes'], $cand['weight'], $cand['max_players'],
             $cand['thumbnail'] !== '' ? $cand['thumbnail'] : null,
             $cand['language'] !== '' ? $cand['language'] : null,
             $cand['required_players'],
             $cand['manual_link'] !== '' ? $cand['manual_link'] : null,
             $cand['link'] !== '' ? $cand['link'] : null,
             (int)$editCand['id'], (int)$livePoll['id']]
        );
        /* bgg_id is deliberately NOT updated: it is the candidate's identity,
         * the form only ever echoes it back, and rewriting it here would let a
         * tampered field repoint an option people had already voted for. */
        log_action('poll_cand_edited', $cand['name'] . ' (poll #' . (int)$livePoll['id'] . ')');
        // Voters hear about it, as they do for an added or removed option — a
        // game they voted for changing its player count is exactly that kind
        // of change. The proposer is told too, unless they are the one doing
        // the editing: an ADMIN may edit somebody else's poll, and that is
        // precisely the change its owner would want to hear about.
        $tell = notify_poll_voter_emails((int)$livePoll['id']);
        $cu = current_user();
        $editorIsProposer =
            ($cu && (int)($livePoll['proposer_user_id'] ?? 0) === (int)$cu['id'])
            || !empty($_SESSION['poll_edit_ok'][(int)$livePoll['id']]);
        if (!$editorIsProposer && !empty($livePoll['proposer_email'])) {
            $tell[] = $livePoll['proposer_email'];
        }
        notify_poll_changed($livePoll, t('ntf_pollchg_edited', $cand['name']), $tell);
        unset($_SESSION['poll_live_edit']);           // one candidate per trip
        /* LOWERING "required players" can put a candidate straight over its
         * threshold, so the poll may resolve on this very save — the same
         * re-check edit_poll.php runs after removing an option. */
        $resolved = poll_check_resolve((int)$livePoll['id']);
        if ($resolved) {
            $dayRow = db_one('SELECT * FROM event_days WHERE id = ?', [(int)$livePoll['day_id']]);
            redirect(front_url((int)($dayRow['day_index'] ?? 1),
                               (int)$livePoll['event_id'], '#game-') . $resolved);
        }
        redirect('edit_poll.php?poll=' . (int)$livePoll['id']);
    }
    if ($livePoll) {
        // Live edit: write straight into the poll and tell its voters. A brand
        // new candidate starts at zero votes, so no resolve check is needed.
        db_run(
            'INSERT INTO poll_games
             (poll_id,name,length_minutes,weight,max_players,thumbnail,bgg_id,language,required_players,manual_link,link)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$livePollId, $cand['name'], $cand['length_minutes'], $cand['weight'], $cand['max_players'],
             $cand['thumbnail'] !== '' ? $cand['thumbnail'] : null,
             $cand['bgg_id'] ?: null,
             $cand['language'] !== '' ? $cand['language'] : null,
             $cand['required_players'],
             $cand['manual_link'] !== '' ? $cand['manual_link'] : null,
             $cand['link'] !== '' ? $cand['link'] : null]
        );
        log_action('poll_cand_added', $cand['name'] . ' (poll #' . $livePollId . ')');
        // Voters always hear about it; the proposer is added to the list when
        // it wasn't them doing the adding (the "let others add" path), since
        // that's exactly the change they'd want to know about.
        $tell = notify_poll_voter_emails($livePollId);
        $byOwner = (verify_decision($livePoll['proposer_user_id'], $livePoll['proposer_email']) === 'allow')
                   || !empty($_SESSION['poll_edit_ok'][$livePollId]);
        if (!$byOwner && !empty($livePoll['proposer_email'])) { $tell[] = $livePoll['proposer_email']; }
        notify_poll_changed($livePoll, t('ntf_pollchg_added', $cand['name']), $tell);
        unset($_SESSION['poll_live_edit']);           // one candidate per trip
        redirect('edit_poll.php?poll=' . $livePollId);
    }
    $draft['games'][] = $cand;                       // append to the session draft
    redirect('add_poll.php?table=' . $tableId);      // back to the poll screen
}

/* ---- EDIT an existing candidate -> prefilled form ------------------------ *
 * Before the BGG/library pick branches: those all START a new candidate, and
 * an edit already has one. The row's own bgg_id decides which form renders, so
 * a BGG candidate keeps its locked image and a manual one keeps its picker. */
if ($editCand && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $cand = poll_candidate_from_row($editCand);
    tpl_render('header', ['page_title' => t('poll_cand_edit_title')]);
    tpl_render('poll_candidate_form', [
        'table' => $table, 'cand' => $cand, 'source' => $cand['source'],
        'thumbs' => $cand['source'] === 'bgg'
            ? []                                  // image locked to the BGG one
            : db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
        'error' => null, 'csrf' => csrf_field(),
        'edit_id' => (int)$editCand['id'],
        'cancel_url' => $candCancel,
        'versions' => game_pick_versions($cand['bgg_id'] ?? 0),
    ]);
    tpl_render('footer');
    exit;
}

/* ---- BGG detail -> prefilled candidate form ------------------------------ */
if (isset($_GET['id'])) {
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
    
        // Editions to offer beside the name; [] unless enabled and BGG has several.
        'versions' => game_pick_versions($cand['bgg_id'] ?? 0),]);
    tpl_render('footer');
    exit;
}

/* ---- Club shelf pick -> prefilled candidate form -------------------------- */
/* Same shape as the BGG detail branch above: this replaces the SEARCH step, so
 * everything after it is unchanged. club_shelf_prefill() does the filling for
 * both flows, which is what keeps the two consistent. */
if (isset($_GET['mine'])) {
    // The member's own library — same step as the club shelf, same form after.
    // Scoped to whoever is signed in, so a row id from another member's
    // library does not resolve.
    /* WHOSE library this reads.
     *
     * Normally your own. But on a poll restricted to the proposer's library it
     * is THEIRS — that is the whole point, and reading the visitor's instead
     * would let anybody add anything by simply having it in their own list. */
    $pickOwner = !empty($libraryOnly)
        ? (int)$livePoll['proposer_user_id']
        : (int)(current_user()['id'] ?? 0);
    if (!library_own_pick_enabled($pickOwner)) redirect('index.php');
    $mineRow = library_own_entry((int)$_GET['mine'], $pickOwner);
    if (!$mineRow) redirect('add_poll_game.php');

    $cand = club_shelf_prefill(poll_candidate_defaults(), $mineRow);
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    tpl_render('poll_candidate_form', [
        'table' => $table, 'cand' => $cand, 'source' => $cand['source'],
        'thumbs' => $cand['source'] === 'bgg'
            ? []
            : db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
        'error' => null, 'csrf' => csrf_field(),
        'versions' => game_pick_versions($cand['bgg_id'] ?? 0),
    ]);
    tpl_render('footer');
    exit;
}

if (isset($_GET['club'])) {
    if (!club_shelf_pick_enabled()) redirect('index.php');
    $shelfRow = club_shelf_entry((int)$_GET['club']);
    if (!$shelfRow) redirect('add_poll_game.php');

    $cand = club_shelf_prefill(poll_candidate_defaults(), $shelfRow);
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    tpl_render('poll_candidate_form', [
        'table' => $table, 'cand' => $cand, 'source' => $cand['source'],
        'thumbs' => $cand['source'] === 'bgg'
            ? []                                  // image locked to the BGG one
            : db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
        'error' => null, 'csrf' => csrf_field(),
    
        // Editions to offer beside the name; [] unless enabled and BGG has several.
        'versions' => game_pick_versions($cand['bgg_id'] ?? 0),]);
    tpl_render('footer');
    exit;
}

/* ---- Gate buttons (manual / bgg / club / own library) --------------------- */
if ($go === 'mine') {
    // Same rule as the pick above: the proposer's list when restricted.
    $pickOwner = !empty($libraryOnly)
        ? (int)$livePoll['proposer_user_id']
        : (int)(current_user()['id'] ?? 0);
    if (!library_own_pick_enabled($pickOwner)) redirect('index.php');
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    tpl_render('add_poll_own_list', [
        'table' => $table,
        'games' => library_own_all($pickOwner),
        // Same reason as the button: the heading must not say "your library"
        // while listing somebody else's.
        'library_owner' => !empty($libraryOnly) ? (string)($livePoll['proposer_name'] ?? '') : '',
    ]);
    tpl_render('footer');
    exit;
}

if ($go === 'club' && club_shelf_pick_enabled()) {
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    tpl_render('add_poll_club_list', [
        'table' => $table,
        'games' => club_shelf_all(true),   // active only: not a game that is lent out
    ]);
    tpl_render('footer');
    exit;
}

if ($go === 'manual') {
    $cand = poll_candidate_defaults();
    $cand['name'] = trim($_POST['name'] ?? '');      // carry the gate's typed name
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    tpl_render('poll_candidate_form', [
        'table' => $table, 'cand' => $cand, 'source' => 'manual',
        'thumbs' => db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
        'error' => null, 'csrf' => csrf_field(),
    
        // Editions to offer beside the name; [] unless enabled and BGG has several.
        'versions' => game_pick_versions($cand['bgg_id'] ?? 0),]);
    tpl_render('footer');
    exit;
}

if ($go === 'bgg') {
    $query = trim($_POST['name'] ?? '');
    // Same three-way split as add_game.php: an empty search box and a missing
    // API code both used to render as plain "no results", which reads as "that
    // game isn't on BGG". See the comment there.
    $bggProblem = null;
    if ($query === '') {
        $bggProblem = 'empty';
    } elseif (!bgg_configured()) {
        $bggProblem = 'unconfigured';
    }
    $results = $bggProblem === null ? bgg_search($query) : [];
    tpl_render('header', ['page_title' => t('addpoll_candidate_title')]);
    // Reuse the shared BGG list, but point its links back here (not add_game.php).
    tpl_render('add_game_bgg_list', [
        'table' => $table, 'query' => $query, 'results' => $results,
        'problem' => $bggProblem,
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
    /* Whose library the button offers, and whether it is the ONLY route.
     * On a restricted poll everything else is hidden AND refused server-side —
     * see the check near the top; hiding alone would only make the other routes
     * harder to find, not unavailable. */
    'own_pick'  => library_own_pick_enabled(
                       !empty($libraryOnly) ? (int)$livePoll['proposer_user_id']
                                            : (int)(current_user()['id'] ?? 0)),
    'library_only' => !empty($libraryOnly),
    /* WHOSE library, for the button's wording. On a restricted poll it is
     * somebody else's, and calling it "my library" would be plainly wrong to
     * everyone but the proposer. Empty means it really is the visitor's own. */
    'library_owner' => !empty($libraryOnly)
        ? (string)($livePoll['proposer_name'] ?? '')
        : '',
    'action'    => 'add_poll_game.php',
    'show_poll' => false,            // no nested-poll button when adding a candidate
    'title'     => t('addpoll_candidate_title'),
]);
tpl_render('footer');
