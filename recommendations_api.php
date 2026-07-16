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

if($action === 'add'){
    $preferredAudit = trim($_POST['preferred_audit'] ?? '');
    $office = $preferredAudit === 'External' ? 'BED' : '';
    $auditType = audit_type_for_office($office);
    $stmt = $conn->prepare("INSERT INTO audit_recommendations (audit_type, office, recommendation, year, status) VALUES (?, ?, '', '', 'Pending')");
    $stmt->bind_param("ss", $auditType, $office);
    $stmt->execute();
    echo json_encode(['ok' => true, 'id' => $conn->insert_id, 'audit_type' => $auditType, 'office' => $office]);
    exit();
}

if($action === 'update'){
    $id = intval($_POST['id'] ?? 0);
    $field = trim($_POST['field'] ?? '');
    $value = $_POST['value'] ?? '';
    $allowedFields = ['office', 'recommendation', 'year', 'status', 'remarks'];

    if($id <= 0 || !in_array($field, $allowedFields, true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid field']);
        exit();
    }
    if($field === 'status' && !in_array($value, ['Pending', 'Submitted', 'Not Submitted'], true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid status']);
        exit();
    }
    if($field === 'year' && $value !== '' && !preg_match('/^\d{4}$/', $value)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Year must be 4 digits']);
        exit();
    }
    if($field === 'office' && !in_array($value, get_all_office_names($conn), true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Office is not recognized']);
        exit();
    }

    if($field === 'office'){
        $derivedAuditType = audit_type_for_office($value);
        $stmt = $conn->prepare("UPDATE audit_recommendations SET office = ?, audit_type = ? WHERE id = ?");
        $stmt->bind_param("ssi", $value, $derivedAuditType, $id);
        $stmt->execute();
        echo json_encode(['ok' => true, 'audit_type' => $derivedAuditType]);
        exit();
    }

    $stmt = $conn->prepare("UPDATE audit_recommendations SET {$field} = ? WHERE id = ?");
    $stmt->bind_param("si", $value, $id);
    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit();
}

if($action === 'save_row'){
    $id = intval($_POST['id'] ?? 0);
    $office = trim($_POST['office'] ?? '');
    $recommendation = trim($_POST['recommendation'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $year = trim($_POST['year'] ?? '');

    if($id <= 0){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid row']);
        exit();
    }
    if(!in_array($office, get_all_office_names($conn), true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Office is not recognized']);
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

    $auditType = audit_type_for_office($office);
    $stmt = $conn->prepare("UPDATE audit_recommendations SET office = ?, recommendation = ?, status = ?, remarks = ?, year = ?, audit_type = ? WHERE id = ?");
    $stmt->bind_param("ssssssi", $office, $recommendation, $status, $remarks, $year, $auditType, $id);
    $stmt->execute();
    echo json_encode(['ok' => true, 'audit_type' => $auditType, 'office' => $office]);
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
