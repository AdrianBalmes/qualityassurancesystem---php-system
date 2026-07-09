<?php
session_start();
require_once __DIR__ . "/database.php";

if(!isset($_SESSION['admin_username']) && !isset($_SESSION['office_username'])){
    header("Location: index.php");
    exit();
}

// Determine context: are we viewing as Admin or Office?
$context = (isset($_GET['type']) && $_GET['type'] === 'office') ? 'office' : (isset($_SESSION['admin_username']) ? 'admin' : 'office');
$currentUsername = ($context === 'admin') ? $_SESSION['admin_username'] : $_SESSION['office_username'];

if(!mysqli_query($conn, "SELECT phone FROM users LIMIT 1")){
    mysqli_query($conn, "ALTER TABLE users ADD phone varchar(30) DEFAULT ''");
}

$message = "";
$error = "";

$stmt = $conn->prepare("SELECT id, username, password, email, phone, role, office FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $currentUsername);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if(!$user){
    session_destroy();
    header("Location: index.php");
    exit();
}

if(isset($_POST['save_profile'])){
    $newUsername = trim($_POST['username']);
    $newEmail = trim($_POST['email']);
    $newPhone = trim($_POST['phone']);
    $currentPassword = trim($_POST['current_password']);
    $newPassword = trim($_POST['new_password']);
    $confirmPassword = trim($_POST['confirm_password']);
    $currentPasswordMatches = password_verify($currentPassword, $user['password']) || hash_equals($user['password'], $currentPassword);

    if($newUsername === ""){
        $error = "Username is required.";
    } elseif($newEmail !== "" && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)){
        $error = "Enter a valid email address.";
    } else {
        $duplicate = $conn->prepare("SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
        $duplicate->bind_param("si", $newUsername, $user['id']);
        $duplicate->execute();

        if($duplicate->get_result()->num_rows > 0){
            $error = "Username is already taken.";
        } elseif($newPassword !== "" && !$currentPasswordMatches){
            $error = "Current password is incorrect.";
        } elseif($newPassword !== "" && $newPassword !== $confirmPassword){
            $error = "New password and confirmation do not match.";
        } else {
            if($newPassword !== ""){
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET username = ?, email = ?, phone = ?, password = ? WHERE id = ?");
                $update->bind_param("ssssi", $newUsername, $newEmail, $newPhone, $hashedPassword, $user['id']);
            } else {
                $update = $conn->prepare("UPDATE users SET username = ?, email = ?, phone = ? WHERE id = ?");
                $update->bind_param("sssi", $newUsername, $newEmail, $newPhone, $user['id']);
            }

            if($update->execute()){
                $_SESSION['username'] = $newUsername;
                $_SESSION['email'] = $newEmail;
                $_SESSION['phone'] = $newPhone;
                $message = "Profile updated successfully.";

                $stmt = $conn->prepare("SELECT id, username, password, email, phone, role, office FROM users WHERE id = ? LIMIT 1");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
            } else {
                $error = "Profile update failed.";
            }
        }
    }
}

$dashboardUrl = $user['role'] === "admin" ? "home.php" : "office_dashboard.php";
$dashboardLabel = $user['role'] === "admin" ? "Admin Dashboard" : "Office Dashboard";
$roleLabel = ucfirst($user['role']);
$officeLabel = $user['office'] !== "" ? $user['office'] : "No office assigned";
$avatarInitial = strtoupper(substr($user['username'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile Settings</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1120px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:12px;font-size:21px;font-weight:800}.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}.nav-links{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.nav-links a{min-height:36px;border-radius:5px;color:#eef4ff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;gap:7px;padding:7px 10px}.nav-links a:hover{background:rgba(255,255,255,.12)}.page{max-width:1120px;margin:26px auto 42px;padding:0 18px}.page-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:14px}.page-title{margin:0;font-size:26px;font-weight:800;color:#26354b}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:18px}.panel-title{margin:0 0 14px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:18px;font-weight:800}.profile-grid{display:grid;grid-template-columns:320px minmax(0,1fr);gap:14px;align-items:start}.profile-card{display:grid;gap:14px}.identity-box{display:grid;justify-items:center;text-align:center;gap:10px;padding:6px 0 2px}.avatar{width:88px;height:88px;border-radius:50%;background:#316fc4;color:#fff;display:grid;place-items:center;font-size:36px;font-weight:800;box-shadow:0 8px 20px rgba(49,111,196,.22)}.user-name{margin:0;font-size:22px;font-weight:800;color:#26354b}.role-badge{display:inline-flex;align-items:center;gap:7px;border-radius:999px;background:#eef4ff;color:#2e67b8;font-size:12px;font-weight:800;padding:6px 10px}.info-list{display:grid;gap:9px}.info-row{display:grid;grid-template-columns:32px 1fr;gap:10px;align-items:start;border-top:1px solid #e6edf7;padding-top:10px}.info-icon{width:32px;height:32px;border-radius:6px;background:#eef4ff;color:#316fc4;display:grid;place-items:center}.info-label{color:#66758d;font-size:12px;font-weight:800;text-transform:uppercase}.info-value{font-weight:800;color:#344156;word-break:break-word}.edit-panel{display:none}.edit-panel.is-visible{display:block}.form-section{border:1px solid #e6edf7;border-radius:8px;padding:14px;margin-bottom:14px}.section-title{display:flex;align-items:center;gap:8px;margin:0 0 12px;font-size:15px;font-weight:800;color:#26354b}.form-label{font-weight:800;color:#344156}.form-control{min-height:42px;border-color:#cfd9e8}.form-control:focus{border-color:#316fc4;box-shadow:0 0 0 .2rem rgba(49,111,196,.12)}.action-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.action-btn{min-height:40px;border:0;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:9px 14px}.secondary-btn{background:#eef4ff;color:#2e67b8}.logout-btn{background:#c23b36}.muted-copy{color:#66758d;font-size:13px}.alert{border-radius:6px;font-weight:700}@media(max-width:860px){.profile-grid{grid-template-columns:1fr}.nav-wrap,.page-head{flex-direction:column;align-items:flex-start}.nav-wrap{padding:14px 18px}}@media(max-width:620px){.brand{font-size:18px}.action-row .action-btn{width:100%}}
</style>
</head>
<body>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-person-circle"></i></span><span>Profile Settings</span></div>
        <nav class="nav-links">
            <a href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES); ?>"><i class="bi bi-speedometer2"></i> <?php echo htmlspecialchars($dashboardLabel, ENT_QUOTES); ?></a>
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </nav>
    </div>
</header>
<main class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Account Profile</h1>
            <div class="muted-copy">Manage contact details and password for this account.</div>
        </div>
        <span class="role-badge"><i class="bi bi-shield-check"></i> <?php echo htmlspecialchars($roleLabel, ENT_QUOTES); ?></span>
    </div>
    <section class="profile-grid">
        <div class="panel panel-pad profile-card">
            <div class="identity-box">
                <div class="avatar"><?php echo htmlspecialchars($avatarInitial, ENT_QUOTES); ?></div>
                <div>
                    <h2 class="user-name"><?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?></h2>
                    <span class="role-badge"><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($officeLabel, ENT_QUOTES); ?></span>
                </div>
            </div>
            <div class="info-list">
                <div class="info-row"><span class="info-icon"><i class="bi bi-envelope-fill"></i></span><div><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($user['email'] ?: 'No email saved', ENT_QUOTES); ?></div></div></div>
                <div class="info-row"><span class="info-icon"><i class="bi bi-telephone-fill"></i></span><div><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($user['phone'] ?: 'No phone number saved', ENT_QUOTES); ?></div></div></div>
                <div class="info-row"><span class="info-icon"><i class="bi bi-person-gear"></i></span><div><div class="info-label">Role</div><div class="info-value"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES); ?></div></div></div>
            </div>
            <button type="button" class="action-btn w-100" id="showEditProfile"><i class="bi bi-pencil-square"></i> Edit Profile</button>
            <a class="action-btn secondary-btn w-100" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES); ?>"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            <a class="action-btn logout-btn w-100" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </div>
        <div class="panel panel-pad edit-panel <?php echo ($message !== "" || $error !== "") ? "is-visible" : ""; ?>" id="editProfilePanel">
            <h2 class="panel-title">Edit Profile</h2>
            <?php if($message !== ""): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div><?php endif; ?>
            <?php if($error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div><?php endif; ?>
            <form method="POST">
                <div class="form-section">
                    <h3 class="section-title"><i class="bi bi-person-lines-fill"></i> Account Details</h3>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email'], ENT_QUOTES); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number for OTP</label>
                            <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'], ENT_QUOTES); ?>" placeholder="Example: 09171234567">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($roleLabel, ENT_QUOTES); ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Office</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($officeLabel, ENT_QUOTES); ?>" readonly>
                        </div>
                    </div>
                </div>
                <div class="form-section">
                    <h3 class="section-title"><i class="bi bi-lock-fill"></i> Password</h3>
                    <div class="muted-copy mb-3">Leave these fields blank if you do not want to change your password.</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" placeholder="Required to change password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="action-row">
                    <button type="submit" name="save_profile" class="action-btn"><i class="bi bi-check2-circle"></i> Save Changes</button>
                    <button type="button" class="action-btn secondary-btn" id="hideEditProfile"><i class="bi bi-x-circle"></i> Cancel</button>
                </div>
            </form>
        </div>
    </section>
</main>
<script>
const editPanel = document.getElementById('editProfilePanel');
const showEdit = document.getElementById('showEditProfile');
const hideEdit = document.getElementById('hideEditProfile');

if(showEdit && editPanel){
    showEdit.addEventListener('click', function(){
        editPanel.classList.add('is-visible');
        editPanel.scrollIntoView({behavior:'smooth', block:'start'});
    });
}

if(hideEdit && editPanel){
    hideEdit.addEventListener('click', function(){
        editPanel.classList.remove('is-visible');
    });
}
</script>
</body>
</html>
