<?php
/* =============================================================================
 *  inc/mailing.php — the per-event mailing list.
 * -----------------------------------------------------------------------------
 *  Three jobs:
 *    1. subscribe / unsubscribe (the box under the timeline, and the token link
 *       in every mail's footer);
 *    2. notify subscribers when a game or a poll is added to their event;
 *    3. work out the four audiences the admin's Mailing tab can write to.
 *
 *  SCOPED TO ONE EVENT. Subscribing is a statement about THIS event, not a
 *  standing subscription to the venue — so the rows carry an event_id and
 *  cascade away with it. "Everyone who ever subscribed" is available to the
 *  admin as a deliberate, separate choice rather than the default.
 *
 *  DEPENDENCIES: send_mail() from inc/mail.php, which every caller that actually
 *  sends already requires. This file deliberately does NOT require it (or
 *  notify.php) itself: the rest of the app plain-`require`s those, so a
 *  require_once here loads them early and the later plain require then fatals
 *  with "cannot redeclare". The email master switch is read straight from the
 *  option instead of through notify_enabled(), which is the same one-line check.
 * ============================================================================= */

/**
 * Is the feature switched on? Checked before rendering the box, before sending
 * any notification, and inside the admin tab.
 * @return bool
 */
function mailing_enabled() {
    return opt_bool('mailing_list');
}

/**
 * The consent wording an admin configured, or '' for none.
 *
 * This IS the switch for the consent checkbox: with no text there is nothing to
 * agree to, so no box is shown and none is required. With text, the box appears
 * and is mandatory.
 * @return string
 */
function mailing_gdpr_text() {
    return trim((string)opt('mailing_gdpr_text'));
}

/**
 * Add an address to an event's list, or return the existing row if it's already
 * there. Idempotent on purpose: someone re-submitting the form (or forgetting
 * they subscribed) should be reassured, not duplicated or refused.
 *
 * @param int    $eventId
 * @param string $email
 * @param string $consent  The exact wording agreed to; '' when none was asked.
 * @return array|null      The subscriber row, or null if the email is invalid.
 */
function mailing_subscribe($eventId, $email, $consent = '') {
    $email = trim($email);
    if (!email_valid($email)) return null;

    $existing = db_one('SELECT * FROM mail_subscribers WHERE event_id = ? AND email = ?',
                       [$eventId, $email]);
    if ($existing) return $existing;

    db_run('INSERT INTO mail_subscribers (event_id, email, token, consent_text) VALUES (?,?,?,?)',
           [$eventId, $email, bin2hex(random_bytes(16)), $consent !== '' ? $consent : null]);
    return db_one('SELECT * FROM mail_subscribers WHERE id = ?', [(int)db()->lastInsertId()]);
}

/**
 * Remove a subscription by its token.
 *
 * Token-only, with no confirmation step and no login: an unsubscribe link that
 * doesn't work in one click isn't really an unsubscribe link. The token is 32
 * hex chars, so guessing one to spite a stranger isn't a practical concern.
 *
 * @param string $token
 * @return string|null  The address removed, or null if the token was unknown.
 */
function mailing_unsubscribe($token) {
    $token = trim((string)$token);
    if ($token === '') return null;
    $row = db_one('SELECT * FROM mail_subscribers WHERE token = ?', [$token]);
    if (!$row) return null;
    db_run('DELETE FROM mail_subscribers WHERE id = ?', [(int)$row['id']]);
    return $row['email'];
}

/**
 * Absolute unsubscribe URL for a token, or '' when no site URL is configured
 * (the same condition under which mail_body_with_footer() drops its link).
 * @param string $token
 * @return string
 */
function mailing_unsub_url($token) {
    $base = site_base_url();
    if ($base === '') return '';
    return rtrim($base, '/') . '/unsubscribe.php?token=' . rawurlencode($token);
}

/* ---- Audiences ------------------------------------------------------------
 *  The four the admin can choose between. Each returns a de-duplicated list of
 *  lowercase addresses, so the same person can't be mailed twice by picking an
 *  overlapping combination.
 * ------------------------------------------------------------------------- */

/** Everyone subscribed to ONE event. @param int $eventId @return string[] */
function mailing_subscriber_emails($eventId) {
    return mailing_clean(db_all('SELECT email FROM mail_subscribers WHERE event_id = ?', [$eventId]));
}

/** Everyone who ever subscribed, to any event. @return string[] */
function mailing_all_subscriber_emails() {
    return mailing_clean(db_all('SELECT DISTINCT email FROM mail_subscribers'));
}

/**
 * Everyone who took part in an event: the addresses left when adding a game,
 * proposing a poll, signing up to play, or voting. These people never asked for
 * mail — the admin choosing this audience is making that call — but they did
 * hand over an address in the context of this event.
 * @param int $eventId
 * @return string[]
 */
function mailing_participant_emails($eventId) {
    $sql = "SELECT brings_email AS e FROM games  WHERE event_id = ? AND brings_email IS NOT NULL
            UNION SELECT proposer_email FROM polls WHERE event_id = ? AND proposer_email IS NOT NULL
            UNION SELECT p.email FROM players p
                  JOIN games g ON g.id = p.game_id
                  WHERE g.event_id = ? AND p.email IS NOT NULL
            UNION SELECT v.email FROM poll_votes v
                  JOIN polls pl ON pl.id = v.poll_id
                  WHERE pl.event_id = ? AND v.email IS NOT NULL";
    return mailing_clean(db_all($sql, [$eventId, $eventId, $eventId, $eventId]));
}

/**
 * Resolve an audience key to a list of addresses.
 * 'subscribers' | 'all_subscribers' | 'participants' | 'event_everyone'
 * @param string $audience
 * @param int    $eventId
 * @return string[]
 */
function mailing_audience($audience, $eventId) {
    switch ($audience) {
        case 'all_subscribers': return mailing_all_subscriber_emails();
        case 'participants':    return mailing_participant_emails($eventId);
        case 'event_everyone':  // subscribers AND participants, merged and de-duped
            return mailing_clean(array_merge(mailing_subscriber_emails($eventId),
                                             mailing_participant_emails($eventId)));
        case 'subscribers':
        default:                return mailing_subscriber_emails($eventId);
    }
}

/**
 * Normalise a raw address list: drop blanks and invalid entries, lowercase, and
 * de-duplicate. Addresses reach the database from several forms over the years,
 * so 'Ala@X.pl' and 'ala@x.pl' must not count as two people.
 *
 * Accepts either db_all() rows (whose single column may be named anything,
 * since a UNION takes its names from the first SELECT) or a plain string list.
 * @param array $rows
 * @return string[]
 */
function mailing_clean($rows) {
    $out = [];
    foreach ($rows as $row) {
        $e = is_array($row) ? reset($row) : $row;
        $e = strtolower(trim((string)$e));
        if ($e !== '' && email_valid($e)) $out[$e] = true;
    }
    return array_keys($out);
}

/* ---- Notifications -------------------------------------------------------- */

/**
 * Tell an event's subscribers that something new is on the schedule.
 *
 * Each message carries that subscriber's OWN unsubscribe link, so it has to be
 * built per recipient rather than once for the batch.
 *
 * @param int    $eventId
 * @param string $name      Game or poll name.
 * @param int    $dayId     Which day it lands on.
 * @param string $startTime 'HH:MM'
 * @param string $anchor    Fragment to scroll to, e.g. 'game-12' or 'poll-3'.
 * @param bool   $isPoll    Picks the wording.
 * @return int              How many subscribers were mailed. Counts recipients
 *                          ATTEMPTED, not deliveries confirmed: send_mail()
 *                          returning true only means the transport accepted the
 *                          message, so a "sent" tally would be false precision.
 */
function mailing_notify_new_item($eventId, $name, $dayId, $startTime, $anchor, $isPoll = false) {
    // opt_bool('send_emails') is exactly what notify_enabled() checks; read it
    // directly so this file needs no include of inc/notify.php.
    if (!mailing_enabled() || !opt_bool('send_emails')) return 0;

    $day = db_one('SELECT * FROM event_days WHERE id = ?', [$dayId]);
    if (!$day) return 0;

    // The date is only worth stating when the event spans more than one day —
    // on a one-day event it's noise, since there's only one date it could be.
    $multiDay = (int)db_val('SELECT COUNT(*) FROM event_days WHERE event_id = ?', [$eventId]) > 1;
    $when = $multiDay && !empty($day['day_date'])
        ? t('ml_when_date', mailing_format_date($day['day_date']), $startTime)
        : t('ml_when_time', $startTime);

    $link = mailing_item_url((int)$day['day_index'], $anchor);
    $sent = 0;
    foreach (db_all('SELECT * FROM mail_subscribers WHERE event_id = ?', [$eventId]) as $sub) {
        $body = t($isPoll ? 'ml_new_poll_body' : 'ml_new_game_body', $name, $when);
        if ($link !== '') $body .= "\n\n" . $link;
        $body .= mailing_unsub_footer($sub['token']);
        send_mail($sub['email'], t($isPoll ? 'ml_new_poll_subject' : 'ml_new_game_subject'), $body);
        $sent++;
    }
    return $sent;
}

/**
 * Deep link to one item on the event page. The fragment is what makes the
 * browser scroll to the card; front_event.php gives every game and poll a
 * matching id.
 * @param int    $dayIndex
 * @param string $anchor
 * @return string
 */
function mailing_item_url($dayIndex, $anchor) {
    $base = site_base_url();
    if ($base === '') return '';
    return rtrim($base, '/') . '/index.php?day=' . (int)$dayIndex . '#' . $anchor;
}

/**
 * The unsubscribe block appended to every mailing-list message. Returns '' when
 * no site URL is configured, rather than printing a dead link.
 * @param string $token
 * @return string
 */
function mailing_unsub_footer($token) {
    $url = mailing_unsub_url($token);
    return $url === '' ? '' : "\n\n---\n" . t('ml_unsub_line') . "\n" . $url;
}

/**
 * 'YYYY-MM-DD' -> 'DD.MM.YYYY', the format used everywhere else in the app.
 * @param string $date
 * @return string
 */
function mailing_format_date($date) {
    $ts = strtotime((string)$date);
    return $ts ? date('d.m.Y', $ts) : (string)$date;
}
