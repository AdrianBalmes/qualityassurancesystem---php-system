<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/upload_access.php";

/**
 * Stream a supporting document an office submitted with a compliance update.
 *
 *   serve_upload.php?id=N             show it (PDFs and images open in the tab)
 *   serve_upload.php?id=N&download=1  always save it
 *
 * Admins may open any document; an office only its own.
 */

if(!isset($_SESSION['admin_username']) && !isset($_SESSION['office_username']) && empty($_SESSION['office_logins'])){
    header("Location: index.php");
    exit();
}
enforce_active_account($conn);

$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT office, file_name, original_name FROM recommendation_documents WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if(!$doc){
    http_response_code(404);
    exit("Document not found.");
}

if(!upload_viewer_can_see_office($doc['office'])){
    http_response_code(403);
    exit("You are not allowed to open this document.");
}

$path = __DIR__ . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . basename($doc['file_name']);
if(!is_file($path)){
    http_response_code(404);
    exit("The file for this document is missing on the server.");
}

upload_send_file($path, $doc['original_name'] !== '' ? $doc['original_name'] : $doc['file_name'], !isset($_GET['download']));
