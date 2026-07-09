<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/mailer_config.php";

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

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $basePath = rtrim(str_replace("\\", "/", dirname($_SERVER['PHP_SELF'])), "/");
        $dashboardUrl = $scheme . "://" . $_SERVER['HTTP_HOST'] . ($basePath !== "" ? $basePath : "") . "/home.php?office=" . urlencode($office) . "#office-documents";
        $adminStmt = $conn->prepare("SELECT username, email FROM users WHERE role = 'admin' AND email <> ''");
        $adminStmt->execute();
        $adminResult = $adminStmt->get_result();
        $mailSent = false;
        while($admin = $adminResult->fetch_assoc()){
            if(sendDocumentUploadNotification($admin['email'], $office, $title, $file_name, $dashboardUrl)){
                $mailSent = true;
            }
        }

        $alertMessage = $mailSent
            ? "Document Submitted Successfully! Waiting for Admin Approval. Gmail notification sent to admin."
            : "Document Submitted Successfully! Waiting for Admin Approval. Admin Gmail notification was not sent.";
        echo "<script>alert(" . json_encode($alertMessage) . "); window.location=" . json_encode($officeDashboardUrl) . ";</script>";
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
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1120px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}.nav-links{display:flex;align-items:center;gap:18px;flex-wrap:wrap}.nav-links a,.nav-links button{color:#eef4ff;text-decoration:none;font-weight:700;background:none;border:0;padding:0}.page{max-width:1120px;margin:26px auto 42px;padding:0 18px}.page-head{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:14px}.page-title{margin:0;font-size:25px;font-weight:800}.muted-copy{color:#66758d;font-size:13px}.office-switcher{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.office-switch{min-height:34px;border-radius:5px;background:#fff;border:1px solid #c8d4e7;color:#2e67b8;text-decoration:none;font-size:13px;font-weight:800;padding:7px 10px}.office-switch.active{background:#316fc4;border-color:#316fc4;color:#fff}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:16px}.panel-title{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:17px;font-weight:800}.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:14px}.stat-box{min-height:72px;border-radius:7px;display:flex;flex-direction:column;justify-content:center;gap:4px;padding:12px 14px;font-weight:800}.stat-box span{font-size:13px}.stat-box strong{font-size:28px;line-height:1}.stat-green{background:#caefdd;color:#236b41}.stat-yellow{background:#fff0ba;color:#765a17}.stat-red{background:#ffd0c9;color:#96352f}.stat-steel{background:#d8e2f5;color:#2d5f9e}.content-grid{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.15fr);gap:14px}.feedback-list{display:grid;gap:10px;max-height:420px;overflow:auto}.feedback-item{border:1px solid #dbe3ef;background:#f8fbff;border-radius:7px;padding:12px}.feedback-title{font-weight:800;color:#344156}.feedback-date{color:#66758d;font-size:12px;font-weight:800}.feedback-body{margin:7px 0 0;color:#344156;font-size:14px;line-height:1.45;white-space:pre-wrap}.form-label{font-weight:800}.action-btn{min-height:38px;border:0;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:8px 14px}.table-wrap{overflow-x:auto}.dashboard-table{min-width:650px}.dashboard-table th{background:#f1f5fb;color:#56637a;font-size:13px}.dashboard-table td{vertical-align:middle;font-weight:700;font-size:13px}.status-chip{display:inline-flex;padding:4px 7px;border-radius:4px;font-size:12px;font-weight:800}.chip-green{background:#cdeedc;color:#277548}.chip-yellow{background:#fff0ba;color:#806119}.chip-red{background:#ffd6d0;color:#a33831}.link-strong{font-weight:800;text-decoration:none;color:#2e67b8}.empty-state{padding:16px;text-align:center;color:#66758d;font-weight:700}@media(max-width:900px){.content-grid{grid-template-columns:1fr}.stat-strip{grid-template-columns:repeat(2,1fr)}.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 18px}.page-head{align-items:flex-start;flex-direction:column}.office-switcher{justify-content:flex-start}}@media(max-width:620px){.brand{font-size:18px}.stat-strip{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-file-earmark-text-fill"></i></span><span>SBC Quality Assurance Electronic Documentation</span></div>
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
        <h2 class="panel-title">Submit Document for Approval</h2>
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
        <h2 class="panel-title">Your Uploaded Documents</h2>
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
</body>
</html>
