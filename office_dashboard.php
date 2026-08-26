<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/recommendation_status.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/review_columns.php";
require_once __DIR__ . "/onedrive_sync.php";

ensure_review_columns($conn);

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

ensure_user_account_columns($conn);
enforce_active_account($conn);

$office = $_SESSION['office_name'];
$officeDashboardUrl = "office_dashboard.php?office=" . urlencode($office);
$officeAuditType = audit_type_for_office($office);

if(isset($_POST['submit_compliance'])){
    $recId = intval($_POST['recommendation_id'] ?? 0);
    $complianceResponse = trim($_POST['compliance_response'] ?? '');

    $ownStmt = $conn->prepare("SELECT id, year FROM audit_recommendations WHERE id = ? AND office = ? LIMIT 1");
    $ownStmt->bind_param("is", $recId, $office);
    $ownStmt->execute();
    $ownedRec = $ownStmt->get_result()->fetch_assoc();
    $ownsRow = $ownedRec !== null;

    if(!$ownsRow){
        echo "<script>alert('That recommendation does not belong to your office.'); window.location=" . json_encode($officeDashboardUrl) . ";</script>";
        exit();
    }

    $updateStmt = $conn->prepare("UPDATE audit_recommendations SET remarks = ?, status = 'Submitted' WHERE id = ? AND office = ?");
    $updateStmt->bind_param("sis", $complianceResponse, $recId, $office);
    $updateStmt->execute();

    log_audit_event($conn, $_SESSION['office_username'], 'office', $office, 'compliance_submitted', 'recommendation', $recId, "{$office} submitted a compliance response for recommendation #{$recId}");

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
            $documentId = $conn->insert_id;

            log_audit_event($conn, $_SESSION['office_username'], 'office', $office, 'document_uploaded', 'document', $documentId, "{$office} uploaded supporting document \"{$original_name}\" for recommendation #{$recId}");

            // Mirror the file into the shared OneDrive repository. This never
            // throws -- if OneDrive is unreachable the file is queued and
            // onedrive_worker.php retries it later.
            od_queue_and_sync($conn, 'recommendation_documents', $documentId, $office, $file_name, $original_name, $ownedRec['year'] ?? '');
        }
    }

    echo "<script>alert('Compliance update submitted successfully.'); window.location=" . json_encode($officeDashboardUrl . "#recommendations") . ";</script>";
    exit();
}

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

$recLookup = [];
foreach($recommendations as $row){
    $recLookup[(int) $row['id']] = $row;
}
$allOfficeDocuments = [];
foreach($docsByRecommendation as $recId => $docs){
    $allOfficeDocuments[] = [
        'recommendation_id' => $recId,
        'recommendation' => $recLookup[$recId]['recommendation'] ?? '',
        'year' => $recLookup[$recId]['year'] ?? '',
        'status' => $recLookup[$recId]['status'] ?? '',
        'docs' => $docs,
    ];
}
usort($allOfficeDocuments, function($a, $b){
    $aLatest = $a['docs'][0]['uploaded_at'] ?? '';
    $bLatest = $b['docs'][0]['uploaded_at'] ?? '';
    return strtotime($bLatest) <=> strtotime($aLatest);
});

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
.nav-wrap{max-width:1680px;margin:auto;min-height:74px;padding:0 clamp(14px,2vw,32px);display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}
.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}
.nav-links{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.nav-links a,.nav-links button{color:#eef4ff;text-decoration:none;font-weight:700;background:none;border:0;padding:0}
.nav-dropdown-toggle{display:inline-flex;align-items:center;gap:6px;cursor:pointer}
/* .nav-links a above would paint these near-white on the white menu. */
.nav-links .dropdown-menu{padding:6px;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 8px 22px rgba(44,74,119,.18);min-width:200px}
.nav-links .dropdown-item{color:#344156;font-weight:700;font-size:13.5px;border-radius:5px;padding:9px 12px;display:flex;align-items:center;gap:9px}
.nav-links .dropdown-item:hover,.nav-links .dropdown-item:focus{background:#eef4ff;color:#2e67b8}
.nav-links .dropdown-item.signout{color:#c23b36}
.nav-links .dropdown-item.signout:hover,.nav-links .dropdown-item.signout:focus{background:#ffe1dc;color:#a5302b}
.page{max-width:1680px;margin:26px auto 42px;padding:0 clamp(14px,2vw,32px)}
.page-head{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.page-title{margin:0;font-size:26px;font-weight:800}
.muted-copy{color:#66758d;font-size:13px;font-weight:600}
.panel{background:#fff;border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 6px 18px rgba(44,74,119,.08)}
.panel-pad{padding:18px}
.panel-title{margin:0 0 14px;padding-bottom:12px;border-bottom:1px solid #eef1f6;font-size:16px;font-weight:800;display:flex;align-items:center;gap:8px}
.section-heading{margin:24px 4px 12px;font-size:19px;font-weight:800;color:#344156}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:20px}
.summary-card{background:#fff;border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 6px 18px rgba(44,74,119,.08);padding:16px;display:flex;flex-direction:column;gap:6px}
.summary-card .icon{width:36px;height:36px;border-radius:8px;display:grid;place-items:center;font-size:17px;color:#fff}
.summary-card .label{font-size:12.5px;font-weight:800;color:#66758d}
.summary-card .value{font-size:26px;font-weight:800;color:#26354b}
.icon-total{background:#316fc4}
.icon-completed{background:#2fa66a}
.icon-ongoing{background:#e0a51d}
.icon-overdue{background:#e0533f}
.icon-compliance{background:#7a5fd6}
.charts-grid{display:grid;grid-template-columns:minmax(320px,1fr) minmax(420px,1.4fr);gap:16px;margin-bottom:20px}
.chart-box{height:260px;position:relative}
.form-label{font-weight:800}
.table-wrap{overflow-x:auto}
.dashboard-table{min-width:650px}
.dashboard-table th{background:#f4f7fc;color:#56637a;font-size:12.5px}
.dashboard-table td{vertical-align:top;font-weight:600;font-size:13px}
.rec-text-cell{min-width:260px;word-break:break-word;overflow-wrap:anywhere}
.status-chip{display:inline-flex;padding:4px 8px;border-radius:5px;font-size:12px;font-weight:800}
.chip-green{background:#d9f4e4;color:#237a4c}
.chip-yellow{background:#fff0ba;color:#806119}
.chip-red{background:#fddcd6;color:#a33831}
.chip-blue{background:#d8e2f5;color:#2e5fa3}
.chip-orange{background:#ffe3c2;color:#95530a}
.chip-steel{background:#e4e9f1;color:#4c5a72}
.review-feedback-block{background:#f8fbff;border:1px solid #e6edf7;border-radius:6px;padding:8px 10px;font-size:12.5px;color:#344156;white-space:pre-wrap;word-break:break-word}
.link-strong{font-weight:800;text-decoration:none;color:#2e67b8}
.empty-state{padding:16px;text-align:center;color:#66758d;font-weight:700}
.doc-pill-list{display:flex;flex-direction:column;gap:8px}
.doc-pill{font-size:13px;font-weight:700;color:#2e67b8;text-decoration:none;word-break:break-word;display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #dbe3ef;border-radius:6px}
.doc-pill:hover{background:#f7fbff}
.doc-trigger-btn{border:1px solid #c8d4e7;border-radius:5px;background:#eef4ff;color:#2e67b8;font-weight:800;font-size:12.5px;padding:6px 10px;display:inline-flex;align-items:center;gap:7px;cursor:pointer}
.doc-trigger-btn:hover{background:#dfeaff}
.doc-count-badge{background:#316fc4;color:#fff;border-radius:999px;min-width:18px;height:18px;padding:0 5px;font-size:11px;display:inline-flex;align-items:center;justify-content:center}
.doc-sidebar-backdrop{position:fixed;inset:0;background:rgba(15,26,42,.4);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:1060}
.doc-sidebar-backdrop.is-open{opacity:1;pointer-events:auto}
.doc-sidebar{position:fixed;top:0;right:0;height:100vh;width:min(360px,92vw);background:#fff;box-shadow:-8px 0 24px rgba(15,26,42,.18);transform:translateX(100%);transition:transform .25s ease;z-index:1061;display:flex;flex-direction:column}
.doc-sidebar.is-open{transform:translateX(0)}
.doc-sidebar-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #e6edf7}
.doc-sidebar-head h3{margin:0;font-size:16px;font-weight:800;color:#26354b}
.doc-sidebar-close{border:0;background:#eef4ff;color:#2e67b8;width:30px;height:30px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
.doc-sidebar-close:hover{background:#dfeaff}
.doc-sidebar-rec{padding:12px 18px;border-bottom:1px solid #e6edf7;font-size:13px;font-weight:700;color:#344156;background:#f8fbff}
.doc-sidebar-body{padding:14px 18px;overflow-y:auto;flex:1}
.compliance-btn{border:0;background:#eef4ff;color:#2e67b8;border-radius:6px;padding:6px 10px;font-weight:800;font-size:12.5px;white-space:nowrap}
form{max-width:100%}input,select,textarea{max-width:100%}
img,canvas,svg{max-width:100%}
@media(max-width:1100px){.charts-grid{grid-template-columns:1fr}}
@media(max-width:900px){.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 18px}.page-head{align-items:flex-start;flex-direction:column}}
@media(max-width:620px){.brand{font-size:18px}}
@media(max-width:480px){.page{padding:0 10px;margin:16px auto 28px}.panel-pad{padding:12px}.panel-title{font-size:15px}.page-title{font-size:20px}.brand{font-size:15px;gap:8px}.brand-icon{width:32px;height:32px;font-size:15px}.nav-links{gap:12px;font-size:13px}}
</style>
</head>
<body>
<?php render_page_background(); ?>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-file-earmark-text-fill"></i></span><?php sc_span($siteContent, 'office.brand', 'SBC Quality Assurance Electronic Documentation'); ?></div>
        <nav class="nav-links">
            <a href="<?php echo htmlspecialchars($officeDashboardUrl, ENT_QUOTES); ?>">Dashboard</a>
            <a href="repository.php">Repository</a>
            <button type="button" data-bs-toggle="modal" data-bs-target="#allDocumentsModal"><i class="bi bi-folder2-open"></i> My Documents</button>
            <div class="dropdown">
                <button type="button" class="nav-dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-circle"></i> Profile <i class="bi bi-chevron-down" style="font-size:11px"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="office_profile.php?office=<?php echo urlencode($office); ?>"><i class="bi bi-person-circle"></i> Office Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item signout" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign Out</a></li>
                </ul>
            </div>
        </nav>
    </div>
</header>
<main class="page">
<div class="page-head">
    <div>
        <h1 class="page-title"><?php echo htmlspecialchars($office, ENT_QUOTES); ?> Dashboard</h1>
        <div class="muted-copy">Signed in as <?php echo htmlspecialchars($_SESSION['office_username'], ENT_QUOTES); ?> &middot; <?php echo htmlspecialchars($officeAuditType, ENT_QUOTES); ?> Audit</div>
    </div>
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
                    <th style="width:130px">Status</th>
                    <th style="width:70px">Year</th>
                    <th style="width:200px">Compliance Response</th>
                    <th style="width:200px">Review Feedback</th>
                    <th style="width:150px">Supporting Documents</th>
                    <th style="width:120px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if(!empty($recommendations)){
                    foreach($recommendations as $row){
                        $recId = (int) $row['id'];
                        $recText = nl2br(htmlspecialchars($row['recommendation'], ENT_QUOTES));
                        $statusInfo = review_status_chip_info($row['status']);
                        $label = $statusInfo['label'];
                        $chipClass = $statusInfo['class'];
                        $year = $row['year'] !== '' ? htmlspecialchars($row['year'], ENT_QUOTES) : '&mdash;';
                        $remarksDisplay = $row['remarks'] !== null && $row['remarks'] !== '' ? nl2br(htmlspecialchars($row['remarks'], ENT_QUOTES)) : '<span class="muted-copy">Not submitted yet</span>';
                        $safeRemarksValue = htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES);
                        $reviewFeedbackDisplay = !empty($row['review_remarks']) ? nl2br(htmlspecialchars($row['review_remarks'], ENT_QUOTES)) : '<span class="muted-copy">No feedback yet</span>';

                        $docsHtml = '<span class="muted-copy">None yet</span>';
                        if(!empty($docsByRecommendation[$recId])){
                            $docsForJs = [];
                            foreach($docsByRecommendation[$recId] as $doc){
                                $docsForJs[] = [
                                    'url' => "uploads/" . rawurlencode($doc['file_name']),
                                    'label' => $doc['original_name'],
                                ];
                            }
                            $docsJson = htmlspecialchars(json_encode($docsForJs), ENT_QUOTES);
                            $docCount = count($docsForJs);
                            $docsHtml = "<button type='button' class='doc-trigger-btn' data-doc-trigger data-rec-text='" . htmlspecialchars($row['recommendation'], ENT_QUOTES) . "' data-docs='{$docsJson}'><i class='bi bi-folder2-open'></i> Documents <span class='doc-count-badge'>{$docCount}</span></button>";
                        }

                        echo "
                        <tr>
                            <td class='rec-text-cell'>{$recText}</td>
                            <td><span class='status-chip {$chipClass}'>{$label}</span></td>
                            <td>{$year}</td>
                            <td class='rec-text-cell'>{$remarksDisplay}</td>
                            <td class='rec-text-cell'><div class='review-feedback-block'>{$reviewFeedbackDisplay}</div></td>
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
                    echo "<tr><td colspan='7' class='empty-state'>No audit recommendations have been assigned to your office yet.</td></tr>";
                }
                ?>
            </tbody>
        </table>
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

<div class="modal fade" id="allDocumentsModal" tabindex="-1" aria-labelledby="allDocumentsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="allDocumentsModalLabel"><i class="bi bi-folder2-open"></i> My Submitted Documents</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-wrap">
                    <table class="table dashboard-table">
                        <thead>
                            <tr>
                                <th>Recommendation</th>
                                <th style="width:110px">Status</th>
                                <th style="width:70px">Year</th>
                                <th>Files</th>
                                <th style="width:160px">Latest Upload</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if(!empty($allOfficeDocuments)){
                                foreach($allOfficeDocuments as $group){
                                    $docRecText = htmlspecialchars($group['recommendation'], ENT_QUOTES);
                                    $docYear = $group['year'] !== '' ? htmlspecialchars($group['year'], ENT_QUOTES) : '&mdash;';
                                    $docStatusLabel = classify_recommendation_status(['status' => $group['status'], 'year' => $group['year']]);
                                    $docChipClass = recommendation_status_chip_class($docStatusLabel);
                                    $latestUpload = $group['docs'][0]['uploaded_at'] ?? '';
                                    $docUploaded = $latestUpload !== '' ? htmlspecialchars(date("M j, Y g:i A", strtotime($latestUpload)), ENT_QUOTES) : '&mdash;';
                                    $filesHtml = "<div class='doc-pill-list'>";
                                    foreach($group['docs'] as $doc){
                                        $docUrl = "uploads/" . rawurlencode($doc['file_name']);
                                        $docLabel = htmlspecialchars($doc['original_name'], ENT_QUOTES);
                                        $filesHtml .= "<a class='doc-pill' href='" . htmlspecialchars($docUrl, ENT_QUOTES) . "' target='_blank'><i class='bi bi-paperclip'></i> {$docLabel}</a>";
                                    }
                                    $filesHtml .= "</div>";
                                    echo "
                                    <tr>
                                        <td class='rec-text-cell'>{$docRecText}</td>
                                        <td><span class='status-chip {$docChipClass}'>{$docStatusLabel}</span></td>
                                        <td>{$docYear}</td>
                                        <td>{$filesHtml}</td>
                                        <td>{$docUploaded}</td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='empty-state'>No documents have been submitted yet.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="doc-sidebar-backdrop" id="docSidebarBackdrop"></div>
<aside class="doc-sidebar" id="docSidebar" aria-hidden="true">
    <div class="doc-sidebar-head">
        <h3><i class="bi bi-folder2-open"></i> Supporting Documents</h3>
        <button type="button" class="doc-sidebar-close" id="docSidebarClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="doc-sidebar-rec" id="docSidebarRecText"></div>
    <div class="doc-sidebar-body" id="docSidebarBody"></div>
</aside>
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
    var sidebar = document.getElementById('docSidebar');
    var backdrop = document.getElementById('docSidebarBackdrop');
    var closeBtn = document.getElementById('docSidebarClose');
    var recTextEl = document.getElementById('docSidebarRecText');
    var bodyEl = document.getElementById('docSidebarBody');
    if(!sidebar || !backdrop || !bodyEl){ return; }

    function openSidebar(recText, docs){
        recTextEl.textContent = recText;
        bodyEl.innerHTML = '';
        if(!docs.length){
            var empty = document.createElement('div');
            empty.className = 'muted-copy';
            empty.textContent = 'No documents submitted.';
            bodyEl.appendChild(empty);
        } else {
            var list = document.createElement('div');
            list.className = 'doc-pill-list';
            docs.forEach(function(doc){
                var a = document.createElement('a');
                a.href = doc.url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.className = 'doc-pill';
                var icon = document.createElement('i');
                icon.className = 'bi bi-paperclip';
                a.appendChild(icon);
                a.appendChild(document.createTextNode(doc.label || ''));
                list.appendChild(a);
            });
            bodyEl.appendChild(list);
        }
        sidebar.classList.add('is-open');
        backdrop.classList.add('is-open');
        sidebar.setAttribute('aria-hidden', 'false');
    }

    function closeSidebar(){
        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        sidebar.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('click', function(e){
        var trigger = e.target.closest('[data-doc-trigger]');
        if(trigger){
            var docs = [];
            try { docs = JSON.parse(trigger.getAttribute('data-docs') || '[]'); } catch(err){ docs = []; }
            openSidebar(trigger.getAttribute('data-rec-text') || '', docs);
        }
    });

    if(closeBtn){ closeBtn.addEventListener('click', closeSidebar); }
    backdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ closeSidebar(); } });
})();

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
