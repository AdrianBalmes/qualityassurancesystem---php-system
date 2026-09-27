<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/audit_areas.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/review_columns.php";
require_once __DIR__ . "/in_charge.php";
require_once __DIR__ . "/recommendation_rules.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/office_statuses.php";
require_once __DIR__ . "/notifications.php";
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$isAdmin = isset($_SESSION['admin_username']) && $_SESSION['admin_role'] === 'admin';

// Everything here is the administrator's, bar one thing an office does on its
// own work: taking back a file it submitted itself. Offices still do not
// assign who is in charge -- the dashboard no longer offers it and this
// refuses it too.
if(!$isAdmin && !($action === 'delete_document' && isset($_SESSION['office_username']))){
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

    // An office added here sees the recommendation on its dashboard from now
    // on, so it is told the same way it would be told about a new one.
    $knownOffices = get_all_office_names($conn);
    $newlyAssigned = array_values(array_filter($names, function($name) use ($stored, $knownOffices){
        return in_array($name, $knownOffices, true) && !in_array($name, $stored, true);
    }));
    if(!empty($newlyAssigned)){
        $recStmt = $conn->prepare("SELECT recommendation FROM audit_recommendations WHERE id = ? LIMIT 1");
        $recStmt->bind_param("i", $id);
        $recStmt->execute();
        $recText = trim((string) ($recStmt->get_result()->fetch_assoc()['recommendation'] ?? ''));

        notify_offices($conn, $newlyAssigned, NOTIFY_ASSIGNED, $id,
            "Your office was put in charge of a {$row['office']} recommendation",
            $recText !== '' ? $recText : 'The recommendation has not been written out yet.',
            $actorOffice);
    }
    log_audit_event($conn, $actorUsername, $actorRole, $actorOffice, 'in_charge_updated', 'recommendation', $id,
        "Set who is in charge of recommendation #{$id} ({$row['office']}) to {$who}");

    echo json_encode(['ok' => true, 'in_charge' => $names]);
    exit();
}

// ---- Each office's own status options ------------------------------------
// Built-in statuses are shared; anything added here belongs to one office and
// is invisible to every other.
function status_manager_reply($conn, $office, $extra = []){
    echo json_encode(array_merge([
        'ok'       => true,
        'office'   => $office,
        'statuses' => office_status_choices($conn, $office),
        'custom'   => office_custom_status_rows($conn, $office),
    ], $extra));
    exit();
}

function status_manager_fail($message, $code = 400){
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit();
}

if($action === 'list_office_statuses' || $action === 'add_office_status'
   || $action === 'rename_office_status' || $action === 'delete_office_status'){
    ensure_office_statuses_table($conn);

    $office = trim($_POST['office'] ?? '');
    if($action !== 'list_office_statuses' && $action !== 'add_office_status' && (int) ($_POST['id'] ?? 0) > 0){
        // Rename and delete are addressed by id; the office comes from the row
        // so one office cannot touch another's statuses by naming it.
        $idStmt = $conn->prepare("SELECT office FROM office_statuses WHERE id = ? LIMIT 1");
        $idStmt->bind_param("i", $_POST['id']);
        $idStmt->execute();
        $found = $idStmt->get_result()->fetch_assoc();
        if($found){ $office = $found['office']; }
    }
    if($office === '' || !in_array($office, get_all_office_names($conn), true)){
        status_manager_fail('Office is not recognized');
    }

    if($action === 'list_office_statuses'){
        status_manager_reply($conn, $office);
    }

    if($action === 'add_office_status'){
        $name = trim($_POST['name'] ?? '');
        $problem = office_status_name_problem($conn, $office, $name);
        if($problem !== ''){ status_manager_fail($problem); }

        $stmt = $conn->prepare("INSERT INTO office_statuses (office, name, created_by) VALUES (?,?,?)");
        $stmt->bind_param("sss", $office, $name, $adminUsername);
        $stmt->execute();
        log_audit_event($conn, $adminUsername, 'admin', $office, 'status_created', 'office_status', $conn->insert_id,
            "Added the status \"{$name}\" for {$office}");
        status_manager_reply($conn, $office, ['message' => "Added {$name}."]);
    }

    $statusId = intval($_POST['id'] ?? 0);
    $rowStmt = $conn->prepare("SELECT id, office, name FROM office_statuses WHERE id = ? LIMIT 1");
    $rowStmt->bind_param("i", $statusId);
    $rowStmt->execute();
    $status = $rowStmt->get_result()->fetch_assoc();
    if(!$status){
        status_manager_fail('That status no longer exists', 404);
    }

    if($action === 'rename_office_status'){
        $newName = trim($_POST['name'] ?? '');
        if($newName === $status['name']){
            status_manager_reply($conn, $office);
        }
        $problem = office_status_name_problem($conn, $office, $newName, $statusId);
        if($problem !== ''){ status_manager_fail($problem); }

        // Recommendations store the status by name, so they follow the rename.
        $conn->begin_transaction();
        try {
            $up = $conn->prepare("UPDATE office_statuses SET name = ? WHERE id = ?");
            $up->bind_param("si", $newName, $statusId);
            $up->execute();
            $recs = $conn->prepare("UPDATE audit_recommendations SET status = ? WHERE office = ? AND status = ?");
            $recs->bind_param("sss", $newName, $office, $status['name']);
            $recs->execute();
            $moved = $conn->affected_rows;
            $conn->commit();
        } catch(Throwable $e){
            $conn->rollback();
            status_manager_fail('Could not rename that status', 500);
        }
        log_audit_event($conn, $adminUsername, 'admin', $office, 'status_updated', 'office_status', $statusId,
            "Renamed the {$office} status \"{$status['name']}\" to \"{$newName}\" ({$moved} recommendation(s) updated)");
        status_manager_reply($conn, $office, ['message' => "Renamed to {$newName}.", 'renamed_from' => $status['name']]);
    }

    // delete_office_status
    $useStmt = $conn->prepare("SELECT COUNT(*) AS n FROM audit_recommendations WHERE office = ? AND status = ?");
    $useStmt->bind_param("ss", $office, $status['name']);
    $useStmt->execute();
    $inUse = (int) $useStmt->get_result()->fetch_assoc()['n'];
    if($inUse > 0){
        status_manager_fail("\"{$status['name']}\" is set on {$inUse} recommendation(s). Change those to another status first.");
    }
    $del = $conn->prepare("DELETE FROM office_statuses WHERE id = ?");
    $del->bind_param("i", $statusId);
    $del->execute();
    log_audit_event($conn, $adminUsername, 'admin', $office, 'status_deleted', 'office_status', $statusId,
        "Deleted the {$office} status \"{$status['name']}\"");
    status_manager_reply($conn, $office, ['message' => "Deleted {$status['name']}.", 'deleted' => $status['name']]);
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
    // What counts as valid depends on the recommendation's own office: the
    // built-in statuses plus that office's custom ones, and never another's.
    $ownerStmt = $conn->prepare("SELECT office, status FROM audit_recommendations WHERE id = ? LIMIT 1");
    $ownerStmt->bind_param("i", $id);
    $ownerStmt->execute();
    $owner = $ownerStmt->get_result()->fetch_assoc();
    $allowedStatuses = $owner ? office_status_choices($conn, $owner['office']) : OFFICE_STATUS_BUILTIN;
    // Keeping the status a row already has is always fine, even if it has since
    // been removed from the list.
    if(!in_array($status, $allowedStatuses, true) && !($owner && $owner['status'] === $status)){
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

    $beforeStmt = $conn->prepare("SELECT office, status, recommendation, in_charge FROM audit_recommendations WHERE id = ? LIMIT 1");
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

        // A row is created blank and filled in afterwards, so the moment it
        // becomes a real recommendation is the moment it first has text --
        // not the moment the row appeared.
        $recipients = notification_recipients($conn, ['office' => $beforeRow['office'], 'in_charge' => $inCharge]);
        $wasBlank = trim((string) $beforeRow['recommendation']) === '';

        if($wasBlank && $recommendation !== ''){
            notify_offices($conn, $recipients, NOTIFY_NEW, $id,
                'New recommendation for your office', $recommendation);
        } elseif($beforeRow['status'] !== $status){
            notify_offices($conn, $recipients, NOTIFY_STATUS, $id,
                "Status changed to {$status}",
                ($recommendation !== '' ? $recommendation : 'This recommendation has no text yet.'));
        }
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
        $docStmt = $conn->prepare("SELECT d.file_name, d.original_name, d.office, d.recommendation_id, d.review_status,
                                          r.status AS recommendation_status
                                     FROM recommendation_documents d
                                LEFT JOIN audit_recommendations r ON r.id = d.recommendation_id
                                    WHERE d.id = ? LIMIT 1");
        $docStmt->bind_param("i", $docId);
        $docStmt->execute();
        $docRow = $docStmt->get_result()->fetch_assoc();

        if($docRow){
            // An office takes back only its own file, and only while the
            // submission is still open -- office_can_remove_document() in
            // recommendation_rules.php is the same rule the page asks.
            $actorUsername = $adminUsername;
            $actorRole = 'admin';

            if(!$isAdmin){
                $login = acting_office_login();
                $actingOffice = $login['office'] ?? '';

                if(!$login || !office_can_remove_document($docRow, $docRow['recommendation_status'], $actingOffice)){
                    http_response_code(403);
                    echo json_encode(['ok' => false, 'error' => 'This document can no longer be removed.']);
                    exit();
                }

                // The account may have been rejected since the page loaded.
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
            }

            $deleteStmt = $conn->prepare("DELETE FROM recommendation_documents WHERE id = ?");
            $deleteStmt->bind_param("i", $docId);
            $deleteStmt->execute();
            $filePath = __DIR__ . "/uploads/" . basename($docRow['file_name']);
            if(is_file($filePath)){
                unlink($filePath);
            }
            forget_pending_onedrive_sync($conn, [$docId]);

            $verb = $isAdmin ? 'Deleted' : 'Withdrew';
            log_audit_event($conn, $actorUsername, $actorRole, $docRow['office'], 'document_deleted', 'document', $docId, "{$verb} submitted document \"{$docRow['original_name']}\" ({$docRow['office']})", (int) $docRow['recommendation_id']);
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

    $beforeStmt = $conn->prepare("SELECT office, status, recommendation, in_charge FROM audit_recommendations WHERE id = ? LIMIT 1");
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

    // Feedback with no decision still changes what the office has to act on,
    // so it is worth a notice of its own.
    $reviewRecipients = notification_recipients($conn, $beforeRow);
    $recSnippet = trim((string) $beforeRow['recommendation']);
    if($beforeRow['status'] !== $newStatus){
        notify_offices($conn, $reviewRecipients, NOTIFY_STATUS, $id,
            "Status changed to {$newStatus}",
            $reviewRemarks !== '' ? $reviewRemarks : $recSnippet);
    } elseif($reviewRemarks !== ''){
        notify_offices($conn, $reviewRecipients, NOTIFY_FEEDBACK, $id,
            'New review feedback', $reviewRemarks);
    }

    echo json_encode(['ok' => true, 'status' => $newStatus, 'review_remarks' => $reviewRemarks, 'doc_id' => $reviewedDoc ? (int) $reviewedDoc['id'] : null, 'doc_review_status' => $reviewedDoc ? $docReviewStatus : null]);
    exit();
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
