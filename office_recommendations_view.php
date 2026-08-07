<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/office_rec_render.php";
require_once __DIR__ . "/review_columns.php";
header('Content-Type: application/json');

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'admin'){
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

ensure_review_columns($conn);

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
    $recIds = array_map(function($row){ return (int) $row['id']; }, $recommendations);
    $docsByRecommendation = fetch_recommendation_documents_map($conn, $recIds);
    $rendered = render_office_recommendation_rows($recommendations, $selectedOffice, $selectedAudit, $docsByRecommendation);
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
