<?php
/* =============================================================================
 *  inc/antibot.php — minimum-fill-time anti-spam check.
 * -----------------------------------------------------------------------------
 *  A LIGHTWEIGHT companion to captcha, not a replacement for it: some admins
 *  don't turn reCAPTCHA on, and a plain arithmetic captcha (the usual
 *  alternative) is itself trivial for a scripted bot to solve. What almost no
 *  simple bot does is wait — it fetches a page and posts straight back. So
 *  every guarded form carries a hidden "when was this rendered" timestamp, and
 *  the handler rejects a submission that arrives faster than a human plausibly
 *  could have filled it in.
 *
 *  TWO BUCKETS, admin-configurable independently, because the honest minimum
 *  differs by task:
 *    'form'  — anything that needs the visitor to actually type something:
 *              add/edit a game, sign up to play, add/edit a poll, add a poll
 *              candidate, comment, message, register. Default 2s.
 *    'click' — a single button press with little or no typing: the delete
 *              confirmations, removing a poll candidate, voting, logging in.
 *              Login is in THIS bucket rather than 'form' on the assumption a
 *              browser autofills the fields, so the 'form' delay would be
 *              real friction for someone who genuinely types fast. Default 1s.
 *  Either threshold set to 0 disables that check entirely.
 *
 *  EXEMPT: logged-in users, exactly like captcha_required() exempts them — an
 *  account is already a stronger signal than form-fill timing.
 *
 *  WHAT THIS IS NOT: tamper-proofing. It's deliberately as simple as the ask
 *  calls for — the same spirit as a basic arithmetic captcha. A targeted
 *  attacker who reads this file could forge an old timestamp; the point is
 *  raising the bar for the generic, unsophisticated bots that just
 *  fetch-and-post, not defeating a targeted attack. That's what reCAPTCHA is
 *  for, and the two are meant to complement each other, not compete.
 *
 *  USAGE, mirroring csrf_check()'s pattern:
 *    - templates: echo antibot_field() inside the guarded <form>.
 *    - controllers: call antibot_check('form') or antibot_check('click') as
 *      the FIRST line of the branch that actually performs the create /
 *      update / delete — not blanket at the top of a multi-purpose POST
 *      handler, so an adjacent no-op action (a "back" button, a "cancel")
 *      isn't held to a timing bar it has no reason to meet.
 * ============================================================================= */

// Hidden field name carrying the render timestamp. Namespaced ("af_" = anti-
// flood) so it can never collide with a genuine form field.
const ANTIBOT_FIELD = 'af_ts';

/**
 * The minimum-delay setting for one bucket, in seconds.
 * @param string $bucket 'form' | 'click'
 * @return int  0 means the check is switched off for that bucket.
 */
function antibot_min_delay($bucket) {
    return $bucket === 'click' ? opt_int('antibot_delay_click') : opt_int('antibot_delay_form');
}

/**
 * The hidden field to embed in a guarded form, stamped with THIS render's time.
 *
 * Always emitted — even when a bucket's threshold is currently 0, or the
 * viewer is logged in — so a template never has to know which case applies;
 * that decision is made once, on submit, inside antibot_check().
 * @return string  A ready-to-echo <input> tag.
 */
function antibot_field() {
    return '<input type="hidden" name="' . ANTIBOT_FIELD . '" value="' . time() . '">';
}

/**
 * Reject a submission that arrived faster than a human plausibly could have.
 *
 * Silently returns (no-op) when:
 *   - the visitor is logged in, or
 *   - the bucket's configured delay is 0 (the admin turned it off).
 * Otherwise ends the request immediately if either:
 *   - the elapsed time since the form was rendered is under the threshold, or
 *   - the timestamp is missing or not a plain non-negative integer.
 * The second case fails CLOSED on purpose: a request that never sent the
 * field at all is at least as suspicious as one that sent a fresh one, and
 * treating "missing" as "trust it" would make the field trivial to defeat by
 * simply not sending it.
 *
 * A plain exit(), matching this project's existing convention for guards that
 * should essentially never fire in ordinary use (compare the bare
 * exit('Unknown table.') on a malformed request elsewhere) — this isn't the
 * CSRF case, which gets a full themed page because session GC makes it a
 * routine, expected failure for real visitors; tripping this one over
 * ordinary human typing speed should be rare to the point of not happening.
 *
 * @param string $bucket 'form' | 'click'
 * @return void
 */
function antibot_check($bucket) {
    if (is_logged_in()) return;
    $min = antibot_min_delay($bucket);
    if ($min <= 0) return;

    $sent = $_POST[ANTIBOT_FIELD] ?? '';
    if (!is_string($sent) || $sent === '' || !ctype_digit($sent)) {
        http_response_code(400);
        exit('Form submitted too quickly. Please go back and try again.');
    }
    if (time() - (int)$sent < $min) {
        http_response_code(400);
        exit('Form submitted too quickly. Please go back and try again.');
    }
}
