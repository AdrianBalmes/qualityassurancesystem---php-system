<?php
session_start();
require_once __DIR__ . "/database.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] != "admin"){
    header("Location: admin_login.php");
    exit();
}

$selectedDocumentId = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;
$selectedOffice = "";
$message = "";

if(isset($_POST['create_feedback'])){
    $documentId = intval($_POST['document_id']);
    $message = trim($_POST['message']);

    $stmt = $conn->prepare("SELECT office, title, file_name FROM documents WHERE id = ?");
    $stmt->bind_param("i", $documentId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();

    if($doc && $message !== ""){
        $insert = $conn->prepare("INSERT INTO feedback (document_id, office, message) VALUES (?, ?, ?)");
        $insert->bind_param("iss", $documentId, $doc['office'], $message);
        $insert->execute();

        echo "<script>alert('Feedback created successfully!'); window.location='create_feedback.php';</script>";
        exit();
    }

    echo "<script>alert('Please select a submitted file and enter feedback.'); window.location='create_feedback.php';</script>";
    exit();
}

if($selectedDocumentId > 0){
    $stmt = $conn->prepare("SELECT office FROM documents WHERE id = ?");
    $stmt->bind_param("i", $selectedDocumentId);
    $stmt->execute();
    $selectedDoc = $stmt->get_result()->fetch_assoc();
    if($selectedDoc){
        $selectedOffice = $selectedDoc['office'];
    } else {
        $selectedDocumentId = 0;
    }
}

$officeDocs = mysqli_query($conn, "SELECT id, office, title, file_name, approval_status, file_link FROM documents ORDER BY office ASC, id DESC");
$recentFeedback = mysqli_query($conn, "SELECT f.office, f.message, f.date_sent, d.title, d.file_name FROM feedback f LEFT JOIN documents d ON f.document_id = d.id ORDER BY f.date_sent DESC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Feedback</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1120px;margin:auto;min-height:70px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:16px}.brand{display:flex;align-items:center;gap:12px;font-size:21px;font-weight:800}.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}.nav-links{display:flex;gap:18px;flex-wrap:wrap}.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}.page{max-width:1120px;margin:26px auto 42px;padding:0 18px}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:18px}.panel-title{margin:0 0 14px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:18px;font-weight:800}.form-label{font-weight:800}.action-btn{min-height:38px;border:0;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:8px 14px}.table-wrap{overflow-x:auto}.dashboard-table{min-width:790px}.dashboard-table th{background:#f1f5fb;color:#56637a;font-size:13px}.dashboard-table td{vertical-align:middle;font-weight:700;font-size:13px}.link-strong{font-weight:800;text-decoration:none;color:#2e67b8}.status-chip{display:inline-flex;padding:4px 7px;border-radius:4px;font-size:12px;font-weight:800}.chip-green{background:#cdeedc;color:#277548}.chip-red{background:#ffd6d0;color:#a33831}.chip-yellow{background:#fff0ba;color:#806119}.muted-copy{color:#66758d;font-size:13px}.grid{display:grid;grid-template-columns:minmax(320px,.9fr) minmax(0,1.1fr);gap:14px}.doc-meta{background:#f5f8fd;border:1px solid #dbe3ef;border-radius:6px;padding:10px 12px;font-size:13px}@media(max-width:900px){.grid{grid-template-columns:1fr}.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 18px}.brand{font-size:18px}}
</style>
</head>
<body>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-chat-square-text-fill"></i></span><span>Create Feedback</span></div>
        <nav class="nav-links">
            <a href="home.php">Dashboard</a>
            <a href="repository.php">Repository</a>
            <a href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
        </nav>
    </div>
</header>
<main class="page">
    <section class="grid">
        <div class="panel panel-pad">
            <h2 class="panel-title">Feedback for Submitted File</h2>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Office Document</label>
                    <select name="document_id" class="form-select" required>
                        <option value="">Select office and file</option>
                        <?php
                        mysqli_data_seek($officeDocs, 0);
                        while($doc = mysqli_fetch_assoc($officeDocs)){
                            $docId = (int)$doc['id'];
                            $selected = $docId === $selectedDocumentId ? "selected" : "";
                            $label = htmlspecialchars($doc['office'] . " - " . $doc['title'] . " (" . $doc['file_name'] . ")", ENT_QUOTES);
                            echo "<option value='$docId' $selected>$label</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Feedback Message</label>
                    <textarea name="message" class="form-control" rows="7" placeholder="Write feedback for the selected office and submitted file..." required><?php echo htmlspecialchars($message, ENT_QUOTES); ?></textarea>
                </div>
                <button type="submit" name="create_feedback" class="action-btn w-100"><i class="bi bi-send-fill"></i> Save Feedback</button>
            </form>
        </div>
        <div class="panel panel-pad">
            <h2 class="panel-title">Submitted Files</h2>
            <div class="table-wrap">
                <table class="table dashboard-table">
                    <thead><tr><th>Office</th><th>Document</th><th>Approval</th><th>File</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php
                    mysqli_data_seek($officeDocs, 0);
                    if(mysqli_num_rows($officeDocs) > 0){
                        while($doc = mysqli_fetch_assoc($officeDocs)){
                            $docId = (int)$doc['id'];
                            $office = htmlspecialchars($doc['office'], ENT_QUOTES);
                            $title = htmlspecialchars($doc['title'], ENT_QUOTES);
                            $approval = htmlspecialchars($doc['approval_status'], ENT_QUOTES);
                            $approvalClass = $doc['approval_status'] == 'Approved' ? 'chip-green' : ($doc['approval_status'] == 'Rejected' ? 'chip-red' : 'chip-yellow');
                            $viewUrl = "view_document.php?id=" . $docId;
                            $safeViewUrl = htmlspecialchars($viewUrl, ENT_QUOTES);
                            echo "<tr><td>$office</td><td>$title</td><td><span class='status-chip $approvalClass'>$approval</span></td><td><a class='link-strong' href='$safeViewUrl' target='_blank'>Open File</a></td><td><a class='action-btn' href='create_feedback.php?document_id=$docId'>Select</a></td></tr>";
                        }
                    } else {
                        echo "<tr><td colspan='5' class='text-center p-3'>No submitted files yet</td></tr>";
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <section class="panel panel-pad mt-3">
        <h2 class="panel-title">Recent Feedback</h2>
        <div class="table-wrap">
            <table class="table dashboard-table">
                <thead><tr><th>Office</th><th>Document</th><th>File</th><th>Feedback</th><th>Date</th></tr></thead>
                <tbody>
                <?php
                if(mysqli_num_rows($recentFeedback) > 0){
                    while($fb = mysqli_fetch_assoc($recentFeedback)){
                        $office = htmlspecialchars($fb['office'], ENT_QUOTES);
                        $title = htmlspecialchars($fb['title'] ?? 'Document removed', ENT_QUOTES);
                        $fileName = htmlspecialchars($fb['file_name'] ?? 'N/A', ENT_QUOTES);
                        $feedbackMessage = htmlspecialchars($fb['message'], ENT_QUOTES);
                        $dateSent = htmlspecialchars($fb['date_sent'], ENT_QUOTES);
                        echo "<tr><td>$office</td><td>$title</td><td>$fileName</td><td>$feedbackMessage</td><td>$dateSent</td></tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='text-center p-3'>No feedback created yet</td></tr>";
                }
                ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
