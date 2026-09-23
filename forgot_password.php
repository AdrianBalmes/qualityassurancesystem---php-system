<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/sms_helper.php";
require_once __DIR__ . "/user_columns.php";

if(isset($_SESSION['admin_username']) && $_SESSION['admin_role'] === 'admin'){
    header("Location: admin_profile.php");
    exit();
}

if(isset($_SESSION['office_username']) && isset($_SESSION['office_name'])){
    header("Location: office_profile.php");
    exit();
}

user_col($conn, 'phone', "varchar(30) DEFAULT ''");
ensure_password_resets_table($conn);

$message = "";
$error = "";

if(isset($_POST['request_reset'])){
    $identifier = trim($_POST['identifier']);
    $phone = preg_replace('/\s+/', '', trim($_POST['phone']));

    if($identifier === "" || $phone === ""){
        $error = "Enter your username or email and phone number.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, phone FROM users WHERE (username = ? OR email = ?) AND phone = ? LIMIT 1");
        $stmt->bind_param("sss", $identifier, $identifier, $phone);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        $recentRequests = 0;
        if($user){
            $recentStmt = $conn->prepare("SELECT COUNT(*) AS total FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL ? MINUTE");
            $window = PASSWORD_RESET_WINDOW_MINUTES;
            $recentStmt->bind_param("ii", $user['id'], $window);
            $recentStmt->execute();
            $recentRequests = (int) $recentStmt->get_result()->fetch_assoc()['total'];
        }

        if(!$user){
            $error = "Account and phone number do not match. Use the phone number saved in your profile.";
        } elseif($recentRequests >= PASSWORD_RESET_MAX_REQUESTS){
            // Each new code brings fresh guesses, so codes are rationed too.
            $error = "Too many reset requests for this account. Please wait " . PASSWORD_RESET_WINDOW_MINUTES . " minutes and try again.";
        } else {
            $otp = (string)random_int(100000, 999999);
            $otpHash = hash('sha256', $otp);
            $expiresAt = date('Y-m-d H:i:s', time() + 600);

            $clearOld = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
            $clearOld->bind_param("i", $user['id']);
            $clearOld->execute();

            $insert = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
            $insert->bind_param("iss", $user['id'], $otpHash, $expiresAt);
            $insert->execute();
            $resetId = $conn->insert_id;

            sendPasswordResetOtpText($conn, $phone, $otp);
            header("Location: reset_password.php?reset_id=" . urlencode((string)$resetId));
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/sbc-logo.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php render_page_background("auth"); ?>
<div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="card shadow p-4" style="width: 420px;">
        <h4 class="text-center mb-3">Forgot Password</h4>
        <?php if($message !== ""): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div><?php endif; ?>
        <?php if($error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div><?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold">Username or Email</label>
                <input type="text" name="identifier" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Phone Number</label>
                <input type="tel" name="phone" class="form-control" placeholder="Number saved in your profile" required>
            </div>
            <button type="submit" name="request_reset" class="btn btn-primary w-100">Send OTP</button>
        </form>
        <div class="text-muted small mt-3">The OTP is valid for 10 minutes.</div>
        <div class="text-center mt-3">
            <a href="index.php" class="text-decoration-none small">Office Login</a>
            <span class="mx-2 text-muted">|</span>
            <a href="admin_login.php" class="text-decoration-none small">Admin Login</a>
        </div>
    </div>
</div>
</body>
</html>
