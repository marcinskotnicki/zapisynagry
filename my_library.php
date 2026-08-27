<?php
/* =============================================================================
 *  my_library.php — a member's own game library.
 * -----------------------------------------------------------------------------
 *  Reached from the user panel when the admin has switched the club library on.
 *  Four actions, all POST + CSRF because every one of them writes:
 *
 *    add_bgg   — paste a BGG link (or bare id); we fetch the game and store it.
 *    add_manual— a name, and optionally a link, for anything not on BGG.
 *    remove    — drop one entry.
 *    sync      — replace the BGG-sourced half of this library with a BGG user's
 *                owned collection. What it may do is chosen on the form:
 *                add only, add and fill gaps, or a full sync that also
 *                DELETES. Only the last needs the confirmation checkbox.
 *
 *  EVERY ACTION IS SCOPED TO ONE OWNER, resolved ONCE below as $owner and used
 *  everywhere in place of a request-supplied id. For a member that is always
 *  themselves, so a hand-edited form still has nothing to aim at.
 *
 *  ADMIN MODE (?user=N): an admin may open and edit ANOTHER member's library,
 *  reached from that member's profile (user.php?user=N). It only changes WHO
 *  $owner is; every action, guard and query below is untouched, which is what
 *  keeps the two modes from drifting apart. Honoured for admins only — for
 *  anyone else the parameter is ignored rather than refused, so editing the URL
 *  lands you back in your own library instead of confirming the id exists.
 *
 *  PRG throughout — each action stashes a flash and redirects, so a refresh
 *  after adding a game does not add it twice.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/library.php';   // already in bootstrap; _once so a re-require cannot redeclare
require_once __DIR__ . '/inc/bgg.php';       // bgg_thing() for the 'add from BGG' action
require_once __DIR__ . '/inc/events.php';     // game_link_sanitize(), shared with the game forms
require_login();                        // members only

// One gate for the whole feature: with the library off this page does not exist.
if (!library_enabled()) redirect('user.php');

$me = current_user();

/* WHOSE library this is (see ADMIN MODE above). Resolved once, from the GET or
 * the POST, so a multi-step action keeps its target across the redirect. */
$wantId = (int)($_GET['user'] ?? $_POST['user'] ?? 0);
$owner  = $me;
if ($wantId > 0 && is_admin() && $wantId !== (int)$me['id']) {
    $row = db_one('SELECT * FROM users WHERE id = ?', [$wantId]);
    if ($row) $owner = $row;
}
$isSelf  = (int)$owner['id'] === (int)$me['id'];
// Every form posts here, and the PRG lands here: carries ?user= when applicable.
$selfUrl = $isSelf ? 'my_library.php' : 'my_library.php?user=' . (int)$owner['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        /* Hide or show one game in the PUBLIC library. It stays in this list
         * either way — that is the point: a game lent to a friend is still
         * yours, it just should not be advertised as available. */
        library_set_active((int)($_POST['game'] ?? 0), !empty($_POST['active']), $owner['id']);
        flash_set(t('lib_visibility_saved'));

    } elseif ($action === 'edit') {
        // Manual entries only — library_update_manual() enforces that, and
        // reports false when the row is a BGG one or the name is empty.
        /* Which editor applies is decided by the ROW, not by the request: a
         * BGG entry has nothing typeable (its name and year are BGG's), so its
         * only edit is a new link; a manual one takes name, year and link. */
        $editId  = (int)($_POST['game'] ?? 0);
        $editRow = $editId ? db_one('SELECT bgg_id FROM library_games WHERE id = ? AND user_id = ?',
                                    [$editId, $owner['id']]) : null;
        if ($editRow && !empty($editRow['bgg_id'])) {
            // Name too: a BGG entry may carry a title in the wrong language.
            library_flash_edit(library_relink_bgg($editId, $_POST['link'] ?? '', $owner['id'], $_POST['name'] ?? ''));
        } else {
            library_flash_edit(library_update_manual(
                $editId,
                $_POST['name'] ?? '',
                (int)($_POST['year'] ?? 0),
                $owner['id'],
                // Only accept a link when the field was actually offered, so a
                // hand-built POST cannot set one the form would not show.
                library_link_field_visible() ? ($_POST['link'] ?? '') : null
            ));
        }

    } elseif ($action === 'remove') {
        // Scoped by user id inside library_remove(), so an id from a
        // hand-edited form cannot reach anybody else's row.
        library_remove($owner['id'], (int)($_POST['game'] ?? 0));
        flash_set(t('lib_removed'));

    } elseif ($action === 'add_manual') {
        $name = trim($_POST['name'] ?? '');
        $link = game_link_sanitize($_POST['link'] ?? '');
        /* A picture for a game BGG has never heard of. Only the admin's own
         * uploads are on offer, and the answer is CHECKED rather than trusted —
         * see thumbnail_is_predefined(). An unrecognised value becomes "none"
         * rather than an error: the only way to send one is to build the POST
         * by hand, and there is nothing useful to tell that visitor. */
        $thumb = trim($_POST['thumbnail'] ?? '');
        if (!thumbnail_is_predefined($thumb)) $thumb = '';
        if ($name === '' || !text_has_content($name) || text_too_long($name, TEXT_NAME_MAX)) {
            flash_set(t('error_name_required'), 'error');
        } else {
            // Year is optional; 0 stores as NULL rather than printing "0".
            library_add($owner['id'], [
                'name' => $name,
                'year' => (int)($_POST['year'] ?? 0),
                'link' => $link,
                'thumbnail' => $thumb,
            ]);
            flash_set(t('lib_added', $name));
        }

    } elseif ($action === 'search_bgg') {
        /* Find a game by NAME instead of pasting its address — the same search
         * the add-game flow offers, so a member who has used one already knows
         * this one. Each hit posts back as an ordinary 'add_bgg' with the id in
         * place of a link, which means the edition chooser and every other rule
         * below apply to a searched game exactly as to a pasted one.
         *
         * Rendered here rather than redirected to, so the results just fetched
         * are shown immediately instead of being stashed in the session. */
        $query = trim($_POST['q'] ?? '');
        $problem = null;
        if ($query === '')            $problem = 'empty';
        elseif (!bgg_configured())    $problem = 'unconfigured';
        $results = $problem === null ? bgg_search($query) : [];
        tpl_render('header', ['page_title' => $isSelf
            ? t('lib_my_title') : t('lib_my_title_admin', $owner['display_name'])]);
        tpl_render('lib_bgg_search', [
            'query' => $query, 'results' => $results, 'problem' => $problem,
            'action' => $selfUrl, 'back' => $selfUrl,
            'uid_field' => $isSelf ? '' : '<input type="hidden" name="user" value="' . (int)$owner['id'] . '">',
            'csrf' => csrf_field(),
        ]);
        tpl_render('footer');
        exit;

    } elseif ($action === 'add_bgg') {
        $why = '';
        $entry = library_entry_from_bgg_input($_POST['bgg'] ?? '', $why);
        if (!$entry) {
            /* A version link gets its own wording: "that is not a BGG address"
             * would be both wrong and unhelpful, since it IS one — just not one
             * that can be resolved directly. */
            if ($why === 'version link')       flash_set(t('lib_bgg_version_link'), 'error');
            elseif ($why === 'not a bgg link') flash_set(t('lib_bgg_bad_link'), 'error');
            else                               flash_set(t('lib_bgg_not_found'), 'error');
        } else {
            /* More than one published edition: ask which, rather than picking
             * silently. Rendered here rather than redirected to, so the list
             * just fetched is used immediately instead of being stashed. */
            $versions = bgg_versions((int)$entry['bgg_id']);
            // Offered when there is a real choice to make: several editions, or
            // several titles the game is sold under.
            if (count($versions) > 1) {
                tpl_render('header', ['page_title' => t('lib_my_title')]);
                tpl_render('lib_version_pick', [
                    'game'     => ['id' => (int)$entry['bgg_id'], 'name' => $entry['name'],
                                   // Fallback art for editions BGG has no cover for.
                                   'thumbnail' => $entry['thumbnail'] ?? ''],
                    'versions' => $versions,
                    'row_id'   => 0,
                    'action'   => 'my_library.php',
                    'back'     => 'my_library.php',
                    'csrf'     => csrf_field(),
                ]);
                tpl_render('footer');
                exit;
            }
            library_add($owner['id'], $entry);
            flash_set(t('lib_added', $entry['name']));
        }

    } elseif ($action === 'add_bgg_pick') {
        // The chooser's answer. The game is re-fetched from its id rather than
        // carried through the form, so a hand-edited post cannot invent one.
        $why    = '';
        $gameId = (int)($_POST['game_id'] ?? 0);
        $entry  = $gameId > 0 ? library_entry_from_bgg_input((string)$gameId, $why) : null;
        if (!$entry) {
            flash_set(t('lib_bgg_not_found'), 'error');
        } else {
            $entry = library_apply_version_choice($entry, $gameId, $_POST);
            library_add($owner['id'], $entry);
            flash_set(t('lib_added', $entry['name']));
        }

    } elseif ($action === 'pick_version') {
        /* "Choose edition" on a game already on the shelf — for people who
         * imported a BGG collection and got the English titles and covers. Same
         * screen as adding, but it UPDATES the row instead of creating one. */
        $rowId = (int)($_POST['game'] ?? 0);
        $row   = $rowId ? db_one('SELECT * FROM library_games WHERE id = ? AND user_id = ?',
                                 [$rowId, $owner['id']]) : null;
        if (!$row || empty($row['bgg_id'])) {
            flash_set(t('lib_edit_failed'), 'error');
        } else {
            $why  = '';
            $game = library_entry_from_bgg_input((string)(int)$row['bgg_id'], $why);
            if (!$game) {
                flash_set(t('lib_bgg_not_found'), 'error');
            } else {
                tpl_render('header', ['page_title' => t('lib_my_title')]);
                tpl_render('lib_version_pick', [
                    'game'     => ['id' => (int)$row['bgg_id'], 'name' => $game['name'],
                                   'thumbnail' => $game['thumbnail'] ?? ''],
                    'versions' => bgg_versions((int)$row['bgg_id']),
                    'row_id'   => $rowId,
                    'action'   => 'my_library.php',
                    'back'     => 'my_library.php',
                    'csrf'     => csrf_field(),
                ]);
                tpl_render('footer');
                exit;
            }
        }

    } elseif ($action === 'set_version') {
        // The chooser's answer for an existing row.
        $rowId = (int)($_POST['game'] ?? 0);
        $row   = $rowId ? db_one('SELECT * FROM library_games WHERE id = ? AND user_id = ?',
                                 [$rowId, $owner['id']]) : null;
        if (!$row || empty($row['bgg_id'])) {
            flash_set(t('lib_edit_failed'), 'error');
        } else {
            $chosen = library_apply_version_choice(
                ['name' => $row['name'], 'year' => $row['year'], 'thumbnail' => $row['thumbnail'],
                 'image' => $row['image'] ?? ''],
                (int)$row['bgg_id'], $_POST);
            // image and bgg_version_id too, or a re-pick through this screen
            // would drop the full-size picture and the edition record it just
            // set moments ago in library_apply_version_choice().
            db_run('UPDATE library_games SET name = ?, year = ?, thumbnail = ?, image = ?, bgg_version_id = ? WHERE id = ?',
                   [$chosen['name'], !empty($chosen['year']) ? (int)$chosen['year'] : null,
                    $chosen['thumbnail'] !== '' ? $chosen['thumbnail'] : null,
                    !empty($chosen['image']) ? $chosen['image'] : null,
                    !empty($chosen['bgg_version_id']) ? (int)$chosen['bgg_version_id'] : null,
                    $rowId]);
            flash_set(t('lib_edit_renamed', $chosen['name']));
        }

    } elseif ($action === 'sync') {
        /* The confirmation is required ONLY for the mode that deletes. Checked
         * server-side rather than relying on the checkbox's `required`, which a
         * client can simply not send — and an unrecognised mode falls back to
         * the safest one, so a mangled request cannot become a full wipe. */
        $syncMode = library_sync_mode($_POST['mode'] ?? '');
        if (in_array($syncMode, library_sync_destructive_modes(), true) && empty($_POST['confirm'])) {
            flash_set(t('lib_sync_confirm_required'), 'error');
        } else {
            $user = library_bgg_user_from_input($_POST['bgg_user'] ?? '');
            if ($user === '') {
                flash_set(t('lib_bgg_bad_user'), 'error');
            } else {
                $why = '';
                $collection = library_fetch_collection($user, $why);
                if ($collection === null) {
                    flash_set(t('lib_sync_failed', $why), 'error');
                } else {
                    $res = library_sync_from_collection($owner['id'], $collection, $syncMode);
                    $msg = t('lib_sync_done', $res['added'], $res['removed'], $res['kept']);
                    if (!empty($res['purged']))  $msg .= ' ' . t('lib_sync_purged', $res['purged']);
                    if (!empty($res['updated'])) $msg .= ' ' . t('lib_sync_updated', $res['updated']);
                    // Same top-up as the club shelf: fill in full-size pictures
                    // for games added before those were recorded.
                    // This member's own rows only — see library_backfill_images().
                    $fill = library_backfill_images('library_games', 25, $owner['id']);
                    // "sync again" only when there is actually more to do.
                    if ($fill['left'] > 0) {
                        $msg .= ' ' . t('lib_images_filled', $fill['done'], $fill['left']);
                    } elseif ($fill['done'] > 0) {
                        $msg .= ' ' . t('lib_images_done', $fill['done']);
                    }
                    flash_set($msg);
                }
            }
        }

    } elseif ($action === 'contact_pref') {
        // Only meaningful while the admin allows contacting at all; saved even
        // when it is off, so switching the feature on restores each member's
        // earlier choice rather than resetting everyone.
        db_run('UPDATE users SET library_contact_ok = ? WHERE id = ?',
               [empty($_POST['library_contact_ok']) ? 0 : 1, $owner['id']]);
        flash_set(t('lib_prefs_saved'));
    }

    redirect($selfUrl);
}

tpl_render('header', ['page_title' => $isSelf
    ? t('lib_my_title') : t('lib_my_title_admin', $owner['display_name'])]);
tpl_render('my_library', [
    'games' => library_for_user($owner['id']),
    // The OWNER, not the viewer: the contact preference on this page belongs to
    // whoever's library it is, and an admin sending $me here would edit their
    // own setting from somebody else's screen.
    'user'  => $owner,
    // Admin mode: name whose library this is, and where the forms post.
    'as_admin' => !$isSelf,
    'self_url' => $selfUrl,
    'flash' => flash_get(),
    // Read AFTER flash_get(), which clears the text — see flash_kind().
    'flash_kind' => flash_kind(),
    'csrf'  => csrf_field(),
]);
tpl_render('footer');
