<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/nav_dropdown.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'admin'){
    header("Location: admin_login.php");
    exit();
}

ensure_user_account_columns($conn);
enforce_active_account($conn);
ensure_audit_log_table($conn);

$siteContent = sc_load($conn);
$officeNames = get_all_office_names($conn);

$knownActions = [
    'login' => 'Login',
    'login_failed' => 'Login Failed',
    'login_blocked' => 'Login Blocked',
    'registration_submitted' => 'Registration Submitted',
    'user_approved' => 'Account Approved',
    'user_rejected' => 'Account Rejected',
    'recommendation_created' => 'Recommendation Created',
    'recommendation_updated' => 'Recommendation Updated',
    'recommendation_deleted' => 'Recommendation Deleted',
    'document_uploaded' => 'Document Uploaded',
    'document_deleted' => 'Document Deleted',
    'compliance_submitted' => 'Compliance Submitted',
    'profile_updated' => 'Profile Updated',
];

$filterOffice = isset($_GET['office']) ? trim($_GET['office']) : '';
$filterAction = isset($_GET['action']) ? trim($_GET['action']) : '';
$filterFrom = isset($_GET['from']) ? trim($_GET['from']) : '';
$filterTo = isset($_GET['to']) ? trim($_GET['to']) : '';
$filterQuery = isset($_GET['q']) ? trim($_GET['q']) : '';

$conditions = [];
$params = [];
$types = "";

if($filterOffice !== ''){
    $conditions[] = "office = ?";
    $params[] = $filterOffice;
    $types .= "s";
}
if($filterAction !== '' && isset($knownActions[$filterAction])){
    $conditions[] = "action = ?";
    $params[] = $filterAction;
    $types .= "s";
}
if($filterFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)){
    $conditions[] = "created_at >= ?";
    $params[] = $filterFrom . " 00:00:00";
    $types .= "s";
}
if($filterTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)){
    $conditions[] = "created_at <= ?";
    $params[] = $filterTo . " 23:59:59";
    $types .= "s";
}
if($filterQuery !== ''){
    $conditions[] = "(description LIKE ? OR actor_username LIKE ?)";
    $likeTerm = "%" . $filterQuery . "%";
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $types .= "ss";
}

$whereSql = !empty($conditions) ? " WHERE " . implode(" AND ", $conditions) : "";

if(isset($_GET['export']) && $_GET['export'] === 'csv'){
    $sql = "SELECT * FROM audit_log" . $whereSql . " ORDER BY created_at DESC";
    if(!empty($params)){
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date/Time', 'Actor', 'Role', 'Office', 'Action', 'Description', 'IP Address']);
    while($row = $result->fetch_assoc()){
        $actionLabel = $knownActions[$row['action']] ?? $row['action'];
        fputcsv($out, [$row['created_at'], $row['actor_username'], $row['actor_role'], $row['office'], $actionLabel, $row['description'], $row['ip_address']]);
    }
    fclose($out);
    exit();
}

$perPage = 25;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) AS total FROM audit_log" . $whereSql;
if(!empty($params)){
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $totalRows = (int) $countStmt->get_result()->fetch_assoc()['total'];
} else {
    $totalRows = (int) $conn->query($countSql)->fetch_assoc()['total'];
}
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = "SELECT * FROM audit_log" . $whereSql . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$listParams = $params;
$listTypes = $types . "ii";
$listParams[] = $perPage;
$listParams[] = $offset;
$listStmt = $conn->prepare($listSql);
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$logRows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);

function activity_action_chip_class($action){
    if($action === 'login'){ return 'chip-blue'; }
    if($action === 'login_failed'){ return 'chip-red'; }
    if(strpos($action, 'created') !== false || strpos($action, 'uploaded') !== false){ return 'chip-green'; }
    if(strpos($action, 'deleted') !== false){ return 'chip-red'; }
    if(strpos($action, 'updated') !== false || $action === 'compliance_submitted'){ return 'chip-yellow'; }
    return 'chip-steel';
}

function activity_query_keep(array $overrides = []){
    $current = ['office' => $_GET['office'] ?? '', 'action' => $_GET['action'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? ''];
    $merged = array_merge($current, $overrides);
    $merged = array_filter($merged, function($v){ return $v !== ''; });
    return http_build_query($merged);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity Log</title>
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
.dashboard{max-width:1680px;margin:26px auto 42px;padding:0 clamp(14px,2vw,32px)}
.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}
.panel-pad{padding:16px}
.panel-title{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:17px;font-weight:800;display:flex;align-items:center;gap:8px}
.muted-copy{color:#66758d;font-size:13px}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:16px;background:#f8fbff;border:1px solid #dbe3ef;border-radius:6px;padding:14px}
.filter-field{display:flex;flex-direction:column;gap:4px}
.filter-field label{font-size:11.5px;font-weight:800;color:#66758d;text-transform:uppercase}
.filter-field select,.filter-field input{min-height:38px;border:1px solid #cfd9e8;border-radius:5px;padding:6px 10px;font-size:13px}
.filter-actions{display:flex;gap:8px;margin-left:auto}
.btn-primary-sm{min-height:38px;border:0;border-radius:5px;background:#316fc4;color:#fff;font-weight:800;padding:8px 14px;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-secondary-sm{min-height:38px;border:1px solid #c8d4e7;border-radius:5px;background:#fff;color:#2e67b8;font-weight:800;padding:8px 14px;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.table-wrap{overflow-x:auto}
.log-table{width:100%;border-collapse:collapse;min-width:900px}
.log-table th{background:#f1f5fb;color:#56637a;font-size:12.5px;text-align:left;padding:10px;border-bottom:2px solid #dbe3ef;white-space:nowrap}
.log-table td{padding:10px;border-bottom:1px solid #eef2f8;font-size:13px;vertical-align:top}
.log-desc{max-width:420px;word-break:break-word}
.action-chip{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap}
.chip-blue{background:#d8e2f5;color:#2e5fa3}
.chip-green{background:#cdeedc;color:#277548}
.chip-yellow{background:#fff0ba;color:#806119}
.chip-red{background:#ffd6d0;color:#a33831}
.chip-steel{background:#e4e9f1;color:#4c5a72}
.empty-state{padding:24px;text-align:center;color:#66758d;font-weight:700}
.pagination-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;flex-wrap:wrap}
.page-links{display:flex;gap:6px;flex-wrap:wrap}
.page-link{min-width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:5px;border:1px solid #c8d4e7;color:#2e67b8;text-decoration:none;font-weight:800;font-size:13px}
.page-link.active{background:#316fc4;border-color:#316fc4;color:#fff}
@media(max-width:760px){.filter-actions{margin-left:0;width:100%}.filter-actions a{flex:1;justify-content:center}}
</style>
</head>
<body>
<header class="topbar"><div class="nav-wrap"><div class="brand"><span class="brand-icon"><i class="bi bi-file-earmark-text-fill"></i></span><?php sc_span($siteContent, 'home.brand', 'SBC Quality Assurance Electronic Documentation Dashboard'); ?></div><nav class="nav-links"><a href="home.php">Home</a><a href="repository.php">Repository</a><a href="activity_log.php">Activity Log</a><a href="manage_users.php">Users</a><?php render_profile_dropdown('admin_profile.php', 'Admin Profile'); ?></nav></div></header>
<main class="dashboard">
<section class="panel panel-pad">
    <h2 class="panel-title"><i class="bi bi-clock-history"></i> Activity Log</h2>
    <div class="muted-copy" style="margin-bottom:14px">A record of recommendation changes, document uploads/deletions, compliance submissions, logins, and profile edits across the system.</div>

    <form class="filter-bar" method="GET">
        <div class="filter-field">
            <label for="filterOffice">Office</label>
            <select name="office" id="filterOffice">
                <option value="">All offices</option>
                <?php foreach($officeNames as $officeName): ?>
                <option value="<?php echo htmlspecialchars($officeName, ENT_QUOTES); ?>" <?php echo $filterOffice === $officeName ? 'selected' : ''; ?>><?php echo htmlspecialchars($officeName, ENT_QUOTES); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="filterAction">Action</label>
            <select name="action" id="filterAction">
                <option value="">All actions</option>
                <?php foreach($knownActions as $actionKey => $actionLabel): ?>
                <option value="<?php echo htmlspecialchars($actionKey, ENT_QUOTES); ?>" <?php echo $filterAction === $actionKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($actionLabel, ENT_QUOTES); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="filterFrom">From</label>
            <input type="date" name="from" id="filterFrom" value="<?php echo htmlspecialchars($filterFrom, ENT_QUOTES); ?>">
        </div>
        <div class="filter-field">
            <label for="filterTo">To</label>
            <input type="date" name="to" id="filterTo" value="<?php echo htmlspecialchars($filterTo, ENT_QUOTES); ?>">
        </div>
        <div class="filter-field" style="flex:1;min-width:180px">
            <label for="filterQuery">Search</label>
            <input type="text" name="q" id="filterQuery" placeholder="Actor or description..." value="<?php echo htmlspecialchars($filterQuery, ENT_QUOTES); ?>">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-primary-sm"><i class="bi bi-funnel-fill"></i> Filter</button>
            <a class="btn-secondary-sm" href="activity_log.php"><i class="bi bi-x-circle"></i> Clear</a>
            <a class="btn-secondary-sm" href="activity_log.php?<?php echo activity_query_keep(['export' => 'csv']); ?>"><i class="bi bi-download"></i> Export CSV</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="log-table">
            <thead>
                <tr>
                    <th style="width:160px">Date/Time</th>
                    <th style="width:140px">Actor</th>
                    <th style="width:110px">Office</th>
                    <th style="width:170px">Action</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($logRows)): ?>
                <tr><td colspan="5" class="empty-state">No activity found for these filters.</td></tr>
                <?php else: foreach($logRows as $log):
                    $actionLabel = $knownActions[$log['action']] ?? $log['action'];
                    $chipClass = activity_action_chip_class($log['action']);
                    $whenLabel = date("M j, Y g:i A", strtotime($log['created_at']));
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($whenLabel, ENT_QUOTES); ?></td>
                    <td><strong><?php echo htmlspecialchars($log['actor_username'], ENT_QUOTES); ?></strong><div class="muted-copy"><?php echo htmlspecialchars(ucfirst($log['actor_role']), ENT_QUOTES); ?></div></td>
                    <td><?php echo $log['office'] !== '' ? htmlspecialchars($log['office'], ENT_QUOTES) : '&mdash;'; ?></td>
                    <td><span class="action-chip <?php echo $chipClass; ?>"><?php echo htmlspecialchars($actionLabel, ENT_QUOTES); ?></span></td>
                    <td class="log-desc"><?php echo htmlspecialchars($log['description'], ENT_QUOTES); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="pagination-bar">
        <div class="muted-copy">Showing <?php echo count($logRows); ?> of <?php echo $totalRows; ?> entries &middot; Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
        <div class="page-links">
            <?php if($page > 1): ?><a class="page-link" href="activity_log.php?<?php echo activity_query_keep(['page' => $page - 1]); ?>"><i class="bi bi-chevron-left"></i></a><?php endif; ?>
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            for($p = $startPage; $p <= $endPage; $p++):
            ?>
            <a class="page-link<?php echo $p === $page ? ' active' : ''; ?>" href="activity_log.php?<?php echo activity_query_keep(['page' => $p]); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if($page < $totalPages): ?><a class="page-link" href="activity_log.php?<?php echo activity_query_keep(['page' => $page + 1]); ?>"><i class="bi bi-chevron-right"></i></a><?php endif; ?>
        </div>
    </div>
</section>
</main>
<?php render_edit_toggle(); ?>
</body>
</html>
