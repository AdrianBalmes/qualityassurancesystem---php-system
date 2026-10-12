<?php

require_once __DIR__ . "/lib/PHPMailer/Exception.php";
require_once __DIR__ . "/lib/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/lib/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Builds the message without sending it. Split out of email_send() so a test
 * can inspect the headers PHPMailer assembles (it stops at preSend(), which
 * never opens a socket) through exactly the code path a real send uses.
 * Throws on a malformed address; email_send() turns that into a result.
 */
function email_build_message(array $config, string $to, string $toName, string $subject, string $body): PHPMailer {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = $config['host'];
    $mail->Port       = (int) $config['port'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['username'];
    $mail->Password   = $config['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout    = 20;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($config['username'], $config['from_name']);
    $mail->addAddress($to, $toName);

    // Plain text by design. PHPMailer's secureHeader() strips CR/LF from the
    // subject and the recipient name, so neither can open a forged header.
    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body    = $body;

    return $mail;
}

/**
 * The only place that talks to SMTP.
 *
 * Always returns a result rather than throwing: the worker has to record the
 * reason against the row and carry on with the rest of the batch, not die
 * partway through and leave the queue half-drained.
 */
function email_send(array $config, string $to, string $toName, string $subject, string $body): array {
    if(!empty($config['dry_run'])){
        // Exercises everything but delivery -- the ONEDRIVE_DRY_RUN trick.
        return ['ok' => true, 'error' => ''];
    }

    if(trim((string) $config['username']) === '' || trim((string) $config['password']) === ''){
        return ['ok' => false, 'error' => 'No SMTP credentials: set EMAIL_USERNAME and EMAIL_APP_PASSWORD in .env'];
    }

    $mail = null;
    try {
        $mail = email_build_message($config, $to, $toName, $subject, $body);
        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch(Exception $e){
        // ErrorInfo carries the SMTP server's own reply, which is more useful
        // against the row than the exception's generic wording.
        $info = $mail ? $mail->ErrorInfo : '';
        return ['ok' => false, 'error' => $info !== '' ? $info : $e->getMessage()];
    } catch(Throwable $e){
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
