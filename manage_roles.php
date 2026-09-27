<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/permissions.php";
require_once __DIR__ . "/nav_dropdown.php";

require_permission($conn, 'roles.manage');

ensure_user_account_columns($conn);
enforce_active_account($conn);
ensure_roles_tables($conn);
$siteContent = sc_load($conn);
$adminUsername = $_SESSION['admin_username'];

function roles_redirect($message, $type = 'success'){
    $_SESSION['roles_notice'] = ['text' => $message, 'type' => $type];
    header("Location: manage_roles.php");
    exit();
}

if(isset($_POST['save_role_permissions'])){
    $slug = trim($_POST['role_slug'] ?? '');

    $lookup = $conn->prepare("SELECT slug, name FROM roles WHERE slug = ? LIMIT 1");
    $lookup->bind_param("s", $slug);
    $lookup->execute();
    $role = $lookup->get_result()->fetch_assoc();

    if(!$role){
        roles_redirect("That role no longer exists.", 'danger');
    }
    // The Head's permissions come from the code, not from these rows. Saving
    // them would be the one edit that could lock everybody out for good.
    if($slug === ROLE_QA_HEAD){
        roles_redirect("The QA Head always holds every permission; it cannot be narrowed.", 'danger');
    }

    $wanted = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
    $granted = [];
    foreach($wanted as $permission){
        // Unknown keys are dropped rather than stored, and the reserved ones
        // are refused however the form was submitted -- this is the check that
        // stops a role granting itself the run of the place.
        if(array_key_exists($permission, PERMISSIONS) && !in_array($permission, HEAD_ONLY_PERMISSIONS, true)){
            $granted[] = $permission;
        }
    }

    $conn->begin_transaction();
    try {
        $clear = $conn->prepare("DELETE FROM role_permissions WHERE role_slug = ?");
        $clear->bind_param("s", $slug);
        $clear->execute();

        $insert = $conn->prepare("INSERT INTO role_permissions (role_slug, permission) VALUES (?,?)");
        foreach($granted as $permission){
            $insert->bind_param("ss", $slug, $permission);
            $insert->execute();
        }
        $conn->commit();
    } catch(Throwable $e){
        $conn->rollback();
        roles_redirect("Could not save that role: " . $e->getMessage(), 'danger');
    }

    $summary = !empty($granted) ? implode(', ', $granted) : 'nothing';
    log_audit_event($conn, $adminUsername, 'admin', 'Admin', 'role_permissions_updated', 'role', null,
        "Set what \"{$role['name']}\" may do: {$summary}");
    roles_redirect("Saved {$role['name']}.");
}

$notice = $_SESSION['roles_notice'] ?? null;
unset($_SESSION['roles_notice']);

$roles = get_roles($conn);
$permissionMap = role_permission_map($conn);

// How many accounts sit on each role, so nobody narrows one blindly.
$counts = [];
$countResult = mysqli_query($conn, "SELECT role_slug, COUNT(*) AS total FROM users WHERE status = '" . USER_STATUS_APPROVED . "' GROUP BY role_slug");
if($countResult){
    while($row = $countResult->fetch_assoc()){
        $counts[$row['role_slug']] = (int) $row['total'];
    }
}

$groups = [];
foreach(PERMISSIONS as $key => $meta){
    $groups[$meta['group']][$key] = $meta;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/sbc-logo.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Roles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2);position:sticky;top:0;z-index:900}
.nav-wrap{max-width:1680px;margin:auto;min-height:74px;padding:0 clamp(14px,2vw,32px);display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}
.brand-icon{width:64px;height:64px;display:grid;place-items:center;flex-shrink:0}
.nav-links{display:flex;gap:20px;flex-wrap:wrap;align-items:center}
.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}
.page{max-width:1120px;margin:26px auto 42px;padding:0 clamp(14px,2vw,32px)}
.page-title{margin:0 0 4px;font-size:26px;font-weight:800}
.muted-copy{color:#66758d;font-size:13px;font-weight:600}
.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12);margin-top:18px}
.panel-pad{padding:18px}
.panel-title{margin:0 0 4px;font-size:16px;font-weight:800;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.role-badge{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;padding:3px 9px;border-radius:999px;background:#e4e9f1;color:#4c5a72}
.role-badge.is-head{background:#d8e2f5;color:#2e5fa3}
.role-count{font-size:12px;font-weight:700;color:#8794a8}
.perm-group{margin-top:16px}
.perm-group h3{margin:0 0 8px;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:#8492a8}
.perm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:8px}
.perm{display:flex;gap:10px;padding:10px 12px;border:1px solid #e6edf7;border-radius:7px;background:#fff;cursor:pointer;margin:0}
.perm:hover{background:#f7fbff}
.perm.is-locked{cursor:default;background:#f8fafd;opacity:.78}
.perm input{width:16px;height:16px;margin-top:2px;flex-shrink:0;accent-color:#316fc4}
.perm-text{display:flex;flex-direction:column;gap:2px;min-width:0}
.perm-label{font-size:13.5px;font-weight:800;color:#344156}
.perm-help{font-size:12px;color:#66758d}
.perm-reserved{font-size:11px;font-weight:800;color:#95530a;text-transform:uppercase;letter-spacing:.3px}
.role-foot{display:flex;justify-content:flex-end;margin-top:16px}
.btn-save{min-height:38px;border:0;border-radius:5px;background:#316fc4;color:#fff;font-weight:800;padding:9px 18px;display:inline-flex;align-items:center;gap:7px;cursor:pointer}
.btn-save:hover{background:#2459a6}
.note{border-left:3px solid #316fc4;background:#f3f8ff;padding:10px 14px;border-radius:0 6px 6px 0;font-size:13px;color:#44536b;margin-top:10px}
.alert{margin-top:18px}
</style>
</head>
<body>
<?php render_page_background(); ?>
<header class="topbar"><div class="nav-wrap"><div class="brand"><span class="brand-icon"><img src="assets/sbc-logo.png" alt="St. Bridget College" style="width:100%;height:100%;object-fit:contain"></span><span>Roles</span></div><nav class="nav-links"><a href="home.php">Home</a><a href="repository.php">Repository</a><a href="activity_log.php">Activity Log</a><a href="manage_users.php">Users</a><a href="manage_offices.php">Offices</a><a href="manage_roles.php">Roles</a><?php render_profile_dropdown('admin_profile.php', 'Admin Profile'); ?></nav></div></header>

<main class="page">
    <h1 class="page-title">Roles</h1>
    <div class="muted-copy">What each kind of QA account may do. A change takes effect on that person's next click &mdash; they do not need to sign in again.</div>

    <?php if($notice): ?>
    <div class="alert alert-<?php echo htmlspecialchars($notice['type'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($notice['text'], ENT_QUOTES); ?></div>
    <?php endif; ?>

    <?php foreach($roles as $role):
        $isHead = $role['slug'] === ROLE_QA_HEAD;
        $isOffice = $role['slug'] === ROLE_OFFICE;
        $held = $permissionMap[$role['slug']] ?? [];
        $accountCount = $counts[$role['slug']] ?? 0;
    ?>
    <section class="panel panel-pad">
        <form method="POST" action="manage_roles.php">
            <input type="hidden" name="role_slug" value="<?php echo htmlspecialchars($role['slug'], ENT_QUOTES); ?>">
            <h2 class="panel-title">
                <?php echo htmlspecialchars($role['name'], ENT_QUOTES); ?>
                <span class="role-badge<?php echo $isHead ? ' is-head' : ''; ?>"><?php echo htmlspecialchars($role['slug'], ENT_QUOTES); ?></span>
                <span class="role-count"><?php echo $accountCount; ?> approved account<?php echo $accountCount === 1 ? '' : 's'; ?></span>
            </h2>

            <?php if($isHead): ?>
            <div class="note"><i class="bi bi-shield-lock-fill"></i> The QA Head always holds every permission. It is fixed in code so this screen can never leave the system with nobody able to manage roles or offices.</div>
            <?php elseif($isOffice): ?>
            <div class="note"><i class="bi bi-info-circle-fill"></i> Office accounts work from their own dashboard and hold none of these. They are listed here so the picture is complete.</div>
            <?php endif; ?>

            <?php foreach($groups as $groupName => $permissions): ?>
            <div class="perm-group">
                <h3><?php echo htmlspecialchars($groupName, ENT_QUOTES); ?></h3>
                <div class="perm-grid">
                    <?php foreach($permissions as $key => $meta):
                        $reserved = in_array($key, HEAD_ONLY_PERMISSIONS, true);
                        $checked = $isHead || isset($held[$key]);
                        $locked = $isHead || $isOffice || $reserved;
                    ?>
                    <label class="perm<?php echo $locked ? ' is-locked' : ''; ?>">
                        <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>"
                               <?php echo $checked && !($reserved && !$isHead) ? 'checked' : ''; ?>
                               <?php echo $locked ? 'disabled' : ''; ?>>
                        <span class="perm-text">
                            <span class="perm-label"><?php echo htmlspecialchars($meta['label'], ENT_QUOTES); ?></span>
                            <span class="perm-help"><?php echo htmlspecialchars($meta['help'], ENT_QUOTES); ?></span>
                            <?php if($reserved && !$isHead): ?>
                            <span class="perm-reserved">QA Head only</span>
                            <?php endif; ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if(!$isHead && !$isOffice): ?>
            <div class="role-foot">
                <button type="submit" name="save_role_permissions" value="1" class="btn-save"><i class="bi bi-check2"></i> Save <?php echo htmlspecialchars($role['name'], ENT_QUOTES); ?></button>
            </div>
            <?php endif; ?>
        </form>
    </section>
    <?php endforeach; ?>
</main>
<?php render_edit_toggle(); ?>
</body>
</html>
