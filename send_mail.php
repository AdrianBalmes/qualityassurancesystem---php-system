<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer-master/src/Exception.php';
require __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/PHPMailer-master/src/SMTP.php';

function sendLoginNotification($toEmail,$username){

$mail = new PHPMailer(true);

try {

    $mail->SMTPDebug = 2;
    $mail->Debugoutput = 'html';

    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;

    // ✅ FIXED EMAIL FORMAT
    $mail->Username = 'adrianbalmes211@gmail.com'; 
    $mail->Password = 'egaupbxglbvxifkz'; // your 16-digit app password

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('adrianbalmes211@gmail.com','EMS System');
    $mail->addAddress($toEmail);

    $mail->isHTML(true);
    $mail->Subject = 'Login Notification';
    $mail->Body = "Hello $username,<br>You have successfully logged into EMS.";

    $mail->send();

} catch (Exception $e) {
    // Do not crash login if email fails
    // You can echo error for testing:
    // echo "Mailer Error: " . $mail->ErrorInfo;
}

}
?>