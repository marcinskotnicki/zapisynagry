<?php
/* =============================================================================
 *  sign_up.php — sign up for a game (or its reserve list).
 * -----------------------------------------------------------------------------
 *  GET renders the signup form; POST records the signup. Whether it's CONFIRMED
 *  or RESERVE is decided at submit time from the current free seats, so two
 *  people racing for the last seat resolve sanely (the one whose write lands
 *  while a seat is free is confirmed; the other becomes reserve).
 *  On a confirmed signup the game's bringer gets a notification email.
 * ============================================================================= */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/events.php';
require __DIR__ . '/inc/notify.php';

// The game can arrive via query (link) or body (form post).
$gameId = (int)($_GET['game'] ?? $_POST['game'] ?? 0);
$game   = $gameId ? db_one('SELECT * FROM games WHERE id = ?', [$gameId]) : null;
if (!$game) { http_response_code(404); exit('Unknown game.'); }

$event = db_one('SELECT * FROM events WHERE id = ?', [$game['event_id']]);
$day   = db_one('SELECT day_index FROM event_days WHERE id = ?', [$game['day_id']]);
$activeDay = (int)($day['day_index'] ?? 1);   // for redirecting back to the right day tab

// Signups only on the live event, and only if permitted by the access rules.
if (!$event || (int)$event['is_archived'] === 1 || !can_signup()) {
    redirect('index.php');
}

// Prefill name/email for a logged-in user; default "knows rules" to 0 ("yes").
$u = current_user();
$form = [
    'name'  => $_POST['name']  ?? ($u['display_name'] ?? guest_identity()['name']),
    'email' => $_POST['email'] ?? ($u['email'] ?? guest_identity()['email']),
    'knows' => isset($_POST['knows']) ? (int)$_POST['knows'] : 0,
    // Set on POST below; declared here so the form can redisplay it after an
    // error without a notice.
    'player_name' => $_POST['player_name'] ?? '',
];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    antibot_check('form');
    $form['name']  = trim((string)$form['name']);
    $form['email'] = trim((string)$form['email']);
    // Only accepted when the field was actually offered, so a hand-built POST
    // cannot use a form the admin has switched off.
    $form['player_name'] = signup_proxy_enabled() ? trim((string)($_POST['player_name'] ?? '')) : '';
    $form['knows'] = min(2, max(0, (int)$form['knows']));   // clamp to the 0..2 codes

    // Data-protection consent, when the admin configured wording and the
    // visitor is not signed in. Checked here rather than trusting the
    // checkbox's `required` attribute, which a client can simply not send.
    if (!consent_ok()) {
        $error = t('gdpr_required');
    } elseif ($form['name'] === '') {
        $error = t('error_signup_name');
    } elseif (!text_has_content($form['name'])) {
        $error = t('error_name_meaningless');
    } elseif (text_too_long($form['name'], TEXT_NAME_MAX)) {
        $error = t('error_too_long', TEXT_NAME_MAX);
    } elseif ($form['player_name'] !== '' && !text_has_content($form['player_name'])) {
        // Held to the same standard as the main name — it is the one that ends
        // up on the table.
        $error = t('error_name_meaningless');
    } elseif (text_too_long($form['player_name'], TEXT_NAME_MAX)) {
        $error = t('error_too_long', TEXT_NAME_MAX);
    } elseif (email_required_for_game($game) && $form['email'] === '') {
        // Required globally (mode 1) or because THIS game's proposer demands it.
        $error = t('error_email_required');
    } elseif ($form['email'] !== '' && !email_valid($form['email'])) {
        $error = t('error_email_invalid');   // non-empty but not X@Y.Z-shaped
    } else {
        // Confirmed unless the game is already full RIGHT NOW (re-checked here,
        // not from a value computed earlier, so the race resolves correctly).
        $isReserve = game_is_full($gameId, $game['max_players']) ? 1 : 0;

        /* WHO SITS AT THE TABLE is the second field when it is filled, and the
         * first field then records who put them there. Left blank — the normal
         * case — the first field is the player, exactly as before. */
        $playerName = $form['player_name'] !== '' ? $form['player_name'] : $form['name'];
        $signedUpBy = $form['player_name'] !== '' ? $form['name'] : null;

        db_run(
            'INSERT INTO players (game_id, name, email, knows_rules, is_reserve, user_id, signed_up_by)
             VALUES (?,?,?,?,?,?,?)',
            [
                $gameId, $playerName,
                $form['email'] !== '' ? $form['email'] : null,   // store NULL, not ''
                $form['knows'], $isReserve,
                $u['id'] ?? null,                                // link to account if logged in
                $signedUpBy,
            ]
        );
        log_action('signup', $playerName . ' -> ' . $game['name'] . ($isReserve ? ' (reserve)' : '')
            . ($signedUpBy !== null ? ' (by ' . $signedUpBy . ')' : ''));
        // Remember the agreement so the next form starts ticked. Only after a
        // successful submission, so the cookie can never record consent for
        // something that was refused.
        consent_remember();
        guest_identity_remember($form['name'], $form['email']);   // prefill next time
        notify_signup($game, $form['name']);                     // no-op unless notifications on
        redirect('index.php?day=' . $activeDay . '#game-' . $gameId);   // PRG + jump to the card
    }
}

// For the form: is the game full (so we can label the button "join reserve")?
$full = game_is_full($gameId, $game['max_players']);

tpl_render('header', ['page_title' => t('signup_title')]);
tpl_render('sign_up', [
    'game'  => $game,
    'form'  => $form,
    'full'  => $full,
    'error' => $error,
    'csrf'  => csrf_field(),
]);
tpl_render('footer');
