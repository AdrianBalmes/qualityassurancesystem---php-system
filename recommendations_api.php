<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/office_directory.php";
header('Content-Type: application/json');

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'admin'){
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

$action = $_POST['action'] ?? '';

if($action === 'add_office_recommendation'){
    $office = trim($_POST['office'] ?? '');
    if(!in_array($office, get_all_office_names($conn), true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Office is not recognized']);
        exit();
    }

    $auditType = audit_type_for_office($office);
    $stmt = $conn->prepare("INSERT INTO audit_recommendations (audit_type, office, recommendation, year, status) VALUES (?, ?, '', '', 'Pending')");
    $stmt->bind_param("ss", $auditType, $office);
    $stmt->execute();
    echo json_encode(['ok' => true, 'id' => $conn->insert_id, 'audit_type' => $auditType, 'office' => $office]);
    exit();
}

if($action === 'save_office_recommendation'){
    $id = intval($_POST['id'] ?? 0);
    $recommendation = trim($_POST['recommendation'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $year = trim($_POST['year'] ?? '');

    if($id <= 0){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid row']);
        exit();
    }
    if(!in_array($status, ['Pending', 'Submitted', 'Not Submitted'], true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid status']);
        exit();
    }
    if($year !== '' && !preg_match('/^\d{4}$/', $year)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Year must be 4 digits']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE audit_recommendations SET recommendation = ?, status = ?, remarks = ?, year = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $recommendation, $status, $remarks, $year, $id);
    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit();
}

if($action === 'delete'){
    $id = intval($_POST['id'] ?? 0);
    if($id > 0){
        $stmt = $conn->prepare("DELETE FROM audit_recommendations WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    echo json_encode(['ok' => true]);
    exit();
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
