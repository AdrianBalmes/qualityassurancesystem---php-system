<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/user_columns.php";

ensure_password_resets_table($conn);

$resetId = isset($_GET['reset_id']) ? intval($_GET['reset_id']) : 0;
$message = "";
$error = "";
$reset = null;

if($resetId > 0){
    $stmt = $conn->prepare("SELECT pr.id, pr.user_id, u.username FROM password_resets pr INNER JOIN users u ON pr.user_id = u.id WHERE pr.id = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() LIMIT 1");
    $stmt->bind_param("i", $resetId);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
}

if(!$reset){
    $error = "OTP request is invalid or expired.";
}

if($reset && isset($_POST['reset_password'])){
    $otp = trim($_POST['otp'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    // Check the new password first, so a typo there does not cost a guess.
    $formError = "";
    if($otp === ""){
        $formError = "Enter the OTP sent to your phone.";
    } elseif($newPassword === ""){
        $formError = "Enter a new password.";
    } elseif(strlen($newPassword) < 8){
        // Same minimum as registration.
        $formError = "Password must be at least 8 characters.";
    } elseif($newPassword !== $confirmPassword){
        $formError = "New password and confirmation do not match.";
    }

    $otpIsValid = false;
    $attemptClaimed = false;
    if($formError === ""){
        // Take one attempt before looking at the code. Doing it in a single
        // conditional UPDATE means parallel guesses cannot slip past the limit.
        $maxAttempts = PASSWORD_RESET_MAX_ATTEMPTS;
        $claim = $conn->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < ?");
        $claim->bind_param("ii", $reset['id'], $maxAttempts);
        $claim->execute();
        $attemptClaimed = $claim->affected_rows === 1;

        if($attemptClaimed){
            $otpHash = hash('sha256', $otp);
            $verify = $conn->prepare("SELECT id FROM password_resets WHERE id = ? AND token_hash = ? LIMIT 1");
            $verify->bind_param("is", $reset['id'], $otpHash);
            $verify->execute();
            $otpIsValid = $verify->get_result()->num_rows === 1;
        } else {
            // Out of attempts: retire the code so it can never be used.
            $burn = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
            $burn->bind_param("i", $reset['id']);
            $burn->execute();
        }
    }

    if($formError !== ""){
        $error = $formError;
    } elseif(!$attemptClaimed){
        $error = "Too many incorrect codes. Please request a new one.";
        $reset = null;
    } elseif(!$otpIsValid){
        $error = "Invalid or expired OTP.";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashedPassword, $reset['user_id']);
        $update->execute();

        $markUsed = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
        $markUsed->bind_param("i", $reset['id']);
        $markUsed->execute();

        $message = "Password reset successfully. You can now log in.";
        $reset = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OTP Verification</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="card shadow p-4" style="width: 420px;">
        <h4 class="text-center mb-3">OTP Verification</h4>
        <?php if($message !== ""): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div><?php endif; ?>
        <?php if($error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div><?php endif; ?>
        <?php if($reset): ?>
        <div class="text-muted small mb-3">Enter the 6-digit OTP sent to your phone, then set your new password.</div>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold">OTP Code</label>
                <input type="text" name="otp" class="form-control" inputmode="numeric" maxlength="6" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">New Password</label>
                <input type="password" name="new_password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button type="submit" name="reset_password" class="btn btn-primary w-100">Reset Password</button>
        </form>
        <?php endif; ?>
        <a href="index.php" class="d-block text-center mt-3">Back to login</a>
    </div>
</div>
</body>
</html>
