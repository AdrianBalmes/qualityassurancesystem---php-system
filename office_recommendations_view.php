<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/office_rec_render.php";
header('Content-Type: application/json');

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'admin'){
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

$selectedAudit = isset($_GET['audit']) ? trim($_GET['audit']) : 'External';
if(!in_array($selectedAudit, ['External', 'Internal'], true)){
    $selectedAudit = 'External';
}

$selectedOffice = isset($_GET['office']) ? trim($_GET['office']) : '';
if($selectedOffice !== '' && audit_type_for_office($selectedOffice) !== $selectedAudit){
    $selectedOffice = '';
}

if($selectedOffice === ''){
    $html = render_select_office_placeholder();
    $count = 0;
} else {
    $recommendations = fetch_office_recommendations($conn, $selectedOffice, $selectedAudit);
    $rendered = render_office_recommendation_rows($recommendations, $selectedOffice, $selectedAudit);
    $html = $rendered['html'];
    $count = $rendered['count'];
}

echo json_encode([
    'ok' => true,
    'office' => $selectedOffice,
    'audit' => $selectedAudit,
    'title' => $selectedOffice . ' Recommendations',
    'html' => $html,
    'count' => $count,
]);
