<?php
session_start();
include("db.php");

// If already logged in
if(isset($_SESSION['username'])){
    if($_SESSION['role'] === "admin"){
        header("Location: admin_dashboard.php");
        exit();
    } else {
        header("Location: office_dashboard.php");
        exit();
    }
}

$error = "";

if(isset($_POST['login'])){

    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $office   = trim($_POST['office']);

    if(empty($username) || empty($password) || empty($office)){
        $error = "All fields are required!";
    } else {

        $stmt = $conn->prepare("SELECT * FROM users WHERE username=? AND password=?");
        $stmt->bind_param("ss", $username, $password);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows === 1){

            $user = $result->fetch_assoc();

            if($user['office'] !== $office){
                $error = "Wrong Office Selected!";
            } else {

                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];
                $_SESSION['office']   = $user['office'];

                if($user['role'] === "admin"){
                    header("Location: admin_dashboard.php");
                    exit();
                } else {
                    header("Location: office_dashboard.php");
                    exit();
                }
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
    <title>Quality Assurance System - Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container d-flex justify-content-center align-items-center vh-100">

    <div class="card shadow p-4" style="width: 400px;">
        <h4 class="text-center mb-4">Quality Assurance System</h4>

        <?php if($error != ""): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="">

            <div class="mb-3">
                <input type="text" name="username" class="form-control" placeholder="Username" required>
            </div>

            <div class="mb-3">
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>

            <div class="mb-3">
                <select name="office" class="form-select" required>
                    <option value="">Select Office</option>
                    <option value="Admin">Admin</option>
                    <option value="Registrar">Registrar</option>
                    <option value="Finance">Finance</option>
                    <option value="CSSAO">CSSAO</option>
                    <option value="HR">HR</option>
                </select>
            </div>

            <button type="submit" name="login" class="btn btn-primary w-100">
                Login
            </button>

        </form>
    </div>

</div>

</body>
</html>