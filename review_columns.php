<?php
/**
 * Add the review-workflow columns to audit_recommendations if an older
 * database lacks them, and widen the original status enum.
 *
 * Uses SHOW COLUMNS rather than a SELECT probe: since PHP 8.1 mysqli throws on
 * a failed query instead of returning false, so probing a missing column
 * crashed the page before the ALTER TABLE could run.
 */
function ensure_review_columns($conn){
    static $done = false;
    if($done){
        return;
    }
    $done = true;

    // TEXT cannot carry a DEFAULT on MySQL 8 (MariaDB allows it), so none here.
    $columns = [
        'review_remarks' => "text",
        'reviewed_by'    => "varchar(50) DEFAULT ''",
        'reviewed_at'    => "datetime DEFAULT NULL",
    ];
    foreach($columns as $column => $definition){
        $result = mysqli_query($conn, "SHOW COLUMNS FROM audit_recommendations LIKE '{$column}'");
        if($result && $result->num_rows === 0){
            mysqli_query($conn, "ALTER TABLE audit_recommendations ADD COLUMN `{$column}` {$definition}");
        }
    }

    $columnResult = mysqli_query($conn, "SHOW COLUMNS FROM audit_recommendations LIKE 'status'");
    if($columnResult){
        $columnInfo = $columnResult->fetch_assoc();
        if($columnInfo && stripos($columnInfo['Type'], 'enum(') === 0 && stripos($columnInfo['Type'], 'Approved') === false){
            mysqli_query($conn, "ALTER TABLE audit_recommendations MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Pending'");
        }
    }
}
