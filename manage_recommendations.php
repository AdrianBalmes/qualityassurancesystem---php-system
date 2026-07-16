<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/office_directory.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] != "admin"){
    header("Location: admin_login.php");
    exit();
}

$selectedAudit = isset($_GET['audit']) ? trim($_GET['audit']) : 'External';
if(!in_array($selectedAudit, ['External', 'Internal'], true)){
    $selectedAudit = 'External';
}

$officeNames = get_all_office_names($conn);

$recStmt = $conn->prepare("SELECT * FROM audit_recommendations WHERE audit_type = ? ORDER BY id DESC");
$recStmt->bind_param("s", $selectedAudit);
$recStmt->execute();
$recommendations = $recStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Recommendations</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}
.nav-wrap{max-width:1300px;margin:auto;min-height:74px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}
.brand-icon{width:38px;height:38px;border-radius:8px;background:#fff;color:#316fc4;display:grid;place-items:center}
.nav-links{display:flex;gap:20px;flex-wrap:wrap}
.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}
.page{max-width:1300px;margin:26px auto 42px;padding:0 18px}
.page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px;flex-wrap:wrap}
.page-title{margin:0;font-size:25px;font-weight:800}
.muted-copy{color:#66758d;font-size:13px;font-weight:700}
.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12)}
.panel-pad{padding:16px}
.add-row-btn{min-height:38px;border:0;border-radius:5px;background:#316fc4;color:#fff;text-decoration:none;font-weight:800;display:inline-flex;align-items:center;gap:8px;padding:8px 16px}
.audit-switcher{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.audit-switch{min-height:34px;border-radius:5px;background:#fff;border:1px solid #c8d4e7;color:#2e67b8;text-decoration:none;font-size:13px;font-weight:800;padding:7px 12px;display:inline-flex;align-items:center}
.audit-switch.active{background:#316fc4;border-color:#316fc4;color:#fff}
.grid-wrap{overflow-x:auto;border:1px solid #dbe3ef;border-radius:6px}
.grid-table{width:100%;border-collapse:collapse;min-width:1100px}
.grid-table th{background:#f1f5fb;color:#56637a;font-size:13px;text-align:left;padding:10px;border-bottom:2px solid #dbe3ef;position:sticky;top:0}
.grid-table td{border-bottom:1px solid #e7edf6;border-right:1px solid #eef2f8;padding:0;vertical-align:top}
.cell-text{min-width:160px;padding:8px 10px;font-size:13px;font-weight:600;color:#344156;outline:none;min-height:38px}
.cell-text.rec-cell{min-width:320px}
.cell-text:focus{background:#eef4ff;box-shadow:inset 0 0 0 2px #316fc4}
.cell-select{width:100%;height:100%;border:0;background:transparent;padding:8px 10px;font-size:13px;font-weight:700}
.cell-select:focus{background:#eef4ff;outline:none}
.cell-year{width:100%;border:0;background:transparent;padding:8px 10px;font-size:13px;font-weight:700}
.cell-year:focus{background:#eef4ff;outline:none}
.row-delete{border:0;background:#ffe1dc;color:#a33831;border-radius:5px;width:32px;height:32px;font-weight:800;display:grid;place-items:center;margin:4px;flex-shrink:0}
.cell-readonly{padding:8px 10px;font-size:13px;font-weight:700;color:#344156}
.cell-select:disabled,.cell-year:disabled{opacity:1;color:#344156;background:transparent;border:0;-webkit-text-fill-color:#344156}
.row-actions{display:flex;gap:6px;padding:4px;align-items:center}
.row-edit-btn,.row-save-btn{border:0;border-radius:5px;padding:6px 10px;font-weight:800;font-size:12px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}
.row-edit-btn{background:#eef4ff;color:#2e67b8}
.row-save-btn{background:#277548;color:#fff;display:none}
tr[data-mode="edit"] .row-edit-btn{display:none}
tr[data-mode="edit"] .row-save-btn{display:inline-flex}
tr[data-mode="edit"] .cell-text{background:#eef4ff;box-shadow:inset 0 0 0 2px #316fc4}
.save-flash{display:inline-block;font-size:11px;font-weight:800;color:#277548;opacity:0;transition:opacity .3s}
.save-flash.show{opacity:1}
.empty-state{padding:18px;text-align:center;color:#66758d;font-weight:800}
.cell-text{word-break:break-word;overflow-wrap:anywhere}
.grid-wrap{-webkit-overflow-scrolling:touch}
@media(max-width:760px){
.page{padding:0 12px}
.page-title{font-size:20px}
.page-head{align-items:flex-start}
.add-row-btn{width:100%;justify-content:center}
}
@media(max-width:480px){
.page{margin:16px auto 28px}
.panel-pad{padding:10px}
.brand{font-size:16px}
.brand-icon{width:32px;height:32px;font-size:16px}
.nav-links{gap:10px;font-size:12px}
}
</style>
</head>
<body>
<header class="topbar">
    <div class="nav-wrap">
        <div class="brand"><span class="brand-icon"><i class="bi bi-table"></i></span><span>Manage Recommendations</span></div>
        <nav class="nav-links">
            <a href="home.php">Dashboard</a>
            <a href="repository.php">Repository</a>
            <a href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
        </nav>
    </div>
</header>
<main class="page">
    <div class="page-head">
        <div>
            <h1 class="page-title">Audit Recommendations Data Grid</h1>
            <div class="muted-copy">Add, edit, and delete recommendation records directly, like a spreadsheet. Changes save automatically. Audit Type is set automatically from Office (BED and College Department are External; every other office is Internal) and can't be edited directly.</div>
        </div>
        <button type="button" id="addRowBtn" class="add-row-btn"><i class="bi bi-plus-lg"></i> Add Row</button>
    </div>
    <div class="audit-switcher" aria-label="Pick dashboard">
        <a class="audit-switch<?php echo $selectedAudit === 'External' ? ' active' : ''; ?>" href="manage_recommendations.php?audit=External">External Audit</a>
        <a class="audit-switch<?php echo $selectedAudit === 'Internal' ? ' active' : ''; ?>" href="manage_recommendations.php?audit=Internal">Internal Audit</a>
    </div>
    <section class="panel panel-pad">
        <div class="grid-wrap">
            <table class="grid-table" id="recGrid">
                <thead>
                    <tr>
                        <th style="width:110px">Audit Type</th>
                        <th style="width:160px">Office</th>
                        <th>Recommendation</th>
                        <th style="width:140px">Status of Submission</th>
                        <th>Remarks</th>
                        <th style="width:90px">Year</th>
                        <th style="width:190px">Actions</th>
                    </tr>
                </thead>
                <tbody id="recGridBody">
                    <?php
                    if($recommendations && mysqli_num_rows($recommendations) > 0){
                        while($row = mysqli_fetch_assoc($recommendations)){
                            $id = (int) $row['id'];
                            $auditType = htmlspecialchars($row['audit_type'], ENT_QUOTES);
                            $recText = htmlspecialchars($row['recommendation'], ENT_QUOTES);
                            $year = htmlspecialchars($row['year'], ENT_QUOTES);
                            $status = htmlspecialchars($row['status'], ENT_QUOTES);
                            $remarks = htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES);
                            $statusOptions = "";
                            foreach(['Pending', 'Submitted', 'Not Submitted'] as $option){
                                $sel = $row['status'] === $option ? " selected" : "";
                                $statusOptions .= "<option value='{$option}'{$sel}>{$option}</option>";
                            }
                            $officeOptionsList = $officeNames;
                            if(!in_array($row['office'], $officeOptionsList, true)){
                                $officeOptionsList[] = $row['office'];
                            }
                            $officeOptions = "";
                            foreach($officeOptionsList as $officeOption){
                                $sel = $row['office'] === $officeOption ? " selected" : "";
                                $safeOfficeOption = htmlspecialchars($officeOption, ENT_QUOTES);
                                $officeOptions .= "<option value='{$safeOfficeOption}'{$sel}>{$safeOfficeOption}</option>";
                            }
                            echo "
                            <tr data-id='{$id}' data-mode='view'>
                                <td class='cell-readonly' data-audit-type-cell>{$auditType}</td>
                                <td><select class='cell-select' data-field='office' disabled>{$officeOptions}</select></td>
                                <td><div class='cell-text rec-cell' contenteditable='false' data-field='recommendation'>{$recText}</div></td>
                                <td><select class='cell-select' data-field='status' disabled>{$statusOptions}</select></td>
                                <td><div class='cell-text' contenteditable='false' data-field='remarks'>{$remarks}</div></td>
                                <td><input type='text' class='cell-year' maxlength='4' data-field='year' value='{$year}' placeholder='e.g. 2026' disabled></td>
                                <td>
                                    <div class='row-actions'>
                                        <button type='button' class='row-edit-btn' title='Edit row'><i class='bi bi-pencil'></i> Edit</button>
                                        <button type='button' class='row-save-btn' title='Save row'><i class='bi bi-check2'></i> Save</button>
                                        <button type='button' class='row-delete' title='Delete row'><i class='bi bi-trash3'></i></button>
                                    </div>
                                </td>
                            </tr>
                            ";
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <div id="gridEmptyState" class="empty-state" style="<?php echo ($recommendations && mysqli_num_rows($recommendations) > 0) ? 'display:none' : ''; ?>">No <?php echo htmlspecialchars($selectedAudit, ENT_QUOTES); ?> Audit recommendations yet. Click "Add Row" to create one.</div>
    </section>
</main>
<script>
(function(){
    var body = document.getElementById('recGridBody');
    var emptyState = document.getElementById('gridEmptyState');
    var addRowBtn = document.getElementById('addRowBtn');

    function toggleEmptyState(){
        emptyState.style.display = body.children.length > 0 ? 'none' : '';
    }

    var selectedAudit = <?php echo json_encode($selectedAudit); ?>;
    var officeNames = <?php echo json_encode($officeNames); ?>;

    function rowActionsHtml(){
        return "<div class='row-actions'>" +
            "<button type='button' class='row-edit-btn' title='Edit row'><i class='bi bi-pencil'></i> Edit</button>" +
            "<button type='button' class='row-save-btn' title='Save row'><i class='bi bi-check2'></i> Save</button>" +
            "<button type='button' class='row-delete' title='Delete row'><i class='bi bi-trash3'></i></button>" +
        "</div>";
    }

    function officeOptionsHtml(selectedOffice){
        var html = "";
        var list = officeNames.slice();
        if(selectedOffice && list.indexOf(selectedOffice) === -1){ list.push(selectedOffice); }
        list.forEach(function(name){
            var sel = name === selectedOffice ? ' selected' : '';
            html += "<option value=\"" + name.replace(/"/g, '&quot;') + "\"" + sel + ">" + name + "</option>";
        });
        return html;
    }

    function buildRow(id, auditType, office){
        var tr = document.createElement('tr');
        tr.setAttribute('data-id', id);
        tr.setAttribute('data-mode', 'edit');
        tr.innerHTML =
            "<td class='cell-readonly' data-audit-type-cell>" + auditType + "</td>" +
            "<td><select class='cell-select' data-field='office'>" + officeOptionsHtml(office || '') + "</select></td>" +
            "<td><div class='cell-text rec-cell' contenteditable='true' data-field='recommendation'></div></td>" +
            "<td><select class='cell-select' data-field='status'>" +
                "<option value='Pending' selected>Pending</option>" +
                "<option value='Submitted'>Submitted</option>" +
                "<option value='Not Submitted'>Not Submitted</option>" +
            "</select></td>" +
            "<td><div class='cell-text' contenteditable='true' data-field='remarks'></div></td>" +
            "<td><input type='text' class='cell-year' maxlength='4' data-field='year' value='' placeholder='e.g. 2026'></td>" +
            "<td>" + rowActionsHtml() + "</td>";
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
            if(el.classList.contains('cell-text')){
                values[field] = el.innerText.replace(/\n+$/, '');
            } else {
                values[field] = el.value;
            }
        });
        return values;
    }

    body.addEventListener('click', function(e){
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
            fetch('recommendations_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=save_row&id=' + encodeURIComponent(id) +
                    '&office=' + encodeURIComponent(values.office || '') +
                    '&recommendation=' + encodeURIComponent(values.recommendation || '') +
                    '&status=' + encodeURIComponent(values.status || '') +
                    '&remarks=' + encodeURIComponent(values.remarks || '') +
                    '&year=' + encodeURIComponent(values.year || '')
            }).then(function(res){ return res.json(); }).then(function(data){
                saveBtn.disabled = false;
                if(!data.ok){
                    alert(data.error || 'Could not save this row.');
                    return;
                }
                if(data.audit_type && data.audit_type !== selectedAudit){
                    trSave.remove();
                    toggleEmptyState();
                    return;
                }
                var auditCell = trSave.querySelector('[data-audit-type-cell]');
                if(auditCell){ auditCell.textContent = data.audit_type; }
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
                toggleEmptyState();
            });
        }
    });

    addRowBtn.addEventListener('click', function(){
        fetch('recommendations_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=add&preferred_audit=' + encodeURIComponent(selectedAudit)
        }).then(function(res){ return res.json(); }).then(function(data){
            if(!data.ok){ return; }
            var tr = buildRow(data.id, data.audit_type || 'Internal', data.office || '');
            body.insertBefore(tr, body.firstChild);
            toggleEmptyState();
            var firstCell = tr.querySelector('.cell-text');
            if(firstCell){ firstCell.focus(); }
        });
    });

    toggleEmptyState();
})();
</script>
</body>
</html>
