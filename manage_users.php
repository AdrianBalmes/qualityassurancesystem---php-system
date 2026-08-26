<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/nav_dropdown.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'admin'){
    header("Location: admin_login.php");
    exit();
}

ensure_user_account_columns($conn);
enforce_active_account($conn);
$siteContent = sc_load($conn);
$adminUsername = $_SESSION['admin_username'];
$notice = "";
$noticeType = "success";

if(isset($_POST['review_user'])){
    $userId = intval($_POST['user_id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if(!in_array($decision, [USER_STATUS_APPROVED, USER_STATUS_REJECTED], true)){
        $notice = "Unknown decision.";
        $noticeType = "danger";
    } else {
        $lookup = $conn->prepare("SELECT username, full_name, office, role, status FROM users WHERE id = ? LIMIT 1");
        $lookup->bind_param("i", $userId);
        $lookup->execute();
        $target = $lookup->get_result()->fetch_assoc();

        $losingAnAdmin = $target
            && $target['role'] === 'admin'
            && $target['status'] === USER_STATUS_APPROVED
            && $decision === USER_STATUS_REJECTED;

        if(!$target){
            $notice = "That account no longer exists.";
            $noticeType = "danger";
        } elseif($target['username'] === $adminUsername && $decision === USER_STATUS_REJECTED){
            // Without this an admin could revoke themselves mid-session.
            $notice = "You cannot revoke your own account.";
            $noticeType = "danger";
        } elseif($losingAnAdmin && approved_admin_count($conn) <= 1){
            // Never leave the system with nobody able to approve anyone.
            $notice = "This is the last approved administrator. Approve another one before revoking this account.";
            $noticeType = "danger";
        } else {
            $update = $conn->prepare("UPDATE users SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_reason = ? WHERE id = ?");
            $update->bind_param("sssi", $decision, $adminUsername, $reason, $userId);
            $update->execute();

            $action = $decision === USER_STATUS_APPROVED ? 'user_approved' : 'user_rejected';
            $verb = $decision === USER_STATUS_APPROVED ? 'approved' : 'rejected';
            $detail = $reason !== '' ? " (reason: {$reason})" : "";
            log_audit_event($conn, $adminUsername, 'admin', $target['office'], $action, 'user', $userId,
                "Admin {$verb} the {$target['office']} account for \"{$target['username']}\"{$detail}");

            $notice = ucfirst($verb) . " " . $target['username'] . ".";
            $noticeType = $decision === USER_STATUS_APPROVED ? "success" : "warning";
        }
    }
}

$filter = isset($_GET['status']) ? trim($_GET['status']) : USER_STATUS_PENDING;
if(!in_array($filter, [USER_STATUS_PENDING, USER_STATUS_APPROVED, USER_STATUS_REJECTED, 'all'], true)){
    $filter = USER_STATUS_PENDING;
}

$counts = [USER_STATUS_PENDING => 0, USER_STATUS_APPROVED => 0, USER_STATUS_REJECTED => 0];
$countResult = mysqli_query($conn, "SELECT status, COUNT(*) AS total FROM users GROUP BY status");
while($countRow = $countResult->fetch_assoc()){
    $status = $countRow['status'] !== '' ? $countRow['status'] : USER_STATUS_APPROVED;
    if(isset($counts[$status])){
        $counts[$status] += (int) $countRow['total'];
    }
}

if($filter === 'all'){
    $listResult = mysqli_query($conn, "SELECT * FROM users ORDER BY FIELD(status,'pending','rejected','approved'), role DESC, created_at DESC, id DESC");
} else {
    $listStmt = $conn->prepare("SELECT * FROM users WHERE status = ? ORDER BY role DESC, created_at DESC, id DESC");
    $listStmt->bind_param("s", $filter);
    $listStmt->execute();
    $listResult = $listStmt->get_result();
}
$userRows = $listResult ? $listResult->fetch_all(MYSQLI_ASSOC) : [];
$approvedAdmins = approved_admin_count($conn);

function mu_chip($status){
    if($status === USER_STATUS_PENDING){ return 'chip-yellow'; }
    if($status === USER_STATUS_REJECTED){ return 'chip-red'; }
    return 'chip-green';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Accounts</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}
.nav-wrap{max-width:1680px;margin:auto;min-height:74px;padding:0 clamp(14px,2vw,32px);display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}
.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}
.nav-links{display:flex;gap:20px;flex-wrap:wrap;align-items:center}
.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}
.page{max-width:1680px;margin:26px auto 42px;padding:0 clamp(14px,2vw,32px)}
.page-title{margin:0 0 4px;font-size:26px;font-weight:800}
.muted-copy{color:#66758d;font-size:13px;font-weight:600}
.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}
.panel-pad{padding:16px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 14px}
.tab{min-height:36px;border-radius:5px;background:#fff;border:1px solid #c8d4e7;color:#2e67b8;text-decoration:none;font-size:13px;font-weight:800;padding:8px 14px;display:inline-flex;align-items:center;gap:7px}
.tab.active{background:#316fc4;border-color:#316fc4;color:#fff}
.tab .count{background:rgba(46,103,184,.12);border-radius:999px;padding:1px 8px;font-size:11.5px}
.tab.active .count{background:rgba(255,255,255,.25)}
.table-wrap{overflow-x:auto}
.user-table{width:100%;border-collapse:collapse;min-width:960px}
.user-table th{background:#f1f5fb;color:#56637a;font-size:12px;text-align:left;padding:11px 12px;border-bottom:2px solid #dbe3ef;text-transform:uppercase;letter-spacing:.3px}
.user-table td{border-bottom:1px solid #e7edf6;padding:11px 12px;font-size:13.5px;vertical-align:middle}
.user-name{font-weight:800;color:#26354b}
.user-sub{color:#8794a8;font-size:12px;font-weight:600}
.chip{display:inline-flex;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap}
.chip-green{background:#cdeedc;color:#277548}
.chip-yellow{background:#fff0ba;color:#806119}
.chip-red{background:#ffd6d0;color:#a33831}
.chip-purple{background:#efe9fb;color:#5b3fa0}
.chip-steel{background:#e4e9f1;color:#4c5a72}
.row-actions{display:flex;gap:6px;flex-wrap:wrap}
.btn-approve,.btn-reject,.btn-revoke{border:0;border-radius:5px;font-weight:800;font-size:12px;padding:7px 12px;display:inline-flex;align-items:center;gap:5px;cursor:pointer}
.btn-approve{background:#2fa66a;color:#fff}
.btn-approve:hover{background:#268a58}
.btn-reject{background:#c23b36;color:#fff}
.btn-reject:hover{background:#a5302b}
.btn-revoke{background:#eef1f6;color:#4c5a72}
.btn-revoke:hover{background:#e3e8f0}
.empty-state{padding:34px 18px;text-align:center;color:#8794a8;font-weight:700}
</style>
</head>
<body>
<?php render_page_background(); ?>
<header class="topbar"><div class="nav-wrap"><div class="brand"><span class="brand-icon"><img src="assets/sbc-logo.png" alt="St. Bridget College" style="width:100%;height:100%;object-fit:contain;border-radius:inherit"></span><span>User Accounts</span></div><nav class="nav-links"><a href="home.php">Home</a><a href="repository.php">Repository</a><a href="activity_log.php">Activity Log</a><a href="manage_users.php">Users</a><?php render_profile_dropdown('admin_profile.php', 'Admin Profile'); ?></nav></div></header>

<main class="page">
    <h1 class="page-title">User Accounts</h1>
    <div class="muted-copy">Review who may sign in, and which department they belong to.</div>

    <?php if($notice !== ""): ?>
        <div class="alert alert-<?php echo htmlspecialchars($noticeType, ENT_QUOTES); ?> mt-3 mb-0"><?php echo htmlspecialchars($notice, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <div class="tabs">
        <?php
        $tabs = [
            USER_STATUS_PENDING  => ['Pending', $counts[USER_STATUS_PENDING]],
            USER_STATUS_APPROVED => ['Approved', $counts[USER_STATUS_APPROVED]],
            USER_STATUS_REJECTED => ['Rejected', $counts[USER_STATUS_REJECTED]],
            'all'                => ['All', array_sum($counts)],
        ];
        foreach($tabs as $key => $tab):
        ?>
            <a class="tab<?php echo $filter === $key ? ' active' : ''; ?>" href="manage_users.php?status=<?php echo urlencode($key); ?>">
                <?php echo htmlspecialchars($tab[0], ENT_QUOTES); ?> <span class="count"><?php echo (int) $tab[1]; ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <section class="panel panel-pad">
        <div class="table-wrap">
            <table class="user-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th style="width:120px">Role</th>
                        <th>Department</th>
                        <th>Contact</th>
                        <th style="width:150px">Status</th>
                        <th>Reviewed</th>
                        <th style="width:230px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($userRows)): ?>
                    <tr><td colspan="7" class="empty-state">No accounts in this list.</td></tr>
                <?php else: foreach($userRows as $row):
                    $status = $row['status'] !== '' ? $row['status'] : USER_STATUS_APPROVED;
                    $displayName = trim($row['full_name']) !== '' ? $row['full_name'] : $row['username'];
                    $isAdminRow = $row['role'] === 'admin';
                    $isSelf = $row['username'] === $adminUsername;
                    $isLastAdmin = $isAdminRow && $status === USER_STATUS_APPROVED && $approvedAdmins <= 1;
                ?>
                    <tr>
                        <td>
                            <div class="user-name"><?php echo htmlspecialchars($displayName, ENT_QUOTES); ?></div>
                            <div class="user-sub">@<?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?><?php echo $isSelf ? ' · you' : ''; ?></div>
                        </td>
                        <td>
                            <span class="chip <?php echo $isAdminRow ? 'chip-purple' : 'chip-steel'; ?>">
                                <?php echo $isAdminRow ? 'Administrator' : 'Department'; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row['office'] !== '' && !$isAdminRow ? $row['office'] : ($isAdminRow ? 'All departments' : '—'), ENT_QUOTES); ?></td>
                        <td>
                            <div><?php echo htmlspecialchars($row['email'] !== '' ? $row['email'] : '—', ENT_QUOTES); ?></div>
                            <div class="user-sub"><?php echo htmlspecialchars(trim((string) $row['phone']) !== '' ? $row['phone'] : 'No phone', ENT_QUOTES); ?></div>
                        </td>
                        <td>
                            <span class="chip <?php echo mu_chip($status); ?>"><?php echo htmlspecialchars(user_status_label($status), ENT_QUOTES); ?></span>
                            <?php if($status === USER_STATUS_REJECTED && trim((string) $row['review_reason']) !== ''): ?>
                                <div class="user-sub mt-1"><?php echo htmlspecialchars($row['review_reason'], ENT_QUOTES); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="user-sub">
                            <?php if(!empty($row['reviewed_at'])): ?>
                                <?php echo htmlspecialchars(date('M j, Y', strtotime($row['reviewed_at'])), ENT_QUOTES); ?>
                                by <?php echo htmlspecialchars((string) $row['reviewed_by'], ENT_QUOTES); ?>
                            <?php else: ?>
                                Not yet reviewed
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <?php if($status !== USER_STATUS_APPROVED): ?>
                                    <form method="POST" onsubmit="return confirm('Approve <?php echo htmlspecialchars(addslashes($row['username']), ENT_QUOTES); ?>?');">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="decision" value="<?php echo USER_STATUS_APPROVED; ?>">
                                        <button type="submit" name="review_user" class="btn-approve"><i class="bi bi-check2"></i> Approve</button>
                                    </form>
                                <?php endif; ?>

                                <?php if($isSelf): ?>
                                    <span class="user-sub">Your own account</span>
                                <?php elseif($isLastAdmin): ?>
                                    <span class="user-sub">Last administrator</span>
                                <?php elseif($status === USER_STATUS_PENDING): ?>
                                    <form method="POST" onsubmit="return mu_reject(this);">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="decision" value="<?php echo USER_STATUS_REJECTED; ?>">
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" name="review_user" class="btn-reject"><i class="bi bi-x-lg"></i> Reject</button>
                                    </form>
                                <?php elseif($status === USER_STATUS_APPROVED): ?>
                                    <form method="POST" onsubmit="return mu_reject(this);">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="decision" value="<?php echo USER_STATUS_REJECTED; ?>">
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" name="review_user" class="btn-revoke"><i class="bi bi-slash-circle"></i> Revoke access</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
function mu_reject(form){
    var reason = window.prompt('Reason (optional) — the user sees this when they try to sign in:', '');
    if(reason === null){ return false; }
    form.reason.value = reason;
    return true;
}
</script>
</body>
</html>
