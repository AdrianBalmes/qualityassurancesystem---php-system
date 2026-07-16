<?php
session_start();
require_once __DIR__ . "/database.php";
header('Content-Type: application/json');

if(!isset($_SESSION['admin_username'])){
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

$key = trim($_POST['content_key'] ?? '');
$value = $_POST['content_value'] ?? '';

if($key === '' || strlen($key) > 150){
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid key']);
    exit();
}

$stmt = $conn->prepare("INSERT INTO site_content (content_key, content_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE content_value = VALUES(content_value)");
$stmt->bind_param("ss", $key, $value);
$stmt->execute();

echo json_encode(['ok' => true]);
