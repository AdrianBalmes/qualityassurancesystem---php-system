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

$selectedProgram = isset($_GET['program']) ? trim($_GET['program']) : '';
if($selectedProgram !== '' && !audit_program_is_valid($selectedOffice, $selectedProgram)){
    $selectedProgram = '';
}

$selectedArea = isset($_GET['area']) ? trim($_GET['area']) : '';
if($selectedArea !== '' && $selectedArea !== AUDIT_AREA_UNASSIGNED
   && !audit_area_is_valid($selectedOffice, $selectedArea, $selectedProgram)){
    $selectedArea = '';
}

// Three steps for a programmed office -- programmes, then that programme's
// areas, then the recommendations. Offices without programmes skip the first.
if(office_has_programs($selectedOffice) && $selectedProgram === ''){
    echo json_encode([
        'ok' => true,
        'office' => $selectedOffice,
        'audit' => $selectedAudit,
        'program' => '',
        'area' => '',
        'view' => 'programs',
        'title' => $selectedOffice . ' — Programs',
        'html' => render_program_cards($conn, $selectedOffice, $selectedAudit),
        'count' => 0,
    ]);
    exit();
}

if(office_has_areas($selectedOffice) && $selectedArea === ''){
    echo json_encode([
        'ok' => true,
        'office' => $selectedOffice,
        'audit' => $selectedAudit,
        'program' => $selectedProgram,
        'area' => '',
        'view' => 'areas',
        'accent' => $selectedProgram !== '' ? audit_program_accent($selectedOffice, $selectedProgram) : 'blue',
        'title' => ($selectedProgram !== '' ? $selectedProgram : $selectedOffice) . ' — Areas',
        'html' => render_area_cards($conn, $selectedOffice, $selectedAudit, $selectedProgram),
        'count' => 0,
    ]);
    exit();
}

$recommendations = fetch_office_recommendations($conn, $selectedOffice, $selectedAudit, $selectedArea, $selectedProgram);
$recIds = array_map(function($row){ return (int) $row['id']; }, $recommendations);
$docsByRecommendation = fetch_recommendation_documents_map($conn, $recIds);
$rendered = render_office_recommendation_rows($recommendations, $selectedOffice, $selectedAudit, $docsByRecommendation);

echo json_encode([
    'ok' => true,
    'office' => $selectedOffice,
    'audit' => $selectedAudit,
    'program' => $selectedProgram,
    'area' => $selectedArea,
    'view' => 'rows',
    'title' => $selectedArea !== ''
        ? ($selectedProgram !== '' ? $selectedProgram : $selectedOffice) . ' — ' . $selectedArea
        : office_recommendations_title($selectedOffice),
    'html' => $rendered['html'],
    'count' => $rendered['count'],
]);
