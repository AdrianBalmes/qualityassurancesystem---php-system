<?php

/**
 * One-command setup for a fresh checkout.
 *
 *   php setup.php
 *
 * Brings the database up to what the code expects and creates the directories
 * the app writes to. Safe to run repeatedly -- every step checks before acting.
 *
 * Why this exists: several helpers (profile_columns.php, feedback_columns.php,
 * review_columns.php) try to self-add missing columns by running
 * "SELECT <col> FROM <table>" and testing for a falsy return. Since PHP 8.1
 * mysqli throws on a failed query instead of returning false, so those probes
 * abort the request before their own ALTER TABLE can run. This script uses
 * SHOW COLUMNS, which returns an empty result set instead of throwing.
 */

if(PHP_SAPI !== 'cli'){
    http_response_code(403);
    exit("This script is command line only.\n");
}

require_once __DIR__ . "/database.php";

$changes = 0;

echo "Checking database schema...\n";

ensure_table($conn, 'recommendation_documents', "CREATE TABLE recommendation_documents (
    id int(11) NOT NULL AUTO_INCREMENT,
    recommendation_id int(11) NOT NULL,
    office varchar(100) NOT NULL,
    file_name varchar(255) NOT NULL,
    original_name varchar(255) NOT NULL,
    uploaded_at datetime DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY recommendation_id (recommendation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci", $changes);

// profile_columns.php
ensure_column($conn, 'users', 'phone',         "varchar(30) DEFAULT ''", $changes);
ensure_column($conn, 'users', 'profile_photo', "varchar(255) DEFAULT ''", $changes);
ensure_column($conn, 'users', 'created_at',    "datetime DEFAULT CURRENT_TIMESTAMP", $changes);
ensure_column($conn, 'users', 'last_login',    "datetime DEFAULT NULL", $changes);

// feedback_columns.php -- absent from ems_db.sql, so a fresh import lacks them.
ensure_column($conn, 'feedback', 'attachment_name',          "varchar(255) DEFAULT ''", $changes);
ensure_column($conn, 'feedback', 'attachment_original_name', "varchar(255) DEFAULT ''", $changes);

// review_columns.php. TEXT columns cannot carry a DEFAULT on MySQL 8, though
// MariaDB permits it -- so review_remarks is added without one.
ensure_column($conn, 'audit_recommendations', 'review_remarks', "text", $changes);
ensure_column($conn, 'audit_recommendations', 'reviewed_by',    "varchar(50) DEFAULT ''", $changes);
ensure_column($conn, 'audit_recommendations', 'reviewed_at',    "datetime DEFAULT NULL", $changes);

// The review workflow adds statuses beyond the original enum.
$statusType = column_type($conn, 'audit_recommendations', 'status');
if($statusType !== null && stripos($statusType, 'enum(') === 0){
    mysqli_query($conn, "ALTER TABLE audit_recommendations MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
    echo "  + audit_recommendations.status widened from enum to varchar(20)\n";
    $changes++;
}

// OneDrive sync tables create themselves on first use, but doing it here means
// `onedrive_worker.php status` works on a brand new checkout.
require_once __DIR__ . "/onedrive_sync.php";
od_ensure_sync_table($conn);
od_ensure_token_table($conn);

echo $changes === 0 ? "  Schema already up to date.\n" : "  Applied {$changes} change(s).\n";

echo "\nChecking directories...\n";
foreach(['uploads', 'uploads/avatars'] as $dir){
    $path = __DIR__ . "/" . $dir;
    if(is_dir($path)){
        echo "  ok       {$dir}/\n";
        continue;
    }
    mkdir($path, 0775, true);
    echo "  created  {$dir}/\n";
}

echo "\nChecking configuration...\n";
if(is_readable(__DIR__ . "/.env")){
    echo "  ok       .env present\n";
} else {
    echo "  MISSING  .env -- copy .env.example to .env\n";
    echo "           (only needed for OneDrive sync; the app runs without it)\n";
}

echo "\nSetup complete. Start the app with:\n  php -S localhost:8080\n";

// ---------------------------------------------------------------------------

function ensure_table($conn, $table, $createSql, &$changes){
    $result = mysqli_query($conn, "SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if($result && $result->num_rows > 0){
        return;
    }
    mysqli_query($conn, $createSql);
    echo "  + created table {$table}\n";
    $changes++;
}

function ensure_column($conn, $table, $column, $definition, &$changes){
    if(column_type($conn, $table, $column) !== null){
        return;
    }
    mysqli_query($conn, "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    echo "  + {$table}.{$column}\n";
    $changes++;
}

/** Returns the column's SQL type, or null when the column does not exist. */
function column_type($conn, $table, $column){
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");
    if(!$result || $result->num_rows === 0){
        return null;
    }
    $row = $result->fetch_assoc();
    return $row['Type'] ?? '';
}
