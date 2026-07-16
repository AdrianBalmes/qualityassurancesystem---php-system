<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
header('Content-Type: application/json');

if(!isset($_SESSION['office_username']) || !isset($_SESSION['office_name'])){
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

$office = $_SESSION['office_name'];
$action = $_POST['action'] ?? '';

if($action === 'add'){
    $auditType = audit_type_for_office($office);
    $stmt = $conn->prepare("INSERT INTO audit_recommendations (audit_type, office, recommendation, year, status) VALUES (?, ?, '', '', 'Pending')");
    $stmt->bind_param("ss", $auditType, $office);
    $stmt->execute();
    echo json_encode(['ok' => true, 'id' => $conn->insert_id, 'audit_type' => $auditType]);
    exit();
}

if($action === 'update'){
    $id = intval($_POST['id'] ?? 0);
    $field = trim($_POST['field'] ?? '');
    $value = $_POST['value'] ?? '';
    $allowedFields = ['recommendation', 'status', 'remarks'];

    if($id <= 0 || !in_array($field, $allowedFields, true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid field']);
        exit();
    }

    $ownStmt = $conn->prepare("SELECT id FROM audit_recommendations WHERE id = ? AND office = ? LIMIT 1");
    $ownStmt->bind_param("is", $id, $office);
    $ownStmt->execute();
    if($ownStmt->get_result()->num_rows === 0){
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not your office\'s record']);
        exit();
    }

    if($field === 'status' && !in_array($value, ['Pending', 'Submitted', 'Not Submitted'], true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid status']);
        exit();
    }
    $stmt = $conn->prepare("UPDATE audit_recommendations SET {$field} = ? WHERE id = ? AND office = ?");
    $stmt->bind_param("sis", $value, $id, $office);
    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit();
}

if($action === 'delete'){
    $id = intval($_POST['id'] ?? 0);
    if($id > 0){
        $stmt = $conn->prepare("DELETE FROM audit_recommendations WHERE id = ? AND office = ?");
        $stmt->bind_param("is", $id, $office);
        $stmt->execute();
    }
    echo json_encode(['ok' => true]);
    exit();
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
