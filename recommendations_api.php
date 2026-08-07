<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/review_columns.php";
header('Content-Type: application/json');

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'admin'){
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

ensure_review_columns($conn);

$adminUsername = $_SESSION['admin_username'];
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
    $newId = $conn->insert_id;

    log_audit_event($conn, $adminUsername, 'admin', $office, 'recommendation_created', 'recommendation', $newId, "Created a new {$auditType} recommendation row for {$office}");

    echo json_encode(['ok' => true, 'id' => $newId, 'audit_type' => $auditType, 'office' => $office]);
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
    if(!in_array($status, ['Pending', 'Submitted', 'Not Submitted', 'Approved', 'Rejected', 'Needs Revision', 'Completed'], true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid status']);
        exit();
    }
    if($year !== '' && !preg_match('/^\d{4}$/', $year)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Year must be 4 digits']);
        exit();
    }

    $beforeStmt = $conn->prepare("SELECT office, status FROM audit_recommendations WHERE id = ? LIMIT 1");
    $beforeStmt->bind_param("i", $id);
    $beforeStmt->execute();
    $beforeRow = $beforeStmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare("UPDATE audit_recommendations SET recommendation = ?, status = ?, remarks = ?, year = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $recommendation, $status, $remarks, $year, $id);
    $stmt->execute();

    if($beforeRow){
        $statusChange = $beforeRow['status'] !== $status ? " (status: {$beforeRow['status']} \xe2\x86\x92 {$status})" : "";
        log_audit_event($conn, $adminUsername, 'admin', $beforeRow['office'], 'recommendation_updated', 'recommendation', $id, "Updated recommendation for {$beforeRow['office']}{$statusChange}");
    }

    echo json_encode(['ok' => true]);
    exit();
}

if($action === 'delete'){
    $id = intval($_POST['id'] ?? 0);
    if($id > 0){
        $beforeStmt = $conn->prepare("SELECT office, recommendation FROM audit_recommendations WHERE id = ? LIMIT 1");
        $beforeStmt->bind_param("i", $id);
        $beforeStmt->execute();
        $beforeRow = $beforeStmt->get_result()->fetch_assoc();

        $stmt = $conn->prepare("DELETE FROM audit_recommendations WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        if($beforeRow){
            $snippet = mb_substr(trim($beforeRow['recommendation']), 0, 80);
            log_audit_event($conn, $adminUsername, 'admin', $beforeRow['office'], 'recommendation_deleted', 'recommendation', $id, "Deleted recommendation for {$beforeRow['office']}: \"{$snippet}\"");
        }
    }
    echo json_encode(['ok' => true]);
    exit();
}

if($action === 'delete_document'){
    $docId = intval($_POST['doc_id'] ?? 0);
    if($docId > 0){
        $docStmt = $conn->prepare("SELECT file_name, original_name, office FROM recommendation_documents WHERE id = ? LIMIT 1");
        $docStmt->bind_param("i", $docId);
        $docStmt->execute();
        $docRow = $docStmt->get_result()->fetch_assoc();
        if($docRow){
            $deleteStmt = $conn->prepare("DELETE FROM recommendation_documents WHERE id = ?");
            $deleteStmt->bind_param("i", $docId);
            $deleteStmt->execute();
            $filePath = __DIR__ . "/uploads/" . $docRow['file_name'];
            if(is_file($filePath)){
                unlink($filePath);
            }

            log_audit_event($conn, $adminUsername, 'admin', $docRow['office'], 'document_deleted', 'document', $docId, "Deleted submitted document \"{$docRow['original_name']}\" ({$docRow['office']})");
        }
    }
    echo json_encode(['ok' => true]);
    exit();
}

if($action === 'review_recommendation'){
    $id = intval($_POST['id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');
    $reviewRemarks = trim($_POST['review_remarks'] ?? '');

    $decisionStatusMap = [
        'approve' => 'Approved',
        'reject' => 'Rejected',
        'needs_revision' => 'Needs Revision',
        'completed' => 'Completed',
        'remarks_only' => null,
    ];

    if($id <= 0 || !array_key_exists($decision, $decisionStatusMap)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid review request']);
        exit();
    }

    $beforeStmt = $conn->prepare("SELECT office, status FROM audit_recommendations WHERE id = ? LIMIT 1");
    $beforeStmt->bind_param("i", $id);
    $beforeStmt->execute();
    $beforeRow = $beforeStmt->get_result()->fetch_assoc();

    if(!$beforeRow){
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Recommendation not found']);
        exit();
    }

    $newStatus = $decisionStatusMap[$decision];
    if($newStatus !== null){
        $stmt = $conn->prepare("UPDATE audit_recommendations SET status = ?, review_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->bind_param("sssi", $newStatus, $reviewRemarks, $adminUsername, $id);
    } else {
        $newStatus = $beforeRow['status'];
        $stmt = $conn->prepare("UPDATE audit_recommendations SET review_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssi", $reviewRemarks, $adminUsername, $id);
    }
    $stmt->execute();

    $decisionVerbs = [
        'approve' => 'approved',
        'reject' => 'rejected',
        'needs_revision' => 'requested revision on',
        'completed' => 'marked completed',
        'remarks_only' => 'added review remarks to',
    ];
    $verb = $decisionVerbs[$decision] ?? 'reviewed';
    $statusChangeNote = $beforeRow['status'] !== $newStatus ? " (status: {$beforeRow['status']} \xe2\x86\x92 {$newStatus})" : "";
    log_audit_event($conn, $adminUsername, 'admin', $beforeRow['office'], 'recommendation_reviewed', 'recommendation', $id, "Admin {$verb} recommendation for {$beforeRow['office']}{$statusChangeNote}");

    echo json_encode(['ok' => true, 'status' => $newStatus, 'review_remarks' => $reviewRemarks]);
    exit();
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
