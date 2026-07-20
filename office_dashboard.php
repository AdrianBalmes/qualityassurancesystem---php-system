<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/recommendation_status.php";

$siteContent = sc_load($conn);

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
$officeAuditType = audit_type_for_office($office);

if(isset($_POST['upload'])){

    $title  = trim($_POST['title']);
    $status = trim($_POST['status']);

    $original_name = basename($_FILES['file']['name']);
    $tmp_name  = $_FILES['file']['tmp_name'];
    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed_ext = ['doc', 'docx', 'pdf', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];

    if(!in_array($file_ext, $allowed_ext, true)){
        echo "<script>alert('Only document, spreadsheet, presentation, PDF, JPG, and PNG files can be submitted.'); window.location=" . json_encode($officeDashboardUrl) . ";</script>";
        exit();
    }

    $safe_base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($original_name, PATHINFO_FILENAME));
    $file_name = $safe_base . "_" . date("YmdHis") . "_" . bin2hex(random_bytes(3)) . "." . $file_ext;
    $upload_path = "uploads/" . $file_name;

    if(move_uploaded_file($tmp_name, $upload_path)){
        $stmt = $conn->prepare("INSERT INTO documents (office,title,status,file_name,approval_status) VALUES (?,?,?,?,?)");
        $approval_status = 'Pending';
        $stmt->bind_param("sssss",$office,$title,$status,$file_name,$approval_status);
        $stmt->execute();

        echo "<script>alert('Document Submitted Successfully! Waiting for Admin Approval.'); window.location=" . json_encode($officeDashboardUrl) . ";</script>";
    }else{
        echo "<script>alert('Upload Failed!');</script>";
    }
}

if(isset($_POST['submit_compliance'])){
    $recId = intval($_POST['recommendation_id'] ?? 0);
    $complianceResponse = trim($_POST['compliance_response'] ?? '');

    $ownStmt = $conn->prepare("SELECT id FROM audit_recommendations WHERE id = ? AND office = ? LIMIT 1");
    $ownStmt->bind_param("is", $recId, $office);
    $ownStmt->execute();
    $ownsRow = $ownStmt->get_result()->num_rows > 0;

    if(!$ownsRow){
        echo "<script>alert('That recommendation does not belong to your office.'); window.location=" . json_encode($officeDashboardUrl) . ";</script>";
        exit();
    }

    $updateStmt = $conn->prepare("UPDATE audit_recommendations SET remarks = ? WHERE id = ? AND office = ?");
    $updateStmt->bind_param("sis", $complianceResponse, $recId, $office);
    $updateStmt->execute();

    if(!empty($_FILES['compliance_file']['name'])){
        $original_name = basename($_FILES['compliance_file']['name']);
        $tmp_name = $_FILES['compliance_file']['tmp_name'];
        $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_ext = ['doc', 'docx', 'pdf', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];

        if(!in_array($file_ext, $allowed_ext, true)){
            echo "<script>alert('Compliance saved. The attached file type is not supported, so it was not uploaded.'); window.location=" . json_encode($officeDashboardUrl . "#recommendations") . ";</script>";
            exit();
        }

        $safe_base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($original_name, PATHINFO_FILENAME));
        $file_name = $safe_base . "_" . date("YmdHis") . "_" . bin2hex(random_bytes(3)) . "." . $file_ext;
        $upload_path = "uploads/" . $file_name;

        if(move_uploaded_file($tmp_name, $upload_path)){
            $docStmt = $conn->prepare("INSERT INTO recommendation_documents (recommendation_id, office, file_name, original_name) VALUES (?,?,?,?)");
            $docStmt->bind_param("isss", $recId, $office, $file_name, $original_name);
            $docStmt->execute();
        }
    }

    echo "<script>alert('Compliance update submitted successfully.'); window.location=" . json_encode($officeDashboardUrl . "#recommendations") . ";</script>";
    exit();
}

$implemented = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Implemented' AND office='$office'"))['total'];
$partial = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Partially' AND office='$office'"))['total'];
$notimpl = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Not Implemented' AND office='$office'"))['total'];
$totaldocs = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE office='$office'"))['total'];

$feedbackStmt = $conn->prepare("SELECT f.document_id, f.message, f.date_sent, d.title, d.file_name, d.file_link FROM feedback f LEFT JOIN documents d ON f.document_id = d.id WHERE f.office = ? ORDER BY f.date_sent DESC LIMIT 10");
$feedbackStmt->bind_param("s", $office);
$feedbackStmt->execute();
$feedbackMessages = $feedbackStmt->get_result();

$recStmt = $conn->prepare("SELECT * FROM audit_recommendations WHERE office = ? ORDER BY year DESC, id DESC");
$recStmt->bind_param("s", $office);
$recStmt->execute();
$recommendations = $recStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recStats = compute_recommendation_stats($recommendations);

$recIds = array_map(function($row){ return (int) $row['id']; }, $recommendations);
$docsByRecommendation = [];
if(!empty($recIds)){
    $placeholders = implode(',', array_fill(0, count($recIds), '?'));
    $docTypes = str_repeat('i', count($recIds));
    $docStmt = $conn->prepare("SELECT * FROM recommendation_documents WHERE recommendation_id IN ($placeholders) ORDER BY uploaded_at DESC");
    $docStmt->bind_param($docTypes, ...$recIds);
    $docStmt->execute();
    $docResult = $docStmt->get_result();
    while($docRow = $docResult->fetch_assoc()){
        $docsByRecommendation[(int) $docRow['recommendation_id']][] = $docRow;
    }
}

$officeNames = get_all_office_names($conn);
$peerOffices = array_values(array_filter($officeNames, function($name) use ($officeAuditType){
    return audit_type_for_office($name) === $officeAuditType;
}));

$allRowsForAuditType = [];
$peerStmt = $conn->prepare("SELECT * FROM audit_recommendations WHERE audit_type = ?");
$peerStmt->bind_param("s", $officeAuditType);
$peerStmt->execute();
$peerResult = $peerStmt->get_result();
while($peerRow = $peerResult->fetch_assoc()){
    $allRowsForAuditType[$peerRow['office']][] = $peerRow;
}

$peerLabels = [];
$peerCompliance = [];
foreach($peerOffices as $peerOffice){
    $peerRows = $allRowsForAuditType[$peerOffice] ?? [];
    $peerStats = compute_recommendation_stats($peerRows);
    $peerLabels[] = $peerOffice;
    $peerCompliance[] = $peerStats['compliance_pct'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($office, ENT_QUOTES); ?> Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body{margin:0;background:#f1f5fb;color:#344156;font-family:Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}
.nav-wrap{max-width:1180px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}
.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}
.nav-links{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.nav-links a,.nav-links button{color:#eef4ff;text-decoration:none;font-weight:700;background:none;border:0;padding:0}
.page{max-width:1180px;margin:26px auto 42px;padding:0 18px}
.page-head{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.page-title{margin:0;font-size:26px;font-weight:800}
.muted-copy{color:#66758d;font-size:13px;font-weight:600}
.office-switcher{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.office-switch{min-height:34px;border-radius:5px;background:#fff;border:1px solid #c8d4e7;color:#2e67b8;text-decoration:none;font-size:13px;font-weight:800;padding:7px 10px}
.office-switch.active{background:#316fc4;border-color:#316fc4;color:#fff}
.panel{background:#fff;border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 6px 18px rgba(44,74,119,.08)}
.panel-pad{padding:18px}
.panel-title{margin:0 0 14px;padding-bottom:12px;border-bottom:1px solid #eef1f6;font-size:16px;font-weight:800;display:flex;align-items:center;gap:8px}
.section-heading{margin:24px 4px 12px;font-size:19px;font-weight:800;color:#344156}
.summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}
.summary-card{background:#fff;border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 6px 18px rgba(44,74,119,.08);padding:16px;display:flex;flex-direction:column;gap:6px}
.summary-card .icon{width:36px;height:36px;border-radius:8px;display:grid;place-items:center;font-size:17px;color:#fff}
.summary-card .label{font-size:12.5px;font-weight:800;color:#66758d}
.summary-card .value{font-size:26px;font-weight:800;color:#26354b}
.icon-total{background:#316fc4}
.icon-completed{background:#2fa66a}
.icon-ongoing{background:#e0a51d}
.icon-overdue{background:#e0533f}
.icon-compliance{background:#7a5fd6}
.charts-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:16px;margin-bottom:20px}
.chart-box{height:260px;position:relative}
.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px}
.stat-box{min-height:72px;border-radius:8px;display:flex;flex-direction:column;justify-content:center;gap:4px;padding:12px 14px;font-weight:800}
.stat-box span{font-size:13px}
.stat-box strong{font-size:26px;line-height:1}
.stat-green{background:#e0f6ea;color:#236b41}
.stat-yellow{background:#fff3d6;color:#7a5a13}
.stat-red{background:#fde3df;color:#96352f}
.stat-steel{background:#e3eaf8;color:#2d5f9e}
.content-grid{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.15fr);gap:16px}
.content-grid>.panel{min-width:0}
.feedback-list{display:grid;gap:10px;max-height:420px;overflow:auto}
.feedback-item{border:1px solid #dbe3ef;background:#f8fbff;border-radius:7px;padding:12px}
.feedback-title{font-weight:800;color:#344156}
.feedback-date{color:#66758d;font-size:12px;font-weight:800}
.feedback-body{margin:7px 0 0;color:#344156;font-size:14px;line-height:1.45;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere}
.form-label{font-weight:800}
.action-btn{min-height:38px;border:0;border-radius:6px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:8px 14px}
.table-wrap{overflow-x:auto}
.dashboard-table{min-width:650px}
.dashboard-table th{background:#f4f7fc;color:#56637a;font-size:12.5px}
.dashboard-table td{vertical-align:top;font-weight:600;font-size:13px}
.rec-text-cell{min-width:260px;word-break:break-word;overflow-wrap:anywhere}
.status-chip{display:inline-flex;padding:4px 8px;border-radius:5px;font-size:12px;font-weight:800}
.chip-green{background:#d9f4e4;color:#237a4c}
.chip-yellow{background:#fff0ba;color:#806119}
.chip-red{background:#fddcd6;color:#a33831}
.link-strong{font-weight:800;text-decoration:none;color:#2e67b8}
.empty-state{padding:16px;text-align:center;color:#66758d;font-weight:700}
.doc-pill-list{display:flex;flex-direction:column;gap:4px}
.doc-pill{font-size:11.5px;font-weight:700;color:#2e67b8;text-decoration:none;word-break:break-word}
.compliance-btn{border:0;background:#eef4ff;color:#2e67b8;border-radius:6px;padding:6px 10px;font-weight:800;font-size:12.5px;white-space:nowrap}
form{max-width:100%}input,select,textarea{max-width:100%}
img,canvas,svg{max-width:100%}
@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(3,1fr)}.charts-grid{grid-template-columns:1fr}}
@media(max-width:900px){.content-grid{grid-template-columns:1fr}.stat-strip{grid-template-columns:repeat(2,1fr)}.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 18px}.page-head{align-items:flex-start;flex-direction:column}.office-switcher{justify-content:flex-start}}
@media(max-width:620px){.brand{font-size:18px}.stat-strip{grid-template-columns:1fr}.summary-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.page{padding:0 10px;margin:16px auto 28px}.panel-pad{padding:12px}.panel-title{font-size:15px}.page-title{font-size:20px}.brand{font-size:15px;gap:8px}.brand-icon{width:32px;height:32px;font-size:15px}.nav-links{gap:12px;font-size:13px}.stat-box{padding:10px}.stat-box strong{font-size:22px}.office-switch{padding:6px 8px;font-size:12px}.summary-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-file-earmark-text-fill"></i></span><?php sc_span($siteContent, 'office.brand', 'SBC Quality Assurance Electronic Documentation'); ?></div>
        <nav class="nav-links">
            <a href="<?php echo htmlspecialchars($officeDashboardUrl, ENT_QUOTES); ?>">Dashboard</a>
            <a href="index.php">Login Office</a>
            <a href="repository.php">Repository</a>
            <button type="button" data-bs-toggle="modal" data-bs-target="#feedbackModal">QA Feedback</button>
            <a href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
        </nav>
    </div>
</header>
<main class="page">
<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo htmlspecialchars($office, ENT_QUOTES); ?> Dashboard</h1>
        <div class="muted-copy">Signed in as <?php echo htmlspecialchars($_SESSION['office_username'], ENT_QUOTES); ?> &middot; <?php echo htmlspecialchars($officeAuditType, ENT_QUOTES); ?> Audit</div>
    </div>
    <?php if(isset($_SESSION['office_logins']) && is_array($_SESSION['office_logins']) && count($_SESSION['office_logins']) > 1): ?>
    <div class="office-switcher" aria-label="Logged in offices">
        <?php foreach($_SESSION['office_logins'] as $loggedOffice => $loginData):
            $safeLoggedOffice = htmlspecialchars($loggedOffice, ENT_QUOTES);
            $switchUrl = "office_dashboard.php?office=" . urlencode($loggedOffice);
            $activeClass = $loggedOffice === $office ? " active" : "";
        ?>
            <a class="office-switch<?php echo $activeClass; ?>" href="<?php echo htmlspecialchars($switchUrl, ENT_QUOTES); ?>"><?php echo $safeLoggedOffice; ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<h2 class="section-heading"><?php echo htmlspecialchars($officeAuditType, ENT_QUOTES); ?> Audit Recommendations Overview</h2>
<section class="summary-grid">
    <div class="summary-card"><span class="icon icon-total"><i class="bi bi-list-check"></i></span><span class="label">Total Recommendations</span><span class="value"><?php echo $recStats['total']; ?></span></div>
    <div class="summary-card"><span class="icon icon-completed"><i class="bi bi-check2-circle"></i></span><span class="label">Completed</span><span class="value"><?php echo $recStats['completed']; ?></span></div>
    <div class="summary-card"><span class="icon icon-ongoing"><i class="bi bi-hourglass-split"></i></span><span class="label">Ongoing</span><span class="value"><?php echo $recStats['ongoing']; ?></span></div>
    <div class="summary-card"><span class="icon icon-overdue"><i class="bi bi-exclamation-triangle-fill"></i></span><span class="label">Overdue</span><span class="value"><?php echo $recStats['overdue']; ?></span></div>
    <div class="summary-card"><span class="icon icon-compliance"><i class="bi bi-graph-up-arrow"></i></span><span class="label">Compliance Rate</span><span class="value"><?php echo $recStats['compliance_pct']; ?>%</span></div>
</section>

<section class="charts-grid">
    <div class="panel panel-pad">
        <h2 class="panel-title"><i class="bi bi-pie-chart-fill"></i> Your Recommendation Status</h2>
        <div class="chart-box"><canvas id="ownStatusChart"></canvas></div>
    </div>
    <div class="panel panel-pad">
        <h2 class="panel-title"><i class="bi bi-bar-chart-fill"></i> Compliance Progress Across Offices <span class="muted-copy" style="font-weight:700">(view only)</span></h2>
        <div class="chart-box"><canvas id="peerComplianceChart"></canvas></div>
    </div>
</section>

<section class="panel panel-pad" id="recommendations">
    <h2 class="panel-title"><i class="bi bi-clipboard-data"></i> <?php echo htmlspecialchars($officeAuditType, ENT_QUOTES); ?> Audit Recommendations</h2>
    <div class="muted-copy" style="margin-bottom:12px">These recommendations are assigned and managed by the Internal Audit administrator. You can view them and submit your compliance response, action taken, and supporting documents &mdash; you cannot add, edit, or delete a recommendation.</div>
    <div class="table-wrap">
        <table class="table dashboard-table">
            <thead>
                <tr>
                    <th>Recommendation</th>
                    <th style="width:110px">Status</th>
                    <th style="width:70px">Year</th>
                    <th style="width:220px">Compliance Response</th>
                    <th style="width:160px">Supporting Documents</th>
                    <th style="width:120px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if(!empty($recommendations)){
                    foreach($recommendations as $row){
                        $recId = (int) $row['id'];
                        $recText = nl2br(htmlspecialchars($row['recommendation'], ENT_QUOTES));
                        $label = classify_recommendation_status($row);
                        $chipClass = recommendation_status_chip_class($label);
                        $year = $row['year'] !== '' ? htmlspecialchars($row['year'], ENT_QUOTES) : '&mdash;';
                        $remarksDisplay = $row['remarks'] !== null && $row['remarks'] !== '' ? nl2br(htmlspecialchars($row['remarks'], ENT_QUOTES)) : '<span class="muted-copy">Not submitted yet</span>';
                        $safeRemarksValue = htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES);

                        $docsHtml = '<span class="muted-copy">None yet</span>';
                        if(!empty($docsByRecommendation[$recId])){
                            $docsHtml = "<div class='doc-pill-list'>";
                            foreach($docsByRecommendation[$recId] as $doc){
                                $docUrl = "uploads/" . rawurlencode($doc['file_name']);
                                $docLabel = htmlspecialchars($doc['original_name'], ENT_QUOTES);
                                $docsHtml .= "<a class='doc-pill' href='" . htmlspecialchars($docUrl, ENT_QUOTES) . "' target='_blank'><i class='bi bi-paperclip'></i> {$docLabel}</a>";
                            }
                            $docsHtml .= "</div>";
                        }

                        echo "
                        <tr>
                            <td class='rec-text-cell'>{$recText}</td>
                            <td><span class='status-chip {$chipClass}'>{$label}</span></td>
                            <td>{$year}</td>
                            <td class='rec-text-cell'>{$remarksDisplay}</td>
                            <td>{$docsHtml}</td>
                            <td>
                                <button type='button' class='compliance-btn' data-bs-toggle='modal' data-bs-target='#complianceModal' data-rec-id='{$recId}' data-remarks=\"{$safeRemarksValue}\">
                                    <i class='bi bi-pencil-square'></i> Update Compliance
                                </button>
                            </td>
                        </tr>
                        ";
                    }
                } else {
                    echo "<tr><td colspan='6' class='empty-state'>No audit recommendations have been assigned to your office yet.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</section>

<h2 class="section-heading">Document Submissions</h2>
<section class="stat-strip">
    <div class="stat-box stat-green"><span>Implemented</span><strong><?php echo $implemented; ?></strong></div>
    <div class="stat-box stat-yellow"><span>Partially Implemented</span><strong><?php echo $partial; ?></strong></div>
    <div class="stat-box stat-red"><span>Not Implemented</span><strong><?php echo $notimpl; ?></strong></div>
    <div class="stat-box stat-steel"><span>Total Documents</span><strong><?php echo $totaldocs; ?></strong></div>
</section>
<section class="content-grid">
    <div class="panel panel-pad">
        <h2 class="panel-title"><i class="bi bi-upload"></i> <?php sc_span($siteContent, 'office.doc_form.title', 'Submit Document for Approval'); ?></h2>
        <form method="POST" action="<?php echo htmlspecialchars($officeDashboardUrl, ENT_QUOTES); ?>" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">Document Title</label>
                <input type="text" name="title" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Implementation Status</label>
                <select name="status" class="form-select" required>
                    <option value="">Select status</option>
                    <option value="Implemented">Implemented</option>
                    <option value="Partially">Partially Implemented</option>
                    <option value="Not Implemented">Not Implemented</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">File</label>
                <input type="file" name="file" class="form-control" accept=".doc,.docx,.pdf,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png" required>
                <div class="muted-copy mt-1">Accepted: DOC, DOCX, PDF, XLS, XLSX, PPT, PPTX, JPG, PNG.</div>
            </div>

            <button type="submit" name="upload" class="action-btn w-100"><i class="bi bi-upload"></i> Submit for Approval</button>
        </form>
    </div>
    <div class="panel panel-pad">
        <h2 class="panel-title"><i class="bi bi-folder2-open"></i> <?php sc_span($siteContent, 'office.doc_table.title', 'Your Uploaded Documents'); ?></h2>
        <div class="table-wrap">
            <table class="table dashboard-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Approval Status</th>
                        <th>Uploaded Date</th>
                        <th>File</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $result = mysqli_query($conn,"SELECT * FROM documents WHERE office='$office' ORDER BY id DESC");

                    if(mysqli_num_rows($result) > 0){
                        while($row = mysqli_fetch_assoc($result)){
                            if($row['approval_status'] == "Approved"){
                                $badge = "<span class='status-chip chip-green'>Approved</span>";
                            } elseif($row['approval_status'] == "Rejected"){
                                $badge = "<span class='status-chip chip-red'>Rejected</span>";
                            } else {
                                $badge = "<span class='status-chip chip-yellow'>Pending</span>";
                            }

                            $uploadedDate = isset($row['created_at']) ? $row['created_at'] : 'N/A';
                            $viewUrl = "view_document.php?id=" . (int) $row['id'] . "&office=" . urlencode($office);
                            $viewLabel = "View";
                            $statusClass = $row['status'] == 'Implemented' ? 'chip-green' : ($row['status'] == 'Partially' ? 'chip-yellow' : 'chip-red');
                            $safeTitle = htmlspecialchars($row['title'], ENT_QUOTES);
                            $safeStatus = htmlspecialchars($row['status'], ENT_QUOTES);
                            echo "
                            <tr>
                                <td>{$safeTitle}</td>
                                <td><span class='status-chip {$statusClass}'>{$safeStatus}</span></td>
                                <td>$badge</td>
                                <td>{$uploadedDate}</td>
                                <td><a href='" . htmlspecialchars($viewUrl, ENT_QUOTES) . "' target='_blank' class='link-strong'>" . htmlspecialchars($viewLabel, ENT_QUOTES) . "</a></td>
                            </tr>
                            ";
                        }
                    } else {
                        echo "<tr><td colspan='5' class='empty-state'>No documents uploaded yet</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
</main>

<div class="modal fade" id="complianceModal" tabindex="-1" aria-labelledby="complianceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?php echo htmlspecialchars($officeDashboardUrl, ENT_QUOTES); ?>#recommendations" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="complianceModalLabel">Update Compliance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="recommendation_id" id="complianceRecId" value="">
                    <div class="mb-3">
                        <label class="form-label">Compliance Response / Action Taken</label>
                        <textarea name="compliance_response" id="complianceResponseField" class="form-control" rows="5" placeholder="Describe the action taken or your compliance response..."></textarea>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Supporting Document (optional)</label>
                        <input type="file" name="compliance_file" class="form-control" accept=".doc,.docx,.pdf,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                        <div class="muted-copy mt-1">Accepted: DOC, DOCX, PDF, XLS, XLSX, PPT, PPTX, JPG, PNG.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_compliance" class="btn btn-primary">Submit Compliance Update</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="feedbackModal" tabindex="-1" aria-labelledby="feedbackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="feedbackModalLabel">QA Feedback Messages</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="feedback-list">
                    <?php
                    if($feedbackMessages && mysqli_num_rows($feedbackMessages) > 0){
                        while($fb = mysqli_fetch_assoc($feedbackMessages)){
                            $fbTitle = htmlspecialchars($fb['title'] ?? 'Document removed', ENT_QUOTES);
                            $fbFileName = htmlspecialchars($fb['file_name'] ?? 'N/A', ENT_QUOTES);
                            $fbMessage = htmlspecialchars($fb['message'], ENT_QUOTES);
                            $fbDate = htmlspecialchars($fb['date_sent'], ENT_QUOTES);
                            $viewUrl = !empty($fb['document_id']) ? "view_document.php?id=" . (int) $fb['document_id'] . "&office=" . urlencode($office) : "";
                            $safeViewUrl = htmlspecialchars($viewUrl, ENT_QUOTES);
                            echo "<article class='feedback-item'><div class='feedback-title'>{$fbTitle}</div><div class='muted-copy'>{$fbFileName}</div><div class='feedback-date'>{$fbDate}</div><p class='feedback-body'>{$fbMessage}</p>";
                            if($safeViewUrl !== ""){
                                echo "<a class='link-strong d-inline-block mt-2' href='{$safeViewUrl}' target='_blank'>Open Document</a>";
                            }
                            echo "</article>";
                        }
                    } else {
                        echo "<div class='empty-state'>No QA feedback messages yet</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
new Chart(document.getElementById('ownStatusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Completed', 'Ongoing', 'Overdue'],
        datasets: [{
            data: [<?php echo $recStats['completed']; ?>, <?php echo $recStats['ongoing']; ?>, <?php echo $recStats['overdue']; ?>],
            backgroundColor: ['#2fa66a', '#e0a51d', '#e0533f']
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('peerComplianceChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($peerLabels); ?>,
        datasets: [{
            label: 'Compliance %',
            data: <?php echo json_encode($peerCompliance); ?>,
            backgroundColor: '#316fc4'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, max: 100, ticks: { callback: function(v){ return v + '%'; } } } }
    }
});

(function(){
    var modal = document.getElementById('complianceModal');
    if(!modal){ return; }
    modal.addEventListener('show.bs.modal', function(e){
        var button = e.relatedTarget;
        if(!button){ return; }
        document.getElementById('complianceRecId').value = button.getAttribute('data-rec-id') || '';
        document.getElementById('complianceResponseField').value = button.getAttribute('data-remarks') || '';
    });
})();
</script>
<?php render_edit_toggle(); ?>
</body>
</html>
