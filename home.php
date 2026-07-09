<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/graph_token_helper.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] != "admin"){
    header("Location: admin_login.php");
    exit();
}

$defaultOffices = ['Faculty', 'CSSAO', 'IBED', 'SHS', 'Registrar', 'Finance', 'IRRPO', 'Marketing Office', 'External Relations', 'Guidance', 'EDU Hub', 'Praxis', 'Library', 'ITMSO', 'College Department'];
$officeNames = $defaultOffices;
$officeResult = mysqli_query($conn, "SELECT office FROM users WHERE office <> '' AND office <> 'Admin' UNION SELECT office FROM documents WHERE office <> '' UNION SELECT office FROM tasks WHERE office <> '' ORDER BY office ASC");
if($officeResult){
    while($officeRow = mysqli_fetch_assoc($officeResult)){
        $officeName = trim($officeRow['office']);
        if($officeName !== '' && !in_array($officeName, $officeNames, true)){
            $officeNames[] = $officeName;
        }
    }
}

$selectedOffice = isset($_GET['office']) ? trim($_GET['office']) : '';
if(!in_array($selectedOffice, $officeNames, true)){
    $selectedOffice = '';
}

if(isset($_POST['update_status'])){
    $id = intval($_POST['document_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    $allowedStatuses = ['Implemented', 'Partially', 'Not Implemented'];

    $docStmt = $conn->prepare("SELECT office FROM documents WHERE id = ? LIMIT 1");
    $docStmt->bind_param("i", $id);
    $docStmt->execute();
    $doc = $docStmt->get_result()->fetch_assoc();

    if($doc && in_array($newStatus, $allowedStatuses, true)){
        $updateStmt = $conn->prepare("UPDATE documents SET status = ? WHERE id = ?");
        $updateStmt->bind_param("si", $newStatus, $id);
        $updateStmt->execute();
        $redirectOffice = !empty($doc['office']) ? $doc['office'] : $selectedOffice;
        $statusMessage = "updated";
    } else {
        $redirectOffice = $selectedOffice;
        $statusMessage = "failed";
    }

    $officeQuery = $redirectOffice !== '' ? '?office=' . urlencode($redirectOffice) . '&status_update=' . urlencode($statusMessage) : '?status_update=' . urlencode($statusMessage);
    header("Location: home.php$officeQuery#office-documents");
    exit();
}

if(isset($_GET['approve']) || isset($_GET['reject'])){
    $id = isset($_GET['approve']) ? intval($_GET['approve']) : intval($_GET['reject']);
    $decision = isset($_GET['approve']) ? 'Approved' : 'Rejected';

    $docStmt = $conn->prepare("SELECT office FROM documents WHERE id = ? LIMIT 1");
    $docStmt->bind_param("i", $id);
    $docStmt->execute();
    $doc = $docStmt->get_result()->fetch_assoc();

    if($decision === 'Approved'){
        $updateStmt = $conn->prepare("UPDATE documents SET approval_status='Approved', file_link=NULL WHERE id = ?");
    } else {
        $updateStmt = $conn->prepare("UPDATE documents SET approval_status='Rejected' WHERE id = ?");
    }
    $updateStmt->bind_param("i", $id);
    $updateStmt->execute();

    $redirectOffice = $doc && !empty($doc['office']) ? $doc['office'] : $selectedOffice;
    $officeQuery = $redirectOffice !== '' ? '?office=' . urlencode($redirectOffice) : '';
    $alertMessage = "Document {$decision}! Approval status is now visible to the office.";
    echo "<script>alert(" . json_encode($alertMessage) . "); window.location='home.php$officeQuery#office-documents';</script>";
    exit();
}

$implemented = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Implemented'"))['total'];
$partial = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Partially'"))['total'];
$notimpl = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Not Implemented'"))['total'];
$totaldocs = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents"))['total'];
$pendingDocs = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE approval_status='Pending'"))['total'];

$latestPendingDoc = null;
$latestPendingResult = mysqli_query($conn,"SELECT id, office, title, file_name FROM documents WHERE approval_status='Pending' ORDER BY id DESC LIMIT 1");
if($latestPendingResult && mysqli_num_rows($latestPendingResult) > 0){
    $latestPendingDoc = mysqli_fetch_assoc($latestPendingResult);
}

$officeImplemented = [];
$officePartial = [];
$officeNotImpl = [];
foreach($officeNames as $officeName){
    $safeOffice = mysqli_real_escape_string($conn, $officeName);
    $officeImplemented[] = (int) mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE office='$safeOffice' AND status='Implemented'"))['total'];
    $officePartial[] = (int) mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE office='$safeOffice' AND status='Partially'"))['total'];
    $officeNotImpl[] = (int) mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE office='$safeOffice' AND status='Not Implemented'"))['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SBC QA Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1180px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}.nav-links{display:flex;gap:20px;flex-wrap:wrap}.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}.dashboard{max-width:1180px;margin:26px auto 42px;padding:0 18px}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:16px}.panel-title{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:17px;font-weight:800}.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;padding:12px 16px;margin-bottom:14px}.stat-box{min-height:46px;border-radius:7px;display:flex;align-items:center;justify-content:center;gap:7px;padding:10px 12px;font-weight:800}.stat-box strong{font-size:20px}.stat-green{background:#caefdd}.stat-yellow{background:#fff0ba}.stat-red{background:#ffd0c9}.stat-steel{background:#d8e2f5}.main-grid{display:grid;grid-template-columns:minmax(0,3fr) minmax(270px,1fr);gap:12px}.left-stack,.side-stack{display:grid;gap:12px}.two-col{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(260px,.8fr);gap:12px}.chart-box{height:210px}.table-wrap{overflow-x:auto}.dashboard-table{min-width:820px}.dashboard-table th{background:#f1f5fb;color:#56637a;font-size:13px}.dashboard-table td{vertical-align:middle;font-weight:700;font-size:13px}.status-chip{display:inline-flex;padding:4px 7px;border-radius:4px;font-size:12px;font-weight:800}.chip-green{background:#cdeedc;color:#277548}.chip-yellow{background:#fff0ba;color:#806119}.chip-red{background:#ffd6d0;color:#a33831}.action-grid{display:grid;gap:12px}.action-btn{min-height:36px;border:0;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:flex;align-items:center;justify-content:center;gap:10px}.note{display:grid;grid-template-columns:25px 1fr auto;gap:8px;align-items:center;padding:12px 0;border-bottom:1px solid #dbe3ef}.note-icon{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;color:#fff}.office-tabs{display:grid;grid-template-columns:36px minmax(0,1fr) 36px;gap:8px;align-items:stretch;background:#e8eef8;border:1px solid #dbe3ef;padding:10px;border-radius:6px}.office-scroll{display:flex;gap:12px;overflow-x:auto;scroll-behavior:smooth;scrollbar-width:thin}.office-slide-btn{width:36px;border:1px solid #c8d4e7;border-radius:6px;background:#fff;color:#316fc4;display:grid;place-items:center}.office-tile{flex:0 0 104px;min-height:82px;display:grid;justify-items:center;align-content:center;gap:7px;font-weight:800;font-size:13px;text-decoration:none;color:#344156;border:2px solid transparent;border-radius:6px;padding:6px;text-align:center}.office-tile:hover,.office-tile.active{border-color:#316fc4;background:#f7fbff}.office-icon{width:70px;height:54px;border-radius:6px;background:#fff;color:#3971c1;display:grid;place-items:center;font-size:29px}.document-panel{margin-top:12px}.repo-controls{display:grid;grid-template-columns:minmax(220px,1.4fr) repeat(3,minmax(150px,1fr));gap:10px;margin:0 0 12px}.repo-summary{margin:0 0 10px;color:#66758d;font-size:13px;font-weight:800}.btn-xs{padding:4px 8px;font-size:12px;font-weight:800}.link-strong{font-weight:800;text-decoration:none;color:#2e67b8}.repo-pill{padding:5px 9px;border-radius:999px;background:#eef4ff;color:#2e67b8;font-size:12px;font-weight:800}.empty-state{padding:14px;text-align:center;color:#66758d;font-weight:700}.action-inline{display:flex;gap:6px;flex-wrap:wrap}.status-form{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px}.status-select{width:auto;min-width:145px;font-size:12px;font-weight:700}.qa-popup{position:fixed;right:22px;top:92px;width:min(360px,calc(100vw - 36px));background:#fff;border:1px solid #c8d4e7;border-left:5px solid #e57855;border-radius:8px;box-shadow:0 14px 34px rgba(38,53,75,.22);z-index:2000;padding:14px;display:none}.qa-popup.show{display:block}.qa-popup-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px}.qa-popup-title{font-weight:800;color:#26354b}.qa-popup-close{border:0;background:#eef4ff;color:#2e67b8;border-radius:5px;width:30px;height:30px;font-weight:800}.qa-popup-body{font-size:14px;color:#344156;line-height:1.4}.qa-popup-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}.qa-popup-link{min-height:34px;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;padding:7px 11px}.qa-popup-muted{color:#66758d;font-size:12px;font-weight:800;word-break:break-word}@media(max-width:1060px){.main-grid,.two-col{grid-template-columns:1fr}.side-stack{grid-template-columns:1fr 1fr}.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 18px}.stat-strip{grid-template-columns:repeat(2,1fr)}.repo-controls{grid-template-columns:1fr 1fr}}@media(max-width:760px){.brand{font-size:17px}.stat-strip,.side-stack,.repo-controls{grid-template-columns:1fr}.chart-box{height:230px}.office-tabs{grid-template-columns:32px minmax(0,1fr) 32px}.office-tile{flex-basis:94px}.qa-popup{top:118px;right:18px}}
</style>
</head>
<body>
<header class="topbar"><div class="nav-wrap"><div class="brand"><span class="brand-icon"><i class="bi bi-file-earmark-text-fill"></i></span><span>SBC Quality Assurance Electronic Documentation Dashboard</span></div><nav class="nav-links"><a href="home.php">Home</a><a href="repository.php">Repository</a><a href="#tasks">Tasks</a><a href="create_feedback.php" target="_blank">Feedback</a><a href="profile.php"><i class="bi bi-person-circle"></i> Profile</a></nav></div></header>
<?php if($latestPendingDoc){
    $popupOffice = htmlspecialchars($latestPendingDoc['office'], ENT_QUOTES);
    $popupTitle = htmlspecialchars($latestPendingDoc['title'], ENT_QUOTES);
    $popupFile = htmlspecialchars($latestPendingDoc['file_name'], ENT_QUOTES);
    $popupDocId = (int) $latestPendingDoc['id'];
    $popupUrl = "home.php?office=" . urlencode($latestPendingDoc['office']) . "#office-documents";
    $safePopupUrl = htmlspecialchars($popupUrl, ENT_QUOTES);
?>
<div class="qa-popup" id="qaUploadPopup" data-doc-id="<?php echo $popupDocId; ?>">
    <div class="qa-popup-head"><div class="qa-popup-title"><i class="bi bi-bell-fill"></i> New Document Uploaded</div><button type="button" class="qa-popup-close" id="qaUploadPopupClose" aria-label="Close notification">x</button></div>
    <div class="qa-popup-body"><strong><?php echo $popupOffice; ?></strong> submitted <strong><?php echo $popupTitle; ?></strong> for QA review.<div class="qa-popup-muted"><?php echo $popupFile; ?></div></div>
    <div class="qa-popup-actions"><a class="qa-popup-link" href="<?php echo $safePopupUrl; ?>">Review Document</a><a class="qa-popup-link" href="download_document.php?id=<?php echo $popupDocId; ?>">Download</a></div>
</div>
<?php } ?>
<main class="dashboard">
<section class="panel stat-strip"><div class="stat-box stat-green">Documents Implemented: <strong><?php echo $implemented; ?></strong></div><div class="stat-box stat-yellow">Partially Implemented: <strong><?php echo $partial; ?></strong></div><div class="stat-box stat-red">Not Implemented: <strong><?php echo $notimpl; ?></strong></div><div class="stat-box stat-steel">Total Documents: <strong><?php echo $totaldocs; ?></strong></div></section>
<section class="main-grid"><div class="left-stack"><section class="panel panel-pad"><h2 class="panel-title">Implementation Status by Office <span class="repo-pill"><?php echo ucfirst(getRepositoryMode()); ?> Repository</span></h2><div class="chart-box"><canvas id="chart"></canvas></div></section><div class="two-col"><section class="panel panel-pad" id="tasks"><h2 class="panel-title">Task Monitoring</h2><div class="table-wrap"><table class="table dashboard-table"><thead><tr><th>Task</th><th>Office</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php
$tasks = mysqli_query($conn,"SELECT * FROM tasks ORDER BY due_date ASC LIMIT 6");
if($tasks && mysqli_num_rows($tasks)>0){while($row=mysqli_fetch_assoc($tasks)){ $taskName=htmlspecialchars($row['task_name'],ENT_QUOTES); $office=htmlspecialchars($row['office'],ENT_QUOTES); $dueDate=htmlspecialchars($row['due_date'],ENT_QUOTES); $status=htmlspecialchars($row['status'],ENT_QUOTES); $chipClass=$row['status']=='Implemented'?'chip-green':($row['status']=='Partially'?'chip-yellow':'chip-red'); echo "<tr><td>$taskName</td><td>$office</td><td>$dueDate</td><td><span class='status-chip $chipClass'>$status</span></td><td><a class='link-strong' href='#'>Review</a></td></tr>";}} else {echo "<tr><td colspan='5' class='text-center p-3'>No tasks yet</td></tr>";}
?>
</tbody></table></div></section><section class="panel panel-pad"><h2 class="panel-title">Quick Actions</h2><div class="action-grid"><a class="action-btn" href="office_dashboard.php"><i class="bi bi-upload"></i> Upload Document</a><a class="action-btn" href="#tasks"><i class="bi bi-envelope-fill"></i> Send Reminder</a><a class="action-btn" href="create_feedback.php" target="_blank"><i class="bi bi-chat-square-text-fill"></i> Create Feedback</a></div></section></div><section class="panel panel-pad"><h2 class="panel-title">Office Document Submissions</h2><div class="office-tabs"><button type="button" class="office-slide-btn" data-slide-office="-1" aria-label="Slide offices left"><i class="bi bi-chevron-left"></i></button><div class="office-scroll" id="officeTabs">
<?php
$officeIcons=['Faculty'=>'bi-mortarboard-fill','CSSAO'=>'bi-people-fill','IBED'=>'bi-bank2','SHS'=>'bi-house-door-fill','Registrar'=>'bi-building-fill','Finance'=>'bi-wallet2','IRRPO'=>'bi-briefcase-fill','Marketing Office'=>'bi-megaphone-fill','External Relations'=>'bi-globe2','Guidance'=>'bi-compass-fill','EDU Hub'=>'bi-lightbulb-fill','Praxis'=>'bi-tools','Library'=>'bi-book-half','ITMSO'=>'bi-pc-display-horizontal','College Department'=>'bi-buildings-fill'];
foreach($officeNames as $officeName){$officeLabel=htmlspecialchars($officeName,ENT_QUOTES);$officeUrl=urlencode($officeName);$icon=$officeIcons[$officeName]??'bi-folder-fill';$active=$selectedOffice===$officeName?' active':'';echo "<a class='office-tile$active' href='home.php?office=$officeUrl#office-documents'><div class='office-icon'><i class='bi $icon'></i></div><span>$officeLabel</span></a>";}
?>
</div><button type="button" class="office-slide-btn" data-slide-office="1" aria-label="Slide offices right"><i class="bi bi-chevron-right"></i></button></div><div class="document-panel" id="office-documents">
<?php if($selectedOffice === ''){ ?>
<div class="empty-state">Select an office to view submitted documents.</div>
<?php } else { ?>
<h2 class="panel-title"><?php echo htmlspecialchars($selectedOffice, ENT_QUOTES); ?> Submitted Documents</h2><div class="table-wrap"><table class="table dashboard-table"><thead><tr><th>Title</th><th>Implementation Status</th><th>Approval</th><th>Action</th></tr></thead><tbody>
<?php
$safeSelectedOffice = mysqli_real_escape_string($conn, $selectedOffice);
$result = mysqli_query($conn,"SELECT * FROM documents WHERE office='$safeSelectedOffice' ORDER BY id DESC");
if($result && mysqli_num_rows($result)>0){while($row=mysqli_fetch_assoc($result)){ $title=htmlspecialchars($row['title'],ENT_QUOTES); $status=htmlspecialchars($row['status'],ENT_QUOTES); $approval=htmlspecialchars($row['approval_status'],ENT_QUOTES); $statusClass=$row['status']=='Implemented'?'chip-green':($row['status']=='Partially'?'chip-yellow':'chip-red'); $approvalClass=$row['approval_status']=='Approved'?'chip-green':($row['approval_status']=='Rejected'?'chip-red':'chip-yellow'); $id=(int)$row['id']; $officeUrl=urlencode($selectedOffice); $statusOptions=""; foreach(["Implemented", "Partially", "Not Implemented"] as $option){ $selected=$row['status']===$option?" selected":""; $safeOption=htmlspecialchars($option,ENT_QUOTES); $statusOptions.="<option value='$safeOption'$selected>$safeOption</option>"; } $statusForm="<form method='POST' action='home.php?office=$officeUrl#office-documents' class='status-form'><input type='hidden' name='document_id' value='$id'><select name='status' class='form-select form-select-sm status-select'>$statusOptions</select><button type='submit' name='update_status' class='btn btn-secondary btn-xs'>Update</button></form>"; $viewAction="<a href='view_document.php?id=$id' target='_blank' class='btn btn-primary btn-xs'>View</a>"; $approvalActions=$row['approval_status']==='Pending'?"<a href='?office=$officeUrl&approve=$id' class='btn btn-success btn-xs'>Approve</a> <a href='?office=$officeUrl&reject=$id' class='btn btn-danger btn-xs'>Reject</a>":""; echo "<tr><td>$title</td><td><span class='status-chip $statusClass'>$status</span>$statusForm</td><td><span class='status-chip $approvalClass'>$approval</span></td><td><div class='action-inline'>$viewAction $approvalActions <a href='create_feedback.php?document_id=$id' target='_blank' class='btn btn-primary btn-xs'>Feedback</a></div></td></tr>"; }} else { echo "<tr><td colspan='4' class='text-center p-3'>No submitted documents for this office yet</td></tr>"; }
?>
</tbody></table></div>
<?php } ?>
</div></section></div><aside class="side-stack"><section class="panel panel-pad"><h2 class="panel-title">Notifications</h2><div class="note"><span class="note-icon" style="background:#e57855">!</span><div><strong>New Document Uploaded</strong><br><?php echo $pendingDocs; ?> pending review</div><a class="link-strong" href="<?php echo $latestPendingDoc ? htmlspecialchars($popupUrl, ENT_QUOTES) : '#office-documents'; ?>">View</a></div><div class="note"><span class="note-icon" style="background:#58b978">✓</span><div><strong>Feedback Survey</strong><br>Open feedback page</div><a class="link-strong" href="create_feedback.php" target="_blank">View</a></div></section><section class="panel panel-pad"><h2 class="panel-title">Submission Monitor</h2><p class="mb-1"><strong>Pending Reviews</strong> <span class="text-success float-end"><?php echo $pendingDocs; ?></span></p><p class="mb-0"><strong>Total Documents</strong> <span class="text-success float-end"><?php echo $totaldocs; ?></span></p></section></aside></section>
</main><script>
(function(){var popup=document.getElementById('qaUploadPopup');if(!popup){return;}var docId=popup.getAttribute('data-doc-id')||'';var storageKey='qaSeenUploadDocId';var closeButton=document.getElementById('qaUploadPopupClose');if(localStorage.getItem(storageKey)!==docId){popup.classList.add('show');}if(closeButton){closeButton.addEventListener('click',function(){localStorage.setItem(storageKey,docId);popup.classList.remove('show');});}})();
document.querySelectorAll('[data-slide-office]').forEach(function(button){button.addEventListener('click',function(){var tabs=document.getElementById('officeTabs');if(!tabs){return;}var direction=parseInt(button.getAttribute('data-slide-office'),10)||1;tabs.scrollBy({left:direction*260,behavior:'smooth'});});});
new Chart(document.getElementById('chart'),{type:'bar',data:{labels:<?php echo json_encode($officeNames); ?>,datasets:[{label:'Implemented',data:<?php echo json_encode($officeImplemented); ?>,backgroundColor:'#58b978',stack:'status'},{label:'Partially',data:<?php echo json_encode($officePartial); ?>,backgroundColor:'#f5c54f',stack:'status'},{label:'Not implemented',data:<?php echo json_encode($officeNotImpl); ?>,backgroundColor:'#e65b59',stack:'status'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true,ticks:{precision:0}}}}});
</script>
</body>
</html>
