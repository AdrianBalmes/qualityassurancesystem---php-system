<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/office_rec_render.php";
require_once __DIR__ . "/office_directory.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] != "admin"){
    header("Location: admin_login.php");
    exit();
}

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

if(isset($_POST['update_recommendation'])){
    $recId = intval($_POST['recommendation_id'] ?? 0);
    $newRecStatus = trim($_POST['rec_status'] ?? '');
    $newRecRemarks = trim($_POST['rec_remarks'] ?? '');
    $allowedRecStatuses = ['Pending', 'Submitted', 'Not Submitted'];
    $redirectAudit = trim($_POST['audit_type'] ?? 'External');
    if(!in_array($redirectAudit, ['External', 'Internal'], true)){
        $redirectAudit = 'External';
    }
    $redirectOffice = trim($_POST['redirect_office'] ?? '');
    if(!in_array($redirectOffice, $officeNames, true) || audit_type_for_office($redirectOffice) !== $redirectAudit){
        $redirectOffice = '';
    }

    if($recId > 0 && in_array($newRecStatus, $allowedRecStatuses, true)){
        $updateRecStmt = $conn->prepare("UPDATE audit_recommendations SET status = ?, remarks = ? WHERE id = ?");
        $updateRecStmt->bind_param("ssi", $newRecStatus, $newRecRemarks, $recId);
        $updateRecStmt->execute();
    }
    if($redirectOffice !== ''){
        header("Location: home.php?audit=" . urlencode($redirectAudit) . "&office=" . urlencode($redirectOffice) . "#audit-recommendations");
    } else {
        header("Location: home.php?audit=" . urlencode($redirectAudit) . "#audit-recommendations");
    }
    exit();
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

$auditRecommendations = $selectedOffice !== '' ? fetch_office_recommendations($conn, $selectedOffice, $selectedAudit) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SBC QA Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1180px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}.nav-links{display:flex;gap:20px;flex-wrap:wrap}.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}.dashboard{max-width:1180px;margin:26px auto 42px;padding:0 18px}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:16px}.panel-title{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid #dbe3ef;font-size:17px;font-weight:800}.muted-copy{color:#66758d;font-size:13px}.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;padding:12px 16px;margin-bottom:14px}.stat-box{min-height:46px;border-radius:7px;display:flex;align-items:center;justify-content:center;gap:7px;padding:10px 12px;font-weight:800}.stat-box strong{font-size:20px}.stat-green{background:#caefdd}.stat-yellow{background:#fff0ba}.stat-red{background:#ffd0c9}.stat-steel{background:#d8e2f5}.chart-box{height:210px}.table-wrap{overflow-x:auto}.dashboard-table{min-width:820px}.dashboard-table th{background:#f1f5fb;color:#56637a;font-size:13px}.dashboard-table td{vertical-align:middle;font-weight:700;font-size:13px}.status-chip{display:inline-flex;padding:4px 7px;border-radius:4px;font-size:12px;font-weight:800}.chip-green{background:#cdeedc;color:#277548}.chip-yellow{background:#fff0ba;color:#806119}.chip-red{background:#ffd6d0;color:#a33831}.btn-xs{padding:4px 8px;font-size:12px;font-weight:800}.link-strong{font-weight:800;text-decoration:none;color:#2e67b8}.empty-state{padding:14px;text-align:center;color:#66758d;font-weight:700}.status-select{width:auto;min-width:145px;font-size:12px;font-weight:700}.audit-panel{margin-bottom:14px}.audit-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px}.audit-switcher{display:flex;gap:8px;flex-wrap:wrap}.audit-switch{min-height:34px;border-radius:5px;background:#fff;border:1px solid #c8d4e7;color:#2e67b8;text-decoration:none;font-size:13px;font-weight:800;padding:7px 12px;display:inline-flex;align-items:center}.audit-switch.active{background:#316fc4;border-color:#316fc4;color:#fff}.rec-cell{min-width:220px;word-break:break-word;overflow-wrap:anywhere}.rec-update-form{display:flex;flex-direction:column;gap:6px;margin-top:8px}.rec-update-form select,.rec-update-form input{max-width:100%}.section-heading{margin:18px 4px 8px;font-size:19px;font-weight:800;color:#344156}.office-tabs{display:grid;grid-template-columns:36px minmax(0,1fr) 36px;gap:8px;align-items:stretch;background:#e8eef8;border:1px solid #dbe3ef;padding:10px;border-radius:6px}.office-scroll{display:flex;gap:12px;overflow-x:auto;scroll-behavior:smooth;scrollbar-width:thin;-webkit-overflow-scrolling:touch}.office-slide-btn{width:36px;border:1px solid #c8d4e7;border-radius:6px;background:#fff;color:#316fc4;display:grid;place-items:center;flex-shrink:0}.office-tile{flex:0 0 104px;min-height:82px;display:grid;justify-items:center;align-content:center;gap:7px;font-weight:800;font-size:13px;text-decoration:none;color:#344156;border:2px solid transparent;border-radius:6px;padding:6px;text-align:center}.office-tile:hover,.office-tile.active{border-color:#316fc4;background:#f7fbff}.office-icon{width:70px;height:54px;border-radius:6px;background:#fff;color:#3971c1;display:grid;place-items:center;font-size:29px}.document-panel{margin-top:12px}img,canvas,svg{max-width:100%}.dashboard,.page,.nav-wrap{width:100%}@media(max-width:1060px){.nav-wrap{flex-direction:column;align-items:flex-start;padding:14px 18px}.stat-strip{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.brand{font-size:17px}.stat-strip{grid-template-columns:1fr}.chart-box{height:230px}.office-tabs{grid-template-columns:32px minmax(0,1fr) 32px}.office-tile{flex-basis:94px}.audit-head{align-items:flex-start}.audit-switcher{width:100%}.audit-switch{flex:1 1 auto;justify-content:center;text-align:center}}@media(max-width:480px){.dashboard{padding:0 10px;margin:16px auto 28px}.panel-pad{padding:12px}.panel-title{font-size:15px}.page-title{font-size:20px}.stat-box{font-size:12px;padding:8px 10px}.stat-box strong{font-size:17px}.brand{font-size:15px;gap:8px}.brand-icon{width:32px;height:32px;font-size:15px}.nav-links{gap:12px;font-size:13px}.office-tile{flex-basis:80px;min-height:72px}.office-icon{width:56px;height:44px;font-size:22px}.section-heading{font-size:16px}}
</style>
</head>
<body>
<header class="topbar"><div class="nav-wrap"><div class="brand"><span class="brand-icon"><i class="bi bi-file-earmark-text-fill"></i></span><?php sc_span($siteContent, 'home.brand', 'SBC Quality Assurance Electronic Documentation Dashboard'); ?></div><nav class="nav-links"><a href="home.php">Home</a><a href="manage_recommendations.php?audit=<?php echo urlencode($selectedAudit); ?>">Manage Recommendations</a><a href="repository.php">Repository</a><a href="create_feedback.php">Feedback</a><a href="profile.php"><i class="bi bi-person-circle"></i> Profile</a></nav></div></header>
<main class="dashboard">
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
    <div style="margin-bottom:16px">
        <h3 style="margin:0 0 8px;font-size:15px;font-weight:800;color:#344156"><?php sc_span($siteContent, 'home.audit.chart_title', 'Recommendations Submitted by Office'); ?></h3>
        <div class="chart-box"><canvas id="officeRecChart"></canvas></div>
    </div>
    <h3 class="panel-title" id="auditRecTitle" style="font-size:15px;border-bottom:0;padding-bottom:0;margin-bottom:8px"><?php echo $selectedOffice === '' ? 'Select an office below' : htmlspecialchars($selectedOffice, ENT_QUOTES) . ' Recommendations'; ?></h3>
    <div class="table-wrap">
        <table class="table dashboard-table">
            <thead>
                <tr>
                    <th>Office</th>
                    <th>Recommendation</th>
                    <th>Status of Submission</th>
                    <th>Remarks</th>
                    <th>Year</th>
                </tr>
            </thead>
            <tbody id="auditRecTbody">
                <?php
                if($selectedOffice === ''){
                    echo render_select_office_placeholder();
                } else {
                    $auditRecRendered = render_office_recommendation_rows($auditRecommendations, $selectedOffice, $selectedAudit, '#audit-recommendations');
                    echo $auditRecRendered['html'];
                }
                ?>
            </tbody>
        </table>
    </div>
</section>
<h2 class="section-heading"><?php sc_span($siteContent, 'home.office.section_title', 'Manage Recommendations by Office'); ?></h2>
<section class="panel panel-pad" id="office-recommendations">
    <h2 class="panel-title"><?php sc_span($siteContent, 'home.office.panel_title', 'All Offices'); ?></h2>
    <div class="office-tabs">
        <button type="button" class="office-slide-btn" data-slide-office="-1" aria-label="Slide offices left"><i class="bi bi-chevron-left"></i></button>
        <div class="office-scroll" id="officeTabs">
            <a class="office-tile<?php echo $selectedOffice === '' ? ' active' : ''; ?>" data-office="" href="home.php?audit=<?php echo urlencode($selectedAudit); ?>#audit-recommendations"><div class="office-icon"><i class="bi bi-grid-fill"></i></div><span>All Offices</span></a>
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
</main>
<script>
new Chart(document.getElementById('officeRecChart'),{type:'bar',data:{labels:<?php echo json_encode($recOfficeLabels); ?>,datasets:[{label:'Recommendations Submitted',data:<?php echo json_encode($recOfficeCounts); ?>,backgroundColor:'#316fc4'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
document.querySelectorAll('[data-slide-office]').forEach(function(button){button.addEventListener('click',function(){var tabs=document.getElementById('officeTabs');if(!tabs){return;}var direction=parseInt(button.getAttribute('data-slide-office'),10)||1;tabs.scrollBy({left:direction*260,behavior:'smooth'});});});
(function(){
    var selectedAudit = <?php echo json_encode($selectedAudit); ?>;
    var officeTabs = document.getElementById('officeTabs');
    var auditTbody = document.getElementById('auditRecTbody');
    var auditTitle = document.getElementById('auditRecTitle');
    if(!officeTabs || !auditTbody || !auditTitle){ return; }

    officeTabs.addEventListener('click', function(e){
        var tile = e.target.closest('.office-tile');
        if(!tile){ return; }
        e.preventDefault();

        var office = tile.getAttribute('data-office') || '';
        officeTabs.querySelectorAll('.office-tile').forEach(function(t){ t.classList.remove('active'); });
        tile.classList.add('active');

        auditTbody.innerHTML = "<tr><td colspan='5' class='empty-state'>Loading&hellip;</td></tr>";

        var params = new URLSearchParams({audit: selectedAudit});
        if(office !== ''){ params.set('office', office); }

        fetch('office_recommendations_view.php?' + params.toString())
            .then(function(res){ return res.json(); })
            .then(function(data){
                if(!data.ok){ return; }
                auditTbody.innerHTML = data.html;
                auditTitle.textContent = office === '' ? 'Select an office below' : data.title;
                var newUrl = 'home.php?audit=' + encodeURIComponent(selectedAudit) + (office !== '' ? '&office=' + encodeURIComponent(office) : '') + '#audit-recommendations';
                window.history.replaceState(null, '', newUrl);
            })
            .catch(function(){
                auditTbody.innerHTML = "<tr><td colspan='5' class='empty-state'>Unable to load recommendations right now.</td></tr>";
            });
    });
})();
</script>
<?php render_edit_toggle(); ?>
</body>
</html>
