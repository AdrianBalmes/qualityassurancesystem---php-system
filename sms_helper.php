<?php
function ensureSmsOutboxTable($conn){
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sms_outbox (
        id int(11) NOT NULL AUTO_INCREMENT,
        phone varchar(30) NOT NULL,
        message text NOT NULL,
        status varchar(30) NOT NULL DEFAULT 'pending',
        created_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function sendPasswordResetOtpText($conn, $phone, $otp){
    $phone = trim((string)$phone);
    $message = "Your QA System password reset OTP is {$otp}. It expires in 10 minutes.";

    if($phone === ''){
        return false;
    }

    ensureSmsOutboxTable($conn);
    $stmt = $conn->prepare("INSERT INTO sms_outbox (phone, message, status) VALUES (?, ?, 'pending')");
    $stmt->bind_param("ss", $phone, $message);
    $stmt->execute();

    error_log("Password reset OTP for {$phone}: {$otp}");
    return true;
}
?>
