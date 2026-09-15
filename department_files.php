<?php
require_once __DIR__ . "/session_bootstrap.php";
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/nav_dropdown.php";
require_once __DIR__ . "/upload_access.php";

/**
 * The files inside one department's folder in the repository.
 *
 * Lists the supporting documents that department uploaded with its compliance
 * updates (recommendation_documents). Reached from the folder grid on
 * repository.php.
 */

if(!isset($_SESSION['admin_username']) && !isset($_SESSION['office_username'])){
    header("Location: index.php");
    exit();
}

ensure_user_account_columns($conn);
enforce_active_account($conn);

$isAdmin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin';
$office = trim($_GET['office'] ?? '');

if($office === ''){
    header("Location: repository.php");
    exit();
}

// An admin opens any department's folder; an office only its own. Same rule
// serve_upload.php applies to each file, so the list never offers a file the
// viewer would then be refused.
if(!upload_viewer_can_see_office($office)){
    http_response_code(403);
    exit("You are not allowed to view this department's files.");
}

// LEFT JOIN: a document outlives the recommendation it was filed against, and
// should still be listed rather than vanishing from the folder.
$filesStmt = $conn->prepare("SELECT d.id, d.file_name, d.original_name, d.uploaded_at,
                                    r.id AS rec_id, r.recommendation, r.year, r.status
                             FROM recommendation_documents d
                             LEFT JOIN audit_recommendations r ON r.id = d.recommendation_id
                             WHERE d.office = ?
                             ORDER BY d.uploaded_at DESC, d.id DESC");
$filesStmt->bind_param("s", $office);
$filesStmt->execute();
$files = $filesStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalFiles = count($files);
$latestUpload = $totalFiles > 0 ? $files[0]['uploaded_at'] : '';
$safeOfficeName = htmlspecialchars($office, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $safeOfficeName; ?> Files</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f6f9;color:#26354b;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}
.nav-wrap{max-width:1240px;margin:auto;min-height:70px;padding:0 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:12px;font-size:19px;font-weight:800}
.brand-icon{width:64px;height:64px;display:grid;place-items:center;flex-shrink:0}
.nav-links{display:flex;gap:18px;flex-wrap:wrap}
.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700;font-size:14px}
.nav-links a:hover{text-decoration:underline}
.page{max-width:1240px;margin:0 auto;padding:36px 20px 56px}
.crumbs{display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;color:#8794a8;margin-bottom:12px}
.crumbs a{color:#316fc4;text-decoration:none}
.crumbs a:hover{text-decoration:underline}
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.page-title{margin:0 0 5px;font-size:26px;font-weight:800;color:#1c2b3f;letter-spacing:-.2px;display:flex;align-items:center;gap:10px}
.page-title i{color:#f0b429}
.page-subtitle{margin:0;font-size:14px;color:#66758d;font-weight:600}
.summary-pills{display:flex;gap:10px;flex-wrap:wrap}
.summary-pill{background:#fff;border:1px solid #e3e8f0;border-radius:999px;padding:8px 16px;font-size:12.5px;font-weight:800;color:#4c5a72;display:inline-flex;align-items:center;gap:7px;box-shadow:0 2px 6px rgba(15,26,42,.04)}
.summary-pill i{color:#316fc4}
.card{background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(15,26,42,.06);padding:22px 24px}
.field-input{width:100%;max-width:320px;border:1px solid #dbe1ea;border-radius:9px;padding:11px 14px;font-size:14px;font-family:inherit;color:#26354b;background:#fff;transition:border-color .15s ease,box-shadow .15s ease}
.field-input:focus{outline:none;border-color:#316fc4;box-shadow:0 0 0 3px rgba(49,111,196,.14)}
.repo-summary{margin:14px 0;color:#8794a8;font-size:12.5px;font-weight:700}
.table-wrap{overflow-x:auto;border:1px solid #eef1f6;border-radius:10px}
.repo-table{width:100%;border-collapse:collapse;min-width:880px}
.repo-table td.nowrap{white-space:nowrap}
.repo-table th{background:#f8fafc;color:#66758d;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;text-align:left;padding:12px 14px;border-bottom:1px solid #eef1f6;white-space:nowrap}
.repo-table td{padding:12px 14px;font-size:13.5px;font-weight:600;color:#344156;border-bottom:1px solid #f1f4f8;vertical-align:middle}
.repo-table tr:last-child td{border-bottom:0}
.file-cell{display:flex;align-items:center;gap:10px}
.file-icon{font-size:20px;color:#4c5a72;flex-shrink:0}
.file-cell strong{display:block;color:#1c2b3f;font-weight:800;word-break:break-word}
.file-cell span{display:block;color:#8794a8;font-size:12px;font-weight:600;margin-top:2px}
.rec-cell{max-width:420px;color:#4c5a72}
.chip{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap}
.chip-steel{background:#eef1f6;color:#4c5a72}
.chip-yellow{background:#fdf3d8;color:#8a6100}
.chip-blue{background:#e3edfb;color:#1f5fbf}
.chip-green{background:#e2f4e8;color:#1d7a43}
.chip-orange{background:#fdeadd;color:#a1541f}
.chip-red{background:#fdeceb;color:#a33831}
.action-inline{display:flex;gap:6px;flex-wrap:wrap}
.btn-xs{border:0;border-radius:6px;font-weight:800;font-size:12px;padding:6px 11px;display:inline-flex;align-items:center;gap:5px;cursor:pointer;text-decoration:none}
.btn-view{background:#316fc4;color:#fff}
.btn-view:hover{background:#2459a6;color:#fff}
.btn-download{background:#eef1f6;color:#4c5a72}
.btn-download:hover{background:#e3e8f0}
.btn-back{background:#fff;border:1px solid #dbe1ea;color:#4c5a72}
.btn-back:hover{background:#f4f6f9}
.empty-state{padding:34px 18px;text-align:center;color:#8794a8;font-weight:700;font-size:13.5px}
.empty-state i{display:block;font-size:30px;color:#cbd3de;margin-bottom:8px}
@media(max-width:980px){.nav-wrap,.page-head{flex-direction:column;align-items:flex-start}}
@media(max-width:680px){.brand{font-size:17px}.page{padding:24px 14px 40px}.card{padding:16px}}
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
    <div class="crumbs">
        <a href="repository.php"><i class="bi bi-arrow-left"></i> Repository</a>
        <span>/</span>
        <span><?php echo $safeOfficeName; ?></span>
    </div>
    <div class="page-head">
        <div>
            <h1 class="page-title"><i class="bi bi-folder2-open"></i> <?php echo $safeOfficeName; ?></h1>
            <p class="page-subtitle">Supporting documents uploaded with this department's compliance updates.</p>
        </div>
        <div class="summary-pills">
            <span class="summary-pill"><i class="bi bi-files"></i> <?php echo $totalFiles; ?> file<?php echo $totalFiles === 1 ? '' : 's'; ?></span>
            <?php if($latestUpload !== ''): ?>
            <span class="summary-pill"><i class="bi bi-clock-history"></i> Last upload <?php echo htmlspecialchars(date("M j, Y", strtotime($latestUpload)), ENT_QUOTES); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <section class="card">
        <input type="search" class="field-input" id="fileSearch" placeholder="Search file or recommendation">
        <p class="repo-summary"><span id="fileCount"><?php echo $totalFiles; ?></span> file(s) shown</p>
        <div class="table-wrap">
            <table class="repo-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Recommendation</th>
                        <th style="width:80px">Year</th>
                        <th style="width:170px">Uploaded</th>
                        <th style="width:210px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if(!empty($files)){
                        $statusChips = [
                            'Pending' => 'chip-steel', 'Not Submitted' => 'chip-red', 'Submitted' => 'chip-yellow',
                            'Approved' => 'chip-blue', 'Needs Revision' => 'chip-orange', 'Rejected' => 'chip-red',
                            'Completed' => 'chip-green',
                        ];
                        foreach($files as $file){
                            $displayName = $file['original_name'] !== '' ? $file['original_name'] : $file['file_name'];
                            $safeDisplayName = htmlspecialchars($displayName, ENT_QUOTES);
                            $safeStoredName = htmlspecialchars($file['file_name'], ENT_QUOTES);
                            // uploads/ is not public; files are streamed after an access check.
                            $fileUrl = "serve_upload.php?id=" . (int) $file['id'];
                            $extension = strtoupper(pathinfo($file['file_name'], PATHINFO_EXTENSION));

                            $recText = trim((string) ($file['recommendation'] ?? ''));
                            $recDisplay = $recText !== ''
                                ? htmlspecialchars(mb_strimwidth($recText, 0, 160, '...'), ENT_QUOTES)
                                : "<span style='color:#a7b0be'>Recommendation was deleted</span>";
                            $year = $file['year'] !== null && $file['year'] !== '' ? htmlspecialchars($file['year'], ENT_QUOTES) : '&mdash;';
                            $uploaded = $file['uploaded_at'] !== null && $file['uploaded_at'] !== ''
                                ? htmlspecialchars(date("M j, Y g:i A", strtotime($file['uploaded_at'])), ENT_QUOTES)
                                : '&mdash;';

                            $status = (string) ($file['status'] ?? '');
                            $statusChip = $status !== ''
                                ? "<span class='chip " . ($statusChips[$status] ?? 'chip-steel') . "'>" . htmlspecialchars($status, ENT_QUOTES) . "</span>"
                                : '';

                            $searchText = htmlspecialchars(strtolower($displayName . " " . $recText), ENT_QUOTES);

                            echo "<tr class='file-row' data-search='{$searchText}'>";
                            echo "<td><div class='file-cell'><i class='bi bi-file-earmark-text file-icon'></i><span><strong>{$safeDisplayName}</strong><span>{$extension} &middot; {$safeStoredName}</span></span></div></td>";
                            echo "<td class='rec-cell'>{$recDisplay} " . ($statusChip !== '' ? "<div style='margin-top:6px'>{$statusChip}</div>" : '') . "</td>";
                            echo "<td>{$year}</td>";
                            echo "<td class='nowrap'>{$uploaded}</td>";
                            echo "<td><div class='action-inline'>";
                            echo "<a class='btn-xs btn-view' href='{$fileUrl}' target='_blank' rel='noopener'><i class='bi bi-eye-fill'></i> View</a>";
                            echo "<a class='btn-xs btn-download' href='{$fileUrl}&amp;download=1'><i class='bi bi-download'></i> Download</a>";
                            echo "</div></td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='5' class='empty-state'><i class='bi bi-folder2'></i>This department has not uploaded any files yet</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px">
            <a class="btn-xs btn-back" href="repository.php"><i class="bi bi-arrow-left"></i> Back to Repository</a>
        </div>
    </section>
</main>

<script>
(function(){
    var search = document.getElementById('fileSearch');
    var countEl = document.getElementById('fileCount');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.file-row'));
    if(!search || !rows.length){ return; }

    search.addEventListener('input', function(){
        var term = search.value.trim().toLowerCase();
        var shown = 0;
        rows.forEach(function(row){
            var match = term === '' || (row.getAttribute('data-search') || '').indexOf(term) !== -1;
            row.style.display = match ? '' : 'none';
            if(match){ shown++; }
        });
        if(countEl){ countEl.textContent = shown; }
    });
})();
</script>
</body>
</html>
