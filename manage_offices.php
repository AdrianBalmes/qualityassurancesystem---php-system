<?php
session_start();
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/page_background.php";
require_once __DIR__ . "/content_helper.php";
require_once __DIR__ . "/user_columns.php";
require_once __DIR__ . "/office_directory.php";
require_once __DIR__ . "/audit_classification.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/nav_dropdown.php";

if(!isset($_SESSION['admin_username']) || $_SESSION['admin_role'] !== 'admin'){
    header("Location: admin_login.php");
    exit();
}

ensure_user_account_columns($conn);
enforce_active_account($conn);
ensure_offices_table($conn);
$siteContent = sc_load($conn);
$adminUsername = $_SESSION['admin_username'];

/**
 * Post/redirect/get: office changes alter what audit_type_for_office() caches,
 * so finish the request and come back clean rather than rendering stale names.
 */
function offices_redirect($message, $type = 'success'){
    $_SESSION['offices_notice'] = ['text' => $message, 'type' => $type];
    header("Location: manage_offices.php");
    exit();
}

$auditTypes = ['Internal', 'External'];

if(isset($_POST['add_office'])){
    $name = trim($_POST['name'] ?? '');
    $auditType = in_array($_POST['audit_type'] ?? '', $auditTypes, true) ? $_POST['audit_type'] : 'Internal';

    if($name === ''){
        offices_redirect("Enter an office name.", 'danger');
    }
    if(strcasecmp($name, 'Admin') === 0){
        offices_redirect("\"Admin\" is reserved for administrator accounts.", 'danger');
    }

    $exists = $conn->prepare("SELECT id FROM offices WHERE name = ? LIMIT 1");
    $exists->bind_param("s", $name);
    $exists->execute();
    if($exists->get_result()->num_rows > 0){
        offices_redirect("\"{$name}\" already exists.", 'danger');
    }

    $insert = $conn->prepare("INSERT INTO offices (name, audit_type, created_by) VALUES (?,?,?)");
    $insert->bind_param("sss", $name, $auditType, $adminUsername);
    $insert->execute();

    log_audit_event($conn, $adminUsername, 'admin', $name, 'office_created', 'office', $conn->insert_id,
        "Admin created the {$auditType} office \"{$name}\"");
    offices_redirect("Added {$name}.");
}

if(isset($_POST['update_office'])){
    $officeId = intval($_POST['office_id'] ?? 0);
    $newName = trim($_POST['name'] ?? '');
    $newType = in_array($_POST['audit_type'] ?? '', $auditTypes, true) ? $_POST['audit_type'] : 'Internal';

    $lookup = $conn->prepare("SELECT name, audit_type FROM offices WHERE id = ? LIMIT 1");
    $lookup->bind_param("i", $officeId);
    $lookup->execute();
    $office = $lookup->get_result()->fetch_assoc();

    if(!$office){
        offices_redirect("That office no longer exists.", 'danger');
    }
    if($newName === ''){
        offices_redirect("Office name cannot be empty.", 'danger');
    }

    if($newName !== $office['name']){
        $clash = $conn->prepare("SELECT id FROM offices WHERE name = ? AND id <> ? LIMIT 1");
        $clash->bind_param("si", $newName, $officeId);
        $clash->execute();
        if($clash->get_result()->num_rows > 0){
            offices_redirect("\"{$newName}\" already exists.", 'danger');
        }
    }

    // The office name is stored as text on every related row, so a rename has
    // to carry them along or their records are orphaned. All or nothing.
    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE offices SET name = ?, audit_type = ? WHERE id = ?");
        $update->bind_param("ssi", $newName, $newType, $officeId);
        $update->execute();

        $renamed = 0;
        if($newName !== $office['name']){
            foreach(['users', 'audit_recommendations', 'recommendation_documents'] as $table){
                $cascade = $conn->prepare("UPDATE `{$table}` SET office = ? WHERE office = ?");
                $cascade->bind_param("ss", $newName, $office['name']);
                $cascade->execute();
                $renamed += $conn->affected_rows;
            }
        }

        // A recommendation carries its own audit_type. Left alone after a type
        // change it would sit under a tab whose office tiles no longer list it.
        $retyped = 0;
        if($newType !== $office['audit_type']){
            $retype = $conn->prepare("UPDATE audit_recommendations SET audit_type = ? WHERE office = ?");
            $retype->bind_param("ss", $newType, $newName);
            $retype->execute();
            $retyped = $conn->affected_rows;
        }

        $conn->commit();
    } catch(Throwable $e){
        $conn->rollback();
        offices_redirect("Could not update that office: " . $e->getMessage(), 'danger');
    }

    $detail = [];
    if($newName !== $office['name']){ $detail[] = "renamed from \"{$office['name']}\" ({$renamed} linked record(s) updated)"; }
    if($newType !== $office['audit_type']){ $detail[] = "moved to {$newType} audit ({$retyped} recommendation(s) updated)"; }
    $summary = !empty($detail) ? implode('; ', $detail) : 'no changes';

    log_audit_event($conn, $adminUsername, 'admin', $newName, 'office_updated', 'office', $officeId,
        "Admin updated office \"{$newName}\": {$summary}");
    offices_redirect("Saved {$newName} — {$summary}.");
}

if(isset($_POST['delete_office'])){
    $officeId = intval($_POST['office_id'] ?? 0);

    $lookup = $conn->prepare("SELECT name FROM offices WHERE id = ? LIMIT 1");
    $lookup->bind_param("i", $officeId);
    $lookup->execute();
    $office = $lookup->get_result()->fetch_assoc();

    if(!$office){
        offices_redirect("That office no longer exists.", 'danger');
    }

    // Refuse rather than cascade: deleting an office with records would strand
    // accounts and recommendations with nowhere to belong.
    $usage = office_usage($conn, $office['name']);
    if($usage['users'] > 0 || $usage['recommendations'] > 0){
        offices_redirect(
            "Cannot delete {$office['name']}: {$usage['users']} account(s) and {$usage['recommendations']} recommendation(s) still belong to it. Move or remove those first.",
            'danger'
        );
    }

    $delete = $conn->prepare("DELETE FROM offices WHERE id = ?");
    $delete->bind_param("i", $officeId);
    $delete->execute();

    log_audit_event($conn, $adminUsername, 'admin', $office['name'], 'office_deleted', 'office', $officeId,
        "Admin deleted the office \"{$office['name']}\"");
    offices_redirect("Deleted {$office['name']}.", 'warning');
}

$notice = $_SESSION['offices_notice'] ?? null;
unset($_SESSION['offices_notice']);

$offices = get_office_rows($conn);
$usageByName = [];
foreach($offices as $row){
    $usageByName[$row['name']] = office_usage($conn, $row['name']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Offices</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{margin:0;background:#eef3fb;color:#344156;font-family:Arial,Helvetica,sans-serif}
.topbar{background:linear-gradient(135deg,#316fc4,#2459a6);color:#fff;box-shadow:0 8px 20px rgba(44,93,165,.2)}
.nav-wrap{max-width:1680px;margin:auto;min-height:74px;padding:0 clamp(14px,2vw,32px);display:flex;align-items:center;justify-content:space-between;gap:18px}
.brand{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:800}
.brand-icon{width:64px;height:64px;display:grid;place-items:center;flex-shrink:0}
.nav-links{display:flex;gap:20px;flex-wrap:wrap;align-items:center}
.nav-links a{color:#eef4ff;text-decoration:none;font-weight:700}
.page{max-width:1120px;margin:26px auto 42px;padding:0 clamp(14px,2vw,32px)}
.page-title{margin:0 0 4px;font-size:26px;font-weight:800}
.muted-copy{color:#66758d;font-size:13px;font-weight:600}
.panel{background:#fff;border:1px solid #dbe3ef;border-radius:8px;box-shadow:0 5px 16px rgba(44,74,119,.12);margin-top:18px}
.panel-pad{padding:18px}
.panel-title{margin:0 0 14px;padding-bottom:10px;border-bottom:1px solid #eef1f6;font-size:16px;font-weight:800;display:flex;align-items:center;gap:8px}
.add-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.add-field{display:flex;flex-direction:column;gap:4px}
.add-field label{font-size:11.5px;font-weight:800;color:#66758d;text-transform:uppercase}
.add-field input,.add-field select,.cell-input,.cell-select{min-height:38px;border:1px solid #cfd9e8;border-radius:5px;padding:7px 10px;font-size:13.5px;font-family:inherit}
.btn-add{min-height:38px;border:0;border-radius:5px;background:#2fa66a;color:#fff;font-weight:800;padding:9px 16px;display:inline-flex;align-items:center;gap:7px;cursor:pointer}
.btn-add:hover{background:#268a58}
.table-wrap{overflow-x:auto}
.office-table{width:100%;border-collapse:collapse;min-width:820px}
.office-table th{background:#f1f5fb;color:#56637a;font-size:12px;text-align:left;padding:11px 12px;border-bottom:2px solid #dbe3ef;text-transform:uppercase;letter-spacing:.3px}
.office-table td{border-bottom:1px solid #e7edf6;padding:9px 12px;font-size:13.5px;vertical-align:middle}
.cell-input{width:100%;min-width:180px}
.usage{color:#8794a8;font-size:12px;font-weight:700;white-space:nowrap}
.row-actions{display:flex;gap:6px}
.btn-save,.btn-del{border:0;border-radius:5px;font-weight:800;font-size:12px;padding:7px 11px;display:inline-flex;align-items:center;gap:5px;cursor:pointer}
.btn-save{background:#316fc4;color:#fff}
.btn-save:hover{background:#2459a6}
.btn-del{background:#ffe1dc;color:#a33831}
.btn-del:hover{background:#ffcac1}
.btn-del[disabled]{background:#f1f3f7;color:#b3bccb;cursor:not-allowed}
.empty-state{padding:30px;text-align:center;color:#8794a8;font-weight:700}
</style>
</head>
<body>
<?php render_page_background(); ?>
<header class="topbar"><div class="nav-wrap"><div class="brand"><span class="brand-icon"><img src="assets/sbc-logo.png" alt="St. Bridget College" style="width:100%;height:100%;object-fit:contain"></span><span>Offices</span></div><nav class="nav-links"><a href="home.php">Home</a><a href="repository.php">Repository</a><a href="activity_log.php">Activity Log</a><a href="manage_users.php">Users</a><a href="manage_offices.php">Offices</a><?php render_profile_dropdown('admin_profile.php', 'Admin Profile'); ?></nav></div></header>

<main class="page">
    <h1 class="page-title">Offices</h1>
    <div class="muted-copy">Departments people can register under, and which audit each falls beneath.</div>

    <?php if($notice): ?>
        <div class="alert alert-<?php echo htmlspecialchars($notice['type'], ENT_QUOTES); ?> mt-3 mb-0">
            <?php echo htmlspecialchars($notice['text'], ENT_QUOTES); ?>
        </div>
    <?php endif; ?>

    <section class="panel panel-pad">
        <h2 class="panel-title"><i class="bi bi-plus-circle"></i> Add an office</h2>
        <form method="POST" class="add-row">
            <div class="add-field" style="flex:1 1 260px">
                <label for="newName">Office name</label>
                <input type="text" id="newName" name="name" maxlength="100" placeholder="e.g. Research Office" required>
            </div>
            <div class="add-field">
                <label for="newType">Audit type</label>
                <select id="newType" name="audit_type">
                    <option value="Internal">Internal</option>
                    <option value="External">External</option>
                </select>
            </div>
            <button type="submit" name="add_office" class="btn-add"><i class="bi bi-plus-lg"></i> Add Office</button>
        </form>
    </section>

    <section class="panel panel-pad">
        <h2 class="panel-title"><i class="bi bi-building"></i> All offices <span class="muted-copy">(<?php echo count($offices); ?>)</span></h2>
        <div class="table-wrap">
            <table class="office-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th style="width:150px">Audit type</th>
                        <th style="width:190px">In use by</th>
                        <th style="width:180px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($offices)): ?>
                    <tr><td colspan="4" class="empty-state">No offices yet. Add one above.</td></tr>
                <?php else: foreach($offices as $row):
                    $usage = $usageByName[$row['name']];
                    $inUse = $usage['users'] > 0 || $usage['recommendations'] > 0;
                ?>
                    <?php $formId = "office-" . (int) $row['id']; ?>
                    <tr>
                        <td><input type="text" class="cell-input" form="<?php echo $formId; ?>" name="name" maxlength="100" value="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>" required></td>
                        <td>
                            <select class="cell-select" form="<?php echo $formId; ?>" name="audit_type">
                                <option value="Internal"<?php echo $row['audit_type'] !== 'External' ? ' selected' : ''; ?>>Internal</option>
                                <option value="External"<?php echo $row['audit_type'] === 'External' ? ' selected' : ''; ?>>External</option>
                            </select>
                        </td>
                        <td class="usage">
                            <?php echo (int) $usage['users']; ?> account<?php echo $usage['users'] === 1 ? '' : 's'; ?>,
                            <?php echo (int) $usage['recommendations']; ?> rec<?php echo $usage['recommendations'] === 1 ? '' : 's'; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="submit" form="<?php echo $formId; ?>" name="update_office" class="btn-save"><i class="bi bi-check2"></i> Save</button>
                                <button type="submit" form="<?php echo $formId; ?>" name="delete_office" class="btn-del"
                                    <?php echo $inUse ? 'disabled title="Still in use"' : 'onclick="return confirm(\'Delete this office?\');"'; ?>>
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php // A <form> cannot live inside <tr>; browsers hoist it out of the
                  // table. The rows point at these by id instead.
            foreach($offices as $row): ?>
                <form id="office-<?php echo (int) $row['id']; ?>" method="POST">
                    <input type="hidden" name="office_id" value="<?php echo (int) $row['id']; ?>">
                </form>
            <?php endforeach; ?>
        </div>
        <div class="muted-copy mt-3">
            Renaming an office updates every account and recommendation attached to it.
            An office still in use cannot be deleted.
        </div>
    </section>
</main>
</body>
</html>
