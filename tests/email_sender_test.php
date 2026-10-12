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
// preSend(), which assembles the message but never connects.

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

// A subject or recipient name carrying CR/LF must not be able to start a new
// header (a forged Bcc would silently copy the mail to a stranger). Build the
// message exactly as email_send() does and read back what PHPMailer produced.
$withCreds = array_merge($live, ['username' => 'sender@example.invalid', 'password' => 'not-a-real-password']);
$mail = email_build_message($withCreds, 'nobody@example.invalid', "Nobody\r\nBcc: attacker@example.invalid",
                            "Hi\r\nBcc: attacker@example.invalid", 'Body');
$mail->preSend();
// The whole message, not getMailMIME(): that holds only the Content-Type part,
// and the Subject/To lines live in the header PHPMailer builds separately, so
// a check against it would pass whether or not an injection got through.
$message = $mail->getSentMIMEMessage();
$header  = substr($message, 0, strpos($message, "\r\n\r\n"));
$lines  = preg_split('/\r\n|\r|\n/', $header);

t_ok('subject is still sent', strpos($header, "Subject: Hi") !== false);
t_ok('subject CR/LF does not start a header', !preg_grep('/^Bcc:/i', $lines));
t_equals('CR/LF is removed, leaving one flat Subject line', array_values(preg_grep('/^Subject:/', $lines)), ["Subject: HiBcc: attacker@example.invalid"]);
t_equals('only one recipient is addressed', array_keys($mail->getAllRecipientAddresses()), ['nobody@example.invalid']);
t_ok('no Bcc recipient was smuggled in', count($mail->getBccAddresses()) === 0);

// The same message must be plain text, as the bodies are written to be.
t_ok('message is plain text', strpos($header, 'Content-Type: text/plain') !== false);

t_summary();
