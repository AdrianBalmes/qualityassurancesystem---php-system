<?php
session_start();
require_once __DIR__ . "/database.php";

if(!isset($_SESSION['admin_username']) && !isset($_SESSION['office_username'])){
    header("Location: index.php");
    exit();
}

$isAdmin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin';
$userOffice = isset($_SESSION['office_name']) ? $_SESSION['office_name'] : '';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Document Repository</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}.nav-wrap{max-width:1180px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}.nav-links{display:flex;gap:20px;flex-wrap:wrap}.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}.page{max-width:1180px;margin:26px auto 42px;padding:0 18px}.page-head{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:14px}.page-title{margin:0;font-size:26px;font-weight:800}.muted-copy{color:#66758d;font-size:13px;font-weight:700}.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}.panel-pad{padding:16px}.repo-controls{display:grid;grid-template-columns:minmax(220px,1.4fr) minmax(145px,1fr);gap:10px;margin-bottom:12px}.table-wrap{overflow-x:auto}.repository-table{min-width:640px}.repository-table th{background:#f1f5fb;color:#56637a;font-size:13px}.repository-table td{vertical-align:middle;font-weight:700;font-size:13px}.action-inline{display:flex;gap:6px;flex-wrap:wrap}.btn-xs{padding:4px 8px;font-size:12px;font-weight:800}.empty-state{padding:18px;text-align:center;color:#66758d;font-weight:800}.repo-summary{margin:0 0 10px;color:#66758d;font-size:13px;font-weight:800}@media(max-width:980px){.repo-controls{grid-template-columns:1fr 1fr}.nav-wrap,.page-head{flex-direction:column;align-items:flex-start;padding:14px 18px}}@media(max-width:680px){.brand{font-size:18px}.repo-controls{grid-template-columns:1fr}.page{padding:0 12px}}
</style>
</head>
<body>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-folder2-open"></i></span><span>Document Repository</span></div>
        <nav class="nav-links">
            <a href="<?php echo $isAdmin ? 'home.php' : 'office_dashboard.php'; ?>">Dashboard</a>
            <a href="repository.php">Repository</a>
            <a href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
        </nav>
    </div>
</header>
<main class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Repository</h1>
            <div class="muted-copy">Search, view, and download approved document files.</div>
        </div>
    </div>
    <section class="panel panel-pad">
        <div class="repo-controls">
            <input type="search" class="form-control" id="repositorySearch" placeholder="Search title, file, or office">
            <select class="form-select" id="repositoryOffice">
                <option value="">All offices</option>
                <?php foreach($officeNames as $officeName){ $safeOffice = htmlspecialchars($officeName, ENT_QUOTES); echo "<option value='{$safeOffice}'>{$safeOffice}</option>"; } ?>
            </select>
        </div>
        <p class="repo-summary"><span id="repositoryCount">0</span> file(s) shown.</p>
        <div class="table-wrap">
            <table class="table repository-table">
                <thead>
                    <tr>
                        <th>Office</th>
                        <th>Title / File</th>
                        <th>File</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if($documents && mysqli_num_rows($documents) > 0){
                        while($row = mysqli_fetch_assoc($documents)){
                            $id = (int) $row['id'];
                            $office = htmlspecialchars($row['office'], ENT_QUOTES);
                            $title = htmlspecialchars($row['title'], ENT_QUOTES);
                            $fileName = htmlspecialchars($row['file_name'], ENT_QUOTES);
                            $viewUrl = "view_document.php?id={$id}";
                            $safeViewUrl = htmlspecialchars($viewUrl, ENT_QUOTES);
                            $downloadUrl = "download_document.php?id={$id}";
                            $searchText = htmlspecialchars(strtolower($row['office'] . " " . $row['title'] . " " . $row['file_name']), ENT_QUOTES);

                            echo "<tr class='repository-row' data-office='{$office}' data-search='{$searchText}'><td>{$office}</td><td>{$title}<div class='text-muted small'>{$fileName}</div></td><td><div class='action-inline'><a class='btn btn-primary btn-xs' href='{$safeViewUrl}' target='_blank'>View</a><a class='btn btn-secondary btn-xs' href='{$downloadUrl}'>Download</a></div></td></tr>";
                        }
                    } else {
                        echo "<tr><td colspan='3' class='empty-state'>No approved document files found</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<script>
(function(){var search=document.getElementById('repositorySearch');var office=document.getElementById('repositoryOffice');var count=document.getElementById('repositoryCount');var rows=[].slice.call(document.querySelectorAll('.repository-row'));function applyFilters(){var term=(search&&search.value||'').trim().toLowerCase();var officeValue=office&&office.value||'';var shown=0;rows.forEach(function(row){var visible=(!term||row.getAttribute('data-search').indexOf(term)!==-1)&&(!officeValue||row.getAttribute('data-office')===officeValue);row.style.display=visible?'':'none';if(visible){shown++;}});if(count){count.textContent=shown;}}[search,office].forEach(function(input){if(input){input.addEventListener('input',applyFilters);input.addEventListener('change',applyFilters);}});applyFilters();})();
</script>
</body>
</html>
