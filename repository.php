<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/nav_dropdown.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/permissions.php";

if(!isset($_SESSION['admin_username']) && !isset($_SESSION['office_username'])){
    header("Location: index.php");
    exit();
}

ensure_user_account_columns($conn);
enforce_active_account($conn);

$isAdmin = session_is_qa_staff();
$userOffice = isset($_SESSION['office_name']) ? $_SESSION['office_name'] : '';


/**
 * One folder per department, counting the supporting documents offices upload
 * with their compliance updates (recommendation_documents). That is a separate
 * store from the approved `documents` listed further down this page.
 */
$departmentFolders = [];
if($isAdmin){
    $counts = [];
    $countResult = mysqli_query($conn, "SELECT office, COUNT(*) AS total, MAX(uploaded_at) AS latest
                                        FROM recommendation_documents WHERE office <> '' GROUP BY office");
    if($countResult){
        while($countRow = mysqli_fetch_assoc($countResult)){
            $counts[$countRow['office']] = ['total' => (int) $countRow['total'], 'latest' => $countRow['latest']];
        }
    }

    // Every department gets a folder, even an empty one, so the grid is the
    // full directory rather than only whoever has uploaded so far.
    $folderNames = get_all_office_names($conn);
    foreach(array_keys($counts) as $nameWithFiles){
        // Files left behind by an office that was since renamed or removed.
        if(!in_array($nameWithFiles, $folderNames, true)){
            $folderNames[] = $nameWithFiles;
        }
    }
    natcasesort($folderNames);

    foreach($folderNames as $folderName){
        if(trim($folderName) === '' || $folderName === 'Admin'){
            continue;
        }
        $departmentFolders[] = [
            'office' => $folderName,
            'total'  => $counts[$folderName]['total'] ?? 0,
            'latest' => $counts[$folderName]['latest'] ?? '',
        ];
    }
}
$departmentFileTotal = array_sum(array_column($departmentFolders, 'total'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="assets/sbc-logo.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Document Repository</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f6f9;color:#26354b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2);position:sticky;top:0;z-index:900}
.nav-wrap{max-width:1240px;margin:auto;min-height:70px;padding:0 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;font-size:19px;font-weight:800}
.brand-icon{width:64px;height:64px;display:grid;place-items:center;flex-shrink:0}
.nav-links{display:flex;gap:18px;flex-wrap:wrap}
.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700;font-size:14px}
.nav-links a:hover{text-decoration:underline}
.page{max-width:1240px;margin:0 auto;padding:36px 20px 56px}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.page-title{margin:0 0 5px;font-size:26px;font-weight:800;color:#1c2b3f;letter-spacing:-.2px}
.page-subtitle{margin:0;font-size:14px;color:#66758d;font-weight:600}
.summary-pills{display:flex;gap:10px;flex-wrap:wrap}
.summary-pill{background:#fff;border:1px solid #e3e8f0;border-radius:999px;padding:8px 16px;font-size:12.5px;font-weight:800;color:#4c5a72;display:inline-flex;align-items:center;gap:7px;box-shadow:0 2px 6px rgba(15,26,42,.04)}
.summary-pill i{color:#316fc4}
.card{background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(15,26,42,.06);padding:22px 24px}
.repo-controls{display:grid;grid-template-columns:minmax(240px,1.4fr) minmax(160px,1fr);gap:12px;margin-bottom:8px}
.field-input,.field-select{width:100%;border:1px solid #dbe1ea;border-radius:9px;padding:11px 14px;font-size:14px;font-family:inherit;color:#26354b;background:#fff;transition:border-color .15s ease,box-shadow .15s ease}
.field-input:focus,.field-select:focus{outline:none;border-color:#316fc4;box-shadow:0 0 0 3px rgba(49,111,196,.14)}
.repo-summary{margin:0 0 14px;color:#8794a8;font-size:12.5px;font-weight:700}
.table-wrap{overflow-x:auto;border:1px solid #eef1f6;border-radius:10px}
.repo-table{width:100%;border-collapse:collapse;min-width:760px}
.repo-table th{background:#f8fafc;color:#66758d;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;text-align:left;padding:12px 14px;border-bottom:1px solid #eef1f6;white-space:nowrap}
.repo-table td{padding:12px 14px;font-size:13.5px;font-weight:600;color:#344156;border-bottom:1px solid #f1f4f8;vertical-align:middle}
.repo-table tr:last-child td{border-bottom:0}
.doc-title-cell strong{display:block;color:#1c2b3f;font-weight:800}
.doc-title-cell span{display:block;color:#8794a8;font-size:12px;font-weight:600;margin-top:2px}
.storage-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap}
.storage-local{background:#eef1f6;color:#4c5a72}
.storage-onedrive{background:#e3edfb;color:#1f5fbf}
.action-inline{display:flex;gap:6px;flex-wrap:wrap}
.btn-xs{border:0;border-radius:6px;font-weight:800;font-size:12px;padding:6px 11px;display:inline-flex;align-items:center;gap:5px;cursor:pointer;text-decoration:none}
.btn-view{background:#316fc4;color:#fff}
.btn-view:hover{background:#2459a6;color:#fff}
.btn-download{background:#eef1f6;color:#4c5a72}
.btn-download:hover{background:#e3e8f0}
.btn-link{background:#e3edfb;color:#1f5fbf}
.btn-link:hover{background:#d4e4f7}
.empty-state{padding:34px 18px;text-align:center;color:#8794a8;font-weight:700;font-size:13.5px}
.section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}
.section-title{margin:0 0 4px;font-size:17px;font-weight:800;color:#1c2b3f;display:flex;align-items:center;gap:8px}
.section-title i{color:#f0b429}
.section-subtitle{margin:0;font-size:13px;color:#66758d;font-weight:600}
.folder-search{max-width:270px}
.folder-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(252px,1fr));gap:12px}
.folder-card{display:flex;align-items:center;gap:12px;padding:14px 16px;border:1px solid #e6ebf3;border-radius:11px;background:#fff;text-decoration:none;color:inherit;transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease}
.folder-card:hover{border-color:#c6d8f2;box-shadow:0 8px 20px rgba(15,26,42,.08);transform:translateY(-1px)}
.folder-icon{font-size:27px;color:#f0b429;line-height:1;flex-shrink:0}
.folder-card.is-empty .folder-icon{color:#cbd3de}
.folder-body{display:flex;flex-direction:column;min-width:0;flex:1}
.folder-name{font-weight:800;font-size:14px;color:#1c2b3f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.folder-meta{font-size:12px;color:#8794a8;font-weight:600;margin-top:2px}
.folder-count{background:#eef4ff;color:#2459a6;border-radius:999px;padding:5px 11px;font-size:12px;font-weight:800;white-space:nowrap}
.folder-card.is-empty .folder-count{background:#f1f3f7;color:#8794a8}
.folders-card{margin-bottom:20px}
.modal-backdrop-custom{position:fixed;inset:0;background:rgba(15,26,42,.45);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:1100;display:flex;align-items:center;justify-content:center;padding:20px}
.modal-backdrop-custom.is-open{opacity:1;pointer-events:auto}
.link-modal{background:#fff;border-radius:12px;box-shadow:0 20px 50px rgba(15,26,42,.3);width:min(460px,100%);padding:26px 28px;transform:translateY(16px);transition:transform .2s ease}
.modal-backdrop-custom.is-open .link-modal{transform:translateY(0)}
.link-modal h3{margin:0 0 4px;font-size:17px;font-weight:800;color:#1c2b3f;display:flex;align-items:center;gap:8px}
.link-modal .muted-copy{margin:0 0 18px;font-size:13px;color:#66758d;font-weight:600}
.link-modal label{display:block;font-size:13px;font-weight:700;color:#344156;margin-bottom:6px}
.link-modal input{width:100%;border:1px solid #dbe1ea;border-radius:8px;padding:10px 12px;font-size:13.5px;margin-bottom:16px}
.link-modal input:focus{outline:none;border-color:#316fc4;box-shadow:0 0 0 3px rgba(49,111,196,.14)}
.link-modal-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.link-modal-actions .btn-xs{padding:9px 14px;font-size:13px}
.btn-cancel{background:#f1f3f7;color:#56637a}
.btn-remove{background:#fdeceb;color:#a33831}
@media(max-width:980px){.repo-controls{grid-template-columns:1fr 1fr}.nav-wrap,.page-head{flex-direction:column;align-items:flex-start}}
@media(max-width:680px){.brand{font-size:17px}.repo-controls{grid-template-columns:1fr}.page{padding:24px 14px 40px}.card{padding:16px}}
</style>
</head>
<body>
<?php render_page_background(); ?>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><img src="assets/sbc-logo.png" alt="St. Bridget College" style="width:100%;height:100%;object-fit:contain"></span><span>Document Repository</span></div>
        <nav class="nav-links">
            <a href="<?php echo $isAdmin ? 'home.php' : 'office_dashboard.php'; ?>">Dashboard</a>
            <a href="repository.php">Repository</a>
            <?php render_profile_dropdown($isAdmin ? 'admin_profile.php' : 'office_profile.php', $isAdmin ? 'Admin Profile' : 'Office Profile', $isAdmin ? 'admin' : 'office'); ?>
        </nav>
    </div>
</header>
<main class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Repository</h1>
            <p class="page-subtitle">Every department's folder of files submitted with their compliance updates.</p>
        </div>
        <div class="summary-pills">
            <?php if($isAdmin): ?>
            <span class="summary-pill"><i class="bi bi-folder-fill"></i> <?php echo count($departmentFolders); ?> department<?php echo count($departmentFolders) === 1 ? '' : 's'; ?> &middot; <?php echo $departmentFileTotal; ?> file<?php echo $departmentFileTotal === 1 ? '' : 's'; ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php if($isAdmin): ?>
    <section class="card folders-card">
        <div class="section-head">
            <div>
                <h2 class="section-title"><i class="bi bi-folder-fill"></i> Department Folders</h2>
                <p class="section-subtitle">Files each department uploaded with its compliance updates. Open a folder to see them.</p>
            </div>
            <input type="search" class="field-input folder-search" id="folderSearch" placeholder="Search department">
        </div>
        <div class="folder-grid" id="folderGrid">
            <?php foreach($departmentFolders as $folder):
                $folderCount = (int) $folder['total'];
                $folderLatest = $folder['latest'] !== '' && $folder['latest'] !== null
                    ? 'Last upload ' . date("M j, Y", strtotime($folder['latest']))
                    : 'No uploads yet';
            ?>
            <a class="folder-card<?php echo $folderCount === 0 ? ' is-empty' : ''; ?>"
               href="department_files.php?office=<?php echo urlencode($folder['office']); ?>"
               data-folder="<?php echo htmlspecialchars(strtolower($folder['office']), ENT_QUOTES); ?>">
                <span class="folder-icon"><i class="bi bi-folder-fill"></i></span>
                <span class="folder-body">
                    <span class="folder-name"><?php echo htmlspecialchars($folder['office'], ENT_QUOTES); ?></span>
                    <span class="folder-meta"><?php echo htmlspecialchars($folderLatest, ENT_QUOTES); ?></span>
                </span>
                <span class="folder-count"><?php echo $folderCount; ?> file<?php echo $folderCount === 1 ? '' : 's'; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php if(empty($departmentFolders)): ?>
            <div class="empty-state">No departments yet</div>
        <?php endif; ?>
        <p class="repo-summary" id="folderNoMatch" style="display:none;margin-top:14px">No department matches that search.</p>
    </section>
    <?php else: ?>
    <?php /* The folder grid is the whole page for an administrator. An office
             sees its own files beside the recommendation they belong to, so it
             is pointed there rather than left looking at an empty page. */ ?>
    <section class="card">
        <div class="empty-state">
            <i class="bi bi-folder2-open" style="font-size:26px;display:block;margin-bottom:8px"></i>
            Files <?php echo htmlspecialchars($userOffice !== '' ? $userOffice : 'your office', ENT_QUOTES); ?> submits are kept with the recommendation they belong to.
            <div style="margin-top:6px">Open <strong>My Documents</strong> on your dashboard to see them all in one place.</div>
        </div>
    </section>
    <?php endif; ?>

</main>


<script>
// Narrow the department folders by name.
(function(){
    var folderSearch = document.getElementById('folderSearch');
    var noMatch = document.getElementById('folderNoMatch');
    var cards = [].slice.call(document.querySelectorAll('.folder-card'));
    if(!folderSearch || !cards.length){ return; }

    folderSearch.addEventListener('input', function(){
        var term = folderSearch.value.trim().toLowerCase();
        var shown = 0;
        cards.forEach(function(card){
            var match = !term || (card.getAttribute('data-folder') || '').indexOf(term) !== -1;
            card.style.display = match ? '' : 'none';
            if(match){ shown++; }
        });
        if(noMatch){ noMatch.style.display = shown === 0 ? '' : 'none'; }
    });
})();

</script>
</body>
</html>
