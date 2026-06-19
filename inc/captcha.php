<?php
/* =============================================================================
 *  inc/captcha.php — captcha for the add-game / add-poll-game forms.
 * -----------------------------------------------------------------------------
 *  Two implementations behind one interface:
 *    - If reCAPTCHA keys are configured in the admin Options, render + verify a
 *      Google reCAPTCHA widget (server-side verified over HTTP).
 *    - Otherwise fall back to a simple "{a} + {b} = ?" arithmetic question whose
 *      expected answer is stashed in the session.
 *  Both are only active when the use_captcha toggle is on.
 *
 *  USAGE in a form flow:
 *    echo captcha_html();                 // in the form template
 *    if (!captcha_verify()) { ...error... }   // in the POST handler
 *  When use_captcha is off, captcha_html() returns '' and captcha_verify()
 *  returns true, so callers need no special-casing.
 * ============================================================================= */

/**
 * Is a captcha required right now?
 * @return bool
 */
function captcha_required() {
    return opt_bool('use_captcha');
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
        // External widget script + container. (This is the vendor's own script,
        // not app inline-JS logic, so it's allowed under the no-inline-JS rule.)
        return '<div class="field">'
             . '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'
             . '<div class="g-recaptcha" data-sitekey="' . e(opt('captcha_site_key')) . '"></div>'
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
        return !empty($data['success']);
    }

    // Math fallback: compare against the sum stashed by captcha_html().
    $expected = $_SESSION['captcha_sum'] ?? null;
    $given    = trim($_POST['captcha'] ?? '');
    unset($_SESSION['captcha_sum']);          // one-shot: invalidate after checking
    return $expected !== null && $given !== '' && (int)$given === (int)$expected;
}
