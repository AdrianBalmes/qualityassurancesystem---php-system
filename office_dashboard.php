<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/audit_classification.php";

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
$implemented = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Implemented' AND office='$office'"))['total'];
$partial = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Partially' AND office='$office'"))['total'];
$notimpl = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE status='Not Implemented' AND office='$office'"))['total'];
$totaldocs = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM documents WHERE office='$office'"))['total'];

$feedbackStmt = $conn->prepare("SELECT f.document_id, f.message, f.date_sent, d.title, d.file_name, d.file_link FROM feedback f LEFT JOIN documents d ON f.document_id = d.id WHERE f.office = ? ORDER BY f.date_sent DESC LIMIT 10");
$feedbackStmt->bind_param("s", $office);
$feedbackStmt->execute();
$feedbackMessages = $feedbackStmt->get_result();

$recStmt = $conn->prepare("SELECT * FROM audit_recommendations WHERE office = ? ORDER BY id DESC");
$recStmt->bind_param("s", $office);
$recStmt->execute();
$recommendations = $recStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($office, ENT_QUOTES); ?> Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1120px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}.nav-links{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.nav-links a,.nav-links button{color:#eef4ff;text-decoration:none;font-weight:700;background:none;border:0;padding:0}.page{max-width:1120px;margin:26px auto 42px;padding:0 18px}.page-head{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:14px}.page-title{margin:0;font-size:25px;font-weight:800}.muted-copy{color:#66758d;font-size:13px}.office-switcher{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.office-switch{min-height:34px;border-radius:5px;background:#fff;border:1px solid #c8d4e7;color:#2e67b8;text-decoration:none;font-size:13px;font-weight:800;padding:7px 10px}.office-switch.active{background:#316fc4;border-color:#316fc4;color:#fff}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:16px}.panel-title{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:17px;font-weight:800}.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px}.stat-box{min-height:72px;border-radius:7px;display:flex;flex-direction:column;justify-content:center;gap:4px;padding:12px 14px;font-weight:800}.stat-box span{font-size:13px}.stat-box strong{font-size:28px;line-height:1}.stat-green{background:#caefdd;color:#236b41}.stat-yellow{background:#fff0ba;color:#765a17}.stat-red{background:#ffd0c9;color:#96352f}.stat-steel{background:#d8e2f5;color:#2d5f9e}.content-grid{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.15fr);gap:14px}.content-grid>.panel{min-width:0}.feedback-list{display:grid;gap:10px;max-height:420px;overflow:auto}.feedback-item{border:1px solid #dbe3ef;background:#f8fbff;border-radius:7px;padding:12px}.feedback-title{font-weight:800;color:#344156}.feedback-date{color:#66758d;font-size:12px;font-weight:800}.feedback-body{margin:7px 0 0;color:#344156;font-size:14px;line-height:1.45;white-space:pre-wrap}.form-label{font-weight:800}.action-btn{min-height:38px;border:0;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:8px 14px}.table-wrap{overflow-x:auto}.dashboard-table{min-width:650px}.dashboard-table th{background:#f1f5fb;color:#56637a;font-size:13px}.dashboard-table td{vertical-align:middle;font-weight:700;font-size:13px}.status-chip{display:inline-flex;padding:4px 7px;border-radius:4px;font-size:12px;font-weight:800}.chip-green{background:#cdeedc;color:#277548}.chip-yellow{background:#fff0ba;color:#806119}.chip-red{background:#ffd6d0;color:#a33831}.link-strong{font-weight:800;text-decoration:none;color:#2e67b8}.empty-state{padding:16px;text-align:center;color:#66758d;font-weight:700}.feedback-body{word-break:break-word;overflow-wrap:anywhere}.status-form select,.status-form input{max-width:100%}img,canvas,svg{max-width:100%}form{max-width:100%}input,select,textarea{max-width:100%}.rec-grid-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px}.rec-grid-head .action-btn{width:auto}.rec-grid-table{min-width:900px;border-collapse:collapse}.rec-grid-table td{padding:0;vertical-align:top;border-bottom:1px solid #e7edf6;border-right:1px solid #eef2f8}.cell-text{min-width:160px;padding:8px 10px;font-size:13px;font-weight:600;color:#344156;outline:none;min-height:38px;word-break:break-word;overflow-wrap:anywhere}.cell-text.rec-cell{min-width:280px}.cell-text:focus{background:#eef4ff;box-shadow:inset 0 0 0 2px #316fc4}.cell-select{width:100%;height:100%;border:0;background:transparent;padding:8px 10px;font-size:13px;font-weight:700;max-width:none}.cell-select:focus{background:#eef4ff;outline:none}.cell-readonly{padding:8px 10px;font-size:13px;font-weight:700;color:#344156}.row-delete{border:0;background:#ffe1dc;color:#a33831;border-radius:5px;width:32px;height:32px;font-weight:800;display:grid;place-items:center;margin:4px;flex-shrink:0}@media(max-width:900px){.content-grid{grid-template-columns:1fr}.stat-strip{grid-template-columns:repeat(2,1fr)}.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 18px}.page-head{align-items:flex-start;flex-direction:column}.office-switcher{justify-content:flex-start}}@media(max-width:620px){.brand{font-size:18px}.stat-strip{grid-template-columns:1fr}}@media(max-width:480px){.page{padding:0 10px;margin:16px auto 28px}.panel-pad{padding:12px}.panel-title{font-size:15px}.page-title{font-size:20px}.brand{font-size:15px;gap:8px}.brand-icon{width:32px;height:32px;font-size:15px}.nav-links{gap:12px;font-size:13px}.stat-box{padding:10px}.stat-box strong{font-size:22px}.office-switch{padding:6px 8px;font-size:12px}}
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
        <div class="muted-copy">Signed in as <?php echo htmlspecialchars($_SESSION['office_username'], ENT_QUOTES); ?></div>
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
<section class="stat-strip">
    <div class="stat-box stat-green"><span>Implemented</span><strong><?php echo $implemented; ?></strong></div>
    <div class="stat-box stat-yellow"><span>Partially Implemented</span><strong><?php echo $partial; ?></strong></div>
    <div class="stat-box stat-red"><span>Not Implemented</span><strong><?php echo $notimpl; ?></strong></div>
    <div class="stat-box stat-steel"><span>Total Documents</span><strong><?php echo $totaldocs; ?></strong></div>
</section>
<section class="content-grid">
    <div class="panel panel-pad">
        <h2 class="panel-title"><?php sc_span($siteContent, 'office.doc_form.title', 'Submit Document for Approval'); ?></h2>
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
        <h2 class="panel-title"><?php sc_span($siteContent, 'office.doc_table.title', 'Your Uploaded Documents'); ?></h2>
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
<section class="panel panel-pad">
    <div class="rec-grid-head">
        <div>
            <h2 class="panel-title" style="border-bottom:0;margin-bottom:2px;padding-bottom:0"><?php sc_span($siteContent, 'office.rec_table.title', 'Your Audit Recommendations'); ?></h2>
            <div class="muted-copy">Add a new recommendation, then click any cell to edit it. Your office (<?php echo htmlspecialchars($office, ENT_QUOTES); ?>) is classified under the <strong><?php echo htmlspecialchars($officeAuditType, ENT_QUOTES); ?> Audit</strong> dashboard. The Year is set by the QA admin after review. Changes save automatically.</div>
        </div>
        <button type="button" id="addRecRowBtn" class="action-btn"><i class="bi bi-plus-lg"></i> Add Row</button>
    </div>
    <div class="table-wrap">
        <table class="table dashboard-table rec-grid-table" id="recGrid">
            <thead>
                <tr>
                    <th style="width:110px">Audit Type</th>
                    <th>Recommendation</th>
                    <th style="width:140px">Status of Submission</th>
                    <th>Remarks</th>
                    <th style="width:80px">Year</th>
                    <th style="width:50px"></th>
                </tr>
            </thead>
            <tbody id="recGridBody">
                <?php
                if($recommendations && mysqli_num_rows($recommendations) > 0){
                    while($row = mysqli_fetch_assoc($recommendations)){
                        $id = (int) $row['id'];
                        $recText = htmlspecialchars($row['recommendation'], ENT_QUOTES);
                        $rowAuditType = htmlspecialchars($row['audit_type'], ENT_QUOTES);
                        $year = $row['year'] !== '' ? htmlspecialchars($row['year'], ENT_QUOTES) : '<span class="muted-copy">&mdash;</span>';
                        $remarks = htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES);
                        $statusOptions = "";
                        foreach(['Pending', 'Submitted', 'Not Submitted'] as $option){
                            $sel = $row['status'] === $option ? " selected" : "";
                            $statusOptions .= "<option value='{$option}'{$sel}>{$option}</option>";
                        }
                        echo "
                        <tr data-id='{$id}'>
                            <td class='cell-readonly'>{$rowAuditType}</td>
                            <td><div class='cell-text rec-cell' contenteditable='true' data-field='recommendation'>{$recText}</div></td>
                            <td><select class='cell-select' data-field='status'>{$statusOptions}</select></td>
                            <td><div class='cell-text' contenteditable='true' data-field='remarks'>{$remarks}</div></td>
                            <td class='cell-readonly'>{$year}</td>
                            <td><button type='button' class='row-delete' title='Delete row'><i class='bi bi-trash3'></i></button></td>
                        </tr>
                        ";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
    <div id="recGridEmptyState" class="empty-state" style="<?php echo ($recommendations && mysqli_num_rows($recommendations) > 0) ? 'display:none' : ''; ?>">No audit recommendations yet. Click "Add Row" to create one.</div>
</section>
</main>
<script>
(function(){
    var body = document.getElementById('recGridBody');
    var emptyState = document.getElementById('recGridEmptyState');
    var addRowBtn = document.getElementById('addRecRowBtn');

    function toggleEmptyState(){
        emptyState.style.display = body.children.length > 0 ? 'none' : '';
    }

    var officeAuditType = <?php echo json_encode($officeAuditType); ?>;

    function buildRow(id){
        var tr = document.createElement('tr');
        tr.setAttribute('data-id', id);
        tr.innerHTML =
            "<td class='cell-readonly'>" + officeAuditType + "</td>" +
            "<td><div class='cell-text rec-cell' contenteditable='true' data-field='recommendation'></div></td>" +
            "<td><select class='cell-select' data-field='status'>" +
                "<option value='Pending' selected>Pending</option>" +
                "<option value='Submitted'>Submitted</option>" +
                "<option value='Not Submitted'>Not Submitted</option>" +
            "</select></td>" +
            "<td><div class='cell-text' contenteditable='true' data-field='remarks'></div></td>" +
            "<td class='cell-readonly'><span class='muted-copy'>&mdash;</span></td>" +
            "<td><button type='button' class='row-delete' title='Delete row'><i class='bi bi-trash3'></i></button></td>";
        return tr;
    }

    function sendUpdate(id, field, value){
        fetch('office_recommendations_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=update&id=' + encodeURIComponent(id) + '&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
        });
    }

    body.addEventListener('blur', function(e){
        var target = e.target;
        if(!target.classList || !target.classList.contains('cell-text')){ return; }
        var tr = target.closest('tr');
        var id = tr.getAttribute('data-id');
        var field = target.getAttribute('data-field');
        sendUpdate(id, field, target.innerText.replace(/\n+$/, ''));
    }, true);

    body.addEventListener('change', function(e){
        var target = e.target;
        var tr = target.closest('tr');
        if(!tr){ return; }
        var id = tr.getAttribute('data-id');
        if(target.classList.contains('cell-select')){
            sendUpdate(id, target.getAttribute('data-field'), target.value);
        }
    });

    body.addEventListener('click', function(e){
        var btn = e.target.closest('.row-delete');
        if(!btn){ return; }
        var tr = btn.closest('tr');
        var id = tr.getAttribute('data-id');
        if(!confirm('Delete this recommendation row?')){ return; }
        fetch('office_recommendations_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=delete&id=' + encodeURIComponent(id)
        }).then(function(){
            tr.remove();
            toggleEmptyState();
        });
    });

    addRowBtn.addEventListener('click', function(){
        fetch('office_recommendations_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=add'
        }).then(function(res){ return res.json(); }).then(function(data){
            if(!data.ok){ return; }
            var tr = buildRow(data.id);
            body.insertBefore(tr, body.firstChild);
            toggleEmptyState();
            var firstCell = tr.querySelector('.cell-text');
            if(firstCell){ firstCell.focus(); }
        });
    });

    toggleEmptyState();
})();
</script>

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
<?php render_edit_toggle(); ?>
</body>
</html>
