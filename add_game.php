<?php
/* =============================================================================
 *  add_game.php — add a game to a table.
 * -----------------------------------------------------------------------------
 *  A single controller that walks several STEPS, chosen from the request shape:
 *    gate    : one "name" field + [Add from BGG] / [Add outside BGG] buttons.
 *    manual  : the full manual form (thumbnail picker, captcha, etc.).
 *    bgglist : search results for the typed name (one /search call).
 *    bggform : the same form, prefilled from a chosen BGG game (image locked).
 *    save    : validate + insert the game (+ auto-add self as player).
 *
 *  HOW A STEP IS PICKED (top to bottom, first match wins):
 *    POST mode=save        -> SAVE
 *    GET  ?id=<bggId>      -> BGG DETAIL FORM
 *    POST go=manual        -> MANUAL FORM
 *    POST go=bgg           -> BGG SEARCH LIST
 *    otherwise             -> GATE
 *  Each step renders and exit()s, so the order above is the routing table.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/bgg.php';
require __DIR__ . '/inc/captcha.php';

// ---- Resolve the target table (and its day/event) --------------------------
$tableId = (int)($_GET['table'] ?? $_POST['table'] ?? 0);
$table   = $tableId ? db_one('SELECT * FROM game_tables WHERE id = ?', [$tableId]) : null;
if (!$table) { http_response_code(404); exit('Unknown table.'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$table['event_id']]);
$day   = db_one('SELECT * FROM event_days WHERE id = ?', [$table['day_id']]);

// Adding is only allowed on the live event, by someone permitted to add.
if (!$event || (int)$event['is_archived'] === 1 || !can_add_games()) {
    redirect('index.php');
}

$activeDay = (int)$day['day_index'];   // for the post-save redirect

/* -----------------------------------------------------------------------------
 *  Helper: assemble the form's prefill values (from POST on re-render, or from
 *  defaults / a BGG detail array on first render). Sensible starting values so
 *  the form is usable without editing every field.
 * --------------------------------------------------------------------------- */
function game_form_defaults($table, $day) {
    return [
        'name'           => '',
        'length_minutes' => 60,
        'weight'         => 2.0,
        'max_players'    => 4,
        // Pre-fill the next free slot on this table so games line up in time.
        'start_time'     => event_next_start_time($table['id'], $day['start_time']),
        'brings_name'    => current_user()['display_name'] ?? '',
        'brings_email'   => current_user()['email'] ?? '',
        'explain_rules'  => 0,
        'add_self'       => 1,
        'require_email'  => 0,      // per-game email rule (shown only in option mode 2)
        'comment'        => '',
        'thumbnail'      => '',     // manual: predefined path; bgg: image URL
        'bgg_id'         => '',
        'language'       => '',
        'link'           => '',     // manual games only: optional external URL
        'source'         => 'manual',
    ];
}

$mode = $_POST['mode'] ?? '';   // 'save' for the final submit
$go   = $_POST['go'] ?? '';     // 'manual' / 'bgg' from the gate buttons

/* =============================================================================
 *  SAVE — validate + insert the game (+ optional self signup), in a transaction.
 * ============================================================================= */
if ($mode === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Gather + coerce every field to a safe type/range.
    $form = [
        'name'           => trim($_POST['name'] ?? ''),
        'length_minutes' => max(0, (int)($_POST['length_minutes'] ?? 0)),
        'weight'         => min(5, max(1, (float)($_POST['weight'] ?? 1))),     // clamp 1..5
        'max_players'    => max(1, (int)($_POST['max_players'] ?? 1)),
        'start_time'     => trim($_POST['start_time'] ?? ''),
        'brings_name'    => trim($_POST['brings_name'] ?? ''),
        'brings_email'   => trim($_POST['brings_email'] ?? ''),
        'explain_rules'  => min(2, max(0, (int)($_POST['explain_rules'] ?? 0))),  // 0..2
        'add_self'       => isset($_POST['add_self']) ? 1 : 0,
        // Per-game email rule: only meaningful (and only shown on the form) in
        // option mode 2, so ignore the checkbox entirely in other modes.
        'require_email'  => (email_require_mode() === 2 && isset($_POST['require_email'])) ? 1 : 0,
        'comment'        => trim($_POST['comment'] ?? ''),
        'source'         => ($_POST['source'] ?? 'manual') === 'bgg' ? 'bgg' : 'manual',
        'bgg_id'         => (int)($_POST['bgg_id'] ?? 0),
        'thumbnail'      => trim($_POST['thumbnail'] ?? ''),
        'language'       => trim($_POST['language'] ?? ''),
        'link'           => game_link_sanitize($_POST['link'] ?? ''),
    ];
    if (!is_valid_time($form['start_time'])) {
        // Bad/blank time -> fall back to the table's next slot rather than reject.
        $form['start_time'] = event_next_start_time($table['id'], $day['start_time']);
    }

    // Validate (name required; email if the admin requires it globally, OR if
    // the proposer ticked "require email" — demanding it of others means
    // providing your own; captcha if on).
    $error = null;
    if ($form['name'] === '') {
        $error = t('error_name_required');
    } elseif (!start_within_event_hours($form['start_time'], $day)) {
        // Rule on + start outside the day's window: reject (matches the input's
        // own min/max, so this only bites a bypassed/forged client).
        $error = t('error_start_outside_hours');
    } elseif ((email_require_mode() === 1 || $form['require_email'] === 1) && $form['brings_email'] === '') {
        $error = t('error_email_required');
    } elseif ($form['brings_email'] !== '' && !email_valid($form['brings_email'])) {
        $error = t('error_email_invalid');   // non-empty but not X@Y.Z-shaped
    } elseif (!captcha_verify()) {
        $error = t('error_captcha');
    }

    if ($error === null) {
        $uid = current_user()['id'] ?? null;            // owner/bringer account (null if guest)
        db()->beginTransaction();                       // game + self-signup are one unit
        try {
            db_run(
                'INSERT INTO games
                 (table_id,event_id,day_id,name,length_minutes,weight,max_players,start_time,
                  thumbnail,bgg_id,language,link,brings_name,brings_email,brings_user_id,explain_rules,require_email,comment,added_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $table['id'], $event['id'], $day['id'], $form['name'],
                    $form['length_minutes'], $form['weight'], $form['max_players'], $form['start_time'],
                    $form['thumbnail'] !== '' ? $form['thumbnail'] : null,   // store NULL, not ''
                    $form['bgg_id'] ?: null,
                    $form['language'] !== '' ? $form['language'] : null,
                    $form['link'] !== '' ? $form['link'] : null,
                    $form['brings_name'] !== '' ? $form['brings_name'] : null,
                    $form['brings_email'] !== '' ? $form['brings_email'] : null,
                    $uid, $form['explain_rules'], $form['require_email'],
                    $form['comment'] !== '' ? $form['comment'] : null,
                    $uid,                                                    // added_by = bringer
                ]
            );
            $gameId = (int)db()->lastInsertId();

            // Auto-add the bringer as the first player when requested.
            if ($form['add_self'] && $form['brings_name'] !== '') {
                db_run(
                    'INSERT INTO players (game_id,name,email,knows_rules,is_reserve,user_id)
                     VALUES (?,?,?,?,0,?)',
                    [$gameId, $form['brings_name'],
                     $form['brings_email'] !== '' ? $form['brings_email'] : null,
                     null, $uid]
                );
            }
            db()->commit();
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $error = $ex->getMessage();
        }

        if ($error === null) {
            log_action('game_add', $form['name']);
            redirect('index.php?day=' . $activeDay . '#game-' . $gameId);   // PRG + jump to card
        }
    }

    // Fell through with an error: re-render the same form (with the entered data).
    tpl_render('header', ['page_title' => t('addgame_title')]);
    tpl_render('add_game_form', [
        'table'   => $table, 'game' => $form, 'source' => $form['source'],
        'thumbs'  => db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
        'captcha' => captcha_html(), 'error' => $error, 'csrf' => csrf_field(),
    ]);
    tpl_render('footer');
    exit;
}

/* =============================================================================
 *  BGG DETAIL FORM  (?id=BGGID) — chosen a search result; prefill + lock image.
 * ============================================================================= */
if (isset($_GET['id'])) {
    $detail = bgg_thing((int)$_GET['id']);          // one /thing call
    $form   = game_form_defaults($table, $day);
    if ($detail) {
        // Overlay BGG values onto the defaults (keep a default if BGG had none).
        $form['name']           = $detail['name'];
        $form['length_minutes'] = $detail['length'] ?: $form['length_minutes'];
        $form['weight']         = $detail['weight'] ?: $form['weight'];
        $form['max_players']    = $detail['maxplayers'] ?: $form['max_players'];
        $form['thumbnail']      = $detail['image'] ?: $detail['thumbnail'];   // prefer full image
        $form['bgg_id']         = $detail['id'];
        $form['source']         = 'bgg';
    }
    tpl_render('header', ['page_title' => t('addgame_title')]);
    tpl_render('add_game_form', [
        'table'   => $table, 'game' => $form, 'source' => 'bgg',
        'thumbs'  => [],                 // empty: the image is locked to the BGG one
        'captcha' => captcha_html(), 'error' => null, 'csrf' => csrf_field(),
    ]);
    tpl_render('footer');
    exit;
}

/* =============================================================================
 *  GATE BUTTONS  (manual / bgg) — which path the user picked on the gate.
 * ============================================================================= */
if ($go === 'manual') {
    // Manual entry: blank form, carrying over whatever name they typed on the gate.
    $form = game_form_defaults($table, $day);
    $form['name'] = trim($_POST['name'] ?? '');
    tpl_render('header', ['page_title' => t('addgame_title')]);
    tpl_render('add_game_form', [
        'table'   => $table, 'game' => $form, 'source' => 'manual',
        'thumbs'  => db_all('SELECT id, filename FROM predefined_thumbnails ORDER BY id DESC'),
        'captcha' => captcha_html(), 'error' => null, 'csrf' => csrf_field(),
    ]);
    tpl_render('footer');
    exit;
}

if ($go === 'bgg') {
    // BGG path: run the search for the typed name and show the results list.
    $query   = trim($_POST['name'] ?? '');
    $results = $query !== '' ? bgg_search($query) : [];
    tpl_render('header', ['page_title' => t('addgame_title')]);
    tpl_render('add_game_bgg_list', [
        'table'   => $table, 'query' => $query, 'results' => $results,
    ]);
    tpl_render('footer');
    exit;
}

/* =============================================================================
 *  GATE (default) — the first screen: name + the two add buttons (+ poll).
 * ============================================================================= */
tpl_render('header', ['page_title' => t('addgame_title')]);
tpl_render('add_game_gate', [
    'table'     => $table,
    'csrf'      => csrf_field(),
    'action'    => 'add_game.php',
    'show_poll' => true,             // the poll button shows here (add_poll reuses this gate)
]);
tpl_render('footer');
