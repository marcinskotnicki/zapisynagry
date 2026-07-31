<?php
/* =============================================================================
 *  inc/antibot.php — minimum-fill-time check + a honeypot field.
 * -----------------------------------------------------------------------------
 *  Two LIGHTWEIGHT companions to captcha, not a replacement for it: some
 *  admins don't turn reCAPTCHA on, and a plain arithmetic captcha (the usual
 *  alternative) is itself trivial for a scripted bot to solve.
 *
 *  1. TIMING — what almost no simple bot does is wait: it fetches a page and
 *     posts straight back. Every guarded form carries a hidden "when was this
 *     rendered" timestamp, and the handler rejects a submission that arrives
 *     faster than a human plausibly could have filled it in.
 *
 *     TWO BUCKETS, admin-configurable independently, because the honest
 *     minimum differs by task:
 *       'form'  — anything that needs the visitor to actually type something:
 *                 add/edit a game, sign up to play, add/edit a poll, add a
 *                 poll candidate, comment, message, register. Default 2s.
 *       'click' — a single button press with little or no typing: the delete
 *                 confirmations, removing a poll candidate, voting, logging
 *                 in. Login is in THIS bucket rather than 'form' on the
 *                 assumption a browser autofills the fields, so the 'form'
 *                 delay would be real friction for someone who genuinely
 *                 types fast. Default 1s.
 *     Either threshold set to 0 disables that check entirely.
 *
 *  2. HONEYPOT — a plain-looking text field ("Website"), hidden from sighted
 *     humans and screen readers alike, sitting in the same forms. A visitor
 *     never sees it and never fills it in; a bot that blindly fills every
 *     input it finds does. One admin-configurable on/off switch
 *     ('antibot_honeypot'), independent of the timing buckets — an admin might
 *     want either alone, or both together.
 *
 *  Both checks share one exemption: logged-in users, exactly like
 *  captcha_required() exempts them — an account is already a stronger signal
 *  than either of these.
 *
 *  WHAT THIS IS NOT: tamper-proofing. Both are deliberately as simple as the
 *  ask calls for — the same spirit as a basic arithmetic captcha. A targeted
 *  attacker who reads this file could forge an old timestamp or simply never
 *  populate the honeypot; the point is raising the bar for the generic,
 *  unsophisticated bots that just fetch-and-post or fill-every-field, not
 *  defeating a targeted attack. That's what reCAPTCHA is for, and all three
 *  are meant to complement each other, not compete.
 *
 *  USAGE, mirroring csrf_check()'s pattern:
 *    - templates: echo antibot_field() inside the guarded <form> — it renders
 *      BOTH the timestamp and the honeypot in one call.
 *    - controllers: call antibot_check('form') or antibot_check('click') as
 *      the FIRST line of the branch that actually performs the create /
 *      update / delete — not blanket at the top of a multi-purpose POST
 *      handler, so an adjacent no-op action (a "back" button, a "cancel")
 *      isn't held to a timing bar it has no reason to meet. The honeypot
 *      check runs inside the SAME call, so every one of the fifteen guarded
 *      flows picked it up the moment this file changed — no controller edits
 *      needed for it specifically.
 * ============================================================================= */

// Hidden field name carrying the render timestamp. Namespaced ("af_" = anti-
// flood) so it can never collide with a genuine form field.
const ANTIBOT_FIELD = 'af_ts';

// The honeypot's field name is deliberately NOT namespaced like the timestamp
// field above — an obviously technical name (af_something) is exactly what a
// bot built to specifically dodge honeypots would recognise and skip. A
// plain, plausible name is what invites a naive fill-every-field bot to give
// itself away. Checked against every real field name in the app's forms
// first, so it can never collide.
const ANTIBOT_HONEYPOT_FIELD = 'website';

/**
 * The minimum-delay setting for one bucket, in seconds.
 * @param string $bucket 'form' | 'click'
 * @return int  0 means the check is switched off for that bucket.
 */
function antibot_min_delay($bucket) {
    return $bucket === 'click' ? opt_int('antibot_delay_click') : opt_int('antibot_delay_form');
}

/**
 * The hidden field(s) to embed in a guarded form: the render timestamp, plus
 * the honeypot text field, one call doing both.
 *
 * Always emitted — even when a bucket's threshold is currently 0, the
 * honeypot is switched off, or the viewer is logged in — so a template never
 * has to know which case applies; that decision is made once, on submit,
 * inside antibot_check().
 *
 * THE HONEYPOT ITSELF: an ordinary-looking text field, hidden with an inline
 * style (not a stylesheet class) so it works even in a theme whose CSS
 * somehow doesn't load, and hidden with off-screen POSITIONING rather than
 * display:none — some bots specifically check computed visibility and skip
 * fields that are display:none or visibility:hidden, since that is the
 * textbook honeypot tell. tabindex="-1" keeps it out of the tab order for a
 * sighted keyboard user; aria-hidden="true" removes it from the accessibility
 * tree for a screen reader; autocomplete="off" discourages a browser or
 * password manager from populating it on a real visitor's behalf, which is
 * this technique's one real risk — an autofilled honeypot would look
 * identical to a bot's.
 *
 * @return string  Ready-to-echo HTML for both fields.
 */
function antibot_field() {
    $ts = '<input type="hidden" name="' . ANTIBOT_FIELD . '" value="' . time() . '">';
    $hp = '<div aria-hidden="true"'
        . ' style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;">'
        . '<label for="hp_' . ANTIBOT_HONEYPOT_FIELD . '">Website</label>'
        . '<input type="text" id="hp_' . ANTIBOT_HONEYPOT_FIELD . '" name="' . ANTIBOT_HONEYPOT_FIELD . '"'
        . ' tabindex="-1" autocomplete="off">'
        . '</div>';
    return $ts . $hp;
}

/**
 * Reject a submission that either trips the honeypot or arrived faster than a
 * human plausibly could have.
 *
 * Silently returns (no-op) when the visitor is logged in — both checks share
 * that one exemption. Otherwise:
 *   - HONEYPOT (checked first, independent of the bucket/timing logic below):
 *     if the feature is on and the honeypot field came back non-empty, reject
 *     immediately. A real visitor never sees or fills it in; something did.
 *   - TIMING: no-op if this bucket's delay is 0 (the admin turned it off).
 *     Otherwise ends the request if the elapsed time since the form was
 *     rendered is under the threshold, OR if the timestamp is missing or not
 *     a plain non-negative integer — failing CLOSED on purpose, since a
 *     request that never sent the field at all is at least as suspicious as
 *     one that sent a fresh one, and trusting "missing" would make the field
 *     trivial to defeat by simply not sending it.
 *
 * A plain exit(), matching this project's existing convention for guards that
 * should essentially never fire in ordinary use (compare the bare
 * exit('Unknown table.') on a malformed request elsewhere) — this isn't the
 * CSRF case, which gets a full themed page because session GC makes it a
 * routine, expected failure for real visitors; tripping either of these over
 * ordinary human behaviour should be rare to the point of not happening.
 *
 * @param string $bucket 'form' | 'click'
 * @return void
 */
function antibot_check($bucket) {
    if (is_logged_in()) return;

    // Deliberately NOT trimmed: the whole signal here is "did anything land in
    // a field a real visitor cannot see or reach at all" — a bot that pads
    // every field with a single space (a real, documented pattern) still
    // counts as suspicious, and trimming first would let exactly that through.
    if (opt_bool('antibot_honeypot')
        && (string)($_POST[ANTIBOT_HONEYPOT_FIELD] ?? '') !== '') {
        http_response_code(400);
        exit('Form submitted too quickly. Please go back and try again.');
    }

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
