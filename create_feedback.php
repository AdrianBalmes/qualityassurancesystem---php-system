<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/feedback_columns.php";
require_once __DIR__ . "/audit_log_helper.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] != "admin"){
    header("Location: admin_login.php");
    exit();
}

ensure_feedback_columns($conn);

$adminUsername = $_SESSION['admin_username'];
$uploadDir = __DIR__ . "/uploads/";

$selectedDocumentId = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;
$selectedOffice = "";
$message = "";
$error = "";

if(isset($_POST['create_feedback'])){
    $documentId = intval($_POST['document_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $postedOffice = trim($_POST['office'] ?? '');

    $stmt = $conn->prepare("SELECT office, title, file_name FROM documents WHERE id = ?");
    $stmt->bind_param("i", $documentId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();

    if(!$doc || $postedOffice === "" || $doc['office'] !== $postedOffice){
        $error = "Please select a valid office and document.";
        $selectedOffice = $postedOffice;
        $selectedDocumentId = $documentId;
    } elseif($message === ""){
        $error = "Please enter your feedback message.";
        $selectedOffice = $postedOffice;
        $selectedDocumentId = $documentId;
    } else {
        $attachmentName = "";
        $attachmentOriginalName = "";

        if(!empty($_FILES['attachment']['name'])){
            $originalName = basename($_FILES['attachment']['name']);
            $tmpName = $_FILES['attachment']['tmp_name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExt = ['doc', 'docx', 'pdf', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];

            if(!in_array($ext, $allowedExt, true)){
                $error = "Attachment must be a DOC, DOCX, PDF, XLS, XLSX, PPT, PPTX, JPG, or PNG file.";
            } elseif($_FILES['attachment']['size'] > 5 * 1024 * 1024){
                $error = "Attachment must be smaller than 5MB.";
            } else {
                $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
                $attachmentName = $safeBase . "_" . date("YmdHis") . "_" . bin2hex(random_bytes(3)) . "." . $ext;
                if(!move_uploaded_file($tmpName, $uploadDir . $attachmentName)){
                    $error = "Could not upload the attachment. Please try again.";
                    $attachmentName = "";
                } else {
                    $attachmentOriginalName = $originalName;
                }
            }
        }

        if($error === ""){
            $insert = $conn->prepare("INSERT INTO feedback (document_id, office, message, attachment_name, attachment_original_name) VALUES (?, ?, ?, ?, ?)");
            $insert->bind_param("issss", $documentId, $doc['office'], $message, $attachmentName, $attachmentOriginalName);
            $insert->execute();

            log_audit_event($conn, $adminUsername, 'admin', $doc['office'], 'feedback_created', 'feedback', $conn->insert_id, "Admin sent feedback to {$doc['office']} for document \"{$doc['title']}\"");

            echo "<script>alert('Feedback created successfully!'); window.location='create_feedback.php';</script>";
            exit();
        }

        $selectedOffice = $postedOffice;
        $selectedDocumentId = $documentId;
    }
}

if($selectedDocumentId > 0 && $selectedOffice === ""){
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

$officeNamesResult = mysqli_query($conn, "SELECT DISTINCT office FROM documents ORDER BY office ASC");
$officeNames = [];
while($row = mysqli_fetch_assoc($officeNamesResult)){
    $officeNames[] = $row['office'];
}

$documentsResult = mysqli_query($conn, "SELECT id, office, title, file_name, approval_status FROM documents ORDER BY office ASC, id DESC");
$documentsForJs = [];
while($doc = mysqli_fetch_assoc($documentsResult)){
    $documentsForJs[] = [
        'id' => (int) $doc['id'],
        'office' => $doc['office'],
        'title' => $doc['title'],
        'file_name' => $doc['file_name'],
        'approval_status' => $doc['approval_status'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Feedback</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f6f9;color:#26354b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}
.nav-wrap{max-width:1120px;margin:auto;min-height:68px;padding:0 20px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.brand{display:flex;align-items:center;gap:12px;font-size:19px;font-weight:800}
.brand-icon{width:36px;height:36px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}
.nav-links{display:flex;gap:18px;flex-wrap:wrap}
.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700;font-size:14px}
.nav-links a:hover{text-decoration:underline}
.page{max-width:1120px;margin:0 auto;padding:48px 20px 60px;display:flex;justify-content:center}
.card{background:#fff;width:100%;max-width:680px;border-radius:14px;box-shadow:0 10px 30px rgba(15,26,42,.08);padding:40px 44px}
.card-title{margin:0 0 6px;font-size:26px;font-weight:800;color:#1c2b3f;letter-spacing:-.2px}
.card-subtitle{margin:0 0 30px;font-size:14.5px;color:#66758d;font-weight:600}
.alert-box{border-radius:8px;padding:12px 14px;font-size:13.5px;font-weight:700;margin-bottom:22px}
.alert-error{background:#fdeceb;color:#a33831;border:1px solid #f6cfc9}
.field-group{margin-bottom:22px}
.field-label{display:block;font-size:13.5px;font-weight:700;color:#344156;margin-bottom:7px}
.field-hint{font-size:12px;color:#8794a8;font-weight:600;margin-top:6px}
.field-select,.field-textarea{width:100%;border:1px solid #dbe1ea;border-radius:9px;padding:12px 14px;font-size:14px;font-family:inherit;color:#26354b;background:#fff;transition:border-color .15s ease,box-shadow .15s ease}
.field-select:focus,.field-textarea:focus{outline:none;border-color:#316fc4;box-shadow:0 0 0 3px rgba(49,111,196,.14)}
.field-select:disabled{background:#f4f6f9;color:#a7b1bf;cursor:not-allowed}
.field-textarea{min-height:170px;resize:vertical;line-height:1.5}
.char-counter{display:flex;justify-content:flex-end;font-size:12px;color:#8794a8;font-weight:700;margin-top:6px}
.char-counter.is-near-limit{color:#c9862a}
.char-counter.is-over-limit{color:#c23b36}
.doc-info-box{display:none;align-items:center;gap:10px;background:#f5f8fd;border:1px solid #dbe3ef;border-radius:8px;padding:10px 12px;margin-top:10px;font-size:13px}
.doc-info-box.is-visible{display:flex}
.doc-info-icon{width:30px;height:30px;border-radius:7px;background:#eef4ff;color:#316fc4;display:grid;place-items:center;flex-shrink:0}
.doc-info-text{flex:1;min-width:0;word-break:break-word;font-weight:700;color:#344156}
.status-chip{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;flex-shrink:0}
.chip-green{background:#cdeedc;color:#277548}
.chip-red{background:#ffd6d0;color:#a33831}
.chip-yellow{background:#fff0ba;color:#806119}
.upload-box{border:1.5px dashed #cfd9e8;border-radius:9px;padding:16px;display:flex;align-items:center;gap:12px;background:#fafcfe}
.upload-icon{width:38px;height:38px;border-radius:8px;background:#eef4ff;color:#316fc4;display:grid;place-items:center;flex-shrink:0;font-size:17px}
.upload-text{flex:1;min-width:0}
.upload-text strong{display:block;font-size:13.5px;color:#344156}
.upload-filename{font-size:12.5px;color:#316fc4;font-weight:700;margin-top:2px;word-break:break-word}
.upload-input{font-size:12.5px}
.form-actions{display:flex;gap:12px;margin-top:32px}
.btn{flex:1;min-height:46px;border-radius:9px;font-weight:800;font-size:14.5px;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;border:0;text-decoration:none;transition:background-color .15s ease}
.btn-primary{background:#316fc4;color:#fff}
.btn-primary:hover{background:#2459a6}
.btn-secondary{background:#eef1f6;color:#4c5a72}
.btn-secondary:hover{background:#e3e8f0}
@media(max-width:900px){.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 20px}.brand{font-size:17px}}
@media(max-width:640px){.page{padding:28px 14px 40px}.card{padding:28px 22px;border-radius:12px}.card-title{font-size:22px}.form-actions{flex-direction:column-reverse}}
</style>
</head>
<body>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-chat-square-text-fill"></i></span><span>Create Feedback</span></div>
        <nav class="nav-links">
            <a href="home.php">Dashboard</a>
            <a href="repository.php">Repository</a>
            <a href="activity_log.php">Activity Log</a>
            <a href="admin_profile.php"><i class="bi bi-person-circle"></i> Profile</a>
        </nav>
    </div>
</header>
<main class="page">
    <div class="card">
        <h1 class="card-title">Create Feedback</h1>
        <p class="card-subtitle">Submit feedback for a selected document</p>

        <?php if($error !== ""): ?>
        <div class="alert-box alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="feedbackForm">
            <div class="field-group">
                <label class="field-label" for="officeSelect">Office / Department</label>
                <select name="office" id="officeSelect" class="field-select" required>
                    <option value="">Select an office</option>
                    <?php foreach($officeNames as $officeName):
                        $safeOffice = htmlspecialchars($officeName, ENT_QUOTES);
                        $sel = $officeName === $selectedOffice ? " selected" : "";
                    ?>
                    <option value="<?php echo $safeOffice; ?>"<?php echo $sel; ?>><?php echo $safeOffice; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field-group">
                <label class="field-label" for="documentSelect">Document</label>
                <select name="document_id" id="documentSelect" class="field-select" required disabled>
                    <option value="">Select an office first</option>
                </select>
                <div class="doc-info-box" id="docInfoBox">
                    <span class="doc-info-icon"><i class="bi bi-file-earmark-text-fill"></i></span>
                    <span class="doc-info-text" id="docInfoText"></span>
                    <span class="status-chip" id="docInfoStatus"></span>
                </div>
            </div>

            <div class="field-group">
                <label class="field-label" for="messageField">Feedback Message</label>
                <textarea name="message" id="messageField" class="field-textarea" maxlength="2000" placeholder="Write feedback for the selected office and document..." required><?php echo htmlspecialchars($message, ENT_QUOTES); ?></textarea>
                <div class="char-counter" id="charCounter">0 / 2000</div>
            </div>

            <div class="field-group">
                <label class="field-label" for="attachmentField">Attachment <span style="font-weight:600;color:#8794a8">(optional)</span></label>
                <div class="upload-box">
                    <span class="upload-icon"><i class="bi bi-paperclip"></i></span>
                    <div class="upload-text">
                        <strong>Attach a supporting file</strong>
                        <input type="file" name="attachment" id="attachmentField" class="upload-input" accept=".doc,.docx,.pdf,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                        <div class="upload-filename" id="attachmentFilename"></div>
                    </div>
                </div>
                <div class="field-hint">DOC, DOCX, PDF, XLS, XLSX, PPT, PPTX, JPG, or PNG. Max 5MB.</div>
            </div>

            <div class="form-actions">
                <a href="home.php" class="btn btn-secondary"><i class="bi bi-x-lg"></i> Cancel</a>
                <button type="submit" name="create_feedback" class="btn btn-primary"><i class="bi bi-send-fill"></i> Save Feedback</button>
            </div>
        </form>
    </div>
</main>
<script>
var allDocuments = <?php echo json_encode($documentsForJs); ?>;
var initialOffice = <?php echo json_encode($selectedOffice); ?>;
var initialDocumentId = <?php echo json_encode($selectedDocumentId); ?>;

var officeSelect = document.getElementById('officeSelect');
var documentSelect = document.getElementById('documentSelect');
var docInfoBox = document.getElementById('docInfoBox');
var docInfoText = document.getElementById('docInfoText');
var docInfoStatus = document.getElementById('docInfoStatus');

var statusChipClass = { 'Approved': 'chip-green', 'Rejected': 'chip-red', 'Pending': 'chip-yellow' };

function populateDocuments(office, preselectId){
    documentSelect.innerHTML = '';
    var docsForOffice = allDocuments.filter(function(doc){ return doc.office === office; });

    if(!office){
        documentSelect.disabled = true;
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Select an office first';
        documentSelect.appendChild(opt);
        hideDocInfo();
        return;
    }

    documentSelect.disabled = false;
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = docsForOffice.length ? 'Select a document' : 'No documents for this office';
    documentSelect.appendChild(placeholder);

    docsForOffice.forEach(function(doc){
        var opt = document.createElement('option');
        opt.value = doc.id;
        opt.textContent = doc.title + ' (' + doc.file_name + ')';
        if(preselectId && String(doc.id) === String(preselectId)){ opt.selected = true; }
        documentSelect.appendChild(opt);
    });

    updateDocInfo();
}

function hideDocInfo(){
    docInfoBox.classList.remove('is-visible');
}

function updateDocInfo(){
    var selectedId = documentSelect.value;
    if(!selectedId){ hideDocInfo(); return; }
    var doc = allDocuments.find(function(d){ return String(d.id) === String(selectedId); });
    if(!doc){ hideDocInfo(); return; }
    docInfoText.textContent = doc.file_name;
    docInfoStatus.textContent = doc.approval_status;
    docInfoStatus.className = 'status-chip ' + (statusChipClass[doc.approval_status] || 'chip-yellow');
    docInfoBox.classList.add('is-visible');
}

officeSelect.addEventListener('change', function(){
    populateDocuments(officeSelect.value, null);
});
documentSelect.addEventListener('change', updateDocInfo);

if(initialOffice){
    populateDocuments(initialOffice, initialDocumentId);
}

var messageField = document.getElementById('messageField');
var charCounter = document.getElementById('charCounter');
var maxChars = 2000;

function updateCharCounter(){
    var len = messageField.value.length;
    charCounter.textContent = len + ' / ' + maxChars;
    charCounter.classList.toggle('is-near-limit', len > maxChars * 0.9 && len <= maxChars);
    charCounter.classList.toggle('is-over-limit', len > maxChars);
}
messageField.addEventListener('input', updateCharCounter);
updateCharCounter();

var attachmentField = document.getElementById('attachmentField');
var attachmentFilename = document.getElementById('attachmentFilename');
attachmentField.addEventListener('change', function(){
    var file = attachmentField.files && attachmentField.files[0];
    attachmentFilename.textContent = file ? file.name : '';
});
</script>
</body>
</html>
