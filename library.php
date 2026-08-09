<?php
/* =============================================================================
 *  library.php — the club's shared game library.
 * -----------------------------------------------------------------------------
 *  Public (subject to the usual access rules), two views:
 *
 *    ?tab=games            every game any member owns, with who owns it;
 *    ?tab=members          members who own anything;
 *    ?tab=members&member=N one member's shelf.
 *
 *  THE MEMBERS TAB IS OPTIONAL (library_show_members). With it off there is one
 *  view, so the tab strip disappears entirely rather than showing a single tab —
 *  and ?tab=members is refused rather than quietly rendering a hidden view.
 *
 *  BLOCKED ACCOUNTS ARE INVISIBLE HERE, enforced in inc/library.php's queries
 *  rather than in this file, so the game list, the member list and one member's
 *  shelf cannot disagree about who counts.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/library.php';   // already in bootstrap; _once so a re-require cannot redeclare

// One gate: with the library off the page does not exist at all.
if (!library_enabled()) redirect('index.php');

/* ADMIN MANAGEMENT, from the page an admin is already looking at.
 *
 * Deliberately here rather than in a separate admin tab: the moment somebody
 * notices a game that should not be listed is while browsing the library, and a
 * second copy of the list in the admin panel would be one more place to keep in
 * step. Every action is POST + CSRF, and library_can_manage() decides — so a
 * member reaches exactly the same controls for their OWN rows, which is what
 * makes one code path serve both. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $me     = current_user();
    $meId   = (int)($me['id'] ?? 0);
    $rowId  = (int)($_POST['game'] ?? 0);
    $row    = $rowId ? db_one('SELECT * FROM library_games WHERE id = ?', [$rowId]) : null;
    $back   = 'library.php?tab=members&member=' . (int)($row['user_id'] ?? 0);

    if (!library_can_manage($row, $meId)) {
        // Not yours and you are not an admin: nothing happens, and the page
        // does not confirm whether the row existed.
        redirect('library.php');
    }
    /* Owner scope of 0 for an admin (any row), their own id otherwise — the
     * helpers add the user_id condition only when it is non-zero.
     *
     * A SECOND, EXPLICIT GUARD for the signed-out case, because 0 means two
     * different things here: "an admin, unscoped" and "nobody is signed in".
     * A guest reaching this line would get scope 0 and therefore admin reach,
     * so the check above would be the only thing standing between them and
     * deleting any row in the table. Requiring a real id unless is_admin()
     * makes that structural instead of a single point of failure. */
    if (!is_admin() && $meId <= 0) redirect('library.php');
    $scope = is_admin() ? 0 : $meId;

    switch ($_POST['action'] ?? '') {
        case 'remove':
            library_remove($scope, $rowId);
            flash_set(t('lib_removed'));
            break;
        case 'toggle':
            library_set_active($rowId, !empty($_POST['active']), $scope);
            flash_set(t('lib_visibility_saved'));
            break;
        case 'edit':
            /* Decided by the row, as on my_library.php: a BGG entry's only
             * edit is a new link, a manual one takes name, year and link. */
            if (!empty($row['bgg_id'])) {
                library_flash_edit(library_relink_bgg($rowId, $_POST['link'] ?? '', $scope));
            } else {
                library_flash_edit(library_update_manual(
                    $rowId, $_POST['name'] ?? '', (int)($_POST['year'] ?? 0), $scope,
                    library_link_field_visible() ? ($_POST['link'] ?? '') : null
                ));
            }
            break;
    }
    redirect($back);
}

$showMembers = library_members_tab_enabled();
$tab = $_GET['tab'] ?? 'games';
// An unknown tab, or the members tab while it is switched off, falls back to
// the games view rather than 404ing — a stale bookmark should still show
// something useful.
if ($tab !== 'members' || !$showMembers) $tab = 'games';

$memberId = (int)($_GET['member'] ?? 0);
$member = null;
$memberGames = [];
$canManageShelf = false;
if ($tab === 'members' && $memberId > 0) {
    /* Re-checked against the same visibility rule as the list: a blocked
     * member's shelf must not be reachable by guessing an id, even though
     * they no longer appear in the list that would have linked to it. */
    $member = db_one(
        'SELECT u.* FROM users u WHERE u.id = ? AND ' . library_public_owner_sql(),
        [$memberId]
    );
    /* Active-only for ordinary visitors; everything for the owner themselves and
     * for an admin, who need to SEE an inactive game in order to switch it back
     * on or delete it. Without this a hidden game would be unreachable from the
     * one screen that manages it. */
    $canManageShelf = is_admin() || (int)(current_user()['id'] ?? 0) === $memberId;
    if ($member) $memberGames = library_for_user($memberId, !$canManageShelf);
}

/* PAGINATION, games tab only. A member's shelf is one person's games and needs
 * no splitting; the merged list is the one that grows past a screenful once a
 * club gets going.
 *
 * All three modes work on the SAME already-merged list rather than pushing
 * LIMIT into SQL, because merging happens in PHP (a game owned by four people
 * is one row on screen), so a database-level limit would slice the wrong
 * thing and give pages of uneven length. */
/* Fetched HERE rather than inline in the render call below: the paging works on
 * this list, and a $games built at render time would discard every slice made
 * above it. (It was written that way first, and all three modes silently showed
 * the full list.) */
$games     = $tab === 'games' ? library_all_games() : [];
$mode      = library_pagination();
$letters   = [];
$letter    = '';
$page      = 1;
$pageCount = 1;

if ($tab === 'games' && $games) {
    if ($mode === 'alpha') {
        $letters = library_letters($games);
        $letter  = (string)($_GET['letter'] ?? '');
        // An unknown letter shows the index rather than an empty list — a stale
        // link should not look like "there are no games".
        if ($letter !== '' && !isset($letters[$letter])) $letter = '';
        if ($letter !== '') {
            $games = array_values(array_filter($games, function ($g) use ($letter) {
                return library_letter($g['name']) === $letter;
            }));
        } else {
            $games = [];   // the index page lists letters, not games
        }
    } elseif ($mode === 'pages') {
        $per       = library_per_page();
        $pageCount = max(1, (int)ceil(count($games) / $per));
        $page      = max(1, min($pageCount, (int)($_GET['page'] ?? 1)));
        $games     = array_slice($games, ($page - 1) * $per, $per);
    }
}

tpl_render('header', ['page_title' => t('lib_title')]);
tpl_render('library', [
    'mode'         => $mode,
    'letters'      => $letters,
    'letter'       => $letter,
    'page'         => $page,
    'page_count'   => $pageCount,
    'tab'          => $tab,
    'show_members' => $showMembers,
    'games'        => $games,
    'members'      => ($tab === 'members' && !$member) ? library_members() : [],
    'member'       => $member,
    'member_games' => $memberGames,
    // Whether to draw the manage controls beside each game on this shelf.
    'can_manage'   => !empty($canManageShelf),
    'csrf'         => csrf_field(),
    'flash'        => flash_get(),
    'flash_kind'   => flash_kind(),
]);
tpl_render('footer');
