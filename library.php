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
if (!library_any_enabled()) redirect('index.php');

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

    /* THE CLUB'S SHELF IS A DIFFERENT TABLE, so a row id alone is ambiguous —
     * club row 7 and member row 7 both exist. The form says which it meant, and
     * the two branches never touch each other's table.
     *
     * Managing the club shelf is an ADMIN action: it is the club's property,
     * not any member's, so there is no owner to fall back on. */
    if (($_POST['scope'] ?? '') === 'club') {
        if (!club_shelf_enabled() || !is_admin()) redirect('library.php');
        $clubRow = $rowId ? club_shelf_entry($rowId) : null;
        if (!$clubRow) redirect('library.php?tab=club');

        switch ($_POST['action'] ?? '') {
            case 'remove':
                club_shelf_remove($rowId);
                flash_set(t('lib_removed'));
                break;
            case 'toggle':
                club_shelf_set_active($rowId, !empty($_POST['active']));
                flash_set(t('lib_visibility_saved'));
                break;
            case 'edit':
                // Same split as a member's shelf: a BGG entry's only edit is a
                // new link, a hand-typed one takes name, year and link.
                if (!empty($clubRow['bgg_id'])) {
                    library_flash_edit(club_shelf_relink_bgg($rowId, $_POST['link'] ?? ''));
                } else {
                    library_flash_edit(club_shelf_update_manual(
                        $rowId, $_POST['name'] ?? '', (int)($_POST['year'] ?? 0),
                        library_link_field_visible() ? ($_POST['link'] ?? '') : null
                    ));
                }
                break;
        }
        redirect('library.php?tab=club');
    }

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

/* WHICH VIEWS EXIST depends on two independent switches, so all four
 * combinations have to land somewhere sensible:
 *   members' library on, club shelf off  -> games / members, as before
 *   both on                              -> games / members / club
 *   club shelf only                      -> club alone, and no tab strip,
 *                                           because one tab is not a choice
 *   neither                              -> the page redirected away already
 *
 * The DEFAULT tab is therefore the club shelf when the members' library is
 * off: landing on an empty "games" view when the club has a cabinet full of
 * games would look broken. */
$showMembers = library_enabled() && library_members_tab_enabled();
$showClub    = club_shelf_enabled();
$showMemberGames = library_enabled();

$tab = $_GET['tab'] ?? ($showMemberGames ? 'games' : 'club');
// A tab that does not exist in this configuration falls back rather than
// 404ing — a bookmark made while a switch was on should still show something
// useful after it is turned off.
if ($tab === 'members' && !$showMembers) $tab = $showMemberGames ? 'games' : 'club';
if ($tab === 'club' && !$showClub)       $tab = 'games';
if ($tab === 'games' && !$showMemberGames) $tab = 'club';
if ($tab !== 'members' && $tab !== 'club') $tab = $showMemberGames ? 'games' : 'club';

/* A tab strip for a single tab is noise, so it appears only when there is
 * genuinely more than one view to move between. */
$tabCount = ($showMemberGames ? 1 : 0) + ($showMembers ? 1 : 0) + ($showClub ? 1 : 0);

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

/* PAGINATION, for whichever whole-collection list this tab shows — the members'
 * merged list or the club's own. A member's shelf is one person's games and
 * needs no splitting; these two are the ones that grow past a screenful.
 *
 * Applied to both from ONE block rather than a copy per tab: the club shelf was
 * asked for with "the same layout, pagination options etc", and two copies of
 * this would drift the moment either changed.
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
/* The club's own games. Active-only for visitors; an admin sees hidden rows
 * too, since this is the screen the manage controls live on — the same split
 * a member's shelf makes. */
$clubManage = is_admin();
$clubGames  = $tab === 'club' ? club_shelf_all(!$clubManage) : [];
$mode      = library_pagination();
$letters   = [];
$letter    = '';
$page      = 1;
$pageCount = 1;

// Bound by reference so one block can page either list in place.
$paged = null;
if ($tab === 'games')     $paged = &$games;
elseif ($tab === 'club')  $paged = &$clubGames;

if ($paged !== null && $paged) {
    if ($mode === 'alpha') {
        $letters = library_letters($paged);
        $letter  = (string)($_GET['letter'] ?? '');
        // An unknown letter shows the index rather than an empty list — a stale
        // link should not look like "there are no games".
        if ($letter !== '' && !isset($letters[$letter])) $letter = '';
        if ($letter !== '') {
            $paged = array_values(array_filter($paged, function ($g) use ($letter) {
                return library_letter($g['name']) === $letter;
            }));
        } else {
            $paged = [];   // the index page lists letters, not games
        }
    } elseif ($mode === 'pages') {
        $per       = library_per_page();
        $pageCount = max(1, (int)ceil(count($paged) / $per));
        $page      = max(1, min($pageCount, (int)($_GET['page'] ?? 1)));
        $paged     = array_slice($paged, ($page - 1) * $per, $per);
    }
}
// Break the reference, or a later write to $paged would silently alter
// whichever list it still points at.
unset($paged);

tpl_render('header', ['page_title' => t('lib_title')]);
tpl_render('library', [
    'mode'         => $mode,
    'letters'      => $letters,
    'letter'       => $letter,
    'page'         => $page,
    'page_count'   => $pageCount,
    'tab'          => $tab,
    'show_members' => $showMembers,
    'show_games'   => $showMemberGames,
    'show_club'    => $showClub,
    'tab_count'    => $tabCount,
    'club_games'   => $clubGames,
    'club_manage'  => $clubManage,
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
