<?php
/* =============================================================================
 *  inc/captcha.php — captcha for the add-game / add-poll-game forms.
 * -----------------------------------------------------------------------------
 *  Two implementations behind one interface:
 *    - If reCAPTCHA keys are configured in the admin Options, render + verify a
 *      Google reCAPTCHA widget (server-side verified over HTTP). The
 *      captcha_version option picks v2 (visible checkbox) or v3 (invisible,
 *      score-based — needs js/scripts.js to mint a token on submit).
 *    - Otherwise fall back to a simple "{a} + {b} = ?" arithmetic question whose
 *      expected answer is stashed in the session.
 *  Both are only active when the use_captcha toggle is on, and only for GUESTS
 *  — logged-in users are skipped (see captcha_required()).
 *
 *  USAGE in a form flow:
 *    echo captcha_html();                 // in the form template
 *    if (!captcha_verify()) { ...error... }   // in the POST handler
 *  When use_captcha is off, captcha_html() returns '' and captcha_verify()
 *  returns true, so callers need no special-casing.
 * ============================================================================= */

/**
 * Is a captcha required right now?
 *
 * Only for guests: someone with an account has already proven they're human
 * (registration itself is captcha'd), so making them tick a box on every game
 * they add is friction for no gain. Registration and the guest-messaging form
 * are unaffected — nobody is logged in at that point.
 *
 * Both captcha_html() and captcha_verify() key off this, so a logged-in user
 * sees no widget AND passes verification — the two stay in step.
 *
 * @return bool
 */
function captcha_required() {
    if (!opt_bool('use_captcha')) return false;   // feature off entirely
    if (is_logged_in()) return false;             // registered account -> trusted
    return true;
}

/**
 * True if reCAPTCHA is configured (both keys present).
 * Decides which of the two implementations is in play.
 * @return bool
 */
function captcha_is_recaptcha() {
    return opt('captcha_site_key') !== '' && opt('captcha_secret_key') !== '';
}

/**
 * Which reCAPTCHA the configured keys belong to: 'v2' (checkbox) or 'v3'
 * (invisible, score-based). Anything unrecognised is treated as v2, which is
 * the safe default because it degrades to a visible challenge rather than a
 * silent score check.
 *
 * NOTE: key types are not interchangeable — a v3 key rendered as a v2 widget
 * produces Google's "Invalid key type" error on the page (and vice versa), so
 * this must match the key you created in the reCAPTCHA console.
 *
 * @return string  'v2' | 'v3'
 */
function captcha_version() {
    return opt('captcha_version') === 'v3' ? 'v3' : 'v2';
}

/**
 * The v3 score (0.0-1.0) at or above which a submission counts as human.
 * Clamped to a sane range; 0.5 is Google's usual starting point.
 * @return float
 */
function captcha_v3_threshold() {
    $t = (float)opt('captcha_v3_threshold');
    if ($t <= 0 || $t > 1) $t = 0.5;      // empty/garbage/out-of-range -> default
    return $t;
}

/**
 * HTML to embed in a form. For the math fallback it also (re)seeds the expected
 * answer in the session. Returns '' when no captcha is required.
 *
 * NOTE the side effect: each call to render the math captcha generates a NEW
 * sum and overwrites the session answer. Call it once per form render.
 *
 * @return string  HTML (possibly empty).
 */
function captcha_html() {
    if (!captcha_required()) return '';

    if (captcha_is_recaptcha()) {
        $key = e(opt('captcha_site_key'));
        if (captcha_version() === 'v3') {
            // v3 is invisible: no widget, just the vendor script plus a hidden
            // field that js/scripts.js fills with a fresh token on submit
            // (tokens expire after ~2 minutes, so we mint one at submit time).
            // Same field name as v2 so captcha_verify() stays shared.
            return '<div class="field">'
                 . '<script src="https://www.google.com/recaptcha/api.js?render=' . $key . '" async defer></script>'
                 . '<input type="hidden" name="g-recaptcha-response" class="recaptcha-v3-token"'
                 . ' data-sitekey="' . $key . '" data-action="submit">'
                 . '</div>';
        }
        // v2 checkbox: the vendor's own script renders the widget in this div.
        // (Vendor script, not app inline-JS, so it's fine under the no-inline-JS rule.)
        return '<div class="field">'
             . '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'
             . '<div class="g-recaptcha" data-sitekey="' . $key . '"></div>'
             . '</div>';
    }

    // Math fallback: pick two small numbers, remember the sum in the session.
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['captcha_sum'] = $a + $b;
    return '<div class="field"><label for="captcha">'
         . e(t('captcha_question', $a, $b))
         . '</label><input type="text" id="captcha" name="captcha" inputmode="numeric" required></div>';
}

/**
 * Verify the submitted captcha. Returns true when not required, or on success.
 * Returns false on any failure (missing answer, wrong answer, reCAPTCHA reject).
 * @return bool
 */
function captcha_verify() {
    if (!captcha_required()) return true;

    if (captcha_is_recaptcha()) {
        // Ask Google's siteverify endpoint whether the token the browser got is
        // valid for our secret key. No token at all -> immediate fail.
        // Both versions use this same endpoint and POST field; v3 additionally
        // returns a score we compare against the configured threshold.
        $resp = $_POST['g-recaptcha-response'] ?? '';
        if ($resp === '') return false;
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);   // return body, don't echo it
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret'   => opt('captcha_secret_key'),
            'response' => $resp,
            'remoteip' => client_ip(),
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);             // don't hang the request
        $out = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string)$out, true);
        if (empty($data['success'])) return false;

        if (captcha_version() === 'v3') {
            // Score-based: Google always "succeeds" for a well-formed token and
            // tells us how human it looked (0.0 = bot, 1.0 = human). A missing
            // score means the key isn't actually a v3 key, so fail closed.
            if (!isset($data['score'])) return false;
            return (float)$data['score'] >= captcha_v3_threshold();
        }
        return true;
    }

    // Math fallback: compare against the sum stashed by captcha_html().
    $expected = $_SESSION['captcha_sum'] ?? null;
    $given    = trim($_POST['captcha'] ?? '');
    unset($_SESSION['captcha_sum']);          // one-shot: invalidate after checking
    return $expected !== null && $given !== '' && (int)$given === (int)$expected;
}
