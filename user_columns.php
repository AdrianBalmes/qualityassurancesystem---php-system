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
