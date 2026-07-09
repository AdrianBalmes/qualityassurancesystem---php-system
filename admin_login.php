<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/mailer_config.php";

if(isset($_SESSION['admin_username'])){
    header("Location: home.php");
    exit();
}

$error = "";
if(isset($_POST['login'])){
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE username=? AND role='admin' LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows === 1){
        $user = $result->fetch_assoc();
        if(password_verify($password, $user['password']) || hash_equals($user['password'], $password)){
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role']     = $user['role'];
            $_SESSION['admin_office']   = $user['office'];
            $_SESSION['admin_user_id']  = $user['id'];
            sendLoginNotification($user['email'], $user['username']);
            header("Location: home.php");
            exit();
        }
    }
    $error = "Invalid Admin Credentials!";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>SBC QA - Admin Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #0f172a; font-family: 'Inter', sans-serif; height: 100vh; display: flex; align-items: center; }
        .login-card { border: none; border-radius: 16px; padding: 40px; background: #fff; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        .brand-logo { width: 60px; height: 60px; background: #dc3545; color: white; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 30px; margin: 0 auto 20px; }
        .form-control { padding: 12px; border-radius: 8px; }
        .btn-danger { padding: 12px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>
<div class="container d-flex justify-content-center">
    <div class="card login-card" style="width: 400px;">
        <div class="brand-logo"><i class="bi bi-shield-lock-fill"></i></div>
        <h4 class="text-center mb-1 fw-bold">Admin Portal</h4>
        <p class="text-center text-muted small mb-4">SBC Quality Assurance System</p>
        <?php if($error): ?><div class="alert alert-danger py-2 small"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold">Admin Username</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" name="login" class="btn btn-danger w-100">Sign In</button>
            <div class="mt-3 text-center">
                <a href="index.php" class="text-decoration-none small text-secondary">Back to Office Login</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
