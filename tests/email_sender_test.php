<?php

// Command line only. tests/ is denied in .htaccess as well, for the same
// reason tools/ is: every file here runs against the live ems_db,
// so reached over the web they would let anyone alter it.
if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}
require_once __DIR__ . "/_assert.php";
require_once __DIR__ . "/../email_sender.php";

echo "email_sender\n";

// Nothing here may open a socket: the dry-run and missing-credentials paths
// return before PHPMailer is built, and the header checks below stop at
// preSend(), which assembles the message but never connects. The credentialed
// email_send() calls all use input PHPMailer rejects while building (no
// recipient name, a malformed address), before any connection is attempted.

// Dry run must report success without touching the network.
$dry = ['enabled' => true, 'dry_run' => true, 'host' => 'smtp.gmail.com', 'port' => 587,
        'username' => '', 'password' => '', 'from_name' => 'SBC Quality Assurance',
        'max_attempts' => 5, 'batch_size' => 20, 'site_url' => 'https://example.invalid'];
$result = email_send($dry, 'nobody@example.invalid', 'Nobody', 'Subject', 'Body');
t_equals('dry run succeeds', $result['ok'], true);
t_equals('dry run reports no error', $result['error'], '');

// Missing credentials must fail clearly rather than hang on a connection.
$live = array_merge($dry, ['dry_run' => false]);
$missing = email_send($live, 'nobody@example.invalid', 'Nobody', 'Subject', 'Body');
t_equals('no credentials fails', $missing['ok'], false);
t_ok('and says why', stripos($missing['error'], 'credential') !== false);

// email_outbox.body and recipient_name can be NULL. That must come back as a
// result for the worker to record, not a TypeError that kills the batch.
// (No credentials, so this returns before any connection is made.)
try {
    $nulls = email_send($live, 'nobody@example.invalid', null, 'Subject', null);
} catch(Throwable $e){
    $nulls = 'threw ' . get_class($e);
}
t_ok('NULL name and body return a result rather than throwing', is_array($nulls) && $nulls['ok'] === false);

// The same NULLs with credentials present reach the build step, where the
// empty body is refused by PHPMailer before it would connect.
$withCreds = array_merge($live, ['username' => 'sender@example.invalid', 'password' => 'not-a-real-password']);
try {
    $nullsBuilt = email_send($withCreds, 'nobody@example.invalid', null, 'Subject', null);
} catch(Throwable $e){
    $nullsBuilt = 'threw ' . get_class($e);
}
t_ok('NULL name and body with credentials also return a result',
    is_array($nullsBuilt) && $nullsBuilt['ok'] === false && $nullsBuilt['error'] !== '');

// An unusable credential value is a result too, not an Error from the check.
try {
    $odd = email_send(array_merge($live, ['username' => new stdClass()]), 'nobody@example.invalid', 'Nobody', 'Subject', 'Body');
} catch(Throwable $e){
    $odd = 'threw ' . get_class($e);
}
t_ok('an unconvertible credential returns a result rather than throwing', is_array($odd) && $odd['ok'] === false);

// A subject or recipient name carrying CR/LF must not be able to start a new
// header (a forged Bcc would silently copy the mail to a stranger). Build the
// message exactly as email_send() does and read back what PHPMailer produced.

/** The header block of the full message, split into lines. */
function t_header_lines($mail){
    $mail->preSend();
    // The whole message, not getMailMIME(): that holds only the Content-Type part,
    // and the Subject/To lines live in the header PHPMailer builds separately, so
    // a check against it would pass whether or not an injection got through.
    $message = $mail->getSentMIMEMessage();
    return explode("\r\n", substr($message, 0, strpos($message, "\r\n\r\n")));
}

$mail   = email_build_message($withCreds, 'nobody@example.invalid', 'Nobody', "Hi\r\nBcc: attacker@example.invalid", 'Body');
$lines  = t_header_lines($mail);
$header = implode("\r\n", $lines);

t_ok('subject is still sent', strpos($header, "Subject: Hi") !== false);
t_ok('subject CR/LF does not start a header', !preg_grep('/^Bcc:/i', $lines));
t_equals('CR/LF is removed, leaving one flat Subject line', array_values(preg_grep('/^Subject:/', $lines)), ["Subject: HiBcc: attacker@example.invalid"]);

// The recipient layer is separate from the subject's: addAddress() strips
// line breaks from the name. A regression there would let a name open a header.
$mail  = email_build_message($withCreds, 'nobody@example.invalid', "Nobody\r\nBcc: attacker@example.invalid", 'Subject', 'Body');
$lines = t_header_lines($mail);
t_ok('name CR/LF does not start a header', !preg_grep('/^Bcc:/i', $lines));
t_equals('name CR/LF leaves one To line', count(preg_grep('/^To:/', $lines)), 1);
t_equals('name CR/LF leaves one recipient', array_keys($mail->getAllRecipientAddresses()), ['nobody@example.invalid']);
t_equals('name CR/LF adds no Bcc recipient', $mail->getBccAddresses(), []);

// An address with a line break is not a smuggling route either: PHPMailer
// refuses it outright, so it never becomes a recipient or a header.
$bad = "nobody@example.invalid\r\nBcc: attacker@example.invalid";
$rejected = null;
try {
    email_build_message($withCreds, $bad, 'Nobody', 'Subject', 'Body');
} catch(Throwable $e){
    $rejected = $e;
}
t_ok('address CR/LF is rejected when building', $rejected !== null);
$sent = email_send($withCreds, $bad, 'Nobody', 'Subject', 'Body');
t_equals('and email_send reports it as a failure', $sent['ok'], false);

// The same message must be plain text, as the bodies are written to be.
t_ok('message is plain text', strpos($header, 'Content-Type: text/plain') !== false);

// 465 is implicit TLS, 587 is STARTTLS; the wrong one hangs until the timeout.
t_equals('port 465 uses implicit TLS',
    email_build_message(array_merge($withCreds, ['port' => 465]), 'nobody@example.invalid', 'Nobody', 'Subject', 'Body')->SMTPSecure, 'ssl');
t_equals('port 587 uses STARTTLS',
    email_build_message(array_merge($withCreds, ['port' => 587]), 'nobody@example.invalid', 'Nobody', 'Subject', 'Body')->SMTPSecure, 'tls');

t_summary();
