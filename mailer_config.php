<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/PHPMailer-master/src/Exception.php";
require_once __DIR__ . "/PHPMailer-master/src/PHPMailer.php";
require_once __DIR__ . "/PHPMailer-master/src/SMTP.php";

function qaMailConfig()
{
    $username = getenv("QA_MAIL_USERNAME") ?: "";
    $password = getenv("QA_MAIL_APP_PASSWORD") ?: "";
    $fromEmail = getenv("QA_MAIL_FROM_EMAIL") ?: $username;

    return [
        "host" => "smtp.gmail.com",
        "port" => 587,
        "username" => $username,
        "password" => preg_replace('/\s+/', '', $password),
        "from_email" => $fromEmail,
        "from_name" => getenv("QA_MAIL_FROM_NAME") ?: "SBC Quality Assurance System",
    ];
}
function qaSendMail($toEmail, $toName, $subject, $htmlBody, $textBody = "")
{
    $toEmail = trim((string) $toEmail);
    if($toEmail === "" || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)){
        return false;
    }

    $config = qaMailConfig();
    if(trim($config["username"]) === "" || trim($config["password"]) === ""){
        error_log("QA mail not sent: Gmail username or app password is not configured.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config["host"];
        $mail->SMTPAuth = true;
        $mail->Username = $config["username"];
        $mail->Password = $config["password"];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) $config["port"];
        $mail->CharSet = "UTF-8";
        $mail->SMTPOptions = [
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
                "allow_self_signed" => true,
            ],
        ];

        $mail->setFrom($config["from_email"], $config["from_name"]);
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== "" ? $textBody : trim(strip_tags(str_replace(["<br>", "<br/>", "<br />"], "\n", $htmlBody)));

        return $mail->send();
    } catch (Exception $e) {
        error_log("QA mail error for {$toEmail}: " . $mail->ErrorInfo);
        return false;
    }
}

function qaMailLayout($heading, $contentHtml, $buttonText = "", $buttonUrl = "")
{
    $buttonHtml = "";
    if($buttonText !== "" && $buttonUrl !== ""){
        $safeButtonText = htmlspecialchars($buttonText, ENT_QUOTES);
        $safeButtonUrl = htmlspecialchars($buttonUrl, ENT_QUOTES);
        $buttonHtml = "<p style='margin:22px 0 4px'><a href='{$safeButtonUrl}' style='background:#316fc4;color:#ffffff;text-decoration:none;padding:10px 14px;border-radius:5px;font-weight:bold;display:inline-block'>{$safeButtonText}</a></p>";
    }

    return "
        <div style='font-family:Arial,Helvetica,sans-serif;color:#344156;line-height:1.5'>
            <div style='max-width:620px;margin:0 auto;border:1px solid #dbe3ef;border-radius:8px;overflow:hidden'>
                <div style='background:#316fc4;color:#ffffff;padding:16px 18px;font-size:18px;font-weight:bold'>SBC Quality Assurance System</div>
                <div style='padding:18px'>
                    <h2 style='margin:0 0 12px;font-size:20px;color:#26354b'>{$heading}</h2>
                    {$contentHtml}
                    {$buttonHtml}
                </div>
            </div>
        </div>
    ";
}

function sendDocumentUploadNotification($recipientEmail, $office, $title, $fileName, $dashboardUrl = "")
{
    $safeOffice = htmlspecialchars($office, ENT_QUOTES);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    $safeFileName = htmlspecialchars($fileName, ENT_QUOTES);

    $body = qaMailLayout(
        "New document submitted to QA",
        "
            <p>An office submitted a file for QA review.</p>
            <p><strong>Office:</strong> {$safeOffice}<br>
            <strong>Document:</strong> {$safeTitle}<br>
            <strong>File:</strong> {$safeFileName}<br>
            <strong>Status:</strong> Pending QA approval</p>
        ",
        $dashboardUrl !== "" ? "Open QA Dashboard" : "",
        $dashboardUrl
    );

    $text = "New document submitted to QA\nOffice: {$office}\nDocument: {$title}\nFile: {$fileName}\nStatus: Pending QA approval\n{$dashboardUrl}";

    return qaSendMail($recipientEmail, "QA", "New QA Document Submission - {$office}", $body, $text);
}

function sendDocumentDecisionNotification($recipientEmail, $office, $title, $fileName, $decision, $documentUrl = "")
{
    $safeOffice = htmlspecialchars($office, ENT_QUOTES);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    $safeFileName = htmlspecialchars($fileName, ENT_QUOTES);
    $safeDecision = htmlspecialchars($decision, ENT_QUOTES);

    $body = qaMailLayout(
        "Document {$safeDecision}",
        "
            <p>QA has updated the approval status of your submitted document.</p>
            <p><strong>Office:</strong> {$safeOffice}<br>
            <strong>Document:</strong> {$safeTitle}<br>
            <strong>File:</strong> {$safeFileName}<br>
            <strong>Decision:</strong> {$safeDecision}</p>
        ",
        $documentUrl !== "" ? "Open Office Dashboard" : "",
        $documentUrl
    );

    return qaSendMail($recipientEmail, $office, "QA Document {$decision} - {$title}", $body);
}

function sendFeedbackNotification($recipientEmail, $office, $title, $fileName, $message, $feedbackUrl = "")
{
    $safeOffice = htmlspecialchars($office, ENT_QUOTES);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    $safeFileName = htmlspecialchars($fileName, ENT_QUOTES);
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES));

    $body = qaMailLayout(
        "New QA feedback",
        "
            <p>QA sent feedback for a submitted document.</p>
            <p><strong>Office:</strong> {$safeOffice}<br>
            <strong>Document:</strong> {$safeTitle}<br>
            <strong>File:</strong> {$safeFileName}</p>
            <div style='border-left:4px solid #316fc4;background:#f1f5fb;padding:10px 12px'>{$safeMessage}</div>
        ",
        $feedbackUrl !== "" ? "Open Feedback" : "",
        $feedbackUrl
    );

    return qaSendMail($recipientEmail, $office, "New QA Feedback - {$title}", $body);
}

function sendLoginNotification($recipientEmail, $username)
{
    $safeUsername = htmlspecialchars($username, ENT_QUOTES);
    $safeDate = date("Y-m-d H:i:s");

    $body = qaMailLayout(
        "Account login notification",
        "
            <p>Your QA system account was used to sign in.</p>
            <p><strong>Username:</strong> {$safeUsername}<br>
            <strong>Date/Time:</strong> {$safeDate}</p>
        "
    );

    return qaSendMail($recipientEmail, $username, "QA System Login Notification", $body);
}
