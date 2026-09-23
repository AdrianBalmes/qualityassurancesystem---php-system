<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/audit_areas.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/review_columns.php";
require_once __DIR__ . "/in_charge.php";
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$isAdmin = isset($_SESSION['admin_username']) && $_SESSION['admin_role'] === 'admin';

// Everything here is the administrator's, except naming who is in charge --
// an office does that on its own recommendations.
if(!$isAdmin && !($action === 'save_in_charge' && isset($_SESSION['office_username']))){
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

ensure_review_columns($conn);

$adminUsername = $isAdmin ? $_SESSION['admin_username'] : '';

/**
 * Which office sign-in this request is acting as. One browser can hold several
 * at once, so the page says which; anything it is not signed in as is refused.
 */
function acting_office_login(){
    $requested = trim($_POST['office'] ?? '');
    if($requested === ''){
        return null;
    }

    $logins = isset($_SESSION['office_logins']) && is_array($_SESSION['office_logins']) ? $_SESSION['office_logins'] : [];
    if(isset($logins[$requested])){
        return $logins[$requested];
    }
    if(($_SESSION['office_name'] ?? '') === $requested){
        return [
            'username' => $_SESSION['office_username'] ?? '',
            'office'   => $_SESSION['office_name'] ?? '',
            'id'       => (int) ($_SESSION['office_user_id'] ?? 0),
        ];
    }
    return null;
}

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

    // Offices that file by programme and area pass whichever is open. Anything
    // this office does not actually use is dropped rather than stored, so the
    // columns cannot fill up with invented categories.
    $program = trim($_POST['program'] ?? '');
    if($program !== '' && !audit_program_is_valid($office, $program)){
        $program = '';
    }

    $area = trim($_POST['area'] ?? '');
    if($area !== '' && !audit_area_is_valid($office, $area, $program)){
        $area = '';
    }

    $auditType = audit_type_for_office($office);
    $stmt = $conn->prepare("INSERT INTO audit_recommendations (audit_type, office, program, area, recommendation, year, status) VALUES (?, ?, ?, ?, '', '', 'Pending')");
    $stmt->bind_param("ssss", $auditType, $office, $program, $area);
    $stmt->execute();
    $newId = $conn->insert_id;

    $where = implode(' / ', array_filter([$office, $program, $area]));
    log_audit_event($conn, $adminUsername, 'admin', $office, 'recommendation_created', 'recommendation', $newId, "Created a new {$auditType} recommendation row for {$where}");

    echo json_encode(['ok' => true, 'id' => $newId, 'audit_type' => $auditType, 'office' => $office, 'program' => $program, 'area' => $area]);
    exit();
}

if($action === 'save_in_charge'){
    $id = intval($_POST['id'] ?? 0);
    if($id <= 0){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid row']);
        exit();
    }

    $rowStmt = $conn->prepare("SELECT office, in_charge FROM audit_recommendations WHERE id = ? LIMIT 1");
    $rowStmt->bind_param("i", $id);
    $rowStmt->execute();
    $row = $rowStmt->get_result()->fetch_assoc();
    if(!$row){
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'That recommendation no longer exists']);
        exit();
    }

    $stored = in_charge_decode($row['in_charge'] ?? '');

    if($isAdmin){
        $actorUsername = $adminUsername;
        $actorRole = 'admin';
        $actorOffice = $row['office'];
        $ownsRecommendation = true;
        $actingOffice = '';
    } else {
        // Anyone in the office may name who is in charge of that office's own
        // recommendations. An office put in charge of someone else's may name
        // its own people there too -- and nothing else, which is enforced
        // below rather than trusted to the page.
        $login = acting_office_login();
        $actingOffice = $login['office'] ?? '';
        $ownsRecommendation = $actingOffice !== '' && $actingOffice === $row['office'];
        $isInCharge = $actingOffice !== '' && in_array($actingOffice, $stored, true);

        if(!$login || (!$ownsRecommendation && !$isInCharge)){
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => "Only {$row['office']} can change who is in charge of this recommendation"]);
            exit();
        }

        // The account may have been rejected since this page was opened.
        $accountStmt = $conn->prepare("SELECT status, role, office FROM users WHERE id = ? LIMIT 1");
        $accountId = (int) ($login['id'] ?? 0);
        $accountStmt->bind_param("i", $accountId);
        $accountStmt->execute();
        $account = $accountStmt->get_result()->fetch_assoc();
        if(!$account || user_login_block_reason($account) !== ''){
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Your account can no longer sign in.']);
            exit();
        }

        $actorUsername = $login['username'] ?? '';
        $actorRole = 'office';
        $actorOffice = $login['office'];
    }

    if($ownsRecommendation){
        $inCharge = in_charge_encode_posted($_POST['in_charge'] ?? '');
    } else {
        // Only this office's own people are taken from the request. Every
        // other name comes from what is stored, so a crafted post cannot
        // rewrite the offices assigned or another office's people.
        $ownStaff = in_charge_office_staff_names($conn, $actingOffice);
        $posted = in_charge_decode($_POST['in_charge'] ?? '');
        $kept = array_values(array_filter($stored, function($name) use ($ownStaff){
            return !in_array($name, $ownStaff, true);
        }));
        $mine = array_values(array_filter($posted, function($name) use ($ownStaff){
            return in_array($name, $ownStaff, true);
        }));
        $inCharge = in_charge_encode(array_merge($kept, $mine));
    }

    $update = $conn->prepare("UPDATE audit_recommendations SET in_charge = ? WHERE id = ?");
    $update->bind_param("si", $inCharge, $id);
    $update->execute();

    $names = in_charge_decode($inCharge);
    $who = !empty($names) ? implode(', ', $names) : 'nobody';
    log_audit_event($conn, $actorUsername, $actorRole, $actorOffice, 'in_charge_updated', 'recommendation', $id,
        "Set who is in charge of recommendation #{$id} ({$row['office']}) to {$who}");

    echo json_encode(['ok' => true, 'in_charge' => $names]);
    exit();
}

if($action === 'save_office_recommendation'){
    $id = intval($_POST['id'] ?? 0);
    $recommendation = trim($_POST['recommendation'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $year = trim($_POST['year'] ?? '');

    // Only present when the grid rendered remarks as a single editable cell.
    // A recommendation with more than one office's remarks renders them
    // read-only instead, and must not be overwritten by an empty save here.
    $remarksProvided = array_key_exists('remarks', $_POST);
    $remarks = trim($_POST['remarks'] ?? '');

    // Sent as a JSON array of office/person names; anything malformed or not a
    // plain string is dropped rather than rejecting the whole save.
    $inCharge = in_charge_encode_posted($_POST['in_charge'] ?? '');

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
    // A single year or a school year. "2026 - 2027" is the same thing typed
    // more loosely, so it is tidied rather than refused.
    $year = preg_replace('/\s*-\s*/', '-', $year);
    if($year !== '' && !preg_match('/^\d{4}(-\d{4})?$/', $year)){
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Year must be 2026 or 2026-2027']);
        exit();
    }
    if(strpos($year, '-') !== false){
        list($yearFrom, $yearTo) = explode('-', $year);
        if((int) $yearTo <= (int) $yearFrom){
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'The second year has to come after the first']);
            exit();
        }
    }

    $beforeStmt = $conn->prepare("SELECT office, status FROM audit_recommendations WHERE id = ? LIMIT 1");
    $beforeStmt->bind_param("i", $id);
    $beforeStmt->execute();
    $beforeRow = $beforeStmt->get_result()->fetch_assoc();

    if($remarksProvided){
        $stmt = $conn->prepare("UPDATE audit_recommendations SET recommendation = ?, status = ?, remarks = ?, year = ?, in_charge = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $recommendation, $status, $remarks, $year, $inCharge, $id);
    } else {
        $stmt = $conn->prepare("UPDATE audit_recommendations SET recommendation = ?, status = ?, year = ?, in_charge = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $recommendation, $status, $year, $inCharge, $id);
    }
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

    // College Department reviews one submitted document at a time: the decision
    // is recorded against the chosen document. Marking the whole
    // recommendation Completed is the one action that needs no document.
    $reviewedDoc = null;
    if($beforeRow['office'] === 'College Department' && $decision !== 'completed'){
        $docId = intval($_POST['doc_id'] ?? 0);
        $docStmt = $conn->prepare("SELECT id, original_name FROM recommendation_documents WHERE id = ? AND recommendation_id = ? LIMIT 1");
        $docStmt->bind_param("ii", $docId, $id);
        $docStmt->execute();
        $reviewedDoc = $docStmt->get_result()->fetch_assoc();
        if(!$reviewedDoc){
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Choose one submitted document to review.']);
            exit();
        }

        $docReviewStatus = $decisionStatusMap[$decision] ?? 'Remarks only';
        $docUpdate = $conn->prepare("UPDATE recommendation_documents SET review_status = ?, review_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $docUpdate->bind_param("sssi", $docReviewStatus, $reviewRemarks, $adminUsername, $docId);
        $docUpdate->execute();
        // Choosing a file only says which submission the feedback is about.
        // The office receives the feedback text alone, never the document.
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
    $docNote = $reviewedDoc ? " (document: {$reviewedDoc['original_name']})" : "";
    log_audit_event($conn, $adminUsername, 'admin', $beforeRow['office'], 'recommendation_reviewed', 'recommendation', $id, "Admin {$verb} recommendation for {$beforeRow['office']}{$docNote}{$statusChangeNote}");

    echo json_encode(['ok' => true, 'status' => $newStatus, 'review_remarks' => $reviewRemarks, 'doc_id' => $reviewedDoc ? (int) $reviewedDoc['id'] : null, 'doc_review_status' => $reviewedDoc ? $docReviewStatus : null]);
    exit();
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
