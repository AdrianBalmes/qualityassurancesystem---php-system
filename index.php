<?php
session_start();
require_once __DIR__ . "/database.php";

$error = "";

if(isset($_POST['login'])){

    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if(empty($username) || empty($password)){
        $error = "Username and password are required!";
    } else {

        $stmt = $conn->prepare("SELECT * FROM users WHERE username=?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows === 1){

            $user = $result->fetch_assoc();
            $passwordMatches = password_verify($password, $user['password']) || hash_equals($user['password'], $password);

            if(!$passwordMatches){
                $error = "Invalid Username or Password!";
            } elseif($user['role'] === "admin"){
                $error = "Admins must use the Admin Login portal.";
            } elseif(trim($user['office']) === ""){
                $error = "This account has no assigned office. Please contact the administrator.";
            } else {
                if(!password_get_info($user['password'])['algo']){
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $updatePassword = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $updatePassword->bind_param("si", $hashedPassword, $user['id']);
                    $updatePassword->execute();
                }

                if(!isset($_SESSION['office_logins']) || !is_array($_SESSION['office_logins'])){
                    $_SESSION['office_logins'] = [];
                }

                $_SESSION['office_logins'][$user['office']] = [
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'office' => $user['office'],
                    'id' => $user['id'],
                    'email' => $user['email']
                ];

                $_SESSION['office_username'] = $user['username'];
                $_SESSION['office_role']     = $user['role'];
                $_SESSION['office_name']     = $user['office'];
                $_SESSION['office_user_id']  = $user['id'];
                $_SESSION['office_email']    = $user['email'];

                header("Location: office_dashboard.php?office=" . urlencode($user['office']));
                exit();
            }
        } else {
            $error = "Invalid Username or Password!";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>SBC Quality Assurance - Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f6f8fb 0%, #e9effd 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 24px 0;
        }
        .login-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(44, 74, 119, 0.1);
            padding: 28px;
            background: #fff;
        }
        .brand-logo {
            width: 60px;
            height: 60px;
            background: #316fc4;
            color: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            margin: 0 auto 20px;
        }
        .form-control {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .btn-primary {
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            background: #316fc4;
        }
        @media (max-width: 576px) {
            .login-card {
                padding: 22px;
            }
        }
    </style>
</head>
<body class="bg-light">
<div class="container d-flex justify-content-center">
    <div class="card login-card" style="width: min(100%, 520px);">
        <div class="brand-logo"><i class="bi bi-shield-check"></i></div>
        <h4 class="text-center mb-1 fw-bold">Office Login</h4>
        <p class="text-center text-muted small mb-4">SBC Quality Assurance System</p>
        <?php if($error != ""): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label small fw-bold">Username</label>
                <input type="text" name="username" class="form-control" placeholder="Enter username" required>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Enter password" required>
            </div>
            <button type="submit" name="login" class="btn btn-primary w-100">
                Login
            </button>
            <div class="mt-3 text-center">
                <a href="forgot_password.php" class="text-decoration-none small d-block">Forgot password?</a>
                <a href="admin_login.php" class="text-decoration-none small d-block mt-1 text-secondary">Login as Admin</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
