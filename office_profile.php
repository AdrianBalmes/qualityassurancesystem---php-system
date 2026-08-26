<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/profile_columns.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/audit_log_helper.php";

if(isset($_SESSION['office_logins']) && is_array($_SESSION['office_logins'])){
    $requestedOffice = isset($_GET['office']) ? trim($_GET['office']) : '';
    if($requestedOffice !== '' && isset($_SESSION['office_logins'][$requestedOffice])){
        $activeLogin = $_SESSION['office_logins'][$requestedOffice];
    } elseif(isset($_SESSION['office_name'], $_SESSION['office_logins'][$_SESSION['office_name']])){
        $activeLogin = $_SESSION['office_logins'][$_SESSION['office_name']];
    } else {
        $activeLogin = reset($_SESSION['office_logins']);
    }

    if($activeLogin){
        $_SESSION['office_username'] = $activeLogin['username'];
        $_SESSION['office_role']     = $activeLogin['role'];
        $_SESSION['office_name']     = $activeLogin['office'];
        $_SESSION['office_user_id']  = $activeLogin['id'];
        $_SESSION['office_email']    = $activeLogin['email'];
    }
}

if(!isset($_SESSION['office_username']) || !isset($_SESSION['office_name'])){
    header("Location: index.php");
    exit();
}

$office = $_SESSION['office_name'];
$officeDashboardUrl = "office_dashboard.php?office=" . urlencode($office);

ensure_profile_columns($conn);
ensure_user_account_columns($conn);
enforce_active_account($conn);

$message = "";
$error = "";

$officeUserId = (int) ($_SESSION['office_user_id'] ?? 0);
$stmt = $conn->prepare("SELECT id, username, password, email, phone, role, office, full_name, profile_photo, created_at, last_login FROM users WHERE id = ? AND role <> 'admin' LIMIT 1");
$stmt->bind_param("i", $officeUserId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if(!$user){
    session_destroy();
    header("Location: index.php");
    exit();
}

$avatarDir = __DIR__ . "/uploads/avatars/";
if(!is_dir($avatarDir)){
    mkdir($avatarDir, 0775, true);
}

if(isset($_POST['save_profile'])){
    $newFullName = trim($_POST['full_name'] ?? '');
    $newUsername = trim($_POST['username']);
    $newEmail = trim($_POST['email']);
    $newPhone = trim($_POST['phone']);
    $currentPassword = trim($_POST['current_password']);
    $newPassword = trim($_POST['new_password']);
    $confirmPassword = trim($_POST['confirm_password']);
    $removePhoto = isset($_POST['remove_photo']);
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
            $newPhotoName = $user['profile_photo'];

            if(!empty($_FILES['avatar']['name'])){
                $originalName = basename($_FILES['avatar']['name']);
                $tmpName = $_FILES['avatar']['tmp_name'];
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if(!in_array($ext, $allowedExt, true)){
                    $error = "Profile photo must be a JPG, PNG, GIF, or WEBP image.";
                } elseif($_FILES['avatar']['size'] > 3 * 1024 * 1024){
                    $error = "Profile photo must be smaller than 3MB.";
                } else {
                    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                    $newPhotoName = $safeBase . "_" . date("YmdHis") . "_" . bin2hex(random_bytes(3)) . "." . $ext;
                    if(!move_uploaded_file($tmpName, $avatarDir . $newPhotoName)){
                        $error = "Could not upload the profile photo. Please try again.";
                        $newPhotoName = $user['profile_photo'];
                    }
                }
            } elseif($removePhoto){
                $newPhotoName = "";
            }

            if($error === ""){
                if($newPassword !== ""){
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $update = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, password = ?, profile_photo = ? WHERE id = ?");
                    $update->bind_param("ssssssi", $newFullName, $newUsername, $newEmail, $newPhone, $hashedPassword, $newPhotoName, $user['id']);
                } else {
                    $update = $conn->prepare("UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, profile_photo = ? WHERE id = ?");
                    $update->bind_param("sssssi", $newFullName, $newUsername, $newEmail, $newPhone, $newPhotoName, $user['id']);
                }

                if($update->execute()){
                    $changedFields = [];
                    if($newFullName !== ($user["full_name"] ?? "")){ $changedFields[] = "full name"; }
                    if($newUsername !== $user["username"]){ $changedFields[] = "username"; }
                    if($newEmail !== $user['email']){ $changedFields[] = "email"; }
                    if($newPhone !== $user['phone']){ $changedFields[] = "phone"; }
                    if($newPassword !== ""){ $changedFields[] = "password"; }
                    if($newPhotoName !== $user['profile_photo']){ $changedFields[] = "photo"; }
                    $changeSummary = !empty($changedFields) ? implode(", ", $changedFields) : "no fields";

                    $_SESSION['office_username'] = $newUsername;
                    if(isset($_SESSION['office_logins'][$office])){
                        $_SESSION['office_logins'][$office]['username'] = $newUsername;
                        $_SESSION['office_logins'][$office]['email'] = $newEmail;
                    }
                    $message = "Profile updated successfully.";

                    log_audit_event($conn, $newUsername, 'office', $office, 'profile_updated', 'user', $user['id'], "{$office} user \"{$newUsername}\" updated their profile ({$changeSummary})");

                    $stmt = $conn->prepare("SELECT id, username, password, email, phone, role, office, full_name, profile_photo, created_at, last_login FROM users WHERE id = ? LIMIT 1");
                    $stmt->bind_param("i", $user['id']);
                    $stmt->execute();
                    $user = $stmt->get_result()->fetch_assoc();
                } else {
                    $error = "Profile update failed.";
                }
            }
        }
    }
}

$roleLabel = ucfirst($user['role']);
$officeLabel = $user['office'] !== "" ? $user['office'] : "No office assigned";
$avatarInitial = strtoupper(substr($user['username'], 0, 1));
$hasPhoto = !empty($user['profile_photo']) && is_file($avatarDir . $user['profile_photo']);
$avatarUrl = $hasPhoto ? "uploads/avatars/" . rawurlencode($user['profile_photo']) : "";
$memberSince = !empty($user['created_at']) ? date("M j, Y", strtotime($user['created_at'])) : "Unknown";
$lastLogin = !empty($user['last_login']) ? date("M j, Y g:i A", strtotime($user['last_login'])) : "This is your first recorded login";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Office Profile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1120px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:12px;font-size:21px;font-weight:800}.brand-icon{width:64px;height:64px;display:grid;place-items:center;flex-shrink:0}.nav-links{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.nav-links a{min-height:36px;border-radius:5px;color:#eef4ff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;gap:7px;padding:7px 10px}.nav-links a:hover{background:rgba(255,255,255,.12)}.page{max-width:1120px;margin:26px auto 42px;padding:0 18px}.page-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:14px}.page-title{margin:0;font-size:26px;font-weight:800;color:#26354b}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:18px}.panel-title{margin:0 0 14px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:18px;font-weight:800}.profile-grid{display:grid;grid-template-columns:340px minmax(0,1fr);gap:16px;align-items:start}.profile-card{overflow:hidden;padding:0!important;display:grid}.card-banner{height:76px;background:linear-gradient(135deg,#316fc4,#7a5fd6);position:relative}.card-body{padding:18px;margin-top:-46px;display:grid;gap:14px}.identity-box{display:grid;justify-items:center;text-align:center;gap:10px}.avatar-wrap{position:relative;width:92px;height:92px;margin:0 auto}.avatar{width:92px;height:92px;border-radius:50%;background:#316fc4;color:#fff;display:grid;place-items:center;font-size:36px;font-weight:800;box-shadow:0 0 0 4px #fff,0 8px 20px rgba(49,111,196,.28);object-fit:cover}.avatar-edit-badge{position:absolute;bottom:0;right:0;width:30px;height:30px;border-radius:50%;background:#316fc4;color:#fff;border:3px solid #fff;display:grid;place-items:center;font-size:13px;cursor:pointer}.avatar-edit-badge:hover{background:#2459a6}.user-name{margin:0;font-size:22px;font-weight:800;color:#26354b}.role-badge{display:inline-flex;align-items:center;gap:7px;border-radius:999px;background:#eef4ff;color:#2e67b8;font-size:12px;font-weight:800;padding:6px 10px}.info-list{display:grid;gap:9px}.info-row{display:grid;grid-template-columns:34px 1fr;gap:10px;align-items:start;border-top:1px solid #e6edf7;padding-top:10px}.info-icon{width:34px;height:34px;border-radius:8px;color:#fff;display:grid;place-items:center;font-size:14px}.icon-blue{background:#316fc4}.icon-green{background:#2fa66a}.icon-purple{background:#7a5fd6}.icon-steel{background:#5b7091}.icon-yellow{background:#e0a51d}.info-label{color:#66758d;font-size:11.5px;font-weight:800;text-transform:uppercase}.info-value{font-weight:800;color:#344156;word-break:break-word;font-size:13.5px}.edit-panel{display:none}.edit-panel.is-visible{display:block}.form-section{border:1px solid #e6edf7;border-radius:8px;padding:14px;margin-bottom:14px}.section-title{display:flex;align-items:center;gap:8px;margin:0 0 12px;font-size:15px;font-weight:800;color:#26354b}.form-label{font-weight:800;color:#344156}.form-control{min-height:42px;border-color:#cfd9e8}.form-control:focus{border-color:#316fc4;box-shadow:0 0 0 .2rem rgba(49,111,196,.12)}.action-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.action-btn{min-height:40px;border:0;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:9px 14px;cursor:pointer}.action-btn:hover{background:#2459a6}.secondary-btn{background:#eef4ff;color:#2e67b8}.secondary-btn:hover{background:#dfeaff;color:#2e67b8}.logout-btn{background:#c23b36}.logout-btn:hover{background:#a5302b}.muted-copy{color:#66758d;font-size:13px}.alert{border-radius:6px;font-weight:700}.photo-picker{display:flex;align-items:center;gap:14px;flex-wrap:wrap}.photo-preview{width:64px;height:64px;border-radius:50%;object-fit:cover;background:#eef4ff;color:#316fc4;display:grid;place-items:center;font-weight:800;font-size:24px;border:1px solid #dbe3ef}.form-check-label{font-weight:700;color:#66758d;font-size:13px}.password-field{position:relative}.password-toggle{position:absolute;right:10px;top:50%;transform:translateY(-50%);border:0;background:none;color:#66758d;cursor:pointer;padding:4px}.password-toggle:hover{color:#316fc4}@media(max-width:860px){.profile-grid{grid-template-columns:1fr}.nav-wrap,.page-head{flex-direction:column;align-items:flex-start}.nav-wrap{padding:14px 18px}}@media(max-width:620px){.brand{font-size:18px}.action-row .action-btn{width:100%}}
</style>
</head>
<body>
<?php render_page_background(); ?>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><img src="assets/sbc-logo.png" alt="St. Bridget College" style="width:100%;height:100%;object-fit:contain"></span><span>Office Profile</span></div>
        <nav class="nav-links">
            <a href="<?php echo htmlspecialchars($officeDashboardUrl, ENT_QUOTES); ?>"><i class="bi bi-speedometer2"></i> Office Dashboard</a>
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </nav>
    </div>
</header>
<main class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Office Profile</h1>
            <div class="muted-copy">Manage your photo, contact details, and password for the <?php echo htmlspecialchars($officeLabel, ENT_QUOTES); ?> account.</div>
        </div>
        <span class="role-badge"><i class="bi bi-shield-check"></i> <?php echo htmlspecialchars($roleLabel, ENT_QUOTES); ?></span>
    </div>
    <section class="profile-grid">
        <div class="panel profile-card">
            <div class="card-banner"></div>
            <div class="card-body">
                <div class="identity-box">
                    <div class="avatar-wrap">
                        <?php if($hasPhoto): ?>
                            <img class="avatar" id="avatarPreviewImg" src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES); ?>" alt="Profile photo">
                        <?php else: ?>
                            <div class="avatar" id="avatarPreviewImg"><?php echo htmlspecialchars($avatarInitial, ENT_QUOTES); ?></div>
                        <?php endif; ?>
                        <span class="avatar-edit-badge" id="avatarEditBadge" title="Change photo"><i class="bi bi-camera-fill"></i></span>
                    </div>
                    <div>
                        <h2 class="user-name"><?php echo htmlspecialchars(trim($user['full_name']) !== '' ? $user['full_name'] : $user['username'], ENT_QUOTES); ?></h2>
                        <?php if(trim($user['full_name']) !== ''): ?><div class="muted-copy">@<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?></div><?php endif; ?>
                        <span class="role-badge"><i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($officeLabel, ENT_QUOTES); ?></span>
                    </div>
                </div>
                <div class="info-list">
                    <div class="info-row"><span class="info-icon icon-blue"><i class="bi bi-envelope-fill"></i></span><div><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($user['email'] ?: 'No email saved', ENT_QUOTES); ?></div></div></div>
                    <div class="info-row"><span class="info-icon icon-green"><i class="bi bi-telephone-fill"></i></span><div><div class="info-label">Phone</div><div class="info-value"><?php echo htmlspecialchars($user['phone'] ?: 'No phone number saved', ENT_QUOTES); ?></div></div></div>
                    <div class="info-row"><span class="info-icon icon-purple"><i class="bi bi-person-gear"></i></span><div><div class="info-label">Role</div><div class="info-value"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES); ?></div></div></div>
                    <div class="info-row"><span class="info-icon icon-steel"><i class="bi bi-calendar-check-fill"></i></span><div><div class="info-label">Member Since</div><div class="info-value"><?php echo htmlspecialchars($memberSince, ENT_QUOTES); ?></div></div></div>
                    <div class="info-row"><span class="info-icon icon-yellow"><i class="bi bi-clock-history"></i></span><div><div class="info-label">Last Login</div><div class="info-value"><?php echo htmlspecialchars($lastLogin, ENT_QUOTES); ?></div></div></div>
                </div>
                <button type="button" class="action-btn w-100" id="showEditProfile"><i class="bi bi-pencil-square"></i> Edit Profile</button>
                <a class="action-btn secondary-btn w-100" href="<?php echo htmlspecialchars($officeDashboardUrl, ENT_QUOTES); ?>"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                <a class="action-btn logout-btn w-100" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
            </div>
        </div>
        <div class="panel panel-pad edit-panel <?php echo ($message !== "" || $error !== "") ? "is-visible" : ""; ?>" id="editProfilePanel">
            <h2 class="panel-title">Edit Profile</h2>
            <?php if($message !== ""): ?><div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div><?php endif; ?>
            <?php if($error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div><?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-section">
                    <h3 class="section-title"><i class="bi bi-image-fill"></i> Profile Photo</h3>
                    <div class="photo-picker">
                        <?php if($hasPhoto): ?>
                            <img class="photo-preview" id="photoPreview" src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES); ?>" alt="Current photo">
                        <?php else: ?>
                            <div class="photo-preview" id="photoPreview"><?php echo htmlspecialchars($avatarInitial, ENT_QUOTES); ?></div>
                        <?php endif; ?>
                        <div>
                            <input type="file" name="avatar" id="avatarInput" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                            <div class="muted-copy mt-1">JPG, PNG, GIF, or WEBP. Max 3MB.</div>
                            <?php if($hasPhoto): ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="remove_photo" id="removePhoto" value="1">
                                <label class="form-check-label" for="removePhoto">Remove current photo</label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="form-section">
                    <h3 class="section-title"><i class="bi bi-person-lines-fill"></i> Account Details</h3>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" maxlength="120" value="<?php echo htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES); ?>" placeholder="e.g. Juan dela Cruz">
                        </div>
                        <div class="col-md-6 mb-3">
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
                            <div class="password-field">
                                <input type="password" name="current_password" class="form-control password-input" placeholder="Required to change password">
                                <button type="button" class="password-toggle" data-toggle-password><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">New Password</label>
                            <div class="password-field">
                                <input type="password" name="new_password" class="form-control password-input">
                                <button type="button" class="password-toggle" data-toggle-password><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Confirm Password</label>
                            <div class="password-field">
                                <input type="password" name="confirm_password" class="form-control password-input">
                                <button type="button" class="password-toggle" data-toggle-password><i class="bi bi-eye"></i></button>
                            </div>
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
const avatarEditBadge = document.getElementById('avatarEditBadge');
const avatarInput = document.getElementById('avatarInput');
const photoPreview = document.getElementById('photoPreview');

function openEditPanel(){
    editPanel.classList.add('is-visible');
    editPanel.scrollIntoView({behavior:'smooth', block:'start'});
}

if(showEdit && editPanel){
    showEdit.addEventListener('click', openEditPanel);
}

if(hideEdit && editPanel){
    hideEdit.addEventListener('click', function(){
        editPanel.classList.remove('is-visible');
    });
}

if(avatarEditBadge && editPanel){
    avatarEditBadge.addEventListener('click', function(){
        openEditPanel();
        if(avatarInput){ avatarInput.click(); }
    });
}

if(avatarInput && photoPreview){
    avatarInput.addEventListener('change', function(){
        const file = avatarInput.files && avatarInput.files[0];
        if(!file){ return; }
        const reader = new FileReader();
        reader.onload = function(e){
            if(photoPreview.tagName === 'IMG'){
                photoPreview.src = e.target.result;
            } else {
                const img = document.createElement('img');
                img.className = 'photo-preview';
                img.id = 'photoPreview';
                img.src = e.target.result;
                photoPreview.replaceWith(img);
            }
        };
        reader.readAsDataURL(file);
    });
}

document.querySelectorAll('[data-toggle-password]').forEach(function(button){
    button.addEventListener('click', function(){
        const input = button.previousElementSibling;
        const icon = button.querySelector('i');
        if(!input){ return; }
        if(input.type === 'password'){
            input.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    });
});
</script>
</body>
</html>
