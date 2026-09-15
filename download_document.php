<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/upload_access.php";

/**
 * Stream a repository document.
 *
 *   download_document.php?id=N           save it
 *   download_document.php?id=N&inline=1  show it in the page (PDFs, images)
 *
 * Also accepts a short-lived signed link from view_document.php: Word opens
 * files through the ms-word: protocol and cannot send the login cookie.
 */

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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$signed = $id > 0 && upload_signature_is_valid($conn, 'document', $id);

if(!$signed){
    if(!isset($_SESSION['admin_username']) && !isset($_SESSION['office_username'])){
        header("Location: index.php");
        exit();
    }
    enforce_active_account($conn);
}

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

if(!$signed && !upload_viewer_can_see_office($doc['office'])){
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

upload_send_file($filePath, $fileName, isset($_GET['inline']));
