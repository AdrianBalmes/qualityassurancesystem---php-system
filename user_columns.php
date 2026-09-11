<?php

/**
 * Columns supporting individual user accounts and the registration approval
 * queue. Uses SHOW COLUMNS rather than a SELECT probe: since PHP 8.1 mysqli
 * throws on a failed query instead of returning false, so probing a missing
 * column aborts the request before the ALTER TABLE can run.
 */

const USER_STATUS_PENDING  = 'pending';
const USER_STATUS_APPROVED = 'approved';
const USER_STATUS_REJECTED = 'rejected';

function ensure_user_account_columns($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    // Existing accounts default to approved so nobody is locked out by this
    // feature arriving. Registrations set 'pending' explicitly.
    user_col($conn, 'full_name',     "varchar(120) NOT NULL DEFAULT ''");
    user_col($conn, 'status',        "varchar(20) NOT NULL DEFAULT '" . USER_STATUS_APPROVED . "'");
    user_col($conn, 'reviewed_by',   "varchar(50) DEFAULT NULL");
    user_col($conn, 'reviewed_at',   "datetime DEFAULT NULL");
    user_col($conn, 'review_reason', "text DEFAULT NULL");
}

function user_col($conn, $column, $definition){
    $result = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($column) . "'");
    if($result && $result->num_rows === 0){
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN `{$column}` {$definition}");
    }
}

/**
 * Limits on the 6-digit password-reset code. Without them the code falls to
 * guessing: a million possibilities, no cap on tries. With them an attacker
 * gets 5 guesses per code and 3 codes per quarter hour.
 */
const PASSWORD_RESET_MAX_ATTEMPTS   = 5;
const PASSWORD_RESET_MAX_REQUESTS   = 3;
const PASSWORD_RESET_WINDOW_MINUTES = 15;

function ensure_password_resets_table($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS password_resets (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id int(11) NOT NULL,
        token_hash varchar(64) NOT NULL,
        expires_at datetime NOT NULL,
        used_at datetime DEFAULT NULL,
        attempts int(11) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        KEY token_hash (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Tables created before the attempt limit existed lack the counter.
    $result = mysqli_query($conn, "SHOW COLUMNS FROM password_resets LIKE 'attempts'");
    if($result && $result->num_rows === 0){
        mysqli_query($conn, "ALTER TABLE password_resets ADD COLUMN attempts int(11) NOT NULL DEFAULT 0");
    }
}

/** Human-readable status, for badges and messages. */
function user_status_label($status){
    switch($status){
        case USER_STATUS_PENDING:  return 'Pending approval';
        case USER_STATUS_REJECTED: return 'Rejected';
        case USER_STATUS_APPROVED: return 'Approved';
    }
    return ucfirst((string) $status);
}

/**
 * Why this account may not sign in, or '' when it may.
 * Treats an unknown/empty status as approved so pre-existing rows keep working.
 */
function user_login_block_reason($user){
    $status = trim((string) ($user['status'] ?? USER_STATUS_APPROVED));

    if($status === USER_STATUS_PENDING){
        return "Your registration is still awaiting administrator approval.";
    }
    if($status === USER_STATUS_REJECTED){
        $reason = trim((string) ($user['review_reason'] ?? ''));
        return $reason !== ''
            ? "Your registration was not approved. Reason: " . $reason
            : "Your registration was not approved. Please contact the QA administrator.";
    }
    return '';
}

/** How many administrators can currently sign in. */
function approved_admin_count($conn){
    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND status = '" . USER_STATUS_APPROVED . "'");
    $row = $result ? $result->fetch_assoc() : null;
    return $row ? (int) $row['total'] : 0;
}

/**
 * Re-check the signed-in account against the database on every page load, and
 * end that sign-in if it is no longer approved.
 *
 * Without this, revoking someone only takes effect at their next sign-in --
 * an admin whose access was withdrawn keeps working until they log out.
 *
 * $scope says which sign-in the calling page runs on: 'admin', 'office', or
 * 'auto' for pages open to both. It matters because a browser can hold an
 * admin and an office sign-in at once -- checking the admin account while
 * rendering an office page would let a revoked office user carry on working.
 */
function enforce_active_account($conn, $scope = 'auto'){
    require_once __DIR__ . "/session_scope.php";

    if($scope === 'auto'){
        $scope = session_scope_has_admin() ? 'admin' : 'office';
    }

    $isAdmin = ($scope === 'admin');
    $userId = (int) ($isAdmin ? ($_SESSION['admin_user_id'] ?? 0) : ($_SESSION['office_user_id'] ?? 0));

    // Nobody is signed in under this scope; the page's own guard handles that.
    if($isAdmin ? !session_scope_has_admin() : !isset($_SESSION['office_username'])){
        return;
    }
    if($userId <= 0){
        return;
    }

    $stmt = $conn->prepare("SELECT status, role, office FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $stillValid = $row
        && user_login_block_reason($row) === ''
        && (($row['role'] === 'admin') === $isAdmin);

    if($stillValid){
        if(!$isAdmin){
            session_scope_follow_office_rename((string) $row['office']);
        }
        return;
    }

    // Drop only the revoked sign-in. Anything else in this browser survives.
    if($isAdmin){
        session_scope_logout_admin();
        $destination = session_scope_has_office() ? "office_dashboard.php" : "admin_login.php?revoked=1";
    } else {
        session_scope_logout_office($_SESSION['office_name'] ?? '');
        $destination = session_scope_has_admin() ? "home.php" : "index.php?revoked=1";
    }

    header("Location: " . $destination);
    exit();
}
