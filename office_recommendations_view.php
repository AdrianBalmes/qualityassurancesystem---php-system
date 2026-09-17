<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/audit_areas.php";
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

$selectedArea = isset($_GET['area']) ? trim($_GET['area']) : '';
if($selectedArea !== '' && $selectedArea !== AUDIT_AREA_UNASSIGNED && !audit_area_is_valid($selectedOffice, $selectedArea)){
    $selectedArea = '';
}

// An office that files by area shows its areas until one is picked. Everything
// else, including the "All Offices" tab, lists recommendations directly.
if(office_has_areas($selectedOffice) && $selectedArea === ''){
    echo json_encode([
        'ok' => true,
        'office' => $selectedOffice,
        'audit' => $selectedAudit,
        'area' => '',
        'view' => 'areas',
        'title' => $selectedOffice . ' — Areas',
        'html' => render_area_cards($conn, $selectedOffice, $selectedAudit),
        'count' => 0,
    ]);
    exit();
}

$recommendations = fetch_office_recommendations($conn, $selectedOffice, $selectedAudit, $selectedArea);
$recIds = array_map(function($row){ return (int) $row['id']; }, $recommendations);
$docsByRecommendation = fetch_recommendation_documents_map($conn, $recIds);
$rendered = render_office_recommendation_rows($recommendations, $selectedOffice, $selectedAudit, $docsByRecommendation);

echo json_encode([
    'ok' => true,
    'office' => $selectedOffice,
    'audit' => $selectedAudit,
    'area' => $selectedArea,
    'view' => 'rows',
    'title' => $selectedArea !== ''
        ? $selectedOffice . ' — ' . $selectedArea
        : office_recommendations_title($selectedOffice),
    'html' => $rendered['html'],
    'count' => $rendered['count'],
]);
