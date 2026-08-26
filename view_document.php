<?php
session_start();
require_once __DIR__ . "/database.php";

if(isset($_SESSION['office_logins']) && is_array($_SESSION['office_logins'])){
    $requestedOffice = isset($_GET['office']) ? trim($_GET['office']) : '';
    if($requestedOffice !== '' && isset($_SESSION['office_logins'][$requestedOffice])){
        $activeLogin = $_SESSION['office_logins'][$requestedOffice];
        $_SESSION['office_username'] = $activeLogin['username'];
        $_SESSION['office_role']     = $activeLogin['role'];
        $_SESSION['office_name']     = $activeLogin['office'];
        $_SESSION['office_user_id']  = $activeLogin['id'];
        $_SESSION['office_email']    = $activeLogin['email'];
    }
}

if(!isset($_SESSION['admin_username']) && !isset($_SESSION['office_username'])){
    header("Location: index.php");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($id <= 0){
    http_response_code(400);
    exit("Invalid document.");
}

$stmt = $conn->prepare("SELECT office, title, file_name, file_link FROM documents WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if(!$doc){
    http_response_code(404);
    exit("Document not found.");
}

$isAdmin = isset($_SESSION['admin_username']) && $_SESSION['admin_role'] === 'admin';
$userOffice = $isAdmin ? ($_SESSION['admin_office'] ?? '') : ($_SESSION['office_name'] ?? '');
$isOwnerOffice = ($userOffice === $doc['office']);
if(!$isAdmin && !$isOwnerOffice){
    http_response_code(403);
    exit("You are not allowed to view this document.");
}

$fileName = basename($doc['file_name']);
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$localUrl = "uploads/" . rawurlencode($fileName);
$viewUrl = !empty($doc['file_link']) ? $doc['file_link'] : $localUrl;
$safeViewUrl = htmlspecialchars($viewUrl, ENT_QUOTES);
$downloadUrl = "download_document.php?id=" . $id;
$mode = isset($_GET['mode']) ? trim($_GET['mode']) : '';
$safeTitle = htmlspecialchars($doc['title'], ENT_QUOTES);
$safeFileName = htmlspecialchars($fileName, ENT_QUOTES);
$safeOffice = htmlspecialchars($doc['office'], ENT_QUOTES);
$officeParam = !$isAdmin ? "&office=" . urlencode($doc['office']) : "";
$dashboardUrl = $isAdmin ? "home.php" : "office_dashboard.php?office=" . urlencode($doc['office']);

$canEmbed = in_array($fileExt, ['pdf', 'jpg', 'jpeg', 'png'], true);
$canUseOfficeViewer = in_array($fileExt, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true) && !empty($doc['file_link']);
$officeViewerUrl = $canUseOfficeViewer ? "https://view.officeapps.live.com/op/embed.aspx?src=" . rawurlencode($doc['file_link']) : "";
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$basePath = rtrim(str_replace("\\", "/", dirname($_SERVER['SCRIPT_NAME'])), "/");
$absoluteLocalUrl = $scheme . "://" . $_SERVER['HTTP_HOST'] . ($basePath !== "" ? $basePath : "") . "/" . $localUrl;
$absoluteViewUrl = !empty($doc['file_link']) ? $doc['file_link'] : $absoluteLocalUrl;
$appProtocols = [
    "doc" => "ms-word:ofe|u|",
    "docx" => "ms-word:ofe|u|",
    "xls" => "ms-excel:ofe|u|",
    "xlsx" => "ms-excel:ofe|u|",
    "ppt" => "ms-powerpoint:ofe|u|",
    "pptx" => "ms-powerpoint:ofe|u|"
];
$appOpenUrl = isset($appProtocols[$fileExt]) ? $appProtocols[$fileExt] . $absoluteViewUrl : "";

if($mode === "file"){
    header("Location: " . $viewUrl);
    exit();
}

if($mode === "app" && $appOpenUrl !== ""){
    header("Location: " . $appOpenUrl);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Document</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1180px;margin:auto;min-height:72px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:12px;font-size:21px;font-weight:800}.brand-icon{width:64px;height:64px;display:grid;place-items:center;flex-shrink:0}.nav-links{display:flex;gap:18px;flex-wrap:wrap}.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}.page{max-width:1180px;margin:24px auto 42px;padding:0 18px}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:16px}.doc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}.doc-title{font-size:22px;font-weight:800;margin:0}.muted-copy{color:#66758d;font-size:13px;font-weight:700;word-break:break-word}.action-inline{display:flex;gap:8px;flex-wrap:wrap}.choice-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.choice-card{border:1px solid #dbe3ef;border-radius:8px;background:#f8fbff;color:#344156;text-decoration:none;min-height:118px;padding:16px;display:flex;flex-direction:column;justify-content:center;gap:8px}.choice-card:hover{border-color:#316fc4;background:#eef4ff}.choice-card i{font-size:28px;color:#316fc4}.choice-title{font-weight:800}.preview-frame{width:100%;height:calc(100vh - 230px);min-height:520px;border:1px solid #dbe3ef;border-radius:7px;background:#f8fbff}.image-preview{width:100%;max-height:calc(100vh - 230px);object-fit:contain;border:1px solid #dbe3ef;border-radius:7px;background:#f8fbff}.empty-state{min-height:360px;display:grid;place-items:center;text-align:center;color:#66758d;font-weight:800;padding:24px;border:1px dashed #c8d4e7;border-radius:7px;background:#f8fbff}.btn{font-weight:800}@media(max-width:900px){.choice-grid{grid-template-columns:1fr 1fr}}@media(max-width:760px){.nav-wrap,.doc-head{flex-direction:column;align-items:flex-start}.brand{font-size:18px}.preview-frame{height:62vh;min-height:360px}.choice-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><img src="assets/sbc-logo.png" alt="St. Bridget College" style="width:100%;height:100%;object-fit:contain"></span><span>Document Viewer</span></div>
        <nav class="nav-links">
            <a href="repository.php">Repository</a>
            <a href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES); ?>">Dashboard</a>
        </nav>
    </div>
</header>
<main class="page">
    <section class="panel panel-pad">
        <div class="doc-head">
            <div>
                <h1 class="doc-title"><?php echo $safeTitle; ?></h1>
                <div class="muted-copy"><?php echo $safeOffice; ?> / <?php echo $safeFileName; ?></div>
            </div>
            <div class="action-inline">
                <a class="btn btn-secondary btn-sm" href="repository.php">Back</a>
                <a class="btn btn-primary btn-sm" href="<?php echo htmlspecialchars($downloadUrl . $officeParam, ENT_QUOTES); ?>">Download</a>
            </div>
        </div>
        <?php if($mode === ""){ ?>
            <div class="choice-grid">
                <?php if($canEmbed || $canUseOfficeViewer){ ?>
                    <a class="choice-card" href="view_document.php?id=<?php echo $id; ?>&mode=browser<?php echo htmlspecialchars($officeParam, ENT_QUOTES); ?>">
                        <i class="bi bi-window"></i>
                        <span class="choice-title">View in Browser</span>
                        <span class="muted-copy">Open a preview inside this page.</span>
                    </a>
                <?php } ?>
                <?php if(in_array($fileExt, ['jpg', 'jpeg', 'png'], true)){ ?>
                    <a class="choice-card" href="view_document.php?id=<?php echo $id; ?>&mode=file<?php echo htmlspecialchars($officeParam, ENT_QUOTES); ?>" target="_blank">
                        <i class="bi bi-image"></i>
                        <span class="choice-title">Open Image</span>
                        <span class="muted-copy">Open the image file in a new tab.</span>
                    </a>
                <?php } ?>
                <?php if($appOpenUrl !== ""){ ?>
                    <a class="choice-card" href="<?php echo htmlspecialchars($appOpenUrl, ENT_QUOTES); ?>">
                        <i class="bi bi-file-earmark-word"></i>
                        <span class="choice-title">Open in Office App</span>
                        <span class="muted-copy">Use Word, Excel, or PowerPoint if installed.</span>
                    </a>
                <?php } ?>
                <a class="choice-card" href="<?php echo htmlspecialchars($downloadUrl . $officeParam, ENT_QUOTES); ?>">
                    <i class="bi bi-download"></i>
                    <span class="choice-title">Download</span>
                    <span class="muted-copy">Save a copy to this computer.</span>
                </a>
            </div>
        <?php } elseif(in_array($fileExt, ['jpg', 'jpeg', 'png'], true)){ ?>
            <img class="image-preview" src="<?php echo $safeViewUrl; ?>" alt="<?php echo $safeFileName; ?>">
        <?php } elseif($fileExt === 'pdf'){ ?>
            <iframe class="preview-frame" src="<?php echo $safeViewUrl; ?>"></iframe>
        <?php } elseif($canUseOfficeViewer){ ?>
            <iframe class="preview-frame" src="<?php echo htmlspecialchars($officeViewerUrl, ENT_QUOTES); ?>"></iframe>
        <?php } else { ?>
            <div class="empty-state">
                <div>
                    <div class="mb-2">Preview is not available for this file type in the browser.</div>
                    <div class="muted-copy">Use Download only when you need a local copy.</div>
                </div>
            </div>
        <?php } ?>
    </section>
</main>
</body>
</html>
