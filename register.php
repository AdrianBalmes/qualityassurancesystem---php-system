<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/profile_columns.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/audit_log_helper.php";

ensure_profile_columns($conn);
ensure_user_account_columns($conn);

$offices = array_values(array_filter(get_all_office_names($conn), function($office){
    return trim($office) !== '' && $office !== 'Admin';
}));
sort($offices);

$error = "";
$success = "";
$form = ['full_name' => '', 'username' => '', 'email' => '', 'phone' => '', 'office' => ''];

if(isset($_POST['register'])){
    $form['full_name'] = trim($_POST['full_name'] ?? '');
    $form['username']  = trim($_POST['username'] ?? '');
    $form['email']     = trim($_POST['email'] ?? '');
    $form['phone']     = trim($_POST['phone'] ?? '');
    $form['office']    = trim($_POST['office'] ?? '');
    $password          = (string) ($_POST['password'] ?? '');
    $confirmPassword   = (string) ($_POST['confirm_password'] ?? '');

    if($form['full_name'] === '' || $form['username'] === '' || $form['email'] === '' || $form['office'] === ''){
        $error = "Full name, username, email and department are all required.";
    } elseif(!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $form['username'])){
        $error = "Username must be 3-50 characters, using letters, numbers, dot, underscore or hyphen only.";
    } elseif(!filter_var($form['email'], FILTER_VALIDATE_EMAIL)){
        $error = "Please enter a valid email address.";
    } elseif(!in_array($form['office'], $offices, true)){
        $error = "Please choose a department from the list.";
    } elseif(strlen($password) < 8){
        $error = "Password must be at least 8 characters.";
    } elseif($password !== $confirmPassword){
        $error = "The two passwords do not match.";
    } else {
        $takenStmt = $conn->prepare("SELECT status FROM users WHERE username = ? LIMIT 1");
        $takenStmt->bind_param("s", $form['username']);
        $takenStmt->execute();
        $existing = $takenStmt->get_result()->fetch_assoc();

        if($existing){
            // Don't reveal an account's review state to an anonymous visitor.
            $error = "That username is already taken. Please choose another.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $role = 'user';
            $pending = USER_STATUS_PENDING;

            $insert = $conn->prepare("INSERT INTO users (username, password, email, phone, role, office, full_name, status) VALUES (?,?,?,?,?,?,?,?)");
            $insert->bind_param("ssssssss", $form['username'], $hashed, $form['email'], $form['phone'], $role, $form['office'], $form['full_name'], $pending);
            $insert->execute();
            $newUserId = $conn->insert_id;

            log_audit_event($conn, $form['username'], 'office', $form['office'], 'registration_submitted', 'user', $newUserId,
                "{$form['full_name']} requested a {$form['office']} account (username \"{$form['username']}\")");

            $success = "Registration submitted. An administrator will review your request, and you can sign in once it is approved.";
            $form = ['full_name' => '', 'username' => '', 'email' => '', 'phone' => '', 'office' => ''];
        }
    }
}

function reg_old($form, $key){
    return htmlspecialchars($form[$key] ?? '', ENT_QUOTES);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Account - SBC Quality Assurance</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:linear-gradient(135deg,#f6f8fb 0%,#e9effd 100%);font-family:'Inter',sans-serif;min-height:100vh;display:flex;align-items:center;padding:24px 0}
.register-card{border:none;border-radius:8px;box-shadow:0 10px 25px rgba(44,74,119,.1);padding:28px;background:#fff}
.brand-logo{width:60px;height:60px;background:#316fc4;color:#fff;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 20px}
.form-control,.form-select{padding:12px;border-radius:8px}
.form-label{font-size:13px;font-weight:600}
.btn-primary{padding:12px;border-radius:8px;font-weight:600}
.hint{font-size:12px;color:#8794a8}
</style>
</head>
<body class="bg-light">
<div class="container d-flex justify-content-center">
    <div class="card register-card" style="width: min(100%, 620px);">
        <div class="brand-logo"><i class="bi bi-person-plus-fill"></i></div>
        <h4 class="text-center mb-1 fw-bold">Create Account</h4>
        <p class="text-center text-muted small mb-4">Request access to the SBC Quality Assurance System</p>

        <?php if($success !== ""): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success, ENT_QUOTES); ?>
            </div>
            <a href="index.php" class="btn btn-primary w-100">Back to Login</a>
        <?php else: ?>
            <?php if($error !== ""): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" maxlength="120" value="<?php echo reg_old($form, 'full_name'); ?>" placeholder="e.g. Juan dela Cruz" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Department</label>
                    <select name="office" class="form-select" required>
                        <option value="">Select your department…</option>
                        <?php foreach($offices as $office): ?>
                            <option value="<?php echo htmlspecialchars($office, ENT_QUOTES); ?>"<?php echo $form['office'] === $office ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($office, ENT_QUOTES); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint mt-1">You will be taken to this department's dashboard after signing in.</div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" maxlength="50" value="<?php echo reg_old($form, 'username'); ?>" placeholder="Choose a username" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="phone" class="form-control" maxlength="30" value="<?php echo reg_old($form, 'phone'); ?>" placeholder="09XXXXXXXXX">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" maxlength="100" value="<?php echo reg_old($form, 'email'); ?>" placeholder="you@example.com" required>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="At least 8 characters" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
                    </div>
                </div>

                <button type="submit" name="register" class="btn btn-primary w-100">
                    <i class="bi bi-send-fill"></i> Submit Registration
                </button>

                <div class="mt-3 text-center">
                    <a href="index.php" class="text-decoration-none small">Already have an account? Sign in</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
