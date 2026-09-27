<?php

require_once __DIR__ . "/user_columns.php";

/**
 * Who may do what.
 *
 * Two different questions hide behind the old `$_SESSION['admin_role'] ===
 * 'admin'` check, and keeping them apart is the whole point of this file:
 *
 *   scope       which offices' data you can see. Both QA roles see every
 *               office, so session_is_qa_staff() answers this and the
 *               repository, document and viewer checks keep using it.
 *   permission  what you may actually do. current_user_can() answers this,
 *               from the role's row in the database.
 *
 * The catalogue below lives in code because each key corresponds to a real
 * gate in a page; which roles hold them lives in the database so the QA Head
 * can change it without a code edit.
 */

/** Grouped for the Roles screen; the group is only a heading. */
const PERMISSIONS = [
    'recommendations.manage'   => ['group' => 'Recommendations', 'label' => 'Manage recommendations',   'help' => 'Open the dashboard grid, add rows, edit and save them.'],
    'recommendations.review'   => ['group' => 'Recommendations', 'label' => 'Review submissions',       'help' => 'Approve, reject and ask for revision on what an office submits.'],
    'recommendations.complete' => ['group' => 'Recommendations', 'label' => 'Mark Completed',           'help' => 'Close a recommendation. The office can no longer change it afterwards.'],
    'recommendations.delete'   => ['group' => 'Recommendations', 'label' => 'Delete recommendations',   'help' => 'Remove a recommendation and every document submitted against it.'],

    'documents.delete'         => ['group' => 'Documents',       'label' => 'Delete submitted documents', 'help' => "Remove a file an office submitted."],
    'repository.manage'        => ['group' => 'Documents',       'label' => 'Manage the repository',      'help' => 'Approve and remove files in the document repository.'],

    'users.manage'             => ['group' => 'Administration',  'label' => 'Manage accounts',          'help' => 'Approve and reject registrations, and set a QA account\'s role.'],
    'activity_log.view'        => ['group' => 'Administration',  'label' => 'View the Activity Log',    'help' => 'Read the system-wide log of who did what.'],

    // Head-only. See HEAD_ONLY_PERMISSIONS.
    'offices.manage'           => ['group' => 'Reserved',        'label' => 'Add, rename, delete offices', 'help' => 'A rename cascades across users, recommendations and documents.'],
    'roles.manage'             => ['group' => 'Reserved',        'label' => 'Edit roles and permissions',  'help' => 'Change what each role may do.'],
    'users.assign_head'        => ['group' => 'Reserved',        'label' => 'Grant the QA Head role',      'help' => 'Promote an account to QA Head.'],
];

/**
 * Never grantable to anything but the QA Head, whatever the Roles screen is
 * asked to save.
 *
 * Without this the one restriction the QA Head chose would be worth nothing:
 * an officer who can edit permissions simply ticks offices.manage for their
 * own role, and an officer who can hand out the Head role promotes themselves.
 */
const HEAD_ONLY_PERMISSIONS = ['offices.manage', 'roles.manage', 'users.assign_head'];

const ROLE_QA_HEAD    = 'qa_head';
const ROLE_QA_OFFICER = 'qa_officer';
const ROLE_OFFICE     = 'office';

/** The roles that come with the system and cannot be deleted. */
const ROLE_SEED = [
    ROLE_QA_HEAD    => 'QA Head',
    ROLE_QA_OFFICER => 'QA Officer',
    ROLE_OFFICE     => 'Office',
];

function ensure_roles_tables($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS roles (
        id int(11) NOT NULL AUTO_INCREMENT,
        slug varchar(40) NOT NULL,
        name varchar(80) NOT NULL,
        is_system tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS role_permissions (
        role_slug varchar(40) NOT NULL,
        permission varchar(60) NOT NULL,
        PRIMARY KEY (role_slug, permission)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // The precise role. `role` keeps its old job of saying which sign-in
    // scope an account belongs to -- enforce_active_account() asserts on it --
    // so this is added beside it rather than replacing it.
    user_col($conn, 'role_slug', "varchar(40) NOT NULL DEFAULT ''");
    mysqli_query($conn, "UPDATE users SET role_slug = '" . ROLE_QA_HEAD . "' WHERE role_slug = '' AND role = 'admin'");
    mysqli_query($conn, "UPDATE users SET role_slug = '" . ROLE_OFFICE . "' WHERE role_slug = '' AND role <> 'admin'");

    $existing = mysqli_query($conn, "SELECT COUNT(*) AS total FROM roles");
    $row = $existing ? $existing->fetch_assoc() : null;
    if($row && (int) $row['total'] > 0){
        return;
    }

    // First run only. Afterwards the Roles screen owns these rows.
    $insertRole = $conn->prepare("INSERT IGNORE INTO roles (slug, name, is_system) VALUES (?,?,1)");
    foreach(ROLE_SEED as $slug => $name){
        $insertRole->bind_param("ss", $slug, $name);
        $insertRole->execute();
    }

    $grant = $conn->prepare("INSERT IGNORE INTO role_permissions (role_slug, permission) VALUES (?,?)");
    foreach(array_keys(PERMISSIONS) as $permission){
        foreach([ROLE_QA_HEAD, ROLE_QA_OFFICER] as $slug){
            if($slug === ROLE_QA_OFFICER && in_array($permission, HEAD_ONLY_PERMISSIONS, true)){
                continue;
            }
            $grant->bind_param("ss", $slug, $permission);
            $grant->execute();
        }
    }
}

/** Every role, for the Roles screen. */
function get_roles($conn){
    ensure_roles_tables($conn);
    $result = mysqli_query($conn, "SELECT * FROM roles ORDER BY id ASC");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function role_display_name($conn, $slug){
    foreach(get_roles($conn) as $role){
        if($role['slug'] === $slug){
            return $role['name'];
        }
    }
    return $slug;
}

/** ['qa_head' => ['offices.manage' => true, ...], ...] */
function role_permission_map($conn){
    static $map = null;
    if($map !== null){
        return $map;
    }

    ensure_roles_tables($conn);
    $map = [];
    $result = mysqli_query($conn, "SELECT role_slug, permission FROM role_permissions");
    if($result){
        while($row = $result->fetch_assoc()){
            $map[$row['role_slug']][$row['permission']] = true;
        }
    }
    return $map;
}

function role_can($conn, $roleSlug, $permission){
    // The Head is defined by the code, not by its rows: a Roles screen that
    // could strip the Head is a way to lock everyone out for good.
    if($roleSlug === ROLE_QA_HEAD){
        return array_key_exists($permission, PERMISSIONS);
    }
    if(in_array($permission, HEAD_ONLY_PERMISSIONS, true)){
        return false;
    }

    $map = role_permission_map($conn);
    return isset($map[$roleSlug][$permission]);
}

/** Whether this browser holds a QA sign-in at all, whichever role. */
function session_is_qa_staff(){
    return isset($_SESSION['admin_username'], $_SESSION['admin_role'])
        && $_SESSION['admin_role'] === 'admin';
}

/** The signed-in QA account's role, refreshed by enforce_active_account(). */
function current_role_slug(){
    if(!session_is_qa_staff()){
        return '';
    }
    $slug = trim((string) ($_SESSION['admin_role_slug'] ?? ''));
    // An account signed in before this existed is treated as the Head it was.
    return $slug !== '' ? $slug : ROLE_QA_HEAD;
}

function current_user_can($conn, $permission){
    if(!session_is_qa_staff()){
        return false;
    }
    return role_can($conn, current_role_slug(), $permission);
}

/**
 * Page guard. Sends someone without a QA sign-in to the login form, and
 * someone signed in but lacking the permission back to the dashboard rather
 * than to a dead end.
 */
function require_permission($conn, $permission){
    if(!session_is_qa_staff()){
        header("Location: admin_login.php");
        exit();
    }
    if(!current_user_can($conn, $permission)){
        header("Location: home.php?denied=" . urlencode($permission));
        exit();
    }
}

/** The same guard for the JSON endpoints, which must not redirect. */
function require_permission_json($conn, $permission){
    if(!current_user_can($conn, $permission)){
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have permission to do that.']);
        exit();
    }
}

/** How many approved QA Heads could still sign in. */
function approved_head_count($conn){
    ensure_roles_tables($conn);
    $sql = "SELECT COUNT(*) AS total FROM users
             WHERE role = 'admin' AND role_slug = '" . ROLE_QA_HEAD . "'
               AND status = '" . USER_STATUS_APPROVED . "'";
    $result = mysqli_query($conn, $sql);
    $row = $result ? $result->fetch_assoc() : null;
    return $row ? (int) $row['total'] : 0;
}

/**
 * Whether this account is the only QA Head left. Demoting, rejecting or
 * deleting it would leave nobody able to manage offices or roles again.
 */
function is_last_qa_head($conn, $user){
    if(($user['role'] ?? '') !== 'admin' || ($user['role_slug'] ?? '') !== ROLE_QA_HEAD){
        return false;
    }
    if(trim((string) ($user['status'] ?? '')) !== USER_STATUS_APPROVED){
        return false;
    }
    return approved_head_count($conn) <= 1;
}
