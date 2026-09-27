<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/audit_areas.php";
require_once __DIR__ . "/recommendation_view_rows.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/in_charge.php";
require_once __DIR__ . "/asset_url.php";
require_once __DIR__ . "/review_columns.php";
require_once __DIR__ . "/nav_dropdown.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] != "admin"){
    header("Location: admin_login.php");
    exit();
}

ensure_user_account_columns($conn);
enforce_active_account($conn, 'admin');
ensure_review_columns($conn);

$siteContent = sc_load($conn);

$officeNames = get_all_office_names($conn);

$selectedAudit = isset($_GET['audit']) ? trim($_GET['audit']) : 'External';
if(!in_array($selectedAudit, ['External', 'Internal'], true)){
    $selectedAudit = 'External';
}

$officeNamesForAudit = array_values(array_filter($officeNames, function($officeName) use ($selectedAudit){
    return audit_type_for_office($officeName) === $selectedAudit;
}));

$selectedOffice = isset($_GET['office']) ? trim($_GET['office']) : '';
if(!in_array($selectedOffice, $officeNamesForAudit, true)){
    $selectedOffice = '';
}

// External has no All Offices tile, so there is no tile to represent an empty
// selection. Open the first office instead of a list nothing is pointing at.
if($selectedAudit === 'External' && $selectedOffice === '' && !empty($officeNamesForAudit)){
    $selectedOffice = $officeNamesForAudit[0];
}

$auditTotalCount = 0;
$auditPendingCount = 0;
$auditSubmittedCount = 0;
$auditNotSubmittedCount = 0;
$auditCountStmt = $conn->prepare("SELECT status, COUNT(*) as total FROM audit_recommendations WHERE audit_type = ? GROUP BY status");
$auditCountStmt->bind_param("s", $selectedAudit);
$auditCountStmt->execute();
$auditCountResult = $auditCountStmt->get_result();
while($auditCountRow = $auditCountResult->fetch_assoc()){
    $auditTotalCount += (int) $auditCountRow['total'];
    if($auditCountRow['status'] === 'Pending'){ $auditPendingCount = (int) $auditCountRow['total']; }
    if($auditCountRow['status'] === 'Submitted'){ $auditSubmittedCount = (int) $auditCountRow['total']; }
    if($auditCountRow['status'] === 'Not Submitted'){ $auditNotSubmittedCount = (int) $auditCountRow['total']; }
}

$recCountsByOffice = [];
$recByOfficeStmt = $conn->prepare("SELECT office, COUNT(*) as total FROM audit_recommendations WHERE audit_type = ? GROUP BY office");
$recByOfficeStmt->bind_param("s", $selectedAudit);
$recByOfficeStmt->execute();
$recByOfficeResult = $recByOfficeStmt->get_result();
while($recByOfficeRow = $recByOfficeResult->fetch_assoc()){
    $recCountsByOffice[$recByOfficeRow['office']] = (int) $recByOfficeRow['total'];
}
$recOfficeLabels = $officeNamesForAudit;
$recOfficeCounts = [];
foreach($officeNamesForAudit as $officeName){
    $recOfficeCounts[] = $recCountsByOffice[$officeName] ?? 0;
}

// A programmed office steps through programmes, then that programme's areas,
// then the recommendations. An office without programmes skips the first step.
$selectedProgram = isset($_GET['program']) ? trim($_GET['program']) : '';
if($selectedProgram !== '' && !audit_program_is_valid($selectedOffice, $selectedProgram)){
    $selectedProgram = '';
}

$selectedArea = isset($_GET['area']) ? trim($_GET['area']) : '';
if($selectedArea !== '' && $selectedArea !== AUDIT_AREA_UNASSIGNED
   && !audit_area_is_valid($selectedOffice, $selectedArea, $selectedProgram)){
    $selectedArea = '';
}

// The toolbar above the grid. A search reaches across an office's programmes
// and areas, so it takes the place of stepping through their cards.
$gridFilters = recommendation_filters_from_request($_GET);
$filtersActive = recommendation_filters_active($gridFilters);
$filterOptions = recommendation_filter_options($conn, $selectedAudit);

$showingPrograms = !$filtersActive && office_has_programs($selectedOffice) && $selectedProgram === '';
$showingAreas = !$filtersActive && !$showingPrograms && office_has_areas($selectedOffice) && $selectedArea === '';

// An empty $selectedOffice is the "All Offices" tab; the fetch helper returns
// every office for this audit type in that case.
$auditRecommendations = ($showingPrograms || $showingAreas)
    ? []
    : fetch_office_recommendations($conn, $selectedOffice, $selectedAudit, $selectedArea, $selectedProgram, $gridFilters);

if($showingPrograms){
    $auditGridTitle = $selectedOffice . ' — Programs';
} elseif($showingAreas){
    $auditGridTitle = ($selectedProgram !== '' ? $selectedProgram : $selectedOffice) . ' — Areas';
} elseif($selectedArea !== ''){
    $auditGridTitle = ($selectedProgram !== '' ? $selectedProgram : $selectedOffice) . ' — ' . $selectedArea;
} else {
    $auditGridTitle = office_recommendations_title($selectedOffice);
}

// Back steps one level: from an area to its programme's areas, from a
// programme's areas to the programmes.
$showBackButton = $selectedArea !== '' || $selectedProgram !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/sbc-logo.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SBC QA Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="<?php echo asset_url('assets/in_charge.css'); ?>" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1680px;margin:auto;min-height:74px;padding:0 clamp(14px,2vw,32px);display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}.brand-icon{width:64px;height:64px;display:grid;place-items:center;flex-shrink:0}.nav-links{display:flex;gap:20px;flex-wrap:wrap;align-items:center}.nav-links a,.nav-links button{color:#eef4ff;text-decoration:none;font-weight:700;background:none;border:0;padding:0;cursor:pointer;font-size:inherit;font-family:inherit;display:inline-flex;align-items:center;gap:6px}.dashboard{max-width:1680px;margin:26px auto 42px;padding:0 clamp(14px,2vw,32px)}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:16px}.panel-title{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:17px;font-weight:800}.muted-copy{color:#66758d;font-size:13px}.stat-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;padding:12px 16px;margin-bottom:14px}.stat-box{min-height:46px;border-radius:7px;display:flex;align-items:center;justify-content:center;gap:7px;padding:10px 12px;font-weight:800}.stat-box strong{font-size:20px}.stat-green{background:#caefdd}.stat-yellow{background:#fff0ba}.stat-red{background:#ffd0c9}.stat-steel{background:#d8e2f5}.chart-box{height:210px}.empty-state{padding:14px;text-align:center;color:#66758d;font-weight:700}.audit-panel{margin-bottom:14px}.audit-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px}.audit-switcher{display:flex;gap:8px;flex-wrap:wrap}.audit-switch{min-height:34px;border-radius:5px;background:#fff;border:1px solid #c8d4e7;color:#2e67b8;text-decoration:none;font-size:13px;font-weight:800;padding:7px 12px;display:inline-flex;align-items:center}.audit-switch.active{background:#316fc4;border-color:#316fc4;color:#fff}.section-heading{margin:18px 4px 8px;font-size:19px;font-weight:800;color:#344156}.office-tabs{display:grid;grid-template-columns:36px minmax(0,1fr) 36px;gap:8px;align-items:stretch;background:#e8eef8;border:1px solid #dbe3ef;padding:10px;border-radius:6px}.office-scroll{display:flex;gap:12px;overflow-x:auto;scroll-behavior:smooth;scrollbar-width:thin;-webkit-overflow-scrolling:touch}.office-slide-btn{width:36px;border:1px solid #c8d4e7;border-radius:6px;background:#fff;color:#316fc4;display:grid;place-items:center;flex-shrink:0}.office-tile{flex:0 0 104px;min-height:82px;display:grid;justify-items:center;align-content:center;gap:7px;font-weight:800;font-size:13px;text-decoration:none;color:#344156;border:2px solid transparent;border-radius:6px;padding:6px;text-align:center}.office-tile:hover,.office-tile.active{border-color:#316fc4;background:#f7fbff}.office-icon{width:70px;height:54px;border-radius:6px;background:#fff;color:#3971c1;display:grid;place-items:center;font-size:29px}.area-cell{padding:18px!important;background:#f8fbff}.grid-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;padding:10px 12px;background:#f1f5fb;border:1px solid #dbe3ef;border-radius:6px}.grid-filter-search{flex:1 1 240px;min-width:170px;border:1px solid #c8d4e7;border-radius:5px;padding:8px 10px;font-size:13px;font-weight:600;background:#fff;color:#344156}.grid-filter-select{border:1px solid #c8d4e7;border-radius:5px;padding:8px 10px;font-size:12.5px;font-weight:700;background:#fff;color:#344156;max-width:210px}.grid-filter-search:focus,.grid-filter-select:focus{outline:none;border-color:#316fc4;box-shadow:0 0 0 2px rgba(49,111,196,.15)}.grid-filter-clear{border:1px solid #c8d4e7;border-radius:5px;background:#fff;color:#2e67b8;font-weight:800;font-size:12.5px;padding:8px 12px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}.grid-filter-clear:hover{background:#eef4ff;border-color:#316fc4}.grid-filter-count{font-size:12.5px;font-weight:800;color:#66758d;margin-left:auto}@media(max-width:620px){.grid-filter-search,.grid-filter-select{flex:1 1 100%;max-width:none}.grid-filter-count{margin-left:0}}
.area-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.area-card{text-align:left;border:1px solid #dbe3ef;background:#fff;border-radius:8px;padding:14px 15px;cursor:pointer;display:flex;flex-direction:column;gap:7px;min-height:92px;justify-content:space-between;transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease}
.area-card:hover{border-color:#316fc4;box-shadow:0 6px 18px rgba(44,74,119,.13);transform:translateY(-1px)}
.area-name{font-size:14px;font-weight:800;color:#26354b;line-height:1.3}
.area-tally{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.area-total{font-size:11.5px;font-weight:800;color:#2e5fa3;background:#e6eefb;border-radius:999px;padding:3px 9px}
.area-pending{font-size:11.5px;font-weight:800;color:#806119;background:#fff0ba;border-radius:999px;padding:3px 9px}
.area-empty{font-size:12px;font-weight:700;color:#9aa8bf}
.area-card-unassigned{border-style:dashed}
/* Programme cards: one row of folders, each in its programme's colour. */
.prog-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(272px,1fr));gap:14px}
.prog-card{display:flex;align-items:center;gap:14px;text-align:left;border:1px solid #dbe3ef;background:#fff;border-radius:9px;padding:16px 16px;cursor:pointer;transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease}
.prog-card:hover{box-shadow:0 8px 22px rgba(44,74,119,.15);transform:translateY(-1px)}
.prog-icon{width:46px;height:46px;border-radius:10px;flex-shrink:0;display:grid;place-items:center;font-size:21px;color:#fff}
.prog-body{display:flex;flex-direction:column;gap:6px;min-width:0;flex:1}
.prog-name{font-size:14.5px;font-weight:800;color:#26354b;line-height:1.3}
.prog-areas{font-size:11.5px;font-weight:800;border-radius:999px;padding:3px 9px}
.prog-go{margin-left:auto;color:#a9bad6;font-size:15px;flex-shrink:0}
/* Blue is the dashboard's existing accent; pink and purple mark the two
   programmes that asked for their own. */
.prog-blue .prog-icon{background:linear-gradient(135deg,#3b7ad4,#2459a6)}
.prog-blue .prog-areas{background:#e6eefb;color:#2e5fa3}
.prog-blue:hover{border-color:#316fc4}
.prog-pink .prog-icon{background:linear-gradient(135deg,#ef7ba8,#d9457f)}
.prog-pink .prog-areas{background:#fde7f0;color:#b93a6e}
.prog-pink:hover{border-color:#d9457f}
.prog-purple .prog-icon{background:linear-gradient(135deg,#9b7ae0,#6f42c1)}
.prog-purple .prog-areas{background:#efe9fb;color:#5b3fa0}
.prog-purple:hover{border-color:#6f42c1}
.prog-steel .prog-icon{background:linear-gradient(135deg,#8fa3c0,#5b7091)}
.prog-steel .prog-areas{background:#e4e9f1;color:#4c5a72}
/* Area cards inherit the colour of the programme they belong to. */
.area-pink{border-left:3px solid #d9457f}
.area-pink:hover{border-color:#d9457f;border-left-color:#d9457f}
.area-pink .area-total{background:#fde7f0;color:#b93a6e}
.area-purple{border-left:3px solid #6f42c1}
.area-purple:hover{border-color:#6f42c1;border-left-color:#6f42c1}
.area-purple .area-total{background:#efe9fb;color:#5b3fa0}
.area-blue{border-left:3px solid #316fc4}
@media(prefers-reduced-motion:reduce){.prog-card{transition:none}.prog-card:hover{transform:none}}
.back-areas-btn{min-height:32px;border:1px solid #c8d4e7;border-radius:5px;background:#fff;color:#2e67b8;font-weight:800;font-size:12.5px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px;cursor:pointer}
.back-areas-btn:hover{background:#eef4ff}
@media(prefers-reduced-motion:reduce){.area-card{transition:none}.area-card:hover{transform:none}}
.document-panel{margin-top:12px}img,canvas,svg{max-width:100%}.dashboard,.page,.nav-wrap{width:100%}.add-row-btn{min-height:38px;border:0;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;gap:8px;padding:8px 16px}.grid-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px}.grid-wrap{overflow-x:auto;border:1px solid #dbe3ef;border-radius:6px;-webkit-overflow-scrolling:touch}.grid-table{width:100%;border-collapse:collapse;min-width:1100px}.grid-table th{background:#f1f5fb;color:#56637a;font-size:13px;text-align:left;padding:10px;border-bottom:2px solid #dbe3ef;position:sticky;top:0}.grid-table td{border-bottom:1px solid #e7edf6;border-right:1px solid #eef2f8;padding:0;vertical-align:top}.cell-text{min-width:160px;padding:8px 10px;font-size:13px;font-weight:600;color:#344156;outline:none;min-height:38px;word-break:break-word;overflow-wrap:anywhere}.cell-text.grid-rec-cell{min-width:320px}.cell-text:focus{background:#eef4ff;box-shadow:inset 0 0 0 2px #316fc4}.cell-select{width:100%;height:100%;border:0;background:transparent;padding:8px 10px;font-size:13px;font-weight:700}.cell-select:focus{background:#eef4ff;outline:none}.cell-year{width:100%;border:0;background:transparent;padding:8px 10px;font-size:13px;font-weight:700}.cell-year:focus{background:#eef4ff;outline:none}.cell-select:disabled,.cell-year:disabled{opacity:1;color:#344156;background:transparent;border:0;-webkit-text-fill-color:#344156}tr[data-mode="view"] .incharge-add-row,tr[data-mode="view"] .incharge-remove{display:none}tr[data-mode="view"] .cell-year::placeholder{color:transparent}.remarks-grouped{display:flex;flex-direction:column;gap:8px;padding:8px 10px}.remarks-office-block{border-left:3px solid #dbe3ef;padding-left:8px}.remarks-office-label{display:block;font-size:11px;font-weight:800;text-transform:uppercase;color:#66758d;margin-bottom:2px}.remarks-office-text{font-size:13px;font-weight:600;color:#344156;word-break:break-word}.cell-readonly{padding:8px 10px;font-size:13px;font-weight:700;color:#344156}.row-actions{display:flex;gap:6px;padding:4px;align-items:center}.row-edit-btn,.row-save-btn,.row-delete,.row-review-btn{border:0;border-radius:5px;font-weight:800;font-size:12px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}.row-edit-btn{background:#eef4ff;color:#2e67b8;padding:6px 10px}.row-save-btn{background:#277548;color:#fff;padding:6px 10px;display:none}.row-review-btn{background:#efe9fb;color:#5b3fa0;padding:6px 10px}.row-review-btn:hover{background:#e2d8f7}.row-delete{background:#ffe1dc;color:#a33831;width:32px;height:32px;justify-content:center}.status-chip{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:11.5px;font-weight:800}.chip-blue{background:#d8e2f5;color:#2e5fa3}.chip-green{background:#cdeedc;color:#277548}.chip-yellow{background:#fff0ba;color:#806119}.chip-red{background:#ffd6d0;color:#a33831}.chip-steel{background:#e4e9f1;color:#4c5a72}.chip-orange{background:#ffe3c2;color:#95530a}.status-select{height:auto;width:calc(100% - 16px);margin:7px 8px;padding:6px 9px;border-radius:999px;font-size:12px;font-weight:800;border:1px solid rgba(15,26,42,.08)}.status-select option{background:#fff;color:#344156}.status-select.chip-steel,.status-select.chip-steel:disabled{background:#e4e9f1;color:#4c5a72;-webkit-text-fill-color:#4c5a72}.status-select.chip-red,.status-select.chip-red:disabled{background:#ffd6d0;color:#a33831;-webkit-text-fill-color:#a33831}.status-select.chip-yellow,.status-select.chip-yellow:disabled{background:#fff0ba;color:#806119;-webkit-text-fill-color:#806119}.status-select.chip-blue,.status-select.chip-blue:disabled{background:#d8e2f5;color:#2e5fa3;-webkit-text-fill-color:#2e5fa3}.status-select.chip-orange,.status-select.chip-orange:disabled{background:#ffe3c2;color:#95530a;-webkit-text-fill-color:#95530a}.status-select.chip-green,.status-select.chip-green:disabled{background:#cdeedc;color:#277548;-webkit-text-fill-color:#277548}tr[data-mode="edit"] .status-select{border-color:#316fc4}.doc-pill-list{display:flex;flex-direction:column;gap:8px}.doc-sidebar-item{display:flex;align-items:center;gap:8px}.doc-pill{font-size:13px;font-weight:700;color:#2e67b8;text-decoration:none;word-break:break-word;display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #dbe3ef;border-radius:6px;flex:1;min-width:0}.doc-pill:hover{background:#f7fbff}.doc-delete-btn{border:0;border-radius:6px;background:#ffe1dc;color:#a33831;width:32px;height:32px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.doc-delete-btn:hover{background:#ffcac1}.doc-trigger-btn{margin:6px 10px;border:1px solid #c8d4e7;border-radius:5px;background:#eef4ff;color:#2e67b8;font-weight:800;font-size:12.5px;padding:6px 10px;display:inline-flex;align-items:center;gap:7px;cursor:pointer}.doc-trigger-btn:hover{background:#dfeaff}.doc-count-badge{background:#316fc4;color:#fff;border-radius:999px;min-width:18px;height:18px;padding:0 5px;font-size:11px;display:inline-flex;align-items:center;justify-content:center}.doc-sidebar-backdrop{position:fixed;inset:0;background:rgba(15,26,42,.4);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:1000}.doc-sidebar-backdrop.is-open{opacity:1;pointer-events:auto}.doc-sidebar{position:fixed;top:0;right:0;height:100vh;width:min(360px,92vw);background:#fff;box-shadow:-8px 0 24px rgba(15,26,42,.18);transform:translateX(100%);transition:transform .25s ease;z-index:1001;display:flex;flex-direction:column}.doc-sidebar.is-open{transform:translateX(0)}.doc-sidebar-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #e6edf7}.doc-sidebar-head h3{margin:0;font-size:16px;font-weight:800;color:#26354b}.doc-sidebar-close{border:0;background:#eef4ff;color:#2e67b8;width:30px;height:30px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.doc-sidebar-close:hover{background:#dfeaff}.doc-sidebar-rec{padding:12px 18px;border-bottom:1px solid #e6edf7;font-size:13px;font-weight:700;color:#344156;background:#f8fbff}.doc-sidebar-body{padding:14px 18px;overflow-y:auto;flex:1}.doc-office-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;border-bottom:1px solid #e6edf7;padding-bottom:10px}.doc-office-tab{border:1px solid #dbe3ef;background:#fff;color:#56637a;font-weight:800;font-size:12px;padding:6px 12px;border-radius:999px;cursor:pointer}.doc-office-tab:hover{background:#f7fbff}.doc-office-tab.active{background:#316fc4;border-color:#316fc4;color:#fff}.review-modal-backdrop{position:fixed;inset:0;background:rgba(15,26,42,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:1100;display:flex;align-items:center;justify-content:center;padding:20px}.review-modal-backdrop.is-open{opacity:1;pointer-events:auto}.review-modal{background:#fff;border-radius:10px;box-shadow:0 20px 50px rgba(15,26,42,.3);width:min(560px,100%);max-height:90vh;display:flex;flex-direction:column;transform:translateY(16px);transition:transform .2s ease}.review-modal-backdrop.is-open .review-modal{transform:translateY(0)}.review-modal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e6edf7}.review-modal-head h3{margin:0;font-size:16px;font-weight:800;color:#26354b;display:flex;align-items:center;gap:8px}.review-modal-body{padding:18px 20px;overflow-y:auto;display:grid;gap:14px}.review-modal-footer{padding:14px 20px;border-top:1px solid #e6edf7;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.review-field-label{font-size:11.5px;font-weight:800;color:#66758d;text-transform:uppercase;margin-bottom:4px;display:block}.review-readonly-block{background:#f8fbff;border:1px solid #e6edf7;border-radius:6px;padding:10px 12px;font-size:13.5px;color:#344156;white-space:pre-wrap;word-break:break-word}.review-textarea{width:100%;min-height:90px;border:1px solid #cfd9e8;border-radius:6px;padding:10px;font-size:13.5px;font-family:inherit;resize:vertical}.review-btn{border:0;border-radius:5px;font-weight:800;font-size:12.5px;padding:9px 14px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}.review-btn-approve{background:#2fa66a;color:#fff}.review-btn-reject{background:#c23b36;color:#fff}.review-btn-revision{background:#e0a51d;color:#fff}.review-btn-completed{background:#316fc4;color:#fff}.review-btn-remarks{background:#eef4ff;color:#2e67b8}.review-btn-cancel{background:#f1f3f7;color:#56637a}.review-btn:disabled{opacity:.45;cursor:not-allowed}.review-doc-option{display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #dbe3ef;border-radius:6px;cursor:pointer;margin:0}.review-doc-option.is-selected{border-color:#316fc4;background:#f3f8ff}.review-doc-option .doc-pill{border:0;padding:2px 0;flex:1;min-width:0}.review-doc-meta{display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:#66758d;flex-shrink:0}tr[data-mode="edit"] .row-edit-btn{display:none}tr[data-mode="edit"] .row-save-btn{display:inline-flex}tr[data-mode="edit"] .cell-text{background:#eef4ff;box-shadow:inset 0 0 0 2px #316fc4}@media(max-width:1060px){.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 18px}}@media(max-width:760px){.brand{font-size:17px}.chart-box{height:230px}.office-tabs{grid-template-columns:32px minmax(0,1fr) 32px}.office-tile{flex-basis:94px}.audit-head{align-items:flex-start}.audit-switcher{width:100%}.audit-switch{flex:1 1 auto;justify-content:center;text-align:center}}@media(max-width:480px){.dashboard{padding:0 10px;margin:16px auto 28px}.panel-pad{padding:12px}.panel-title{font-size:15px}.page-title{font-size:20px}.stat-box{font-size:12px;padding:8px 10px}.stat-box strong{font-size:17px}.brand{font-size:15px;gap:8px}.brand-icon{width:48px;height:48px}.nav-links{gap:12px;font-size:13px}.office-tile{flex-basis:80px;min-height:72px}.office-icon{width:56px;height:44px;font-size:22px}.section-heading{font-size:16px}}
</style>
</head>
<body>
<?php render_page_background(); ?>
<header class="topbar"><div class="nav-wrap"><div class="brand"><span class="brand-icon"><img src="assets/sbc-logo.png" alt="St. Bridget College" style="width:100%;height:100%;object-fit:contain"></span><?php sc_span($siteContent, 'home.brand', 'SBC Quality Assurance Electronic Documentation Dashboard'); ?></div><nav class="nav-links"><a href="home.php">Home</a><a href="repository.php">Repository</a><a href="activity_log.php">Activity Log</a><a href="manage_users.php">Users</a><a href="manage_offices.php">Offices</a><?php render_profile_dropdown('admin_profile.php', 'Admin Profile'); ?></nav></div></header>
<main class="dashboard">
<section class="panel panel-pad" id="office-rec-chart" style="margin-bottom:18px">
    <h3 style="margin:0 0 8px;font-size:15px;font-weight:800;color:#344156"><?php sc_span($siteContent, 'home.audit.chart_title', 'Recommendations Submitted by Offices'); ?></h3>
    <div class="chart-box"><canvas id="officeRecChart"></canvas></div>
</section>
<h2 class="section-heading"><?php sc_span($siteContent, 'home.office.section_title', 'Manage Recommendations by Office'); ?></h2>
<section class="panel panel-pad" id="office-recommendations">
    <h2 class="panel-title"><?php sc_span($siteContent, 'home.office.panel_title', 'All Offices'); ?></h2>
    <div class="office-tabs">
        <button type="button" class="office-slide-btn" data-slide-office="-1" aria-label="Slide offices left"><i class="bi bi-chevron-left"></i></button>
        <div class="office-scroll" id="officeTabs">
            <?php /* External audit has only two offices, each opening its own
                     areas, so an All Offices tile mixing them adds nothing. */ ?>
            <?php if($selectedAudit !== 'External'): ?>
            <a class="office-tile<?php echo $selectedOffice === '' ? ' active' : ''; ?>" data-office="" href="home.php?audit=<?php echo urlencode($selectedAudit); ?>#audit-recommendations"><div class="office-icon"><i class="bi bi-grid-fill"></i></div><span>All Offices</span></a>
            <?php endif; ?>
            <?php foreach($officeNamesForAudit as $officeName):
                $officeLabel = htmlspecialchars($officeName, ENT_QUOTES);
                $officeUrl = urlencode($officeName);
                $active = $selectedOffice === $officeName ? ' active' : '';
            ?>
            <a class="office-tile<?php echo $active; ?>" data-office="<?php echo $officeLabel; ?>" href="home.php?audit=<?php echo urlencode($selectedAudit); ?>&office=<?php echo $officeUrl; ?>#audit-recommendations"><div class="office-icon"><i class="bi bi-folder-fill"></i></div><span><?php echo $officeLabel; ?></span></a>
            <?php endforeach; ?>
        </div>
        <button type="button" class="office-slide-btn" data-slide-office="1" aria-label="Slide offices right"><i class="bi bi-chevron-right"></i></button>
    </div>
</section>
<section class="panel panel-pad audit-panel" id="audit-recommendations">
    <div class="audit-head">
        <div>
            <h2 class="panel-title" style="border-bottom:0;margin-bottom:2px;padding-bottom:0"><?php sc_span($siteContent, 'home.audit.title', 'Audit Recommendations Dashboard'); ?></h2>
            <div class="muted-copy"><?php sc_span($siteContent, 'home.audit.subtitle', 'Pick a dashboard to review recommendations by audit type.'); ?></div>
        </div>
        <div class="audit-switcher" aria-label="Pick dashboard">
            <a class="audit-switch<?php echo $selectedAudit === 'External' ? ' active' : ''; ?>" href="home.php?audit=External#audit-recommendations">External Audit</a>
            <a class="audit-switch<?php echo $selectedAudit === 'Internal' ? ' active' : ''; ?>" href="home.php?audit=Internal#audit-recommendations">Internal Audit</a>
        </div>
    </div>
    <div class="stat-strip" style="padding:0;margin-bottom:14px">
        <div class="stat-box stat-steel">Total <?php echo htmlspecialchars($selectedAudit, ENT_QUOTES); ?>: <strong><?php echo $auditTotalCount; ?></strong></div>
        <div class="stat-box stat-yellow">Pending: <strong><?php echo $auditPendingCount; ?></strong></div>
        <div class="stat-box stat-green">Submitted: <strong><?php echo $auditSubmittedCount; ?></strong></div>
        <div class="stat-box stat-red">Not Submitted: <strong><?php echo $auditNotSubmittedCount; ?></strong></div>
    </div>
    <div class="grid-head">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <button type="button" id="backToAreasBtn" class="back-areas-btn" <?php echo $showBackButton ? '' : 'style="display:none"'; ?>><i class="bi bi-arrow-left"></i> Back</button>
            <h3 class="panel-title" id="auditRecTitle" style="font-size:15px;border-bottom:0;padding-bottom:0;margin-bottom:0"><?php echo htmlspecialchars($auditGridTitle, ENT_QUOTES); ?></h3>
        </div>
        <button type="button" id="addRowBtn" class="add-row-btn" <?php echo ($selectedOffice === '' || $showingAreas || $showingPrograms || $filtersActive) ? 'style="display:none"' : ''; ?>><i class="bi bi-plus-lg"></i> Add Row</button>
    </div>
    <div class="grid-filters">
        <input type="search" id="filterSearch" class="grid-filter-search" placeholder="Search recommendations&hellip;" value="<?php echo htmlspecialchars($gridFilters['search'], ENT_QUOTES); ?>">
        <select id="filterInCharge" class="grid-filter-select" aria-label="Filter by who is in charge">
            <option value="">Anyone in charge</option>
            <?php if($filterOptions['unassigned']): ?>
            <option value="<?php echo RECOMMENDATION_FILTER_NONE; ?>"<?php echo $gridFilters['in_charge'] === RECOMMENDATION_FILTER_NONE ? ' selected' : ''; ?>>Not yet assigned</option>
            <?php endif; ?>
            <?php foreach($filterOptions['in_charge'] as $inChargeName): ?>
            <option value="<?php echo htmlspecialchars($inChargeName, ENT_QUOTES); ?>"<?php echo $gridFilters['in_charge'] === $inChargeName ? ' selected' : ''; ?>><?php echo htmlspecialchars($inChargeName, ENT_QUOTES); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterStatus" class="grid-filter-select" aria-label="Filter by status of submission">
            <option value="">Any status</option>
            <?php foreach(['Pending', 'Submitted', 'Not Submitted', 'Approved', 'Needs Revision', 'Rejected', 'Completed'] as $statusChoice): ?>
            <option value="<?php echo htmlspecialchars($statusChoice, ENT_QUOTES); ?>"<?php echo $gridFilters['status'] === $statusChoice ? ' selected' : ''; ?>><?php echo htmlspecialchars($statusChoice, ENT_QUOTES); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterYear" class="grid-filter-select" aria-label="Filter by year">
            <option value="">Any year</option>
            <?php if($filterOptions['blank_year']): ?>
            <option value="<?php echo RECOMMENDATION_FILTER_NONE; ?>"<?php echo $gridFilters['year'] === RECOMMENDATION_FILTER_NONE ? ' selected' : ''; ?>>No year set</option>
            <?php endif; ?>
            <?php foreach($filterOptions['years'] as $yearChoice): ?>
            <option value="<?php echo htmlspecialchars($yearChoice, ENT_QUOTES); ?>"<?php echo $gridFilters['year'] === $yearChoice ? ' selected' : ''; ?>><?php echo htmlspecialchars($yearChoice, ENT_QUOTES); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" id="filterClear" class="grid-filter-clear"><i class="bi bi-x-lg"></i> Clear</button>
        <span class="grid-filter-count" id="gridCount"><?php
            if($filtersActive){
                $matchCount = count($auditRecommendations);
                echo $matchCount . ($matchCount === 1 ? ' match' : ' matches');
            }
        ?></span>
    </div>
    <div class="grid-wrap">
        <table class="grid-table" id="recGrid">
            <thead>
                <tr id="recGridHeadRow">
                    <?php if($selectedOffice === '' || $selectedAudit !== 'External'): ?>
                    <th style="width:160px">Office</th>
                    <?php endif; ?>
                    <th>Recommendation</th>
                    <th style="width:190px">In Charge</th>
                    <th style="width:140px">Status of Submission</th>
                    <th>Remarks</th>
                    <th style="width:150px">Submitted Documents</th>
                    <th style="width:120px">Year</th>
                    <th style="width:190px">Actions</th>
                </tr>
            </thead>
            <tbody id="auditRecTbody">
                <?php
                if($showingPrograms){
                    echo render_program_cards($conn, $selectedOffice, $selectedAudit);
                } elseif($showingAreas){
                    echo render_area_cards($conn, $selectedOffice, $selectedAudit, $selectedProgram);
                } else {
                    $auditRecIds = array_map(function($row){ return (int) $row['id']; }, $auditRecommendations);
                    $auditDocsByRecommendation = fetch_recommendation_documents_map($conn, $auditRecIds);
                    $auditRecRendered = render_office_recommendation_rows($auditRecommendations, $selectedOffice, $selectedAudit, $auditDocsByRecommendation, $filtersActive);
                    echo $auditRecRendered['html'];
                }
                ?>
            </tbody>
        </table>
    </div>
</section>
</main>
<div class="doc-sidebar-backdrop" id="docSidebarBackdrop"></div>
<aside class="doc-sidebar" id="docSidebar" aria-hidden="true">
    <div class="doc-sidebar-head">
        <h3><i class="bi bi-folder2-open"></i> Submitted Documents</h3>
        <button type="button" class="doc-sidebar-close" id="docSidebarClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="doc-sidebar-rec" id="docSidebarRecText"></div>
    <div class="doc-sidebar-body" id="docSidebarBody"></div>
</aside>
<div class="review-modal-backdrop" id="reviewModalBackdrop">
    <div class="review-modal" role="dialog" aria-modal="true" aria-labelledby="reviewModalTitle">
        <div class="review-modal-head">
            <h3 id="reviewModalTitle"><i class="bi bi-clipboard-check"></i> Review Submission</h3>
            <button type="button" class="doc-sidebar-close" id="reviewModalClose" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="review-modal-body">
            <div>
                <span class="review-field-label">Office</span>
                <div id="reviewOfficeText" style="font-weight:800"></div>
            </div>
            <div>
                <span class="review-field-label">Recommendation</span>
                <div class="review-readonly-block" id="reviewRecText"></div>
            </div>
            <div>
                <span class="review-field-label">Current Status</span>
                <span class="status-chip" id="reviewStatusBadge"></span>
            </div>
            <div>
                <span class="review-field-label">Office's Compliance Remarks</span>
                <div class="review-readonly-block" id="reviewOfficeRemarks"></div>
            </div>
            <div>
                <span class="review-field-label" id="reviewDocsLabel">Submitted Documents</span>
                <div id="reviewDocsList"></div>
            </div>
            <div>
                <label class="review-field-label" for="reviewRemarksInput">Review Remarks (visible to the office)</label>
                <textarea class="review-textarea" id="reviewRemarksInput" placeholder="Add feedback for the office..."></textarea>
            </div>
        </div>
        <div class="review-modal-footer">
            <button type="button" class="review-btn review-btn-cancel" id="reviewModalCancel">Cancel</button>
            <button type="button" class="review-btn review-btn-remarks" data-review-action="remarks_only"><i class="bi bi-chat-left-text"></i> Save Remarks</button>
            <button type="button" class="review-btn review-btn-revision" data-review-action="needs_revision"><i class="bi bi-arrow-repeat"></i> Needs Revision</button>
            <button type="button" class="review-btn review-btn-reject" data-review-action="reject"><i class="bi bi-x-circle"></i> Reject</button>
            <button type="button" class="review-btn review-btn-approve" data-review-action="approve"><i class="bi bi-check-circle"></i> Approve</button>
            <button type="button" class="review-btn review-btn-completed" data-review-action="completed"><i class="bi bi-flag-fill"></i> Mark Completed</button>
        </div>
    </div>
</div>
<script src="<?php echo asset_url('assets/in_charge.js'); ?>"></script>
<script>
InCharge.configure(<?php echo json_encode(in_charge_options($conn)); ?>);

// The status box carries the colour of the status it is showing, from the
// same table the office's own chips and the review modal read.
var STATUS_CHIPS = <?php echo json_encode(review_status_chip_map()); ?>;
function paintStatusSelect(select){
    if(!select){ return; }
    var chip = (STATUS_CHIPS[select.value] || {})['class'] || 'chip-steel';
    select.className = 'cell-select status-select ' + chip;
}
new Chart(document.getElementById('officeRecChart'),{type:'bar',data:{labels:<?php echo json_encode($recOfficeLabels); ?>,datasets:[{label:'Recommendations Submitted',data:<?php echo json_encode($recOfficeCounts); ?>,backgroundColor:'#316fc4'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
document.querySelectorAll('[data-slide-office]').forEach(function(button){button.addEventListener('click',function(){var tabs=document.getElementById('officeTabs');if(!tabs){return;}var direction=parseInt(button.getAttribute('data-slide-office'),10)||1;tabs.scrollBy({left:direction*260,behavior:'smooth'});});});
(function(){
    var sidebar = document.getElementById('docSidebar');
    var backdrop = document.getElementById('docSidebarBackdrop');
    var closeBtn = document.getElementById('docSidebarClose');
    var recTextEl = document.getElementById('docSidebarRecText');
    var bodyEl = document.getElementById('docSidebarBody');
    if(!sidebar || !backdrop || !bodyEl){ return; }

    var currentTrigger = null;

    function buildDocPillList(docs){
        var list = document.createElement('div');
        list.className = 'doc-pill-list';
        docs.forEach(function(doc){
            var item = document.createElement('div');
            item.className = 'doc-sidebar-item';

            var a = document.createElement('a');
            a.href = doc.url;
            a.target = '_blank';
            a.rel = 'noopener';
            a.className = 'doc-pill';
            var icon = document.createElement('i');
            icon.className = 'bi bi-paperclip';
            a.appendChild(icon);
            a.appendChild(document.createTextNode(doc.label || ''));
            item.appendChild(a);

            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'doc-delete-btn';
            delBtn.setAttribute('data-doc-id', doc.id);
            delBtn.title = 'Delete this file';
            delBtn.innerHTML = "<i class='bi bi-trash3'></i>";
            item.appendChild(delBtn);

            list.appendChild(item);
        });
        return list;
    }

    function renderDocs(docs, owningOffice){
        bodyEl.innerHTML = '';
        if(!docs.length){
            var empty = document.createElement('div');
            empty.className = 'muted-copy';
            empty.textContent = 'No documents submitted.';
            bodyEl.appendChild(empty);
            return;
        }

        var distinctOffices = [];
        docs.forEach(function(doc){
            var off = doc.office || '';
            if(off !== '' && distinctOffices.indexOf(off) === -1){ distinctOffices.push(off); }
        });

        // Grouping by submitting office only matters for College Department:
        // that is the office whose recommendations can be passed to others via
        // In Charge, so its documents can arrive from more than one office.
        if(owningOffice === 'College Department' && distinctOffices.length > 1){
            var tabs = document.createElement('div');
            tabs.className = 'doc-office-tabs';
            var listWrap = document.createElement('div');

            function showOffice(off){
                tabs.querySelectorAll('.doc-office-tab').forEach(function(btn){
                    btn.classList.toggle('active', btn.getAttribute('data-doc-office') === off);
                });
                listWrap.innerHTML = '';
                var filtered = off === '' ? docs : docs.filter(function(doc){ return (doc.office || '') === off; });
                listWrap.appendChild(buildDocPillList(filtered));
            }

            var allBtn = document.createElement('button');
            allBtn.type = 'button';
            allBtn.className = 'doc-office-tab active';
            allBtn.setAttribute('data-doc-office', '');
            allBtn.textContent = 'All';
            tabs.appendChild(allBtn);

            distinctOffices.forEach(function(off){
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'doc-office-tab';
                btn.setAttribute('data-doc-office', off);
                btn.textContent = off;
                tabs.appendChild(btn);
            });

            tabs.addEventListener('click', function(e){
                var btn = e.target.closest('.doc-office-tab');
                if(!btn){ return; }
                showOffice(btn.getAttribute('data-doc-office'));
            });

            bodyEl.appendChild(tabs);
            bodyEl.appendChild(listWrap);
            showOffice('');
            return;
        }

        bodyEl.appendChild(buildDocPillList(docs));
    }

    function openSidebar(trigger, recText, docs, owningOffice){
        currentTrigger = trigger;
        recTextEl.textContent = recText;
        renderDocs(docs, owningOffice);
        sidebar.classList.add('is-open');
        backdrop.classList.add('is-open');
        sidebar.setAttribute('aria-hidden', 'false');
    }

    function closeSidebar(){
        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        sidebar.setAttribute('aria-hidden', 'true');
        currentTrigger = null;
    }

    document.addEventListener('click', function(e){
        var trigger = e.target.closest('[data-doc-trigger]');
        if(trigger){
            var docs = [];
            try { docs = JSON.parse(trigger.getAttribute('data-docs') || '[]'); } catch(err){ docs = []; }
            openSidebar(trigger, trigger.getAttribute('data-rec-text') || '', docs, trigger.getAttribute('data-office') || '');
            return;
        }

        var deleteBtn = e.target.closest('.doc-delete-btn');
        if(deleteBtn && bodyEl.contains(deleteBtn)){
            var docId = deleteBtn.getAttribute('data-doc-id');
            if(!confirm('Delete this submitted document? This cannot be undone.')){ return; }
            fetch('recommendations_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_document&doc_id=' + encodeURIComponent(docId)
            }).then(function(res){ return res.json(); }).then(function(data){
                if(!data.ok){ alert(data.error || 'Could not delete this document.'); return; }
                if(!currentTrigger){ return; }
                var docs = [];
                try { docs = JSON.parse(currentTrigger.getAttribute('data-docs') || '[]'); } catch(err){ docs = []; }
                docs = docs.filter(function(doc){ return String(doc.id) !== String(docId); });
                currentTrigger.setAttribute('data-docs', JSON.stringify(docs));
                renderDocs(docs, currentTrigger.getAttribute('data-office') || '');

                if(docs.length === 0){
                    var cell = currentTrigger.closest('td');
                    if(cell){
                        cell.innerHTML = "<span class='muted-copy'>No document submitted</span>";
                    }
                } else {
                    var badge = currentTrigger.querySelector('.doc-count-badge');
                    if(badge){ badge.textContent = docs.length; }
                }
            }).catch(function(){
                alert('Could not delete this document. Please try again.');
            });
        }
    });

    if(closeBtn){ closeBtn.addEventListener('click', closeSidebar); }
    backdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ closeSidebar(); } });
})();
(function(){
    var backdrop = document.getElementById('reviewModalBackdrop');
    var closeBtn = document.getElementById('reviewModalClose');
    var cancelBtn = document.getElementById('reviewModalCancel');
    var officeText = document.getElementById('reviewOfficeText');
    var recText = document.getElementById('reviewRecText');
    var statusBadge = document.getElementById('reviewStatusBadge');
    var officeRemarksEl = document.getElementById('reviewOfficeRemarks');
    var docsListEl = document.getElementById('reviewDocsList');
    var remarksInput = document.getElementById('reviewRemarksInput');
    if(!backdrop){ return; }

    var currentTrigger = null;

    var statusChipMap = {
        'Pending': ['Pending', 'chip-steel'],
        'Not Submitted': ['Not Submitted', 'chip-red'],
        'Submitted': ['Awaiting Review', 'chip-yellow'],
        'Approved': ['Approved', 'chip-blue'],
        'Needs Revision': ['Needs Revision', 'chip-orange'],
        'Rejected': ['Rejected', 'chip-red'],
        'Completed': ['Completed', 'chip-green']
    };

    function renderStatusBadge(status){
        var info = statusChipMap[status] || [status, 'chip-steel'];
        statusBadge.textContent = info[0];
        statusBadge.className = 'status-chip ' + info[1];
    }

    // College Department reviews one submitted document at a time; every other
    // office is reviewed as a whole, exactly as before.
    var docsLabelEl = document.getElementById('reviewDocsLabel');
    var perDocument = false;
    var reviewDocs = [];
    var selectedDocId = null;

    var docChipMap = {
        'Approved': ['Approved', 'chip-blue'],
        'Rejected': ['Rejected', 'chip-red'],
        'Needs Revision': ['Needs Revision', 'chip-orange'],
        'Remarks only': ['Remarks added', 'chip-steel']
    };

    // Everything except Mark Completed needs a document chosen first.
    function updateActionButtons(){
        backdrop.querySelectorAll('[data-review-action]').forEach(function(btn){
            var needsDoc = perDocument && btn.getAttribute('data-review-action') !== 'completed';
            btn.disabled = needsDoc && selectedDocId === null;
            btn.title = btn.disabled ? 'Choose a submitted document to review first' : '';
        });
    }

    function selectReviewDoc(docId){
        selectedDocId = docId;
        var doc = reviewDocs.filter(function(d){ return String(d.id) === String(docId); })[0];
        remarksInput.value = doc ? (doc.review_remarks || '') : '';
        updateActionButtons();
    }

    function renderReviewDocs(docs){
        docsListEl.innerHTML = '';
        if(!docs.length){
            var empty = document.createElement('div');
            empty.className = 'muted-copy';
            empty.textContent = perDocument ? 'No submitted documents to review yet.' : 'No documents submitted.';
            docsListEl.appendChild(empty);
            return;
        }
        var list = document.createElement('div');
        list.className = 'doc-pill-list';
        docs.forEach(function(doc){
            var a = document.createElement('a');
            a.href = doc.url;
            a.target = '_blank';
            a.rel = 'noopener';
            a.className = 'doc-pill';
            var icon = document.createElement('i');
            icon.className = 'bi bi-paperclip';
            a.appendChild(icon);
            a.appendChild(document.createTextNode(doc.label || ''));

            if(!perDocument){
                list.appendChild(a);
                return;
            }

            var option = document.createElement('label');
            option.className = 'review-doc-option' + (String(doc.id) === String(selectedDocId) ? ' is-selected' : '');
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'reviewDoc';
            radio.value = doc.id;
            radio.checked = String(doc.id) === String(selectedDocId);
            radio.addEventListener('change', function(){
                selectReviewDoc(doc.id);
                list.querySelectorAll('.review-doc-option').forEach(function(o){ o.classList.remove('is-selected'); });
                option.classList.add('is-selected');
            });
            option.appendChild(radio);
            option.appendChild(a);

            var meta = document.createElement('span');
            meta.className = 'review-doc-meta';
            if(doc.office){ meta.appendChild(document.createTextNode(doc.office)); }
            if(doc.review_status){
                var info = docChipMap[doc.review_status] || [doc.review_status, 'chip-steel'];
                var chip = document.createElement('span');
                chip.className = 'status-chip ' + info[1];
                chip.textContent = info[0];
                meta.appendChild(chip);
            }
            option.appendChild(meta);
            list.appendChild(option);
        });
        docsListEl.appendChild(list);
    }

    function openReviewModal(trigger){
        currentTrigger = trigger;
        officeText.textContent = trigger.getAttribute('data-office') || '';
        recText.textContent = trigger.getAttribute('data-rec-text') || '(No recommendation text yet)';
        renderStatusBadge(trigger.getAttribute('data-status') || 'Pending');
        officeRemarksEl.textContent = trigger.getAttribute('data-remarks') || '(No compliance response submitted yet)';
        try { reviewDocs = JSON.parse(trigger.getAttribute('data-docs') || '[]'); } catch(err){ reviewDocs = []; }

        perDocument = trigger.getAttribute('data-office') === 'College Department';
        selectedDocId = null;
        if(docsLabelEl){
            docsLabelEl.textContent = perDocument ? 'Choose the submitted document to review' : 'Submitted Documents';
        }
        // Per document, the remarks belong to whichever file is chosen.
        remarksInput.value = perDocument ? '' : (trigger.getAttribute('data-review-remarks') || '');
        remarksInput.placeholder = perDocument ? 'Choose a document above, then add feedback for it...' : 'Add feedback for the office...';

        renderReviewDocs(reviewDocs);
        updateActionButtons();
        backdrop.classList.add('is-open');
    }

    function closeReviewModal(){
        backdrop.classList.remove('is-open');
        currentTrigger = null;
    }

    document.addEventListener('click', function(e){
        var trigger = e.target.closest('[data-review-trigger]');
        if(trigger){
            openReviewModal(trigger);
        }
    });

    if(closeBtn){ closeBtn.addEventListener('click', closeReviewModal); }
    if(cancelBtn){ cancelBtn.addEventListener('click', closeReviewModal); }
    backdrop.addEventListener('click', function(e){ if(e.target === backdrop){ closeReviewModal(); } });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && backdrop.classList.contains('is-open')){ closeReviewModal(); } });

    backdrop.querySelectorAll('[data-review-action]').forEach(function(btn){
        btn.addEventListener('click', function(){
            if(!currentTrigger){ return; }
            var decision = btn.getAttribute('data-review-action');
            var recId = currentTrigger.getAttribute('data-rec-id');
            var reviewRemarksValue = remarksInput.value;
            var reviewBody = 'action=review_recommendation&id=' + encodeURIComponent(recId) +
                '&decision=' + encodeURIComponent(decision) +
                '&review_remarks=' + encodeURIComponent(reviewRemarksValue);
            if(perDocument && decision !== 'completed' && selectedDocId !== null){
                reviewBody += '&doc_id=' + encodeURIComponent(selectedDocId);
            }
            btn.disabled = true;
            fetch('recommendations_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: reviewBody
            }).then(function(res){ return res.json(); }).then(function(data){
                updateActionButtons();
                if(!data.ok){ alert(data.error || 'Could not save the review.'); return; }

                currentTrigger.setAttribute('data-status', data.status);
                currentTrigger.setAttribute('data-review-remarks', data.review_remarks);
                renderStatusBadge(data.status);

                // Keep the chosen document's decision so reopening shows it.
                if(data.doc_id){
                    reviewDocs.forEach(function(d){
                        if(String(d.id) === String(data.doc_id)){
                            d.review_status = data.doc_review_status;
                            d.review_remarks = reviewRemarksValue;
                        }
                    });
                    currentTrigger.setAttribute('data-docs', JSON.stringify(reviewDocs));
                }

                var row = currentTrigger.closest('tr');
                if(row){
                    var select = row.querySelector('.cell-select[data-field="status"]');
                    if(select){
                        select.value = data.status;
                        paintStatusSelect(select);
                    }
                }

                closeReviewModal();
            }).catch(function(){
                updateActionButtons();
                alert('Could not save the review. Please try again.');
            });
        });
    });
})();
(function(){
    var selectedAudit = <?php echo json_encode($selectedAudit); ?>;
    var currentOffice = <?php echo json_encode($selectedOffice); ?>;
    var currentArea = <?php echo json_encode($selectedArea); ?>;
    var currentProgram = <?php echo json_encode($selectedProgram); ?>;
    var officeTabs = document.getElementById('officeTabs');
    var auditTbody = document.getElementById('auditRecTbody');
    var auditTitle = document.getElementById('auditRecTitle');
    var addRowBtn = document.getElementById('addRowBtn');
    var backBtn = document.getElementById('backToAreasBtn');
    var gridHeadRow = document.getElementById('recGridHeadRow');
    var filterSearch = document.getElementById('filterSearch');
    var filterInCharge = document.getElementById('filterInCharge');
    var filterStatus = document.getElementById('filterStatus');
    var filterYear = document.getElementById('filterYear');
    var filterClear = document.getElementById('filterClear');
    var gridCount = document.getElementById('gridCount');

    function currentFilters(){
        return {
            search:    filterSearch ? filterSearch.value.trim() : '',
            in_charge: filterInCharge ? filterInCharge.value : '',
            status:    filterStatus ? filterStatus.value : '',
            year:      filterYear ? filterYear.value : ''
        };
    }
    if(!officeTabs || !auditTbody || !auditTitle){ return; }
    initInChargeCells(auditTbody);

    // "Office" only means something once rows from more than one office are
    // mixed together; scoped to a single office it becomes "In Charge" instead.
    // A single External office drops the Office column; every other view
    // keeps it. In Charge is on every view.
    function gridColumnCount(office){
        return (office === '' || selectedAudit !== 'External') ? 8 : 7;
    }

    function updateGridHeader(office){
        if(!gridHeadRow){ return; }
        gridHeadRow.innerHTML =
            ((office === '' || selectedAudit !== 'External') ? "<th style='width:160px'>Office</th>" : "")
            + "<th>Recommendation</th><th style='width:190px'>In Charge</th>"
            + "<th style='width:140px'>Status of Submission</th><th>Remarks</th>"
            + "<th style='width:150px'>Submitted Documents</th><th style='width:120px'>Year</th>"
            + "<th style='width:190px'>Actions</th>";
    }

    // Office names are pasted into HTML below; an apostrophe ("Dean's Office")
    // used to end the data-office attribute early and break the row's buttons.
    function escapeHtml(text){
        return String(text).replace(/[&<>"']/g, function(ch){
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch];
        });
    }

    function rowActionsHtml(office, recId){
        office = escapeHtml(office);
        return "<div class='row-actions'>" +
            "<button type='button' class='row-edit-btn' title='Edit row'><i class='bi bi-pencil'></i> Edit</button>" +
            "<button type='button' class='row-save-btn' title='Save row'><i class='bi bi-check2'></i> Save</button>" +
            "<button type='button' class='row-review-btn' title='Review submission' data-review-trigger data-rec-id='" + recId + "' data-office='" + office + "' data-rec-text='' data-status='Pending' data-remarks='' data-review-remarks='' data-docs='[]'><i class='bi bi-clipboard-check'></i> Review</button>" +
            "<button type='button' class='row-delete' title='Delete row'><i class='bi bi-trash3'></i></button>" +
        "</div>";
    }

    function initInChargeCells(root){
        InCharge.init(root);
    }

    function buildRow(id, office){
        var tr = document.createElement('tr');
        tr.setAttribute('data-id', id);
        tr.setAttribute('data-mode', 'edit');
        var leadCellsHtml =
            (gridColumnCount(office) === 8 ? "<td class='cell-readonly'>" + escapeHtml(office) + "</td>" : "") +
            "<td><div class='cell-text grid-rec-cell' contenteditable='true' data-field='recommendation'></div></td>" +
            "<td>" + InCharge.cellHtml(office, 'full') + "</td>";
        tr.innerHTML =
            leadCellsHtml +
            "<td><select class='cell-select status-select chip-steel' data-field='status'>" +
                "<option value='Pending' selected>Pending</option>" +
                "<option value='Submitted'>Submitted</option>" +
                "<option value='Not Submitted'>Not Submitted</option>" +
                "<option value='Approved'>Approved</option>" +
                "<option value='Needs Revision'>Needs Revision</option>" +
                "<option value='Rejected'>Rejected</option>" +
                "<option value='Completed'>Completed</option>" +
            "</select></td>" +
            "<td><div class='cell-text' contenteditable='true' data-field='remarks'></div></td>" +
            "<td><span class='muted-copy'>No document submitted</span></td>" +
            "<td><input type='text' class='cell-year' maxlength='9' data-field='year' value='' placeholder='2026 or 2026-2027'></td>" +
            "<td>" + rowActionsHtml(office, id) + "</td>";
        return tr;
    }

    function setRowMode(tr, mode){
        tr.setAttribute('data-mode', mode);
        var editing = mode === 'edit';
        tr.querySelectorAll('.cell-text').forEach(function(el){ el.setAttribute('contenteditable', editing ? 'true' : 'false'); });
        tr.querySelectorAll('.cell-select, .cell-year').forEach(function(el){ el.disabled = !editing; });
    }

    function readRowValues(tr){
        var values = {};
        tr.querySelectorAll('[data-field]').forEach(function(el){
            var field = el.getAttribute('data-field');
            if(el.classList.contains('incharge-cell')){
                values[field] = el.getAttribute('data-json') || '[]';
            } else if(el.classList.contains('cell-text')){
                values[field] = el.innerText.replace(/\n+$/, '');
            } else {
                values[field] = el.value;
            }
        });
        return values;
    }

    function clearPlaceholderRow(){
        var onlyRow = auditTbody.children.length === 1 ? auditTbody.children[0] : null;
        if(onlyRow && !onlyRow.hasAttribute('data-id')){ auditTbody.innerHTML = ''; }
    }

    auditTbody.addEventListener('change', function(e){
        var statusSelect = e.target.closest('.status-select');
        if(statusSelect){ paintStatusSelect(statusSelect); }
    });

    auditTbody.addEventListener('click', function(e){
        var editBtn = e.target.closest('.row-edit-btn');
        if(editBtn){
            var trEdit = editBtn.closest('tr');
            setRowMode(trEdit, 'edit');
            var firstCell = trEdit.querySelector('.cell-text');
            if(firstCell){ firstCell.focus(); }
            return;
        }

        var saveBtn = e.target.closest('.row-save-btn');
        if(saveBtn){
            var trSave = saveBtn.closest('tr');
            var id = trSave.getAttribute('data-id');
            var values = readRowValues(trSave);
            saveBtn.disabled = true;
            // Grouped, per-office remarks render read-only with no data-field,
            // so values.remarks is absent there -- omit it rather than send an
            // empty value that would wipe out every office's submission.
            var saveBody = 'action=save_office_recommendation&id=' + encodeURIComponent(id) +
                '&recommendation=' + encodeURIComponent(values.recommendation || '') +
                '&status=' + encodeURIComponent(values.status || '') +
                '&year=' + encodeURIComponent(values.year || '') +
                '&in_charge=' + encodeURIComponent(values.in_charge || '');
            if(values.remarks !== undefined){
                saveBody += '&remarks=' + encodeURIComponent(values.remarks);
            }
            fetch('recommendations_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: saveBody
            }).then(function(res){ return res.json(); }).then(function(data){
                saveBtn.disabled = false;
                if(!data.ok){
                    alert(data.error || 'Could not save this row.');
                    return;
                }
                setRowMode(trSave, 'view');
            }).catch(function(){
                saveBtn.disabled = false;
                alert('Could not save this row. Please try again.');
            });
            return;
        }

        var deleteBtn = e.target.closest('.row-delete');
        if(deleteBtn){
            var trDelete = deleteBtn.closest('tr');
            var deleteId = trDelete.getAttribute('data-id');
            if(!confirm('Delete this recommendation row?')){ return; }
            fetch('recommendations_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete&id=' + encodeURIComponent(deleteId)
            }).then(function(){
                trDelete.remove();
                if(auditTbody.children.length === 0){
                    auditTbody.innerHTML = "<tr><td colspan='" + gridColumnCount(currentOffice) + "' class='empty-state'>No recommendations available for this office</td></tr>";
                }
            });
            return;
        }

    });

    if(addRowBtn){
        addRowBtn.addEventListener('click', function(){
            if(!currentOffice){ return; }
            fetch('recommendations_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                // Filed under the area currently open, so it appears in the
                // right card rather than falling into Unassigned.
                body: 'action=add_office_recommendation&office=' + encodeURIComponent(currentOffice)
                    + (currentProgram ? '&program=' + encodeURIComponent(currentProgram) : '')
                    + (currentArea ? '&area=' + encodeURIComponent(currentArea) : '')
            }).then(function(res){ return res.json(); }).then(function(data){
                if(!data.ok){ alert(data.error || 'Could not add a new row.'); return; }
                clearPlaceholderRow();
                var tr = buildRow(data.id, currentOffice);
                auditTbody.insertBefore(tr, auditTbody.firstChild);
                initInChargeCells(tr);
                var firstCell = tr.querySelector('.cell-text');
                if(firstCell){ firstCell.focus(); }
            });
        });
    }

    // One loader for all three ways of moving around: picking an office tile,
    // opening an area card, and stepping back out to the areas.
    function loadGrid(office, program, area){
        auditTbody.innerHTML = "<tr><td colspan='" + gridColumnCount(office) + "' class='empty-state'>Loading&hellip;</td></tr>";

        var params = new URLSearchParams({audit: selectedAudit});
        if(office !== ''){ params.set('office', office); }
        if(program !== ''){ params.set('program', program); }
        if(area !== ''){ params.set('area', area); }

        var filters = currentFilters();
        Object.keys(filters).forEach(function(key){
            if(filters[key] !== ''){ params.set(key, filters[key]); }
        });

        fetch('office_recommendations_view.php?' + params.toString())
            .then(function(res){ return res.json(); })
            .then(function(data){
                if(!data.ok){ return; }
                currentOffice = data.office;
                currentProgram = data.program || '';
                currentArea = data.area || '';
                auditTbody.innerHTML = data.html;
                auditTitle.textContent = data.title;
                updateGridHeader(currentOffice);
                initInChargeCells(auditTbody);

                // Add Row needs somewhere to file the recommendation: not on
                // "All Offices", and not while programmes or areas are listed.
                if(addRowBtn){
                    // Adding a row while filtering would file one that vanishes
                    // the moment it is drawn, since a blank row matches nothing.
                    addRowBtn.style.display =
                        (currentOffice === '' || data.view === 'areas' || data.view === 'programs' || data.filtered) ? 'none' : '';
                }
                if(gridCount){
                    gridCount.textContent = data.filtered
                        ? data.count + (data.count === 1 ? ' match' : ' matches')
                        : '';
                }

                // Back appears from the moment there is a level to step back to.
                if(backBtn){
                    backBtn.style.display = (currentArea === '' && currentProgram === '') ? 'none' : '';
                }

                var newUrl = 'home.php?audit=' + encodeURIComponent(selectedAudit)
                    + (currentOffice !== '' ? '&office=' + encodeURIComponent(currentOffice) : '')
                    + (currentProgram !== '' ? '&program=' + encodeURIComponent(currentProgram) : '')
                    + (currentArea !== '' ? '&area=' + encodeURIComponent(currentArea) : '')
                    + Object.keys(filters).map(function(key){
                        return filters[key] !== '' ? '&' + key + '=' + encodeURIComponent(filters[key]) : '';
                      }).join('')
                    + '#audit-recommendations';
                window.history.replaceState(null, '', newUrl);
            })
            .catch(function(){
                auditTbody.innerHTML = "<tr><td colspan='" + gridColumnCount(currentOffice) + "' class='empty-state'>Unable to load recommendations right now.</td></tr>";
            });
    }

    /**
     * A search spans the whole office, so it steps out of whatever programme
     * or area is open rather than looking only inside it -- otherwise a match
     * filed under a different area would look like no match at all.
     */
    function applyFilters(){
        loadGrid(currentOffice, '', '');
    }

    var searchTimer = null;
    if(filterSearch){
        filterSearch.addEventListener('input', function(){
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applyFilters, 300);
        });
        // The clear cross inside a search box fires this, not 'input'.
        filterSearch.addEventListener('search', applyFilters);
    }
    [filterInCharge, filterStatus, filterYear].forEach(function(control){
        if(control){ control.addEventListener('change', applyFilters); }
    });
    if(filterClear){
        filterClear.addEventListener('click', function(){
            if(filterSearch){ filterSearch.value = ''; }
            [filterInCharge, filterStatus, filterYear].forEach(function(control){
                if(control){ control.value = ''; }
            });
            applyFilters();
        });
    }

    officeTabs.addEventListener('click', function(e){
        var tile = e.target.closest('.office-tile');
        if(!tile){ return; }
        e.preventDefault();

        var office = tile.getAttribute('data-office') || '';
        officeTabs.querySelectorAll('.office-tile').forEach(function(t){ t.classList.remove('active'); });
        tile.classList.add('active');

        // Switching office always starts at that office's top level.
        loadGrid(office, '', '');
    });

    // Cards are replaced wholesale on every load, so listen on the table body
    // rather than binding each one.
    auditTbody.addEventListener('click', function(e){
        var program = e.target.closest('.prog-card');
        if(program){
            loadGrid(program.getAttribute('data-office') || currentOffice,
                     program.getAttribute('data-program') || '', '');
            return;
        }

        var area = e.target.closest('.area-card');
        if(area){
            loadGrid(area.getAttribute('data-office') || currentOffice,
                     area.getAttribute('data-program') || currentProgram,
                     area.getAttribute('data-area') || '');
        }
    });

    if(backBtn){
        backBtn.addEventListener('click', function(){
            // One level at a time: an area returns to its programme's areas,
            // a programme returns to the programme cards.
            if(currentArea !== ''){
                loadGrid(currentOffice, currentProgram, '');
            } else {
                loadGrid(currentOffice, '', '');
            }
        });
    }
})();
</script>
<?php render_edit_toggle(); ?>
</body>
</html>
