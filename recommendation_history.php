<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/recommendation_rules.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/permissions.php";
header('Content-Type: application/json');

/**
 * One recommendation's own history, for the modal on both dashboards.
 *
 * The administrator sees every entry. An office sees what concerns it: the
 * recommendation's own life -- created, edited, reviewed -- plus its own
 * submissions. Another office's compliance responses and document names are
 * that office's business, the same way the dashboard already keeps each
 * office's remarks and files apart.
 */

$isAdmin = session_is_qa_staff();
$viewerOffice = '';

if(!$isAdmin){
    // One browser can hold several office sign-ins, so the page says which.
    $requested = trim($_GET['office'] ?? '');
    $logins = isset($_SESSION['office_logins']) && is_array($_SESSION['office_logins']) ? $_SESSION['office_logins'] : [];

    if($requested !== '' && isset($logins[$requested])){
        $viewerOffice = $requested;
    } elseif($requested !== '' && ($_SESSION['office_name'] ?? '') === $requested){
        $viewerOffice = $requested;
    }

    if($viewerOffice === ''){
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit();
    }
    enforce_active_account($conn, 'office');
} else {
    enforce_active_account($conn, 'admin');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($id <= 0){
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid recommendation']);
    exit();
}

$recStmt = $conn->prepare("SELECT office, recommendation, status, in_charge FROM audit_recommendations WHERE id = ? LIMIT 1");
$recStmt->bind_param("i", $id);
$recStmt->execute();
$recommendation = $recStmt->get_result()->fetch_assoc();

// An office may only read the history of a recommendation it is part of.
if(!$isAdmin){
    if(!$recommendation || !recommendation_involves_office($recommendation, $viewerOffice)){
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'That recommendation does not belong to your office.']);
        exit();
    }
}

$entries = [];
foreach(fetch_recommendation_history($conn, $id) as $row){
    if(!$isAdmin && !recommendation_history_entry_is_visible($row, $viewerOffice)){
        continue;
    }

    $style = audit_action_label($row['action']);
    $actor = trim((string) $row['actor_full_name']);
    $description = (string) $row['description'];

    // Who else is in charge is not spelled out to an office that is only one
    // of them; that it changed at all still belongs in the story.
    if(!$isAdmin && $row['action'] === 'in_charge_updated' && trim((string) $row['office']) !== $viewerOffice){
        $description = 'Who is in charge of this recommendation was changed.';
    }

    $entries[] = [
        'label'       => $style['label'],
        'icon'        => $style['icon'],
        'class'       => $style['class'],
        'description' => $description,
        'actor'       => $actor !== '' ? $actor : $row['actor_username'],
        'username'    => $row['actor_username'],
        'role'        => $row['actor_role'],
        'office'      => (string) $row['office'],
        'at'          => date('M j, Y g:i A', strtotime($row['created_at'])),
    ];
}

echo json_encode([
    'ok' => true,
    'id' => $id,
    // A deleted recommendation still has a history worth reading.
    'office' => $recommendation['office'] ?? '',
    'recommendation' => $recommendation['recommendation'] ?? '',
    'status' => $recommendation['status'] ?? '',
    'exists' => $recommendation ? true : false,
    'entries' => $entries,
]);
