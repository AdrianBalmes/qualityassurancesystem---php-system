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

$stmt = $conn->prepare("SELECT office, file_name, file_link FROM documents WHERE id = ? LIMIT 1");
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
    exit("You are not allowed to download this document.");
}

if(!empty($doc['file_link'])){
    header("Location: " . $doc['file_link']);
    exit();
}

$fileName = basename($doc['file_name']);
$filePath = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . $fileName;

if(!is_file($filePath)){
    http_response_code(404);
    exit("Uploaded file not found.");
}

$mimeTypes = [
    "doc" => "application/msword",
    "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "pdf" => "application/pdf",
    "xls" => "application/vnd.ms-excel",
    "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "ppt" => "application/vnd.ms-powerpoint",
    "pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "jpg" => "image/jpeg",
    "jpeg" => "image/jpeg",
    "png" => "image/png"
];
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$contentType = $mimeTypes[$fileExt] ?? "application/octet-stream";

header("Content-Description: File Transfer");
header("Content-Type: " . $contentType);
header("Content-Disposition: attachment; filename=\"" . $fileName . "\"");
header("Content-Length: " . filesize($filePath));
header("Cache-Control: private, max-age=0, must-revalidate");
readfile($filePath);
exit();
?>
