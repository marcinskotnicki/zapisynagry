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

$showMembers = library_members_tab_enabled();
$tab = $_GET['tab'] ?? 'games';
// An unknown tab, or the members tab while it is switched off, falls back to
// the games view rather than 404ing — a stale bookmark should still show
// something useful.
if ($tab !== 'members' || !$showMembers) $tab = 'games';

$memberId = (int)($_GET['member'] ?? 0);
$member = null;
$memberGames = [];
if ($tab === 'members' && $memberId > 0) {
    /* Re-checked against the same visibility rule as the list: a blocked
     * member's shelf must not be reachable by guessing an id, even though
     * they no longer appear in the list that would have linked to it. */
    $member = db_one(
        'SELECT u.* FROM users u WHERE u.id = ? AND ' . library_public_owner_sql(),
        [$memberId]
    );
    if ($member) $memberGames = library_for_user($memberId);
}

tpl_render('header', ['page_title' => t('lib_title')]);
tpl_render('library', [
    'tab'          => $tab,
    'show_members' => $showMembers,
    'games'        => $tab === 'games' ? library_all_games() : [],
    'members'      => ($tab === 'members' && !$member) ? library_members() : [],
    'member'       => $member,
    'member_games' => $memberGames,
]);
tpl_render('footer');
