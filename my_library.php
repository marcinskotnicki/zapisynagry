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
 *                owned collection. DESTRUCTIVE by design, so the form says so
 *                and the POST carries an explicit confirmation checkbox.
 *
 *  EVERY ACTION IS SCOPED TO THE SIGNED-IN MEMBER. Nothing here takes a user id
 *  from the request: a member can only ever edit their own library, so a
 *  hand-edited form has nothing to aim at.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        /* Hide or show one game in the PUBLIC library. It stays in this list
         * either way — that is the point: a game lent to a friend is still
         * yours, it just should not be advertised as available. */
        library_set_active((int)($_POST['game'] ?? 0), !empty($_POST['active']), $me['id']);
        flash_set(t('lib_visibility_saved'));

    } elseif ($action === 'edit') {
        // Manual entries only — library_update_manual() enforces that, and
        // reports false when the row is a BGG one or the name is empty.
        /* Which editor applies is decided by the ROW, not by the request: a
         * BGG entry has nothing typeable (its name and year are BGG's), so its
         * only edit is a new link; a manual one takes name, year and link. */
        $editId  = (int)($_POST['game'] ?? 0);
        $editRow = $editId ? db_one('SELECT bgg_id FROM library_games WHERE id = ? AND user_id = ?',
                                    [$editId, $me['id']]) : null;
        if ($editRow && !empty($editRow['bgg_id'])) {
            // Name too: a BGG entry may carry a title in the wrong language.
            library_flash_edit(library_relink_bgg($editId, $_POST['link'] ?? '', $me['id'], $_POST['name'] ?? ''));
        } else {
            library_flash_edit(library_update_manual(
                $editId,
                $_POST['name'] ?? '',
                (int)($_POST['year'] ?? 0),
                $me['id'],
                // Only accept a link when the field was actually offered, so a
                // hand-built POST cannot set one the form would not show.
                library_link_field_visible() ? ($_POST['link'] ?? '') : null
            ));
        }

    } elseif ($action === 'remove') {
        // Scoped by user id inside library_remove(), so an id from a
        // hand-edited form cannot reach anybody else's row.
        library_remove($me['id'], (int)($_POST['game'] ?? 0));
        flash_set(t('lib_removed'));

    } elseif ($action === 'add_manual') {
        $name = trim($_POST['name'] ?? '');
        $link = game_link_sanitize($_POST['link'] ?? '');
        if ($name === '' || !text_has_content($name) || text_too_long($name, TEXT_NAME_MAX)) {
            flash_set(t('error_name_required'), 'error');
        } else {
            // Year is optional; 0 stores as NULL rather than printing "0".
            library_add($me['id'], [
                'name' => $name,
                'year' => (int)($_POST['year'] ?? 0),
                'link' => $link,
            ]);
            flash_set(t('lib_added', $name));
        }

    } elseif ($action === 'add_bgg') {
        /* Accepts a game link OR a link to one EDITION of it — a version link
         * stores the same game but carries that edition's title and cover, so a
         * Polish printing lists under its Polish name. See
         * library_entry_from_bgg_input(). */
        $why = '';
        $entry = library_entry_from_bgg_input($_POST['bgg'] ?? '', $why);
        if (!$entry) {
            // A bad id, a BGG outage, and a host with no outbound route all
            // look the same from the member's point of view; only "that is not
            // a BGG address at all" is worth its own wording.
            if ($why === 'not a bgg link') {
                flash_set(t('lib_bgg_bad_link'), 'error');
            } elseif (strpos($why, 'version: ') === 0) {
                // Says WHY, because a version lookup reads a web page and can
                // fail in ways worth telling apart.
                flash_set(t('lib_bgg_version_failed', substr($why, 9)), 'error');
            } else {
                flash_set(t('lib_bgg_not_found'), 'error');
            }
        } else {
            library_add($me['id'], $entry);
            flash_set(t('lib_added', $entry['name']));
        }

    } elseif ($action === 'sync') {
        // The confirmation is required because this DELETES games. Checked
        // server-side rather than relying on the checkbox's `required`, which
        // a client can simply not send.
        if (empty($_POST['confirm'])) {
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
                    $res = library_sync_from_collection($me['id'], $collection);
                    flash_set(t('lib_sync_done', $res['added'], $res['removed'], $res['kept']));
                }
            }
        }

    } elseif ($action === 'contact_pref') {
        // Only meaningful while the admin allows contacting at all; saved even
        // when it is off, so switching the feature on restores each member's
        // earlier choice rather than resetting everyone.
        db_run('UPDATE users SET library_contact_ok = ? WHERE id = ?',
               [empty($_POST['library_contact_ok']) ? 0 : 1, $me['id']]);
        flash_set(t('lib_prefs_saved'));
    }

    redirect('my_library.php');
}

tpl_render('header', ['page_title' => t('lib_my_title')]);
tpl_render('my_library', [
    'games' => library_for_user($me['id']),
    'user'  => $me,
    'flash' => flash_get(),
    // Read AFTER flash_get(), which clears the text — see flash_kind().
    'flash_kind' => flash_kind(),
    'csrf'  => csrf_field(),
]);
tpl_render('footer');
