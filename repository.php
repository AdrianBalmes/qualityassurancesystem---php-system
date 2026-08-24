<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/nav_dropdown.php";

if(!isset($_SESSION['admin_username']) && !isset($_SESSION['office_username'])){
    header("Location: index.php");
    exit();
}

ensure_user_account_columns($conn);
enforce_active_account($conn);

$isAdmin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin';
$userOffice = isset($_SESSION['office_name']) ? $_SESSION['office_name'] : '';

if($isAdmin && isset($_POST['set_file_link'])){
    $documentId = intval($_POST['document_id'] ?? 0);
    $fileLink = trim($_POST['file_link'] ?? '');

    if($fileLink !== '' && !filter_var($fileLink, FILTER_VALIDATE_URL)){
        echo "<script>alert('Enter a valid link URL, or leave it blank to remove the link.'); window.location='repository.php';</script>";
        exit();
    }

    $docStmt = $conn->prepare("SELECT office, title FROM documents WHERE id = ? LIMIT 1");
    $docStmt->bind_param("i", $documentId);
    $docStmt->execute();
    $docRow = $docStmt->get_result()->fetch_assoc();

    if($docRow){
        $updateStmt = $conn->prepare("UPDATE documents SET file_link = ? WHERE id = ?");
        $updateStmt->bind_param("si", $fileLink, $documentId);
        $updateStmt->execute();

        $actionLabel = $fileLink !== '' ? 'document_link_updated' : 'document_link_removed';
        $description = $fileLink !== ''
            ? "Admin linked \"{$docRow['title']}\" ({$docRow['office']}) to a OneDrive URL"
            : "Admin removed the OneDrive link from \"{$docRow['title']}\" ({$docRow['office']})";
        log_audit_event($conn, $_SESSION['admin_username'], 'admin', $docRow['office'], $actionLabel, 'document', $documentId, $description);
    }

    header("Location: repository.php");
    exit();
}

$officeNames = [];
if($isAdmin){
    $officeResult = mysqli_query($conn, "SELECT DISTINCT office FROM documents WHERE office <> '' AND approval_status='Approved' ORDER BY office ASC");
    if($officeResult){
        while($officeRow = mysqli_fetch_assoc($officeResult)){
            $officeNames[] = $officeRow['office'];
        }
    }
    $documents = mysqli_query($conn, "SELECT * FROM documents WHERE approval_status='Approved' ORDER BY id DESC");
} else {
    $officeNames[] = $userOffice;
    $docStmt = $conn->prepare("SELECT * FROM documents WHERE office = ? AND approval_status='Approved' ORDER BY id DESC");
    $docStmt->bind_param("s", $userOffice);
    $docStmt->execute();
    $documents = $docStmt->get_result();
}
$documentRows = $documents ? $documents->fetch_all(MYSQLI_ASSOC) : [];
$totalDocs = count($documentRows);
$onedriveCount = count(array_filter($documentRows, function($row){ return !empty($row['file_link']); }));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Document Repository</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f6f9;color:#26354b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}
.nav-wrap{max-width:1240px;margin:auto;min-height:70px;padding:0 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;font-size:19px;font-weight:800}
.brand-icon{width:36px;height:36px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}
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
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-folder2-open"></i></span><span>Document Repository</span></div>
        <nav class="nav-links">
            <a href="<?php echo $isAdmin ? 'home.php' : 'office_dashboard.php'; ?>">Dashboard</a>
            <a href="repository.php">Repository</a>
            <?php render_profile_dropdown($isAdmin ? 'admin_profile.php' : 'office_profile.php', $isAdmin ? 'Admin Profile' : 'Office Profile'); ?>
        </nav>
    </div>
</header>
<main class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Repository</h1>
            <p class="page-subtitle">Search, view, and download approved document files.</p>
        </div>
        <div class="summary-pills">
            <span class="summary-pill"><i class="bi bi-files"></i> <?php echo $totalDocs; ?> file<?php echo $totalDocs === 1 ? '' : 's'; ?></span>
            <span class="summary-pill"><i class="bi bi-cloud-check"></i> <?php echo $onedriveCount; ?> linked to OneDrive</span>
        </div>
    </div>
    <section class="card">
        <div class="repo-controls">
            <input type="search" class="field-input" id="repositorySearch" placeholder="Search title, file, or office">
            <select class="field-select" id="repositoryOffice">
                <option value="">All offices</option>
                <?php foreach($officeNames as $officeName){ $safeOffice = htmlspecialchars($officeName, ENT_QUOTES); echo "<option value='{$safeOffice}'>{$safeOffice}</option>"; } ?>
            </select>
        </div>
        <p class="repo-summary"><span id="repositoryCount">0</span> file(s) shown</p>
        <div class="table-wrap">
            <table class="repo-table">
                <thead>
                    <tr>
                        <th>Office</th>
                        <th>Title / File</th>
                        <th>Storage</th>
                        <th style="width:<?php echo $isAdmin ? '260px' : '170px'; ?>">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if(!empty($documentRows)){
                        foreach($documentRows as $row){
                            $id = (int) $row['id'];
                            $office = htmlspecialchars($row['office'], ENT_QUOTES);
                            $title = htmlspecialchars($row['title'], ENT_QUOTES);
                            $fileName = htmlspecialchars($row['file_name'], ENT_QUOTES);
                            $hasLink = !empty($row['file_link']);
                            $safeFileLink = htmlspecialchars($row['file_link'] ?? '', ENT_QUOTES);
                            $viewUrl = "view_document.php?id={$id}";
                            $downloadUrl = "download_document.php?id={$id}";
                            $searchText = htmlspecialchars(strtolower($row['office'] . " " . $row['title'] . " " . $row['file_name']), ENT_QUOTES);

                            $storageBadge = $hasLink
                                ? "<span class='storage-badge storage-onedrive'><i class='bi bi-cloud-fill'></i> OneDrive Linked</span>"
                                : "<span class='storage-badge storage-local'><i class='bi bi-hdd-fill'></i> Local Upload</span>";

                            echo "<tr class='repository-row' data-office='{$office}' data-search='{$searchText}'>";
                            echo "<td>{$office}</td>";
                            echo "<td class='doc-title-cell'><strong>{$title}</strong><span>{$fileName}</span></td>";
                            echo "<td>{$storageBadge}</td>";
                            echo "<td><div class='action-inline'>";
                            echo "<a class='btn-xs btn-view' href='{$viewUrl}' target='_blank'><i class='bi bi-eye-fill'></i> View</a>";
                            echo "<a class='btn-xs btn-download' href='{$downloadUrl}'><i class='bi bi-download'></i> Download</a>";
                            if($isAdmin){
                                $linkBtnLabel = $hasLink ? "Edit Link" : "Link OneDrive";
                                echo "<button type='button' class='btn-xs btn-link' data-open-link-modal data-doc-id='{$id}' data-doc-title='{$title}' data-doc-link='{$safeFileLink}'><i class='bi bi-cloud-plus-fill'></i> {$linkBtnLabel}</button>";
                            }
                            echo "</div></td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='4' class='empty-state'>No approved document files found</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php if($isAdmin): ?>
<div class="modal-backdrop-custom" id="linkModalBackdrop">
    <div class="link-modal">
        <h3><i class="bi bi-cloud-fill"></i> Link to OneDrive</h3>
        <p class="muted-copy" id="linkModalDocTitle"></p>
        <form method="POST" id="linkModalForm">
            <input type="hidden" name="document_id" id="linkModalDocId" value="">
            <label for="linkModalUrl">OneDrive share URL</label>
            <input type="url" name="file_link" id="linkModalUrl" placeholder="https://onedrive.live.com/...">
            <div class="link-modal-actions">
                <button type="button" class="btn-xs btn-cancel" id="linkModalCancel">Cancel</button>
                <button type="submit" name="set_file_link" formaction="repository.php" class="btn-xs btn-remove" id="linkModalRemove" style="display:none">Remove Link</button>
                <button type="submit" name="set_file_link" formaction="repository.php" class="btn-xs btn-view">Save Link</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function(){
    var search = document.getElementById('repositorySearch');
    var office = document.getElementById('repositoryOffice');
    var count = document.getElementById('repositoryCount');
    var rows = [].slice.call(document.querySelectorAll('.repository-row'));

    function applyFilters(){
        var term = (search && search.value || '').trim().toLowerCase();
        var officeValue = office && office.value || '';
        var shown = 0;
        rows.forEach(function(row){
            var visible = (!term || row.getAttribute('data-search').indexOf(term) !== -1) && (!officeValue || row.getAttribute('data-office') === officeValue);
            row.style.display = visible ? '' : 'none';
            if(visible){ shown++; }
        });
        if(count){ count.textContent = shown; }
    }

    [search, office].forEach(function(input){
        if(input){
            input.addEventListener('input', applyFilters);
            input.addEventListener('change', applyFilters);
        }
    });
    applyFilters();
})();

(function(){
    var backdrop = document.getElementById('linkModalBackdrop');
    if(!backdrop){ return; }
    var docIdInput = document.getElementById('linkModalDocId');
    var docTitleEl = document.getElementById('linkModalDocTitle');
    var urlInput = document.getElementById('linkModalUrl');
    var removeBtn = document.getElementById('linkModalRemove');
    var cancelBtn = document.getElementById('linkModalCancel');

    function openModal(trigger){
        docIdInput.value = trigger.getAttribute('data-doc-id') || '';
        docTitleEl.textContent = trigger.getAttribute('data-doc-title') || '';
        var currentLink = trigger.getAttribute('data-doc-link') || '';
        urlInput.value = currentLink;
        removeBtn.style.display = currentLink ? '' : 'none';
        backdrop.classList.add('is-open');
        urlInput.focus();
    }

    function closeModal(){
        backdrop.classList.remove('is-open');
    }

    document.addEventListener('click', function(e){
        var trigger = e.target.closest('[data-open-link-modal]');
        if(trigger){ openModal(trigger); }
    });

    if(cancelBtn){ cancelBtn.addEventListener('click', closeModal); }
    backdrop.addEventListener('click', function(e){ if(e.target === backdrop){ closeModal(); } });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && backdrop.classList.contains('is-open')){ closeModal(); } });

    if(removeBtn){
        removeBtn.addEventListener('click', function(){
            urlInput.value = '';
        });
    }
})();
</script>
</body>
</html>
