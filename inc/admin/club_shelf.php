<?php
/* =============================================================================
 *  inc/admin/club_shelf.php — the club's own game collection.
 * -----------------------------------------------------------------------------
 *  The same shelf a member keeps in my_library.php, owned by the club instead
 *  of a person: a cabinet at the venue, games bought from club funds. Shared by
 *  every admin — there is no per-admin ownership, because the games are not any
 *  admin's property.
 *
 *  Deliberately the same four actions, in the same order, as my_library.php:
 *
 *    add_bgg   — paste a BGG link (or bare id); we fetch the game and store it.
 *    add_manual— a name, a year, and optionally a link, for anything not on BGG.
 *    remove    — drop one entry.
 *    sync      — replace the BGG-sourced half from a BGG account's owned
 *                collection. DESTRUCTIVE, so the form says so and the POST
 *                carries an explicit confirmation checkbox.
 *
 *  WHAT IS NOT HERE: hiding a game and editing one. Those controls live beside
 *  each game on the public club tab, exactly as they do for a member's shelf —
 *  the point of putting them there was that noticing a wrong entry happens while
 *  looking at the list, and a second copy of the list here would be one more
 *  place to keep in step.
 *
 *  admin.php has already run csrf_check() for POSTs before including us, and
 *  only reaches this file when club_shelf_enabled() — the tab is removed from
 *  the whitelist otherwise, so this is not a second gate to keep in sync.
 * ============================================================================= */

require_once __DIR__ . '/../library.php';
require_once __DIR__ . '/../bgg.php';      // bgg_thing() for "add from BGG"
require_once __DIR__ . '/../events.php';   // game_link_sanitize(), shared with the game forms

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'remove') {
        club_shelf_remove((int)($_POST['game'] ?? 0));
        log_action('club_shelf_remove', 'club game #' . (int)($_POST['game'] ?? 0));
        $flash = t('lib_removed');

    } elseif ($action === 'add_manual') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || !text_has_content($name) || text_too_long($name, TEXT_NAME_MAX)) {
            $flash = t('error_name_required');
            $flashKind = 'error';
        } else {
            club_shelf_add([
                'name' => $name,
                'year' => (int)($_POST['year'] ?? 0),
                'link' => game_link_sanitize($_POST['link'] ?? ''),
            ]);
            log_action('club_shelf_add', $name);
            $flash = t('lib_added', $name);
        }

    } elseif ($action === 'add_bgg') {
        // Same builder as a member's own shelf, so both accept a game link or
        // a link to one EDITION of it — see library_entry_from_bgg_input().
        $why = '';
        $entry = library_entry_from_bgg_input($_POST['bgg'] ?? '', $why);
        if (!$entry) {
            if ($why === 'version link')       $flash = t('lib_bgg_version_link');
            elseif ($why === 'not a bgg link') $flash = t('lib_bgg_bad_link');
            else                               $flash = t('lib_bgg_not_found');
            $flashKind = 'error';
        } else {
            // More than one edition: ask which, exactly as a member's own shelf
            // does. Rendered straight away so the fetched list is used now.
            $versions = bgg_versions((int)$entry['bgg_id']);
            if (count($versions) > 1) {
                $tab_body = tpl_capture('lib_version_pick', [
                    'game'     => ['id' => (int)$entry['bgg_id'], 'name' => $entry['name'],
                                   // Fallback art for editions BGG has no cover for.
                                   'thumbnail' => $entry['thumbnail'] ?? ''],
                    'versions' => $versions,
                    'row_id'   => 0,
                    'action'   => 'admin.php?tab=club_shelf',
                    'back'     => 'admin.php?tab=club_shelf',
                    'csrf'     => csrf_field(),
                ]);
                return;
            }
            club_shelf_add($entry);
            log_action('club_shelf_add', $entry['name'] . ' (BGG ' . $entry['bgg_id'] . ')');
            $flash = t('lib_added', $entry['name']);
        }

    } elseif ($action === 'add_bgg_pick') {
        $why    = '';
        $gameId = (int)($_POST['game_id'] ?? 0);
        $entry  = $gameId > 0 ? library_entry_from_bgg_input((string)$gameId, $why) : null;
        if (!$entry) {
            $flash = t('lib_bgg_not_found');
            $flashKind = 'error';
        } else {
            $entry = library_apply_version_choice($entry, $gameId, $_POST);
            club_shelf_add($entry);
            log_action('club_shelf_add', $entry['name'] . ' (BGG ' . $entry['bgg_id'] . ')');
            $flash = t('lib_added', $entry['name']);
        }

    } elseif ($action === 'pick_version') {
        // "Choose edition" for a club game already on the shelf.
        $row = club_shelf_entry((int)($_POST['game'] ?? 0));
        if (!$row || empty($row['bgg_id'])) {
            $flash = t('lib_edit_failed');
            $flashKind = 'error';
        } else {
            $why  = '';
            $game = library_entry_from_bgg_input((string)(int)$row['bgg_id'], $why);
            if (!$game) {
                $flash = t('lib_bgg_not_found');
                $flashKind = 'error';
            } else {
                $tab_body = tpl_capture('lib_version_pick', [
                    'game'     => ['id' => (int)$row['bgg_id'], 'name' => $game['name'],
                                   'thumbnail' => $game['thumbnail'] ?? ''],
                    'versions' => bgg_versions((int)$row['bgg_id']),
                    'row_id'   => (int)$row['id'],
                    'action'   => 'admin.php?tab=club_shelf',
                    'back'     => 'admin.php?tab=club_shelf',
                    'csrf'     => csrf_field(),
                ]);
                return;
            }
        }

    } elseif ($action === 'set_version') {
        $row = club_shelf_entry((int)($_POST['game'] ?? 0));
        if (!$row || empty($row['bgg_id'])) {
            $flash = t('lib_edit_failed');
            $flashKind = 'error';
        } else {
            $chosen = library_apply_version_choice(
                ['name' => $row['name'], 'year' => $row['year'], 'thumbnail' => $row['thumbnail']],
                (int)$row['bgg_id'], $_POST);
            db_run('UPDATE club_library_games SET name = ?, year = ?, thumbnail = ? WHERE id = ?',
                   [$chosen['name'], !empty($chosen['year']) ? (int)$chosen['year'] : null,
                    $chosen['thumbnail'] !== '' ? $chosen['thumbnail'] : null, (int)$row['id']]);
            log_action('club_shelf_edit', 'edition chosen for #' . (int)$row['id']);
            $flash = t('lib_edit_renamed', $chosen['name']);
        }

    } elseif ($action === 'sync') {
        /* The confirmation is required because this DELETES games. Checked
         * server-side rather than relying on the checkbox's `required`, which a
         * client can simply not send. */
        if (empty($_POST['confirm'])) {
            $flash = t('lib_sync_confirm_required');
            $flashKind = 'error';
        } else {
            $user = library_bgg_user_from_input($_POST['bgg_user'] ?? '');
            if ($user === '') {
                $flash = t('lib_bgg_bad_user');
                $flashKind = 'error';
            } else {
                $why = '';
                $collection = library_fetch_collection($user, $why);
                if ($collection === null) {
                    $flash = t('lib_sync_failed', $why);
                    $flashKind = 'error';
                } else {
                    $res = club_shelf_sync_from_collection($collection);
                    log_action('club_shelf_sync',
                        '+' . $res['added'] . ' -' . $res['removed'] . ' =' . $res['kept']);
                    $flash = t('lib_sync_done', $res['added'], $res['removed'], $res['kept']);
                }
            }
        }
    }
}

$tab_body = tpl_capture('admin_club_shelf', [
    'csrf'  => csrf_field(),
    // Everything, including hidden games: this is the screen they are switched
    // back on from, so filtering them out would make them unreachable.
    'games' => club_shelf_all(false),
]);
