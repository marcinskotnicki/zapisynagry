<?php
/* =============================================================================
 *  inc/mail.php — outgoing mail.
 * -----------------------------------------------------------------------------
 *  send_mail() uses PHPMailer over SMTP when an SMTP server is configured in the
 *  admin Options; otherwise it falls back to PHP's mail(). The signature is the
 *  one the rest of the app already calls, so nothing else changed.
 *
 *  This is the raw TRANSPORT only. Whether a given message *should* be sent is
 *  decided elsewhere: notifications gate on the 'send_emails' toggle (see
 *  inc/notify.php), while transactional mail (verification codes, password reset
 *  links) calls send_mail() directly because the user explicitly requested it.
 *
 *  CONFIG (admin Options): email_smtp_server, email_smtp_port, email_login,
 *  email_password, email_address (From), venue_name (From display name).
 *  Leave email_smtp_server blank to use the mail() fallback.
 * ============================================================================= */

// Vendored PHPMailer (only the three core files are shipped under vendor/). We
// load them at include time if present; the SMTP path below also guards with
// class_exists() so a missing vendor dir simply means "use mail()".
$phpmailerDir = __DIR__ . '/../vendor/phpmailer/src/';
if (is_file($phpmailerDir . 'PHPMailer.php')) {
    require_once $phpmailerDir . 'Exception.php';
    require_once $phpmailerDir . 'PHPMailer.php';
    require_once $phpmailerDir . 'SMTP.php';
}

/**
 * Send a plain-text email. Returns true on (apparent) success.
 *
 * @param string      $to       Recipient address (validated below).
 * @param string      $subject  Subject line (UTF-8).
 * @param string      $body     Plain-text body (UTF-8).
 * @param string|null $replyTo  Optional Reply-To (used by player messaging so
 *                              replies go to the sender, not the From address).
 * @return bool  True on success; false on invalid address or send failure.
 */
function send_mail($to, $subject, $body, $replyTo = null) {
    // Reject obviously bad addresses up front — also guards the loops in notify.
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $smtpHost = opt('email_smtp_server');

    // ---- Preferred path: SMTP via PHPMailer --------------------------------
    // Only when a server is configured AND the vendored class actually loaded.
    if ($smtpHost !== '' && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);   // true = throw on error
            $mail->isSMTP();
            $mail->Host    = $smtpHost;
            $mail->Port    = opt_int('email_smtp_port', 587);  // 587 = STARTTLS default
            $mail->CharSet = 'UTF-8';

            // Authenticate only if a login is configured (some relays are open).
            $login = opt('email_login');
            if ($login !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $login;
                $mail->Password = opt('email_password');
            }
            // Port decides the encryption mode:
            //   465 = implicit TLS (SMTPS); anything else = STARTTLS.
            $mail->SMTPSecure = ((int)$mail->Port === 465)
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

            // From: configured address (or the login if no address is set), with
            // the venue name as the display name.
            $from = opt('email_address') ?: $login;
            $mail->setFrom($from, opt('venue_name') ?: '');
            $mail->addAddress($to);
            if ($replyTo) $mail->addReplyTo($replyTo);

            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->send();
            return true;
        } catch (Throwable $ex) {
            // SMTP failed; we deliberately DON'T silently fall back to mail()
            // (that would likely fail too and mask the real misconfiguration).
            return false;
        }
    }

    // ---- Fallback: PHP mail() ----------------------------------------------
    // Used when no SMTP server is configured (or PHPMailer isn't present).
    $from = opt('email_address');
    $headers = [];
    if ($from !== '')   $headers[] = 'From: ' . $from;
    if ($replyTo)       $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'MIME-Version: 1.0';

    // RFC 2047 encode the subject so non-ASCII (e.g. Polish) survives transit.
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    // @-suppressed: a missing local MTA shouldn't emit a warning into the page.
    return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}
