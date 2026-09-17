<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/audit_areas.php";
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

/**
 * Stop the OneDrive worker retrying files that no longer exist. Rows already
 * uploaded are kept as the record of what the repository holds.
 */
function forget_pending_onedrive_sync($conn, array $docIds){
    if(empty($docIds)){
        return;
    }
    // The queue table only exists once OneDrive sync has been used.
    $table = mysqli_query($conn, "SHOW TABLES LIKE 'onedrive_sync'");
    if(!$table || $table->num_rows === 0){
        return;
    }

    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
    $stmt = $conn->prepare("DELETE FROM onedrive_sync WHERE source_table = 'recommendation_documents' AND status <> 'uploaded' AND source_id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($docIds)), ...$docIds);
    $stmt->execute();
}

if($action === 'add_office_recommendation'){
    $office = trim($_POST['office'] ?? '');
    if(!in_array($office, get_all_office_names($conn), true)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Office is not recognized']);
        exit();
    }

    // Offices that file by area pass the one being viewed. Anything else, or an
    // area this office does not use, files as unassigned rather than inventing
    // a category.
    $area = trim($_POST['area'] ?? '');
    if($area !== '' && !audit_area_is_valid($office, $area)){
        $area = '';
    }

    $auditType = audit_type_for_office($office);
    $stmt = $conn->prepare("INSERT INTO audit_recommendations (audit_type, office, area, recommendation, year, status) VALUES (?, ?, ?, '', '', 'Pending')");
    $stmt->bind_param("sss", $auditType, $office, $area);
    $stmt->execute();
    $newId = $conn->insert_id;

    $where = $area !== '' ? "{$office} / {$area}" : $office;
    log_audit_event($conn, $adminUsername, 'admin', $office, 'recommendation_created', 'recommendation', $newId, "Created a new {$auditType} recommendation row for {$where}");

    echo json_encode(['ok' => true, 'id' => $newId, 'audit_type' => $auditType, 'office' => $office, 'area' => $area]);
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

        // Its supporting documents go with it. The schema has no foreign keys,
        // so left alone their rows and files would stay in uploads/ forever
        // with nothing pointing at them.
        $docsStmt = $conn->prepare("SELECT id, file_name FROM recommendation_documents WHERE recommendation_id = ?");
        $docsStmt->bind_param("i", $id);
        $docsStmt->execute();
        $docs = $docsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $conn->begin_transaction();
        try {
            $docDelete = $conn->prepare("DELETE FROM recommendation_documents WHERE recommendation_id = ?");
            $docDelete->bind_param("i", $id);
            $docDelete->execute();

            $stmt = $conn->prepare("DELETE FROM audit_recommendations WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            $conn->commit();
        } catch(Throwable $e){
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not delete that recommendation']);
            exit();
        }

        // Remove files only once the rows are gone for good.
        foreach($docs as $doc){
            $filePath = __DIR__ . "/uploads/" . basename($doc['file_name']);
            if(is_file($filePath)){
                unlink($filePath);
            }
        }
        forget_pending_onedrive_sync($conn, array_map(function($doc){ return (int) $doc['id']; }, $docs));

        if($beforeRow){
            $snippet = mb_substr(trim($beforeRow['recommendation']), 0, 80);
            $docNote = count($docs) > 0 ? " and " . count($docs) . " supporting document(s)" : "";
            log_audit_event($conn, $adminUsername, 'admin', $beforeRow['office'], 'recommendation_deleted', 'recommendation', $id, "Deleted recommendation{$docNote} for {$beforeRow['office']}: \"{$snippet}\"");
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
            $filePath = __DIR__ . "/uploads/" . basename($docRow['file_name']);
            if(is_file($filePath)){
                unlink($filePath);
            }
            forget_pending_onedrive_sync($conn, [$docId]);

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
