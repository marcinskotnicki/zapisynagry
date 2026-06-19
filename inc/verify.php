<?php
/* =============================================================================
 *  inc/verify.php — edit/delete permission + verification.
 * -----------------------------------------------------------------------------
 *  Encapsulates the agreed tree for who may edit/delete a game or a player, and
 *  what challenge (if any) a non-owner must pass. Shared by player removal,
 *  game edit, and game delete.
 *
 *  A "target" is identified by:
 *    $ownerUserId : the account that created it (NULL if added while unregistered)
 *    $email       : the email stored on it (may be NULL/'')
 *
 *  Decision values returned by verify_decision():
 *    'allow'       : proceed, no challenge
 *    'deny'        : not permitted at all
 *    'email_code'  : must enter a 6-digit code we email to $email
 *    'email_match' : must retype $email (case-insensitive)
 *
 *  HOW THE PIECES FIT TOGETHER in a controller:
 *    1. verify_can_show_buttons($ownerId)  -> decide whether to render edit/delete
 *    2. verify_decision($ownerId, $email)  -> 'allow'|'deny'|'email_code'|'email_match'
 *    3. for a challenge, render the gate; on submit call verify_passes(...)
 *    The two leaf checks (verify_check_email / verify_check_code) are wrapped by
 *    verify_passes() so callers don't branch on the method themselves.
 *
 *  WHY THIS DESIGN: content added by people who weren't logged in has no account
 *  to tie ownership to, so the admin chooses how strangers prove they're the one
 *  who added it (retype the email, or a one-time emailed code), or whether to
 *  require login, or to allow anyone. Registered content is simply owner-only.
 * ============================================================================= */

/**
 * Current user's id, or null.
 * Thin wrapper so the rest of the file reads cleanly.
 * @return int|null
 */
function verify_uid() {
    return current_user()['id'] ?? null;
}

/**
 * Should the edit/delete buttons even be shown for this target?
 *   - admins: yes (anything)
 *   - unregistered-added content ($ownerUserId === null): yes for everyone — the
 *     buttons are visible, but the *action* is still gated by verify_decision().
 *   - registered-added content: only the owner.
 *
 * This governs VISIBILITY only; it never authorises the action by itself.
 *
 * @param int|null $ownerUserId
 * @return bool
 */
function verify_can_show_buttons($ownerUserId) {
    if (is_admin()) return true;
    if ($ownerUserId === null) return true;
    $uid = verify_uid();
    return $uid !== null && (int)$ownerUserId === (int)$uid;
}

/**
 * Decide what's required to act on the target. See header for return values.
 *
 * Order of reasoning:
 *   1. Admins do anything.
 *   2. Registered-added content -> owner only (no challenge), everyone else denied.
 *   3. Unregistered-added content -> follow the admin's verification_method,
 *      but if there's no email stored there's nothing to verify against, so allow.
 *
 * @param int|null    $ownerUserId
 * @param string|null $email
 * @return string  'allow' | 'deny' | 'email_code' | 'email_match'
 */
function verify_decision($ownerUserId, $email) {
    if (is_admin()) return 'allow';

    // Registered-added content: only the owner, no challenge; nobody else.
    if ($ownerUserId !== null) {
        return ((int)$ownerUserId === (int)verify_uid()) ? 'allow' : 'deny';
    }

    // Unregistered-added content: governed by the admin's verification method.
    $method = opt('verification_method');
    if ($method === 'none')                return 'allow';
    if ($email === null || $email === '')  return 'allow';   // nothing to verify against
    if ($method === 'registered')          return is_logged_in() ? 'allow' : 'deny';
    if ($method === 'email_code')          return 'email_code';
    if ($method === 'email_match')         return 'email_match';
    return 'allow';   // unknown/blank method -> permissive default
}

/* ---- email_match --------------------------------------------------------- *
 * The challenger retypes the email that's on the target. We compare case-
 * insensitively and trimmed, so "Bob@X.io " matches "bob@x.io".
 * --------------------------------------------------------------------------- */

/**
 * Case-insensitive match of the retyped email against the stored one.
 * Returns false if there's no stored email (can't match against nothing).
 * @return bool
 */
function verify_check_email($storedEmail, $inputEmail) {
    return $storedEmail !== null && $storedEmail !== ''
        && strcasecmp(trim((string)$inputEmail), trim((string)$storedEmail)) === 0;
}

/* ---- email_code ---------------------------------------------------------- *
 * We email a 6-digit code to the stored address; the challenger types it back.
 * Codes live in the verification_codes table, scoped by (target_type, target_id)
 * and expire after 30 minutes.
 * --------------------------------------------------------------------------- */

/**
 * Generate, store, and email a 6-digit code for a target. Returns the code.
 *
 * Deletes any earlier codes for the same target first, so only the most recent
 * code can be used (re-requesting invalidates the old one).
 *
 * @param string $targetType  e.g. 'game'.
 * @param int    $targetId    The game id.
 * @param string $email       Where to send it (the stored address).
 * @return string  The generated code (also returned for tests/logging).
 */
function verify_send_code($targetType, $targetId, $email) {
    // Clear any earlier codes for this target so only the latest is valid.
    db_run('DELETE FROM verification_codes WHERE target_type = ? AND target_id = ?',
           [$targetType, $targetId]);

    $code    = sprintf('%06d', random_int(0, 999999));   // zero-padded 6 digits
    $expires = gmdate('Y-m-d H:i:s', time() + 1800);     // 30 minutes (UTC, matches DB)
    db_run('INSERT INTO verification_codes (target_type, target_id, email, code, expires_at)
            VALUES (?,?,?,?,?)', [$targetType, $targetId, $email, $code, $expires]);

    send_mail($email, t('verify_email_subject'), t('verify_email_body', $code));
    return $code;
}

/**
 * Verify a submitted code; consume all codes for the target on success.
 *
 * The WHERE clause checks target, email, exact code, AND non-expiry in one shot.
 * On success we delete every code for the target so a code can't be replayed.
 *
 * @return bool
 */
function verify_check_code($targetType, $targetId, $email, $code) {
    $row = db_one(
        'SELECT id FROM verification_codes
         WHERE target_type = ? AND target_id = ? AND email = ? AND code = ? AND expires_at >= ?',
        [$targetType, $targetId, $email, trim((string)$code), gmdate('Y-m-d H:i:s')]
    );
    if (!$row) return false;
    db_run('DELETE FROM verification_codes WHERE target_type = ? AND target_id = ?',
           [$targetType, $targetId]);                    // one-shot: consume on success
    return true;
}

/**
 * Convenience: given a decision and the POSTed challenge fields, did the user
 * pass? ('allow' always passes here; 'deny' never reaches this.)
 *
 * Reads the two challenge inputs by their form field names:
 *   email_match -> $post['vemail'],  email_code -> $post['vcode'].
 *
 * @param string $decision   From verify_decision().
 * @param string $targetType
 * @param int    $targetId
 * @param string $email      Stored email on the target.
 * @param array  $post       Usually $_POST.
 * @return bool
 */
function verify_passes($decision, $targetType, $targetId, $email, $post) {
    switch ($decision) {
        case 'allow':       return true;
        case 'email_match': return verify_check_email($email, $post['vemail'] ?? '');
        case 'email_code':  return verify_check_code($targetType, $targetId, $email, $post['vcode'] ?? '');
        default:            return false;   // 'deny' or unknown
    }
}
